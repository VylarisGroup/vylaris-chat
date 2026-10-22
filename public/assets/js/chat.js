// Nachrichtenverlauf, Kopfzeile und Eingabefeld.

import { api } from './api.js';
import { store } from './store.js';
import { renderRich, firstLink, linkCard } from './richtext.js';
import {
  h, icon, clear, userAvatar, convAvatar, timeOf, fullDate, dayLabel, dayKey, humanSize, uuid, throttle,
  presenceLabel, relativeSeen, copyText, url,
} from './util.js';
import { toast, confirmDialog, menu, emojiPicker, closeMenu, skeletonMessages } from './ui.js';
import { forwardDialog, reportDialog, lightbox } from './dialogs.js';
import { meetingNode, canStartMeeting, startMeeting } from './meets.js';
import { pollNode, eventNode, pollDialog, eventDialog, threadSummary, openThread, closeThread, threadMessage, threadIsOpenFor, queueDraft, canCreate } from './collab.js';

const PAGE = 40;
let root; // Hauptbereich
let el = {}; // DOM-Referenzen
let view = { convId: null, replyTo: null, editing: null, loadingOlder: false, loadingNewer: false, pendingNew: 0 };
let tempId = 0;
let readTimer = null;

// ------------------------------------------------------------------ Aufbau

export function initChat(container) {
  root = container;
  el.empty = h('div', { class: 'hidden h-full flex-col items-center justify-center gap-4 p-8 text-center', id: 'chat-empty' },
    h('span', { class: 'flex h-20 w-20 items-center justify-center rounded-3xl bg-accent/10 text-accent' }, icon('message-rounded-dots', 'text-5xl')),
    h('div', {},
      h('h2', { class: 'text-lg font-semibold tracking-tight' }, 'Wähle eine Unterhaltung'),
      h('p', { class: 'mt-1 max-w-xs text-sm text-slate-500 dark:text-neutral-400' }, 'Starte einen neuen Chat oder öffne eine bestehende Unterhaltung in der Seitenleiste.')),
    h('button', { class: 'btn btn-primary', hidden: store.me?.role === 'guest' || null, onclick: () => store.emit('new-chat') }, icon('plus', 'text-lg'), 'Neue Unterhaltung'));

  el.header = h('header', { class: 'flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 bg-white/80 px-3 backdrop-blur sm:px-4 dark:border-neutral-800 dark:bg-neutral-900/80' });
  el.banner = h('div', { class: 'hidden items-center justify-center gap-2 bg-amber-100 px-3 py-1.5 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200', role: 'status' }, icon('wifi-off', 'text-base'), 'Keine Verbindung: Nachrichten werden gesendet, sobald die Verbindung wieder steht.');
  el.list = h('div', {
    class: 'scroll-thin relative min-h-0 flex-1 overflow-y-auto overscroll-contain bg-slate-50 px-3 py-4 sm:px-6 dark:bg-neutral-950',
    role: 'log', 'aria-live': 'polite', 'aria-label': 'Nachrichtenverlauf', tabindex: '0', id: 'message-list',
    onscroll: throttle(onScroll, 80),
  });
  el.jump = h('button', {
    class: 'btn btn-secondary btn-sm absolute bottom-3 left-1/2 z-10 hidden -translate-x-1/2 gap-1.5 rounded-full shadow-pop', onclick: () => jumpToEnd(),
  }, icon('chevron-down', 'text-base'), h('span', { id: 'jump-label' }, 'Zum Ende'));
  el.typing = h('div', { class: 'h-5 px-4 text-xs text-slate-500 dark:text-neutral-400', 'aria-live': 'polite' });
  el.dropHint = h('div', { class: 'pointer-events-none absolute inset-0 z-20 hidden items-center justify-center bg-accent/10 backdrop-blur-sm' },
    h('div', { class: 'rounded-2xl border-2 border-dashed border-accent bg-white px-8 py-6 text-center font-medium text-accent shadow-pop dark:bg-neutral-900' }, icon('cloud-upload', 'mb-1 block text-4xl'), 'Dateien hier ablegen'));
  buildComposer();
  el.chat = h('div', { class: 'relative hidden h-full min-w-0 flex-1 flex-col', id: 'chat-pane' }, el.header, el.banner,
    h('div', { class: 'relative flex min-h-0 flex-1 flex-col' }, el.list, el.jump), el.typing, el.composerWrap, el.dropHint);
  root.append(el.empty, el.chat);

  // Drag & Drop
  let dragDepth = 0;
  el.chat.addEventListener('dragenter', (e) => { if (hasFiles(e)) { dragDepth++; el.dropHint.classList.replace('hidden', 'flex'); } });
  el.chat.addEventListener('dragleave', () => { dragDepth = Math.max(0, dragDepth - 1); if (!dragDepth) el.dropHint.classList.replace('flex', 'hidden'); });
  el.chat.addEventListener('dragover', (e) => { if (hasFiles(e)) e.preventDefault(); });
  el.chat.addEventListener('drop', (e) => {
    if (!hasFiles(e)) return;
    e.preventDefault();
    dragDepth = 0;
    el.dropHint.classList.replace('flex', 'hidden');
    addFiles([...e.dataTransfer.files]);
  });

  document.addEventListener('visibilitychange', () => { if (!document.hidden) maybeMarkRead(); });
  window.addEventListener('focus', maybeMarkRead);
  store.on('active', renderHeader);
  store.on('conversations', () => { if (store.activeId) renderHeader(); });
  store.on('typing', renderTyping);
  store.on('online', (on) => { el.banner.classList.toggle('hidden', on); el.banner.classList.toggle('flex', !on); if (on) retryFailed(); });
  store.on('open-conversation', async ({ id, around }) => {
    if (around) {
      // Antworten in Threads liegen nicht im Hauptverlauf: stattdessen den Thread öffnen
      try {
        const res = await api.get(`/api/messages/${around}`);
        if (res.message?.thread_root_id) { await openConversation(id, { focus: false }); openThread(id, res.message.thread_root_id); return; }
      } catch { /* normal weiter */ }
    }
    openConversation(id, { around });
  });
  store.on('details-changed', renderHeader);
  store.on('message-update', (m) => applyMessageUpdate(m));
  store.on('message-new', (m) => applyOwnNew(m));
  store.on('draft-remote', (cid) => { if (cid === view.convId && !view.editing) restoreDraft(); });
}

/** Geänderte Nachricht (Umfrage, Termin …) in den Verlauf übernehmen. */
function applyMessageUpdate(m) {
  const cache = store.msgs.get(m.conversation_id);
  if (m.thread_root_id) { threadMessage(m); return; }
  if (cache) { mergeMessage(cache, m); if (view.convId === m.conversation_id) renderList('keep'); }
  threadMessage(m);
}

/** Eigene neue Nachricht (Umfrage/Termin) anhängen und ans Ende springen. */
function applyOwnNew(m) {
  const cache = store.cache(m.conversation_id);
  if (cache.hasAfter) { cache.loaded = false; cache.items = []; cache.hasAfter = false; }
  mergeMessage(cache, m);
  const c = store.conv(m.conversation_id);
  if (c) { c.last_read_message_id = Math.max(c.last_read_message_id, m.id); c.last_message_at = m.created_at; store.emit('conversations'); }
  if (view.convId === m.conversation_id) renderList('bottom');
}

const hasFiles = (e) => e.dataTransfer && [...e.dataTransfer.types].includes('Files');

// ------------------------------------------------------------------- Kopfzeile

function renderHeader() {
  const c = store.active;
  clear(el.header);
  if (!c) return;
  let sub = '';
  if (c.type === 'notes') {
    sub = 'Nur für dich sichtbar · Notizen, Links und Dateien';
  } else if (c.type === 'direct') {
    const o = c.other;
    sub = o?.bot ? 'KI-Assistent · Antworten können Fehler enthalten' : o ? (o.presence !== 'offline' ? presenceLabel(o.presence) : (o.last_seen_at ? `Zuletzt online ${relativeSeen(o.last_seen_at)}` : 'Offline')) : '';
    if (o?.status_message && !o?.bot) sub = `${sub} · ${o.status_message}`;
  } else {
    sub = `${c.member_count} Mitglied${c.member_count === 1 ? '' : 'er'}`;
  }
  el.header.append(
    h('button', { class: 'btn-icon -ml-1 lg:hidden', 'aria-label': 'Zurück zur Liste', onclick: () => { store.mobileView = 'list'; store.emit('mobile-view'); } }, icon('chevron-left', 'text-2xl')),
    h('button', { class: 'flex min-w-0 flex-1 items-center gap-3 rounded-xl p-1 text-left transition hover:bg-slate-100 dark:hover:bg-neutral-800', onclick: toggleDetails, 'aria-label': 'Unterhaltungsdetails anzeigen' },
      convAvatar(c, 'md', true),
      h('span', { class: 'min-w-0' },
        h('span', { class: 'flex items-center gap-1.5 truncate text-sm font-semibold' }, h('span', { class: 'truncate' }, c.name), c.muted ? icon('bell-off', 'text-sm text-slate-400') : null, c.pinned ? icon('pin', 'text-sm text-slate-400') : null),
        h('span', { class: 'block truncate text-xs text-slate-500 dark:text-neutral-400' }, sub))),
    canStartMeeting(c) ? h('button', { class: 'btn-icon', 'aria-label': 'Videomeeting starten', title: 'Videomeeting starten', onclick: () => startMeeting(c.id) }, icon('video')) : '',
    h('button', { class: 'btn-icon', 'aria-label': 'In dieser Unterhaltung suchen', title: 'In dieser Unterhaltung suchen', onclick: () => store.emit('search-in', c.id) }, icon('search')),
    h('button', { class: `btn-icon ${store.detailsOpen ? 'bg-slate-100 text-accent dark:bg-neutral-800' : ''}`, 'aria-label': 'Details', 'aria-pressed': String(store.detailsOpen), title: 'Details ein-/ausblenden', onclick: toggleDetails }, icon('info-circle')),
    h('button', { class: 'btn-icon', 'aria-label': 'Weitere Aktionen', 'aria-haspopup': 'menu', onclick: (e) => convMenu(c, e.currentTarget) }, icon('dots-vertical-rounded')));
}

async function saveToNotes(m) {
  try {
    await api.post(`/api/messages/${m.id}/notes`, {});
    toast('In Notizen gespeichert.');
  } catch (e) { toast(e.message, 'error'); }
}

function toggleDetails() {
  if (store.thread) closeThread();
  store.detailsOpen = !store.detailsOpen;
  store.emit('details-toggle');
  renderHeader();
}

export function convMenu(c, anchor) {
  if (c.type === 'notes') return;
  menu(anchor, [
    { label: c.pinned ? 'Nicht mehr anpinnen' : 'Anpinnen', icon: 'pin', onClick: () => setPref(c, { pinned: !c.pinned }) },
    { label: c.muted ? 'Stummschaltung aufheben' : 'Stummschalten', icon: c.muted ? 'bell' : 'bell-off', onClick: () => setPref(c, { muted: !c.muted }) },
    { label: c.archived ? 'Aus dem Archiv holen' : 'Archivieren', icon: 'archive', onClick: () => setPref(c, { archived: !c.archived }) },
    { label: 'Als gelesen markieren', icon: 'check-double', onClick: () => markRead(c.id, true) },
  ]);
}

export async function setPref(c, prefs) {
  try {
    const res = await api.patch(`/api/conversations/${c.id}/prefs`, prefs);
    if (res.conversation) store.upsertConversation(res.conversation);
    store.emit('conversations');
    if ('archived' in prefs) toast(prefs.archived ? 'Unterhaltung archiviert.' : 'Unterhaltung wiederhergestellt.', 'success', 2500);
    if ('archived' in prefs && prefs.archived && store.activeId === c.id) closeConversation();
  } catch (e) { toast(e.message, 'error'); }
}

export function closeConversation() {
  store.activeId = null;
  view.convId = null;
  el.chat.classList.replace('flex', 'hidden');
  el.empty.classList.replace('hidden', 'flex');
  store.mobileView = 'list';
  store.emit('active');
  store.emit('mobile-view');
  syncUrl();
}

function renderTyping() {
  const t = store.typing;
  clear(el.typing);
  if (t.conversation_id === store.activeId && t.names.length) {
    const txt = t.names.length === 1 ? `${t.names[0]} schreibt …` : `${t.names.slice(0, 2).join(' und ')} schreiben …`;
    el.typing.append(h('span', { class: 'inline-flex items-center gap-1.5' }, h('span', { class: 'flex gap-0.5' }, ...[0, 1, 2].map(() => h('span', { class: 'h-1.5 w-1.5 animate-pulse rounded-full bg-slate-400' }))), txt));
  }
}

// ----------------------------------------------------------- Unterhaltung öffnen

export async function openConversation(id, { around = null, focus = true } = {}) {
  const c = store.conv(id);
  if (!c) {
    toast('Diese Unterhaltung ist nicht (mehr) verfügbar.', 'warn');
    return;
  }
  saveDraft();
  closeMenu();
  const changed = store.activeId !== id;
  store.activeId = id;
  view = { convId: id, replyTo: null, editing: null, loadingOlder: false, loadingNewer: false, pendingNew: 0 };
  store.mobileView = 'chat';
  el.empty.classList.replace('flex', 'hidden');
  el.chat.classList.replace('hidden', 'flex');
  store.typing = { conversation_id: id, names: [] };
  renderTyping();
  if (el.pollTool) { const off = !canCreate() || store.active?.type === 'notes'; for (const t of [el.pollTool, el.eventTool]) { t.hidden = off; t.style.display = off ? 'none' : ''; } }
  store.emit('active');
  store.emit('mobile-view');
  renderHeader();
  restoreDraft();
  syncUrl();
  loadDetails(id);

  const cache = store.cache(id);
  cache.lastReadAtOpen = c.last_read_message_id;
  if (cache.loaded && around === null) {
    renderList('bottom');
    syncNewer(id);
  } else {
    clear(el.list).append(skeletonMessages());
    try {
      const data = await api.get(`/api/conversations/${id}/messages?${around ? `around=${around}` : `limit=${PAGE}`}`);
      if (view.convId !== id) return;
      cache.items = data.messages.concat(cache.items.filter((m) => m._pending));
      cache.hasBefore = data.has_more_before;
      cache.hasAfter = data.has_more_after;
      cache.loaded = true;
    } catch (e) {
      if (view.convId !== id) return;
      clear(el.list).append(errorBox(e.message, () => openConversation(id, { around })));
      return;
    }
    renderList(around ? { target: around } : 'bottom');
  }
  if (focus && window.matchMedia('(min-width: 1024px)').matches) el.input?.focus();
  maybeMarkRead();
}

function errorBox(msg, retry) {
  return h('div', { class: 'flex h-full flex-col items-center justify-center gap-3 text-center' },
    icon('error-circle', 'text-4xl text-red-500'), h('p', { class: 'text-sm' }, msg),
    h('button', { class: 'btn btn-secondary btn-sm', onclick: retry }, 'Erneut versuchen'));
}

function syncUrl() {
  try {
    const u = new URL(location.href);
    if (store.activeId) u.searchParams.set('c', String(store.activeId)); else u.searchParams.delete('c');
    u.searchParams.delete('m');
    history.replaceState(null, '', u.toString());
  } catch { /* ignorieren */ }
}

export async function loadDetails(id) {
  try {
    const res = await api.get(`/api/conversations/${id}`);
    store.members.set(id, res.conversation);
    store.emit('details', id);
  } catch { /* Details sind optional */ }
}

async function syncNewer(id) {
  const cache = store.cache(id);
  const last = [...cache.items].reverse().find((m) => !m._pending);
  if (!last) return;
  try {
    const data = await api.get(`/api/conversations/${id}/messages?after=${last.id}&limit=100`);
    if (!data.messages.length) return;
    for (const m of data.messages) mergeMessage(cache, m);
    cache.hasAfter = data.has_more_after;
    if (view.convId === id) renderList(nearBottom() ? 'bottom' : 'keep');
  } catch { /* nächster Poll gleicht ab */ }
}

// ------------------------------------------------------------------- Liste

const nearBottom = () => el.list.scrollHeight - el.list.scrollTop - el.list.clientHeight < 140;

function mergeMessage(cache, m) {
  // Optimistische Nachricht durch Server-Version ersetzen
  if (m.client_uid) {
    const pi = cache.items.findIndex((x) => x._pending && x.client_uid === m.client_uid);
    if (pi >= 0) cache.items.splice(pi, 1);
  }
  const i = cache.items.findIndex((x) => x.id === m.id);
  if (i >= 0) { cache.items[i] = m; return false; }
  cache.items.push(m);
  cache.items.sort((a, b) => (a._pending ? Infinity : a.id) - (b._pending ? Infinity : b.id));
  return true;
}

/** Wird vom Poll aufgerufen. Gibt neu eingetroffene fremde Nachrichten zurück. */
const seenIds = new Set();
export function applyPolledMessages(list) {
  const incoming = [];
  const touched = new Set();
  for (const m of list) {
    if (m.thread_root_id) {
      // Antworten in Threads gehören nicht in den Hauptverlauf
      threadMessage(m);
      const known = store.msgs.get(m.conversation_id)?.items.find((x) => x.id === m.thread_root_id);
      if (known && !known.thread) known.thread = { count: 1, last_id: m.id, last_at: m.created_at, participants: [m.sender].filter(Boolean) };
      if (m.sender && m.sender.id !== store.me.id && !m.deleted && !threadIsOpenFor(m.thread_root_id) && !seenIds.has(m.id)) { seenIds.add(m.id); incoming.push(m); }
      continue;
    }
    const cache = store.msgs.get(m.conversation_id);
    if (!cache || !cache.loaded) { if (m.sender && m.sender.id !== store.me.id && !m.deleted) incoming.push(m); continue; }
    // Nur anhängen, wenn der Verlauf am Ende ist (sonst Lücke)
    const exists = cache.items.some((x) => x.id === m.id);
    if (!exists && cache.hasAfter) continue;
    const added = mergeMessage(cache, m);
    touched.add(m.conversation_id);
    if (added && m.sender && m.sender.id !== store.me.id && m.kind !== 'system') incoming.push(m);
  }
  if (touched.has(view.convId)) {
    const stick = nearBottom();
    const added = incoming.filter((m) => m.conversation_id === view.convId).length;
    renderList(stick ? 'bottom' : 'keep');
    if (!stick && added) { view.pendingNew += added; updateJump(); }
    maybeMarkRead();
  }
  return incoming;
}

function groupable(a, b) {
  return a && b && a.kind === 'text' && b.kind === 'text' && a.sender && b.sender && a.sender.id === b.sender.id
    && !a.deleted === !b.deleted && Math.abs(new Date(b.created_at) - new Date(a.created_at)) < 5 * 60 * 1000
    && dayKey(a.created_at) === dayKey(b.created_at);
}

/**
 * mode: 'bottom' | 'keep' | { target: messageId }
 */
function renderList(mode = 'keep') {
  const c = store.active;
  const cache = store.msgs.get(view.convId);
  if (!c || !cache) return;
  const prevOffset = el.list.scrollHeight - el.list.scrollTop;
  clear(el.list);
  const items = cache.items;
  if (cache.hasBefore) {
    el.list.append(h('div', { class: 'flex justify-center pb-3' },
      h('button', { class: 'btn btn-secondary btn-sm', id: 'load-older', onclick: loadOlder }, icon('up-arrow-alt', 'text-base'), 'Ältere Nachrichten laden')));
  } else if (items.length) {
    el.list.append(h('div', { class: 'pb-4 text-center text-xs text-slate-400' }, c.type === 'group' ? 'Beginn der Gruppe' : 'Beginn der Unterhaltung'));
  }
  if (!items.length) {
    el.list.append(h('div', { class: 'flex h-full flex-col items-center justify-center gap-2 text-center text-slate-500' },
      icon('message-rounded', 'text-5xl opacity-40'), h('p', { class: 'text-sm' }, 'Noch keine Nachrichten. Schreibe die erste!')));
  }
  let prev = null;
  let separatorDone = false;
  const selfId = store.me.id;
  items.forEach((m, idx) => {
    if (!prev || dayKey(prev.created_at) !== dayKey(m.created_at)) {
      el.list.append(h('div', { class: 'sticky top-0 z-[5] my-3 flex justify-center' }, h('span', { class: 'rounded-full bg-white/90 px-3 py-1 text-[11px] font-medium text-slate-500 shadow-sm backdrop-blur dark:bg-neutral-800/90 dark:text-neutral-400' }, dayLabel(m.created_at))));
    }
    if (!separatorDone && !m._pending && m.id > cache.lastReadAtOpen && m.sender && m.sender.id !== selfId && m.kind !== 'system') {
      separatorDone = true;
      el.list.append(h('div', { class: 'my-3 flex items-center gap-3 text-xs font-semibold text-accent', id: 'unread-separator', role: 'separator' },
        h('span', { class: 'h-px flex-1 bg-accent/40' }), 'Ungelesene Nachrichten', h('span', { class: 'h-px flex-1 bg-accent/40' })));
    }
    const continues = groupable(prev, m);
    const nextSame = groupable(m, items[idx + 1]);
    el.list.append(messageNode(m, { continues, last: !nextSame, group: c.type === 'group' }));
    prev = m;
  });

  if (mode === 'bottom') {
    el.list.scrollTop = el.list.scrollHeight;
  } else if (mode === 'keep') {
    el.list.scrollTop = el.list.scrollHeight - prevOffset;
  } else if (mode && mode.target) {
    const node = el.list.querySelector(`[data-mid="${mode.target}"]`);
    if (node) { node.scrollIntoView({ block: 'center' }); flash(node); } else el.list.scrollTop = el.list.scrollHeight;
  }
  updateJump();
}

function flash(node) {
  node.classList.add('ring-2', 'ring-accent', 'ring-offset-2', 'rounded-2xl');
  setTimeout(() => node.classList.remove('ring-2', 'ring-accent', 'ring-offset-2', 'rounded-2xl'), 1800);
}

function updateJump() {
  const cache = store.msgs.get(view.convId);
  const away = !nearBottom() || (cache && cache.hasAfter);
  el.jump.classList.toggle('hidden', !away);
  el.jump.classList.toggle('flex', !!away);
  const label = el.jump.querySelector('#jump-label');
  if (label) label.textContent = view.pendingNew > 0 ? `${view.pendingNew} neue Nachricht${view.pendingNew > 1 ? 'en' : ''}` : 'Zum Ende';
}

async function jumpToEnd() {
  const cache = store.msgs.get(view.convId);
  view.pendingNew = 0;
  if (cache?.hasAfter) {
    const id = view.convId;
    cache.loaded = false; cache.items = [];
    await openConversation(id);
    return;
  }
  el.list.scrollTo({ top: el.list.scrollHeight, behavior: 'smooth' });
}

function onScroll() {
  const cache = store.msgs.get(view.convId);
  if (!cache) return;
  if (nearBottom()) view.pendingNew = 0;
  updateJump();
  if (el.list.scrollTop < 120 && cache.hasBefore && !view.loadingOlder) loadOlder();
  if (cache.hasAfter && nearBottom() && !view.loadingNewer) loadNewer();
  maybeMarkRead();
}

async function loadOlder() {
  const cache = store.msgs.get(view.convId);
  const id = view.convId;
  if (!cache || view.loadingOlder || !cache.hasBefore) return;
  const first = cache.items.find((m) => !m._pending);
  if (!first) return;
  view.loadingOlder = true;
  try {
    const data = await api.get(`/api/conversations/${id}/messages?before=${first.id}&limit=${PAGE}`);
    for (const m of data.messages) mergeMessage(cache, m);
    cache.hasBefore = data.has_more_before;
    if (view.convId === id) renderList('keep');
  } catch (e) { toast(e.message, 'error'); } finally { view.loadingOlder = false; }
}

async function loadNewer() {
  const cache = store.msgs.get(view.convId);
  const id = view.convId;
  if (!cache || view.loadingNewer) return;
  const last = [...cache.items].reverse().find((m) => !m._pending);
  if (!last) return;
  view.loadingNewer = true;
  try {
    const data = await api.get(`/api/conversations/${id}/messages?after=${last.id}&limit=${PAGE}`);
    for (const m of data.messages) mergeMessage(cache, m);
    cache.hasAfter = data.has_more_after;
    if (view.convId === id) renderList('keep');
  } catch (e) { toast(e.message, 'error'); } finally { view.loadingNewer = false; }
}

// --------------------------------------------------------------- Nachricht

function statusIcon(m) {
  if (m._pending) {
    return m._state === 'failed'
      ? h('button', { class: 'inline-flex items-center gap-1 text-red-200 underline', onclick: () => retryMessage(m), title: 'Senden fehlgeschlagen. Erneut versuchen.' }, icon('error-circle', 'text-sm'), 'Erneut senden')
      : h('span', { title: 'Wird gesendet …', 'aria-label': 'Wird gesendet' }, icon('time-five', 'text-sm'));
  }
  if (!m.status) return null;
  const readable = store.me.settings.show_read_receipts;
  const label = { sent: 'Gesendet', delivered: 'Zugestellt', read: 'Gelesen' }[m.status];
  const detail = m.recipient_count > 1 && m.status !== 'sent' ? ` (${m.read_count} von ${m.recipient_count} gelesen)` : '';
  if (m.status === 'sent') return h('span', { title: label, 'aria-label': label }, icon('check', 'text-sm'));
  return h('span', { title: label + detail, 'aria-label': label + detail }, icon('check-double', `text-sm ${m.status === 'read' && readable !== false ? 'text-emerald-300' : ''}`));
}

function messageNode(m, { continues, last, group }) {
  if (m.kind === 'system') {
    return h('div', { class: 'my-2 flex justify-center', 'data-mid': m.id }, h('span', { class: 'rounded-full bg-slate-200/70 px-3 py-1 text-center text-xs text-slate-600 dark:bg-neutral-800 dark:text-neutral-400' }, m.body));
  }
  const own = m.sender && m.sender.id === store.me.id;
  const showHead = !continues;
  const isCard = (m.kind === 'poll' || m.kind === 'event' || m.kind === 'meeting') && !m.deleted;
  const bubbleCls = own && !isCard
    ? 'bg-accent text-white'
    : 'border border-slate-200 bg-white text-slate-800 dark:border-neutral-700 dark:bg-neutral-800 dark:text-neutral-100';
  const corner = own ? (last ? 'rounded-br-md' : '') : (last ? 'rounded-bl-md' : '');
  const body = h('div', { class: `relative min-w-0 max-w-full rounded-2xl px-3.5 py-2 text-sm shadow-sm ${bubbleCls} ${corner} ${isCard ? 'w-[22rem]' : ''}` });

  if (m.deleted) {
    body.append(h('p', { class: 'flex items-center gap-1.5 italic opacity-70' }, icon('block', 'text-base'), 'Diese Nachricht wurde gelöscht.'));
  } else {
    if (m.forwarded) body.append(h('p', { class: 'mb-1 flex items-center gap-1 text-[11px] font-medium opacity-70' }, icon('share', 'text-sm'), 'Weitergeleitet'));
    if (m.reply_to) {
      body.append(h('button', {
        class: `mb-1.5 block w-full max-w-full rounded-lg border-l-4 px-2.5 py-1 text-left text-xs ${own ? 'border-white/60 bg-white/15' : 'border-accent bg-slate-100 dark:bg-neutral-700/60'}`,
        onclick: () => jumpToMessage(m.reply_to.id), 'aria-label': 'Zur zitierten Nachricht springen',
      }, h('span', { class: 'block truncate font-semibold' }, m.reply_to.sender_name), h('span', { class: 'block truncate opacity-80' }, m.reply_to.deleted ? 'Nachricht gelöscht' : m.reply_to.text)));
    }
    if (group && !own && showHead) {
      body.append(h('p', { class: 'mb-0.5 text-xs font-semibold text-accent' }, m.sender?.display_name || 'Unbekannt'));
    }
    if (m.sender?.bot) {
      body.append(h('p', { class: 'mb-1 inline-flex items-center gap-1 rounded-full bg-violet-100 px-2 py-0.5 text-[11px] font-semibold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300', title: 'Diese Nachricht wurde von einer KI erzeugt und kann Fehler enthalten.' }, icon('bot', 'text-sm'), 'KI-Antwort'));
    }
    if (m.kind === 'poll') body.append(pollNode(m));
    else if (m.kind === 'event') body.append(eventNode(m));
    else if (m.kind === 'meeting') body.append(meetingNode(m));
    else if (m.body) {
      body.append(h('div', { class: 'msg-text' }, renderRich(m.body, { mentions: m.mentions, selfUsername: store.me.username, own })));
      const link = firstLink(m.body);
      if (link) body.append(linkCard(link, own));
    }
    for (const a of m.attachments) body.append(attachmentNode(a, own));
  }
  const meta = h('div', { class: `mt-1 flex items-center justify-end gap-1.5 text-[11px] ${own && !isCard ? 'text-white/75' : 'text-slate-400'}` },
    m.edited_at && !m.deleted ? h('span', { title: `Bearbeitet ${fullDate(m.edited_at)}` }, 'bearbeitet') : null,
    h('time', { datetime: m.created_at || '', title: fullDate(m.created_at) }, timeOf(m.created_at)),
    own && !m.deleted ? statusIcon(m) : null);
  body.append(meta);

  const reactions = !m.deleted && m.reactions.length
    ? h('div', { class: `mt-1 flex flex-wrap gap-1 ${own ? 'justify-end' : ''}` }, m.reactions.map((r) => h('button', {
      class: `inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition ${r.mine ? 'border-accent bg-accent/10 text-accent' : 'border-slate-200 bg-white hover:bg-slate-50 dark:border-neutral-700 dark:bg-neutral-800 dark:hover:bg-neutral-700'}`,
      title: r.names.join(', '), 'aria-label': `${r.emoji} ${r.count}: ${r.names.join(', ')}`, 'aria-pressed': String(r.mine),
      onclick: () => react(m, r.emoji),
    }, h('span', r.emoji), h('span', { class: 'font-medium' }, String(r.count)))))
    : null;

  const toolbar = (!m.deleted && !m._pending) ? h('div', { class: `absolute -top-4 ${own ? 'right-2' : 'left-2'} z-[6] hidden items-center rounded-lg border border-slate-200 bg-white p-0.5 shadow-soft group-focus-within:flex group-hover:flex dark:border-neutral-700 dark:bg-neutral-800` },
    h('button', { class: 'btn-icon h-7 w-7 text-lg', 'aria-label': 'Reagieren', title: 'Reagieren', onclick: (e) => reactionPicker(m, e.currentTarget) }, icon('smile')),
    h('button', { class: 'btn-icon h-7 w-7 text-lg', 'aria-label': 'Antworten', title: 'Antworten', onclick: () => startReply(m) }, icon('reply')),
    h('button', { class: 'btn-icon h-7 w-7 text-lg', 'aria-label': 'Im Thread antworten', title: 'Im Thread antworten', onclick: () => openThread(m.conversation_id, m.id) }, icon('message-square-detail')),
    h('button', { class: 'btn-icon h-7 w-7 text-lg', 'aria-label': 'Weitere Aktionen', title: 'Mehr', 'aria-haspopup': 'menu', onclick: (e) => messageMenu(m, e.currentTarget) }, icon('dots-horizontal-rounded'))) : null;

  const col = h('div', { class: `group relative flex min-w-0 max-w-[88%] flex-col sm:max-w-[72%] ${own ? 'items-end' : 'items-start'}` }, toolbar, body, reactions, m.deleted ? null : threadSummary(m, own));
  const avatarCol = own ? null : h('div', { class: 'mr-2 w-8 shrink-0 self-end' }, showHead || !continues ? (last ? userAvatar(m.sender || { display_name: '?' }, 'sm') : null) : null);

  const row = h('div', {
    class: `flex ${own ? 'justify-end' : 'justify-start'} ${continues ? 'mt-0.5' : 'mt-3'} transition`,
    'data-mid': m.id, role: 'article', tabindex: '0',
    'aria-label': `${own ? 'Du' : (m.sender?.display_name || 'Unbekannt')}, ${fullDate(m.created_at)}: ${m.deleted ? 'gelöscht' : (m.kind === 'poll' ? `Umfrage: ${m.poll?.question || ''}` : m.kind === 'event' ? `Termin: ${m.event?.title || ''}` : m.kind === 'meeting' ? 'Videomeeting' : m.body.slice(0, 200))}`,
    onkeydown: (e) => { if (e.key === 'Enter' && e.target === row && !m.deleted && !m._pending) { e.preventDefault(); messageMenu(m, row); } },
  }, avatarCol, col);

  // Touch-Geräte: Tippen öffnet das Aktionsmenü
  body.addEventListener('click', (e) => {
    if (m.deleted || m._pending || e.target.closest('a,button,img')) return;
    if (window.matchMedia('(hover: none)').matches) messageMenu(m, body);
  });
  body.addEventListener('contextmenu', (e) => {
    if (m.deleted || m._pending || e.target.closest('a')) return;
    e.preventDefault();
    messageMenu(m, { x: e.clientX, y: e.clientY });
  });
  return row;
}

function attachmentNode(a, own) {
  if (a.is_image) {
    const img = h('img', {
      src: url(`files/${a.id}?thumb=1`), alt: a.name, loading: 'lazy',
      class: 'max-h-72 w-auto max-w-full rounded-xl object-cover',
      width: a.width && a.height ? Math.min(a.width, 360) : null, height: a.width && a.height ? Math.round(Math.min(a.width, 360) * a.height / a.width) : null,
    });
    return h('button', { class: 'mt-1.5 block overflow-hidden rounded-xl bg-black/5', onclick: () => lightbox(a), 'aria-label': `Bild ${a.name} vergrössern` }, img);
  }
  const ext = (a.name.split('.').pop() || '').toLowerCase();
  const ic = { pdf: 'file-pdf', doc: 'file-doc', docx: 'file-doc', xls: 'spreadsheet', xlsx: 'spreadsheet', csv: 'spreadsheet', zip: 'archive', rar: 'archive', '7z': 'archive', txt: 'file', mp3: 'music', wav: 'music', mp4: 'video', mov: 'video' }[ext] || 'file-blank';
  return h('a', {
    href: url(`files/${a.id}?download=1`), download: a.name,
    class: `mt-1.5 flex items-center gap-3 rounded-xl border px-3 py-2 transition ${own ? 'border-white/25 bg-white/10 hover:bg-white/20' : 'border-slate-200 bg-slate-50 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800/60 dark:hover:bg-neutral-700'}`,
    'aria-label': `${a.name}, ${humanSize(a.size)}, herunterladen`,
  }, icon(ic, 'text-3xl'), h('span', { class: 'min-w-0 flex-1' }, h('span', { class: 'block truncate text-sm font-medium' }, a.name), h('span', { class: 'block text-xs opacity-70' }, humanSize(a.size))), icon('download', 'text-xl opacity-80'));
}

// ------------------------------------------------------------------ Aktionen

function findMsg(id) { return store.msgs.get(view.convId)?.items.find((m) => m.id === id); }

function reactionPicker(m, anchor) {
  const row = h('div', { class: 'flex gap-0.5 p-1.5' }, store.reactions.map((e) => h('button', {
    class: 'flex h-9 w-9 items-center justify-center rounded-lg text-xl transition hover:scale-110 hover:bg-slate-100 dark:hover:bg-neutral-700', 'aria-label': `Mit ${e} reagieren`,
    onclick: () => { closeMenu(); react(m, e); },
  }, e)));
  import('./ui.js').then(({ openPopover }) => openPopover(anchor, row));
}

async function react(m, emoji) {
  try {
    const res = await api.post(`/api/messages/${m.id}/reactions`, { emoji });
    const cache = store.msgs.get(m.conversation_id);
    if (cache) { mergeMessage(cache, res.message); if (view.convId === m.conversation_id) renderList('keep'); }
  } catch (e) { toast(e.message, 'error'); }
}

export function startReply(m) {
  view.editing = null;
  view.replyTo = m;
  renderComposerBars();
  el.input.focus();
}

function canEdit(m) {
  if (m.sender?.id !== store.me.id || m.deleted || m.kind !== 'text') return false;
  const mins = store.limits.edit_minutes;
  return !(mins > 0 && Date.now() - new Date(m.created_at).getTime() > mins * 60000);
}

function messageMenu(m, anchor) {
  const c = store.active;
  const own = m.sender?.id === store.me.id;
  const canDelete = own || (c.type === 'group' && ['admin', 'moderator'].includes(c.role));
  menu(anchor, [
    { label: 'Antworten', icon: 'reply', onClick: () => startReply(m) },
    { label: m.thread?.count ? 'Thread öffnen' : 'Im Thread antworten', icon: 'message-square-detail', onClick: () => openThread(m.conversation_id, m.id) },
    { label: 'Reagieren', icon: 'smile', onClick: () => reactionPicker(m, anchor instanceof Element ? anchor : el.list) },
    m.body && m.kind === 'text' ? { label: 'Text kopieren', icon: 'copy', onClick: async () => toast((await copyText(m.body)) ? 'In die Zwischenablage kopiert.' : 'Kopieren nicht möglich.', 'success', 2000) } : null,
    m.kind === 'text' ? { label: 'Weiterleiten', icon: 'share', onClick: () => forwardDialog(m) } : null,
    m.kind === 'text' && store.active?.type !== 'notes' && store.me?.role !== 'guest' ? { label: 'In Notizen speichern', icon: 'bookmark', onClick: () => saveToNotes(m) } : null,
    canEdit(m) && m.body ? { divider: true } : null,
    canEdit(m) && m.body ? { label: 'Bearbeiten', icon: 'edit', onClick: () => startEdit(m) } : null,
    !own ? { label: 'Melden', icon: 'flag', onClick: () => reportDialog(m) } : null,
    canDelete ? { divider: true } : null,
    canDelete ? { label: own ? 'Löschen' : 'Als Moderator löschen', icon: 'trash', danger: true, onClick: () => deleteMessage(m) } : null,
  ], { align: own ? 'right' : 'left' });
}

async function deleteMessage(m) {
  const ok = await confirmDialog({
    title: 'Nachricht löschen?',
    text: 'Die Nachricht und ihre Anhänge werden für alle Mitglieder endgültig gelöscht. Das lässt sich nicht rückgängig machen.',
    confirmLabel: 'Löschen', danger: true,
  });
  if (!ok) return;
  try {
    await api.del(`/api/messages/${m.id}`);
    const cache = store.msgs.get(m.conversation_id);
    const it = cache?.items.find((x) => x.id === m.id);
    if (it) { it.deleted = true; it.body = ''; it.attachments = []; it.reactions = []; it.mentions = []; it.reply_to = it.reply_to; if (view.convId === m.conversation_id) renderList('keep'); }
    toast('Nachricht gelöscht.', 'success', 2000);
  } catch (e) { toast(e.message, 'error'); }
}

function startEdit(m) {
  view.replyTo = null;
  view.editing = m;
  el.input.value = m.body;
  autosize();
  renderComposerBars();
  el.input.focus();
}

export function jumpToMessage(id) {
  const node = el.list.querySelector(`[data-mid="${id}"]`);
  if (node) { node.scrollIntoView({ block: 'center', behavior: 'smooth' }); flash(node); return; }
  const cid = view.convId;
  const cache = store.cache(cid);
  cache.loaded = false;
  cache.items = [];
  openConversation(cid, { around: id, focus: false });
}

// ------------------------------------------------------------ Lesestatus

function maybeMarkRead() {
  clearTimeout(readTimer);
  readTimer = setTimeout(() => {
    const c = store.active;
    if (!c || document.hidden || !document.hasFocus?.() && false) return;
    const cache = store.msgs.get(c.id);
    if (!cache || !cache.loaded || cache.hasAfter || !nearBottom()) return;
    const last = [...cache.items].reverse().find((m) => !m._pending);
    if (!last) return;
    if (last.id > c.last_read_message_id || c.unread > 0) markRead(c.id, false, last.id);
  }, 350);
}

export async function markRead(id, force = false, upTo = null) {
  const c = store.conv(id);
  if (!c) return;
  try {
    const res = await api.post(`/api/conversations/${id}/read`, upTo ? { message_id: upTo } : {});
    c.last_read_message_id = res.last_read_message_id;
    c.unread = 0;
    c.unread_mentions = 0;
    store.emit('conversations');
    if (force) toast('Als gelesen markiert.', 'success', 1800);
  } catch { /* nächster Versuch beim nächsten Scroll/Poll */ }
}

// ----------------------------------------------------------------- Composer

function buildComposer() {
  el.input = h('textarea', {
    class: 'scroll-thin max-h-48 min-h-[44px] w-full resize-none bg-transparent px-3 py-3 text-sm outline-none placeholder:text-slate-400',
    rows: '1', placeholder: 'Nachricht schreiben …', 'aria-label': 'Nachricht', maxlength: '8000', id: 'composer-input',
    oninput: onInput, onkeydown: onKeydown, onpaste: onPaste,
  });
  el.bars = h('div', {});
  el.attachList = h('div', { class: 'flex flex-wrap gap-2 px-3 pt-2' });
  el.fileInput = h('input', { type: 'file', multiple: true, class: 'hidden', onchange: (e) => { addFiles([...e.target.files]); e.target.value = ''; }, tabindex: '-1', 'aria-hidden': 'true' });
  el.mention = h('div', { class: 'absolute bottom-full left-3 z-30 mb-2 hidden max-h-56 w-64 overflow-y-auto rounded-xl border border-slate-200 bg-white p-1 shadow-pop dark:border-neutral-700 dark:bg-neutral-800', role: 'listbox', 'aria-label': 'Erwähnung auswählen' });
  el.sendBtn = h('button', { class: 'btn btn-primary h-10 w-10 shrink-0 rounded-xl p-0', 'aria-label': 'Senden', title: 'Senden (Enter)', onclick: send }, icon('send', 'text-xl'));
  const tool = (ic, label, fn) => h('button', { class: 'btn-icon h-8 w-8 text-lg', type: 'button', 'aria-label': label, title: label, onclick: fn }, icon(ic));
  el.composerWrap = h('div', { class: 'relative shrink-0 border-t border-slate-200 bg-white px-3 pb-3 pt-2 sm:px-4 dark:border-neutral-800 dark:bg-neutral-900' },
    el.mention, el.bars,
    h('div', { class: 'rounded-2xl border border-slate-300 bg-white transition focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/25 dark:border-neutral-700 dark:bg-neutral-800' },
      el.attachList,
      el.input,
      h('div', { class: 'flex items-center gap-0.5 px-2 pb-2' },
        tool('bold', 'Fett (Strg+B)', () => wrapSel('**')),
        tool('italic', 'Kursiv (Strg+I)', () => wrapSel('_')),
        tool('code-alt', 'Code', () => wrapSel('`')),
        tool('at', 'Person erwähnen', () => { insertAtCursor('@'); onInput(); }),
        tool('smile', 'Emoji einfügen', (e) => emojiPicker(e.currentTarget, (ch) => insertAtCursor(ch))),
        tool('paperclip', 'Datei anhängen', () => el.fileInput.click()),
        el.pollTool = tool('poll', 'Umfrage erstellen', () => view.convId && pollDialog(view.convId)),
        el.eventTool = tool('calendar-event', 'Termin erstellen', () => view.convId && eventDialog(view.convId)),
        h('span', { class: 'flex-1' }),
        el.sendBtn)),
    el.fileInput);
}

function autosize() {
  el.input.style.height = 'auto';
  el.input.style.height = `${Math.min(el.input.scrollHeight, 192)}px`;
}

const sendTyping = throttle(() => {
  if (view.convId) api.post(`/api/conversations/${view.convId}/typing`).catch(() => {});
}, 3000);

function onInput() {
  autosize();
  if (el.input.value.trim() && !view.editing) sendTyping();
  updateMention();
  saveDraft();
}

function insertAtCursor(text) {
  const t = el.input;
  const s = t.selectionStart ?? t.value.length;
  const e2 = t.selectionEnd ?? s;
  t.value = t.value.slice(0, s) + text + t.value.slice(e2);
  t.selectionStart = t.selectionEnd = s + text.length;
  t.focus();
  autosize();
  saveDraft();
}

function wrapSel(mark) {
  const t = el.input;
  const s = t.selectionStart;
  const e2 = t.selectionEnd;
  const sel = t.value.slice(s, e2);
  t.value = t.value.slice(0, s) + mark + sel + mark + t.value.slice(e2);
  t.selectionStart = s + mark.length;
  t.selectionEnd = e2 + mark.length;
  t.focus();
  autosize();
  saveDraft();
}

function onKeydown(e) {
  if (!el.mention.classList.contains('hidden')) {
    const opts = [...el.mention.querySelectorAll('button')];
    const cur = opts.findIndex((b) => b.dataset.active === '1');
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault();
      const n = (cur + (e.key === 'ArrowDown' ? 1 : -1) + opts.length) % opts.length;
      opts.forEach((b, i) => { b.dataset.active = i === n ? '1' : '0'; b.classList.toggle('bg-slate-100', i === n); b.classList.toggle('dark:bg-neutral-700', i === n); });
      opts[n]?.scrollIntoView({ block: 'nearest' });
      return;
    }
    if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); (opts[Math.max(0, cur)])?.click(); return; }
    if (e.key === 'Escape') { e.preventDefault(); hideMention(); return; }
  }
  const mod = e.ctrlKey || e.metaKey;
  if (mod && e.key.toLowerCase() === 'b') { e.preventDefault(); wrapSel('**'); return; }
  if (mod && e.key.toLowerCase() === 'i') { e.preventDefault(); wrapSel('_'); return; }
  if (e.key === 'Escape') {
    if (view.editing || view.replyTo) { e.preventDefault(); cancelBars(); }
    return;
  }
  if (e.key === 'ArrowUp' && !el.input.value) {
    const cache = store.msgs.get(view.convId);
    const mine = [...(cache?.items || [])].reverse().find((m) => m.sender?.id === store.me.id && canEdit(m) && m.body && !m._pending);
    if (mine) { e.preventDefault(); startEdit(mine); }
    return;
  }
  if (e.key === 'Enter' && !e.isComposing) {
    const enterSends = store.me.settings.enter_sends;
    if ((enterSends && !e.shiftKey) || (!enterSends && mod)) { e.preventDefault(); send(); }
  }
}

function onPaste(e) {
  const files = [...(e.clipboardData?.files || [])];
  if (files.length) { e.preventDefault(); addFiles(files); }
}

// Erwähnungen
function updateMention() {
  const c = store.active;
  const before = el.input.value.slice(0, el.input.selectionStart ?? 0);
  const m = before.match(/(?:^|\s)@([A-Za-z0-9._-]{0,30})$/);
  if (!m || !c || c.type !== 'group') { hideMention(); return; }
  const q = m[1].toLowerCase();
  const members = (store.members.get(c.id)?.members || []).filter((u) => u.id !== store.me.id && (u.username.toLowerCase().startsWith(q) || u.display_name.toLowerCase().includes(q))).slice(0, 8);
  if (!members.length) { hideMention(); return; }
  clear(el.mention).classList.remove('hidden');
  members.forEach((u, i) => el.mention.append(h('button', {
    class: `flex w-full items-center gap-2.5 rounded-lg px-2.5 py-1.5 text-left text-sm hover:bg-slate-100 dark:hover:bg-neutral-700 ${i === 0 ? 'bg-slate-100 dark:bg-neutral-700' : ''}`, role: 'option', dataset: { active: i === 0 ? '1' : '0' },
    onmousedown: (ev) => ev.preventDefault(),
    onclick: () => {
      const pos = el.input.selectionStart;
      const head = el.input.value.slice(0, pos).replace(/@[A-Za-z0-9._-]{0,30}$/, `@${u.username} `);
      el.input.value = head + el.input.value.slice(pos);
      el.input.selectionStart = el.input.selectionEnd = head.length;
      hideMention();
      el.input.focus();
      autosize();
    },
  }, userAvatar(u, 'xs'), h('span', { class: 'min-w-0' }, h('span', { class: 'block truncate font-medium' }, u.display_name), h('span', { class: 'block truncate text-xs text-slate-500' }, `@${u.username}`)))));
}
function hideMention() { el.mention.classList.add('hidden'); clear(el.mention); }

// Entwürfe (nur im Speicher dieser Sitzung)
function saveDraft() {
  if (!view.convId) return;
  const d = store.drafts.get(view.convId) || {};
  d.text = view.editing ? d.text : el.input.value;
  d.replyTo = view.replyTo;
  store.drafts.set(view.convId, d);
  if (!view.editing) queueDraft(view.convId, 0, el.input.value, view.replyTo?.id || null);
}
function restoreDraft() {
  const d = store.drafts.get(view.convId) || {};
  el.input.value = d.text || '';
  view.replyTo = d.replyTo || null;
  autosize();
  renderComposerBars();
  renderAttachments();
  hideMention();
}

function attachments() {
  const d = store.drafts.get(view.convId) || {};
  d.attachments ||= [];
  store.drafts.set(view.convId, d);
  return d.attachments;
}

function renderComposerBars() {
  clear(el.bars);
  const bar = (label, text, ic, onClose) => h('div', { class: 'mb-2 flex items-center gap-2 rounded-xl border-l-4 border-accent bg-slate-100 px-3 py-1.5 text-xs dark:bg-neutral-800' },
    icon(ic, 'text-lg text-accent'),
    h('span', { class: 'min-w-0 flex-1' }, h('span', { class: 'block font-semibold' }, label), h('span', { class: 'block truncate opacity-80' }, text)),
    h('button', { class: 'btn-icon h-7 w-7 text-base', 'aria-label': 'Abbrechen', onclick: onClose }, icon('x')));
  if (view.replyTo) el.bars.append(bar(`Antwort an ${view.replyTo.sender?.display_name || 'Unbekannt'}`, view.replyTo.body || 'Anhang', 'reply', cancelBars));
  if (view.editing) el.bars.append(bar('Nachricht bearbeiten', view.editing.body, 'edit', cancelBars));
  el.sendBtn.replaceChildren(icon(view.editing ? 'check' : 'send', 'text-xl'));
}

function cancelBars() {
  if (view.editing) { el.input.value = (store.drafts.get(view.convId) || {}).text || ''; }
  view.editing = null;
  view.replyTo = null;
  autosize();
  renderComposerBars();
  saveDraft();
}

// Anhänge
function addFiles(files) {
  if (!view.convId || !files.length) return;
  const convId = view.convId;
  const list = attachments();
  for (const file of files) {
    if (list.length >= 10) { toast('Maximal 10 Anhänge pro Nachricht.', 'warn'); break; }
    if (store.limits.max_upload_bytes && file.size > store.limits.max_upload_bytes) {
      toast(`„${file.name}“ ist grösser als erlaubt (${humanSize(store.limits.max_upload_bytes)}).`, 'error');
      continue;
    }
    if (file.size === 0) { toast(`„${file.name}“ ist leer.`, 'error'); continue; }
    const item = { key: uuid(), name: file.name || 'Datei', size: file.size, progress: 0, status: 'uploading', attachment: null, xhr: null, error: '' };
    list.push(item);
    api.upload(`/api/conversations/${convId}/uploads`, file, (p, xhr) => {
      if (xhr) item.xhr = xhr;
      item.progress = p;
      if (view.convId === convId) renderAttachments();
    }).then((res) => { item.status = 'done'; item.attachment = res.attachment; item.progress = 1; })
      .catch((e) => { item.status = 'error'; item.error = e.message; if (e.message !== 'Upload abgebrochen.') toast(`Upload fehlgeschlagen: ${e.message}`, 'error'); })
      .finally(() => { if (view.convId === convId) renderAttachments(); });
  }
  renderAttachments();
}

function renderAttachments() {
  clear(el.attachList);
  const list = (store.drafts.get(view.convId) || {}).attachments || [];
  el.attachList.classList.toggle('hidden', !list.length);
  for (const it of list) {
    const bar = it.status === 'uploading' ? h('span', { class: 'mt-1 block h-1 overflow-hidden rounded-full bg-slate-200 dark:bg-neutral-700' }, h('span', { class: 'block h-full rounded-full bg-accent transition-all', style: { width: `${Math.round(it.progress * 100)}%` } })) : null;
    el.attachList.append(h('div', { class: `flex w-52 items-center gap-2 rounded-xl border px-2.5 py-1.5 text-xs ${it.status === 'error' ? 'border-red-300 bg-red-50 text-red-700 dark:border-red-900 dark:bg-red-950/40 dark:text-red-300' : 'border-slate-200 bg-slate-50 dark:border-neutral-700 dark:bg-neutral-900'}` },
      icon(it.attachment?.is_image ? 'image' : 'file', 'text-xl'),
      h('span', { class: 'min-w-0 flex-1' }, h('span', { class: 'block truncate font-medium' }, it.name), h('span', { class: 'block opacity-70' }, it.status === 'error' ? it.error : (it.status === 'uploading' ? `${Math.round(it.progress * 100)} %` : humanSize(it.size))), bar),
      h('button', { class: 'btn-icon h-6 w-6 text-base', 'aria-label': `${it.name} entfernen`, onclick: () => { it.xhr?._abort?.(); const l = attachments(); l.splice(l.indexOf(it), 1); renderAttachments(); } }, icon('x'))));
  }
}

// -------------------------------------------------------------------- Senden

async function send() {
  if (!view.convId) return;
  const convId = view.convId;
  const text = el.input.value.trim();
  if (view.editing) return saveEdit(text);
  const list = attachments();
  if (list.some((a) => a.status === 'uploading')) { toast('Bitte warte, bis die Uploads abgeschlossen sind.', 'warn'); return; }
  const ready = list.filter((a) => a.status === 'done');
  if (!text && !ready.length) return;
  if (text.length > store.limits.max_body) { toast(`Die Nachricht ist zu lang (maximal ${store.limits.max_body} Zeichen).`, 'error'); return; }

  const pending = {
    id: --tempId, _pending: true, _state: 'sending', conversation_id: convId, kind: 'text', client_uid: uuid(),
    sender: { id: store.me.id, display_name: store.me.display_name, username: store.me.username, has_avatar: store.me.has_avatar, avatar_v: store.me.avatar_v },
    body: text, deleted: false, edited_at: null, created_at: new Date().toISOString(), forwarded: false,
    reply_to: view.replyTo ? { id: view.replyTo.id, sender_name: view.replyTo.sender?.display_name || 'Unbekannt', text: view.replyTo.body.slice(0, 140), deleted: false } : null,
    reactions: [], attachments: ready.map((a) => a.attachment), mentions: [], status: null, read_count: 0, recipient_count: 0,
    _payload: { body: text, client_uid: null, reply_to_id: view.replyTo?.id || null, attachment_ids: ready.map((a) => a.attachment.id) },
  };
  pending._payload.client_uid = pending.client_uid;
  const cache = store.cache(convId);
  if (cache.hasAfter) { cache.loaded = false; cache.items = []; cache.hasAfter = false; }
  cache.items.push(pending);

  // Eingabe zurücksetzen
  el.input.value = '';
  view.replyTo = null;
  store.drafts.set(convId, {});
  queueDraft(convId, 0, '', null, true);
  autosize();
  renderComposerBars();
  renderAttachments();
  hideMention();
  renderList('bottom');
  transmit(pending);
}

async function transmit(p) {
  p._state = 'sending';
  try {
    const res = await api.post(`/api/conversations/${p.conversation_id}/messages`, p._payload);
    const cache = store.msgs.get(p.conversation_id);
    if (cache) mergeMessage(cache, res.message);
    const c = store.conv(p.conversation_id);
    if (c) { c.last_read_message_id = Math.max(c.last_read_message_id, res.message.id); c.last_message_at = res.message.created_at; c.last_message = { text: res.message.body || 'Anhang', sender: store.me.display_name, sender_id: store.me.id, kind: 'text', deleted: false, at: res.message.created_at }; store.emit('conversations'); }
  } catch (e) {
    if (e.status >= 400 && e.status < 500 && e.status !== 0 && e.status !== 429 && e.status !== 408) {
      // Dauerhafter Fehler (z. B. nicht mehr Mitglied): nicht erneut versuchen
      p._state = 'failed';
      p._fatal = true;
      toast(e.message, 'error');
    } else {
      p._state = 'failed';
      if (e.status === 429) toast(e.message, 'warn');
    }
  }
  if (view.convId === p.conversation_id) renderList(nearBottom() ? 'bottom' : 'keep');
}

function retryMessage(m) {
  if (m._fatal) {
    const cache = store.msgs.get(m.conversation_id);
    cache.items.splice(cache.items.indexOf(m), 1);
    renderList('keep');
    return;
  }
  transmit(m);
  if (view.convId === m.conversation_id) renderList('keep');
}

function retryFailed() {
  for (const cache of store.msgs.values()) {
    for (const m of cache.items) if (m._pending && m._state === 'failed' && !m._fatal) transmit(m);
  }
}

async function saveEdit(text) {
  const m = view.editing;
  if (!m) return;
  if (!text && !m.attachments.length) { toast('Die Nachricht darf nicht leer sein. Lösche sie stattdessen.', 'warn'); return; }
  if (text === m.body) { cancelBars(); return; }
  try {
    const res = await api.patch(`/api/messages/${m.id}`, { body: text });
    const cache = store.msgs.get(m.conversation_id);
    if (cache) mergeMessage(cache, res.message);
    view.editing = null;
    el.input.value = (store.drafts.get(view.convId) || {}).text || '';
    autosize();
    renderComposerBars();
    renderList('keep');
  } catch (e) { toast(e.message, 'error'); }
}

export function focusComposer() { el.input?.focus(); }
export function activeView() { return view; }
