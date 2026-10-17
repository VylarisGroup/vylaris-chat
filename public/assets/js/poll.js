// Near-Realtime per adaptivem Polling: Ereignisse seit dem Cursor abholen, Zustand aktualisieren, Hinweise anzeigen.

import { api, ApiError } from './api.js';
import { store } from './store.js';
import { setTitle, url } from './util.js';
import { toast } from './ui.js';
import { applyRemoteDrafts } from './collab.js';
import { applyPolledMessages, closeConversation, loadDetails, openConversation } from './chat.js';

const FAST = 2500; // Fenster sichtbar und aktiv
const IDLE = 6000; // sichtbar, aber seit 2 Minuten keine Eingabe
const HIDDEN = 15000; // Tab im Hintergrund
const MAX_BACKOFF = 30000;

let timer = null;
let running = false;
let inFlight = false;
let failures = 0;
let lastActivity = Date.now();
let audioCtx = null;

const isActive = () => !document.hidden && Date.now() - lastActivity < 120000;

let stream = null;
let streamOk = false;
let streamKey = '';

/** Echtzeitmodus: EventSource weckt den normalen Poll auf. Fällt bei Fehlern automatisch aufs reine Polling zurück. */
/** Meldet dem Server sofort, dass die App nicht mehr sichtbar ist, damit Push ab jetzt zugestellt wird. */
function reportHidden() {
  try {
    fetch(url('/api/presence/hidden'), { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-CSRF-Token': api.csrf, 'Content-Type': 'application/json', Accept: 'application/json' }, body: '{}' }).catch(() => {});
  } catch { /* optional */ }
}

function openStream() {
  if (!store.realtime || store.realtime !== 'sse' || !('EventSource' in window) || document.hidden) {
    closeStream();
    return;
  }
  const key = `${store.activeId || 0}`;
  if (stream && key === streamKey && stream.readyState !== 2) return;
  closeStream();
  streamKey = key;
  const q = new URLSearchParams({ cursor: String(store.cursor), view: key });
  stream = new EventSource(url(`/api/stream?${q}`), { withCredentials: true });
  stream.addEventListener('open', () => { streamOk = true; });
  stream.addEventListener('wake', () => { closeStream(); schedule(0); });
  stream.addEventListener('bye', () => { closeStream(); setTimeout(openStream, 200); });
  stream.addEventListener('error', () => { streamOk = false; closeStream(); setTimeout(openStream, 5000); });
}

function closeStream() {
  if (stream) { stream.close(); stream = null; }
  streamOk = false;
}

function nextDelay() {
  if (failures > 0) return Math.min(MAX_BACKOFF, FAST * 2 ** failures);
  if (document.hidden) return HIDDEN;
  if (streamOk) return 20000; // Sicherheitsnetz, solange die Echtzeitverbindung steht
  return isActive() ? FAST : IDLE;
}

function schedule(ms = nextDelay()) {
  clearTimeout(timer);
  if (running) timer = setTimeout(tick, ms);
}

export function startPolling() {
  if (running) return;
  running = true;
  const bump = () => { lastActivity = Date.now(); };
  for (const evt of ['pointerdown', 'keydown', 'touchstart', 'wheel']) window.addEventListener(evt, bump, { passive: true });
  window.addEventListener('mousemove', (() => { let t = 0; return () => { const n = Date.now(); if (n - t > 5000) { t = n; bump(); } }; })(), { passive: true });
  document.addEventListener('visibilitychange', () => { if (!document.hidden) { bump(); schedule(50); openStream(); } else { closeStream(); reportHidden(); } });
  store.on('active', () => { if (store.realtime === 'sse') { closeStream(); openStream(); } });
  window.addEventListener('online', () => { schedule(50); });
  window.addEventListener('offline', () => setOnline(false));
  window.addEventListener('focus', () => schedule(50));
  openStream();
  schedule(FAST);
}

function setOnline(on) {
  if (store.online === on) return;
  store.online = on;
  store.emit('online', on);
}

async function tick() {
  if (inFlight) return;
  inFlight = true;
  try {
    const q = new URLSearchParams({ cursor: String(store.cursor), view: String(store.activeId || 0), active: isActive() ? '1' : '0', visible: document.hidden ? '0' : '1' });
    const res = await api.get(`/api/poll?${q}`, { timeout: 20000 });
    failures = 0;
    setOnline(true);
    await handle(res);
  } catch (e) {
    if (e instanceof ApiError && e.status === 401) return; // onAuthLost leitet um
    failures = Math.min(failures + 1, 4);
    if (failures >= 2 || !navigator.onLine) setOnline(false);
  } finally {
    inFlight = false;
    schedule();
    if (store.realtime === 'sse' && !stream && !document.hidden) openStream();
  }
}

/** Zustand komplett neu laden (nach Verbindungsabbruch oder zu vielen verpassten Ereignissen). */
export async function resync() {
  const res = await api.get('/api/bootstrap');
  store.cursor = res.cursor;
  store.conversations.clear();
  for (const c of res.conversations) store.upsertConversation(c);
  store.notificationsUnread = res.notifications_unread;
  store.emit('conversations');
  store.emit('notifications');
  if (store.activeId) {
    if (!store.conv(store.activeId)) closeConversation();
    else {
      const cache = store.cache(store.activeId);
      cache.loaded = false;
      cache.items = [];
      openConversation(store.activeId, { focus: false });
    }
  }
  refreshTitle();
}

async function handle(res) {
  if (res.reset) { await resync(); return; }
  store.cursor = res.cursor;

  for (const id of res.removed || []) {
    store.conversations.delete(id);
    store.msgs.delete(id);
    store.members.delete(id);
    if (store.activeId === id) {
      closeConversation();
      toast('Du bist nicht mehr Mitglied dieser Unterhaltung.', 'info');
    }
  }
  for (const c of res.conversations || []) store.upsertConversation(c);

  const incoming = res.messages?.length ? applyPolledMessages(res.messages) : [];
  if (res.draft_convs?.length) applyRemoteDrafts(res.drafts, res.draft_convs, (cid) => {
    // Eigene, noch nicht synchronisierte Eingabe hat Vorrang vor Entwürfen anderer Geräte
    const box = document.getElementById('composer-input');
    if (store.activeId !== cid || !box) return false;
    return box.value !== ((store.drafts.get(cid) || {}).synced ?? '');
  });
  if (res.conversations?.length || res.removed?.length) store.emit('conversations');

  // Gruppeninfo des geöffneten Chats aktuell halten
  if (store.detailsOpen && store.activeId && res.events?.some((e) => e.conversation_id === store.activeId && /^conversation\./.test(e.type))) {
    loadDetails(store.activeId);
  }

  store.typing = res.typing || { conversation_id: 0, names: [] };
  store.emit('typing');

  if (res.notifications_unread !== store.notificationsUnread) {
    const grew = res.notifications_unread > store.notificationsUnread;
    store.notificationsUnread = res.notifications_unread;
    store.emit('notifications');
    if (grew) store.emit('notification-new');
  }

  if (incoming.length) notifyIncoming(incoming);
  refreshTitle();
}

export function refreshTitle() {
  setTitle(store.totalUnread(), store.branding.company_name);
}

// ------------------------------------------------------------ Hinweise

function notifyIncoming(list) {
  const s = store.me.settings;
  let sound = false;
  for (const m of list) {
    const c = store.conv(m.conversation_id);
    if (!c) continue;
    const mentioned = (m.mentions || []).some((x) => x.id === store.me.id);
    if (c.muted && !mentioned) continue;
    const watching = !document.hidden && document.hasFocus() && store.activeId === c.id;
    if (watching) continue;
    sound = true;
    if (s.notify_browser && 'Notification' in window && Notification.permission === 'granted' && (document.hidden || !document.hasFocus() || store.activeId !== c.id)) {
      showDesktop(c, m, mentioned);
    }
  }
  if (sound && s.notify_sound) beep();
}

function showDesktop(c, m, mentioned) {
  try {
    const who = m.sender?.display_name || 'Neue Nachricht';
    const text = m.body ? m.body.replace(/\s+/g, ' ').slice(0, 100) : (m.attachments?.length ? `Anhang: ${m.attachments[0].name}` : '');
    const n = new Notification(c.type === 'group' ? `${who} in ${c.name}` : who, {
      body: mentioned ? `Erwähnung: ${text}` : text,
      tag: `conv-${c.id}`,
      renotify: true,
      icon: undefined,
    });
    n.onclick = () => {
      window.focus();
      store.emit('open-conversation', { id: c.id, around: m.id });
      n.close();
    };
    setTimeout(() => n.close(), 8000);
  } catch { /* Benachrichtigung nicht möglich */ }
}

function beep() {
  try {
    audioCtx ??= new (window.AudioContext || window.webkitAudioContext)();
    if (audioCtx.state === 'suspended') audioCtx.resume();
    const t = audioCtx.currentTime;
    const osc = audioCtx.createOscillator();
    const gain = audioCtx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(880, t);
    osc.frequency.exponentialRampToValueAtTime(1175, t + 0.12);
    gain.gain.setValueAtTime(0.0001, t);
    gain.gain.exponentialRampToValueAtTime(0.12, t + 0.02);
    gain.gain.exponentialRampToValueAtTime(0.0001, t + 0.22);
    osc.connect(gain).connect(audioCtx.destination);
    osc.start(t);
    osc.stop(t + 0.25);
  } catch { /* Audio nicht verfügbar */ }
}
