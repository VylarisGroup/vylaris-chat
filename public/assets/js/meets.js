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

function open(meetingId, convId, link) {
  const origin = store.meets?.origin;
  if (!origin || !link.embed_url.startsWith(origin + '/')) { toast('Ungültige Meeting-Adresse.', 'error'); return; }
  const frame = h('iframe', { src: link.embed_url, allow: ALLOW, allowfullscreen: '', title: 'Videomeeting', class: 'h-full w-full border-0 bg-black', referrerpolicy: 'no-referrer' });
  const bar = h('div', { class: 'flex shrink-0 items-center gap-2 bg-neutral-900 px-3 py-2 text-white' },
    icon('video', 'text-lg text-emerald-400'),
    h('span', { class: 'min-w-0 flex-1 truncate text-sm font-medium', 'data-meeting-title': '' }, 'Videomeeting'),
    h('button', { type: 'button', class: 'btn-icon text-white hover:bg-white/10', 'aria-label': 'Meeting minimieren', title: 'Minimieren (Meeting läuft weiter)', onclick: minimize }, icon('minus')),
    h('button', { type: 'button', class: 'btn-icon text-white hover:bg-white/10', 'aria-label': 'Meeting verlassen', title: 'Verlassen', onclick: close }, icon('phone-off')));
  const root = h('div', { class: 'meet-overlay fixed inset-0 z-[80] flex flex-col bg-black sm:inset-4 sm:rounded-2xl sm:shadow-2xl', role: 'dialog', 'aria-label': 'Videomeeting', 'aria-modal': 'false' }, bar, h('div', { class: 'min-h-0 flex-1 overflow-hidden sm:rounded-b-2xl' }, frame));
  document.body.append(root);
  current = { meetingId, convId, root, frame, bar, mini: false };
  window.addEventListener('message', onMessage);
}

function minimize() {
  if (!current) return;
  current.mini = true;
  current.root.className = 'meet-overlay fixed bottom-4 right-4 z-[80] flex h-44 w-72 flex-col overflow-hidden rounded-xl bg-black shadow-2xl';
  current.bar.classList.add('hidden');
  if (!current.pip) {
    current.pip = h('button', { type: 'button', class: 'absolute right-1 top-1 z-10 flex items-center gap-1 rounded-lg bg-neutral-900/85 px-2 py-1 text-xs text-white hover:bg-neutral-800', 'aria-label': 'Meeting vergrössern', onclick: expand }, icon('expand-alt', 'text-sm'), 'Öffnen');
    current.root.append(current.pip);
  }
  current.pip.classList.remove('hidden');
}

function expand() {
  if (!current) return;
  current.mini = false;
  current.root.className = 'meet-overlay fixed inset-0 z-[80] flex flex-col bg-black sm:inset-4 sm:rounded-2xl sm:shadow-2xl';
  current.bar.classList.remove('hidden');
  current.pip?.classList.add('hidden');
}

function close() {
  if (!current) return;
  window.removeEventListener('message', onMessage);
  current.root.remove(); // Das Entfernen des iframes trennt die Verbindung
  const mid = current.meetingId;
  current = null;
  api.get(`/api/meetings/${mid}`).then((r) => r.message && store.emit('message-update', r.message)).catch(() => {});
}

function onMessage(e) {
  if (!current || !store.meets || e.origin !== store.meets.origin || e.source !== current.frame.contentWindow) return;
  if (e.data?.source !== 'trs-meets') return;
  if (e.data.type === 'left' || e.data.type === 'ended') close();
}

// Beim Schliessen des Tabs sauber verlassen
window.addEventListener('pagehide', () => { if (current) current.root.remove(); });
