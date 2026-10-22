// Videokonferenzen (TheRedStonee Meets): Meeting-Karte im Chat, Start, Beitritt und eingebettetes Overlay.
import { api } from './api.js';
import { store } from './store.js';
import { h, icon, timeOf, fullDate } from './util.js';
import { toast, confirmDialog } from './ui.js';

const ALLOW = 'camera; microphone; display-capture; autoplay; fullscreen; clipboard-write';
let current = null; // { meetingId, convId, root, frame, mini }

export const meetsEnabled = () => !!store.meets;
export const isGuest = () => store.me?.role === 'guest';

/** Darf die Person in der aktiven Unterhaltung ein Meeting starten? (Server prüft ebenfalls.) */
export function canStartMeeting(conv) {
  if (!meetsEnabled() || !conv || isGuest()) return false;
  if (conv.type === 'direct' && conv.other?.bot) return false;
  return conv.type === 'direct' || ['admin', 'moderator'].includes(conv.role);
}

export function meetingNode(m) {
  const mt = m.meeting;
  if (!mt) return h('p', { class: 'italic opacity-70' }, 'Meeting nicht verfügbar.');
  const inThis = current && current.meetingId === mt.id;
  const count = mt.participants;
  const info = h('div', { class: 'min-w-0 flex-1' },
    h('p', { class: 'mb-0.5 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-accent' }, icon('video', 'text-base'), mt.active ? 'Videomeeting läuft' : 'Videomeeting beendet'),
    h('p', { class: 'text-sm font-semibold' }, mt.starter_name ? `Gestartet von ${mt.starter_name}` : 'Videomeeting'),
    h('p', { class: 'text-xs text-slate-600 dark:text-neutral-300', 'data-meeting-count': String(mt.id) },
      mt.active
        ? (count > 0 ? `${count} ${count === 1 ? 'Person ist' : 'Personen sind'} im Meeting` : 'Noch niemand im Meeting') + ` · seit ${timeOf(mt.started_at)}`
        : `${timeOf(mt.started_at)} bis ${timeOf(mt.ended_at)}${mt.peak > 0 ? ` · bis zu ${mt.peak} Teilnehmende` : ''}`));
  const icn = h('div', { class: `flex h-14 w-14 shrink-0 items-center justify-center rounded-xl text-3xl ${mt.active ? 'bg-emerald-500/15 text-emerald-600' : 'bg-slate-200 text-slate-500 dark:bg-neutral-700'}`, 'aria-hidden': 'true' }, icon('video'));
  const wrap = h('div', { class: 'w-full min-w-[15rem]', 'data-meeting': mt.id }, h('div', { class: 'flex gap-3' }, icn, info));
  if (mt.active && meetsEnabled()) {
    wrap.append(h('div', { class: 'mt-3 flex flex-wrap gap-2' },
      h('button', { type: 'button', class: 'btn btn-primary', onclick: () => (inThis ? expand() : joinMeeting(mt.id, m.conversation_id)) }, icon('video', 'text-lg'), inThis ? 'Zum Meeting' : 'Beitreten'),
      mt.can_end ? h('button', { type: 'button', class: 'btn btn-secondary', onclick: () => endMeeting(mt.id) }, 'Beenden') : null));
  }
  return wrap;
}

export async function startMeeting(convId) {
  if (current) { toast('Du bist bereits in einem Meeting. Bitte verlasse es zuerst.', 'error'); expand(); return; }
  try {
    const res = await api.post(`/api/conversations/${convId}/meeting`, {});
    if (res.message) store.emit('message-update', res.message);
    open(res.meeting.id, convId, res.link);
    if (res.existing) toast('In dieser Unterhaltung läuft bereits ein Meeting. Du bist beigetreten.');
  } catch (e) { toast(e.message, 'error'); }
}

const handled = new Map();

/** Annahme aus einer Benachrichtigung. Doppelte Ereignisse innerhalb von 15 Sekunden werden ignoriert. */
export function acceptCall(meetingId, convId) {
  meetingId = Number(meetingId); convId = Number(convId);
  if (!Number.isInteger(meetingId) || meetingId <= 0 || !Number.isInteger(convId) || convId <= 0) return;
  const now = Date.now();
  if (now - (handled.get(meetingId) || 0) < 15000) return;
  handled.set(meetingId, now);
  if (!meetsEnabled()) { toast('Videokonferenzen sind nicht aktiviert.', 'error'); return; }
  joinMeeting(meetingId, convId);
}

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.addEventListener('message', (e) => {
    if (e.data?.type === 'meet-accept') acceptCall(e.data.meetingId, e.data.convId);
  });
}

export async function joinMeeting(meetingId, convId) {
  if (current) {
    if (current.meetingId === meetingId) { expand(); return; }
    toast('Du bist bereits in einem anderen Meeting. Bitte verlasse es zuerst.', 'error');
    return;
  }
  try {
    const res = await api.post(`/api/meetings/${meetingId}/join`, {});
    if (res.message) store.emit('message-update', res.message);
    open(meetingId, convId, res.link);
  } catch (e) {
    toast(e.message, 'error');
    api.get(`/api/meetings/${meetingId}`).then((r) => r.message && store.emit('message-update', r.message)).catch(() => {});
  }
}

async function endMeeting(meetingId) {
  const ok = await confirmDialog({ title: 'Meeting für alle beenden?', text: 'Alle Teilnehmenden werden getrennt. Das lässt sich nicht rückgängig machen.', confirmLabel: 'Beenden' });
  if (!ok) return;
  try {
    const res = await api.post(`/api/meetings/${meetingId}/end`, {});
    store.emit('message-update', res.message);
    if (current && current.meetingId === meetingId) close();
  } catch (e) { toast(e.message, 'error'); }
}

const FULL = 'meet-overlay fixed inset-0 z-[80] flex flex-col bg-black sm:inset-4 sm:rounded-2xl sm:shadow-2xl';
const MINI = 'meet-overlay fixed bottom-24 right-3 z-[80] h-28 w-40 overflow-hidden rounded-xl bg-black shadow-2xl sm:bottom-4 sm:right-4 sm:h-44 sm:w-72';
const fmt = (sec) => `${Math.floor(sec / 60)}:${String(sec % 60).padStart(2, '0')}`;

function open(meetingId, convId, link) {
  const origin = store.meets?.origin;
  if (!origin || !link.embed_url.startsWith(origin + '/')) { toast('Ungültige Meeting-Adresse.', 'error'); return; }
  const frame = h('iframe', { src: link.embed_url, allow: ALLOW, allowfullscreen: '', title: 'Videomeeting', class: 'h-full w-full border-0 bg-black', referrerpolicy: 'no-referrer' });
  // Grosse, gut treffbare «Chat»-Taste links: ein Tipp und das Meeting läuft klein weiter, der Chat ist sofort bedienbar.
  const bar = h('div', { class: 'flex shrink-0 items-center gap-2 bg-neutral-900 px-2 py-2 text-white', style: { paddingTop: 'max(0.5rem, env(safe-area-inset-top))', paddingLeft: 'max(0.5rem, env(safe-area-inset-left))', paddingRight: 'max(0.5rem, env(safe-area-inset-right))' } },
    h('button', { type: 'button', class: 'inline-flex h-10 items-center gap-1 rounded-xl bg-white/10 px-3 text-sm font-medium hover:bg-white/20', 'aria-label': 'Zurück zum Chat, Meeting läuft weiter', title: 'Zurück zum Chat (Esc). Das Meeting läuft weiter.', onclick: minimize }, icon('chevron-left', 'text-xl'), 'Chat'),
    h('span', { class: 'flex min-w-0 flex-1 items-center gap-1.5 truncate text-sm font-medium' }, icon('video', 'text-lg text-emerald-400'), h('span', { class: 'truncate' }, 'Videomeeting'), h('span', { class: 'tabular-nums text-xs text-white/60', 'data-meet-time': '' }, '0:00')),
    h('button', { type: 'button', class: 'inline-flex h-10 items-center gap-1 rounded-xl bg-red-600 px-3 text-sm font-medium hover:bg-red-500', 'aria-label': 'Meeting verlassen', title: 'Verlassen', onclick: close }, icon('phone-off', 'text-lg'), h('span', { class: 'hidden sm:inline' }, 'Verlassen')));
  const body = h('div', { class: 'min-h-0 flex-1 overflow-hidden sm:rounded-b-2xl', style: { paddingBottom: 'env(safe-area-inset-bottom)' } }, frame);
  const root = h('div', { class: FULL, role: 'dialog', 'aria-label': 'Videomeeting', 'aria-modal': 'false' }, bar, body);
  // Minimiert: durchsichtige Fläche über dem Bild – ein Tipp irgendwo darauf öffnet das Meeting wieder gross
  const cover = h('button', { type: 'button', class: 'absolute inset-0 z-10 hidden h-full w-full cursor-pointer bg-transparent', 'aria-label': 'Meeting wieder gross anzeigen', onclick: expand });
  // Schwebende Statusleiste oben: immer erreichbar, solange das Meeting klein läuft
  const pill = h('div', { class: 'fixed left-1/2 z-[81] hidden -translate-x-1/2 items-center gap-1 whitespace-nowrap rounded-full bg-emerald-600 py-1 pl-1 pr-1 text-sm font-medium text-white shadow-lg', style: { top: 'calc(env(safe-area-inset-top) + 4.25rem)' } },
    h('button', { type: 'button', class: 'inline-flex items-center gap-2 rounded-full px-3 py-1.5 hover:bg-emerald-500', 'aria-label': 'Zurück zum Meeting', onclick: expand }, icon('video', 'text-lg'), 'Meeting läuft', h('span', { class: 'tabular-nums text-xs text-white/80', 'data-meet-time': '' }, '0:00'), h('span', { class: 'text-xs underline' }, 'Zurück')),
    h('button', { type: 'button', class: 'inline-flex h-8 w-8 items-center justify-center rounded-full bg-red-600 hover:bg-red-500', 'aria-label': 'Meeting verlassen', title: 'Verlassen', onclick: close }, icon('phone-off', 'text-base')));
  root.append(cover);
  document.body.append(root, pill);
  current = { meetingId, convId, root, frame, bar, cover, pill, mini: false, since: Date.now(), wake: null };
  current.timer = setInterval(() => {
    const t = fmt(Math.floor((Date.now() - current.since) / 1000));
    for (const el of document.querySelectorAll('[data-meet-time]')) el.textContent = t;
  }, 1000);
  window.addEventListener('message', onMessage);
  document.addEventListener('keydown', onKey);
  window.addEventListener('popstate', onPop);
  // Zurück-Geste / Zurück-Taste (Android, Browser): erst das Meeting minimieren statt die App zu verlassen
  history.pushState({ meet: meetingId }, '');
  holdScreen();
}

function onKey(e) { if (e.key === 'Escape' && current && !current.mini) { e.preventDefault(); minimize(); } }

function onPop() {
  if (current && !current.mini) {
    current.poppedByBack = true;
    minimize();
  }
}

function minimize() {
  if (!current || current.mini) return;
  current.mini = true;
  current.root.className = MINI;
  current.bar.classList.add('hidden');
  current.cover.classList.remove('hidden');
  current.pill.classList.remove('hidden');
  current.pill.classList.add('inline-flex');
  // Der in open() angelegte Verlaufseintrag wird entfernt, wenn nicht schon die Zurück-Geste ihn verbraucht hat
  if (!current.poppedByBack && history.state?.meet === current.meetingId) history.back();
  current.poppedByBack = false;
  store.emit('meet-mini', true);
}

function expand() {
  if (!current || !current.mini) return;
  current.mini = false;
  current.root.className = FULL;
  current.bar.classList.remove('hidden');
  current.cover.classList.add('hidden');
  current.pill.classList.add('hidden');
  current.pill.classList.remove('inline-flex');
  history.pushState({ meet: current.meetingId }, '');
  store.emit('meet-mini', false);
}

/** Bildschirm während des Meetings wach halten (nicht überall verfügbar; Fehler sind unkritisch). */
async function holdScreen() {
  try {
    if (current && 'wakeLock' in navigator) current.wake = await navigator.wakeLock.request('screen');
  } catch { /* nicht erlaubt oder nicht unterstützt */ }
}
document.addEventListener('visibilitychange', () => { if (current && !document.hidden && (!current.wake || current.wake.released)) holdScreen(); });

function close() {
  if (!current) return;
  window.removeEventListener('message', onMessage);
  document.removeEventListener('keydown', onKey);
  window.removeEventListener('popstate', onPop);
  clearInterval(current.timer);
  try { current.wake?.release(); } catch { /* ignorieren */ }
  const stateIsOurs = !current.mini && history.state?.meet === current.meetingId;
  current.root.remove(); // Das Entfernen des iframes trennt die Verbindung
  current.pill.remove();
  const mid = current.meetingId;
  current = null;
  if (stateIsOurs) history.back();
  store.emit('meet-mini', false);
  api.get(`/api/meetings/${mid}`).then((r) => r.message && store.emit('message-update', r.message)).catch(() => {});
}

function onMessage(e) {
  if (!current || !store.meets || e.origin !== store.meets.origin || e.source !== current.frame.contentWindow) return;
  if (e.data?.source !== 'trs-meets') return;
  if (e.data.type === 'left' || e.data.type === 'ended') close();
}

// Beim Schliessen des Tabs sauber verlassen
window.addEventListener('pagehide', () => { if (current) { current.root.remove(); current.pill.remove(); } });
