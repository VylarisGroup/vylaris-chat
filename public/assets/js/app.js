// Einstiegspunkt: Start-Daten laden, Oberfläche zusammensetzen, Tastenkürzel, Deep-Links.

import { api } from './api.js';
import { store } from './store.js';
import { h, icon, state, clear } from './util.js';
import { skeletonList, toast, closeMenu } from './ui.js';
import { initChat, openConversation } from './chat.js';
import { initSidebar, focusSearch } from './sidebar.js';
import { initDetails } from './details.js';
import { initThreads, loadDrafts } from './collab.js';
import { startPolling, refreshTitle } from './poll.js';
import { initViewport } from './viewport.js';
import { acceptCall } from './meets.js';
import { registerServiceWorker, syncPush, clearBadge } from './push.js';
import { newChatDialog, shortcutsDialog } from './dialogs.js';

state.base = document.querySelector('meta[name="app-base"]')?.content || '';
const app = document.getElementById('app');

function fatal(message) {
  clear(app);
  app.append(h('div', { class: 'flex h-full flex-col items-center justify-center gap-4 p-8 text-center' },
    icon('error-circle', 'text-5xl text-red-500'),
    h('h1', { class: 'text-lg font-semibold' }, 'Der Messenger konnte nicht geladen werden'),
    h('p', { class: 'max-w-sm text-sm text-slate-600 dark:text-neutral-400' }, message),
    h('button', { class: 'btn btn-primary', onclick: () => location.reload() }, icon('refresh', 'text-lg'), 'Erneut versuchen')));
}

function buildLayout() {
  clear(app);
  const sidebar = h('aside', { id: 'sidebar', class: 'flex w-full shrink-0 flex-col border-r border-slate-200 bg-white lg:flex lg:w-80 xl:w-[22rem] dark:border-neutral-800 dark:bg-neutral-900', 'aria-label': 'Unterhaltungen' });
  const main = h('main', { id: 'main', class: 'hidden min-w-0 flex-1 bg-white lg:flex dark:bg-neutral-900' });
  const details = h('aside', { id: 'details', class: 'hidden', 'aria-label': 'Details' });
  const thread = h('aside', { id: 'thread', class: 'hidden', 'aria-label': 'Thread' });
  app.append(h('div', { class: 'flex h-full w-full overflow-hidden' }, sidebar, main, details, thread));
  return { sidebar, main, details, thread };
}

function applyResponsive(parts) {
  const chat = store.mobileView === 'chat';
  parts.sidebar.classList.toggle('hidden', chat);
  parts.sidebar.classList.toggle('flex', !chat);
  parts.main.classList.toggle('hidden', !chat);
  parts.main.classList.toggle('flex', chat);
  // ab lg immer beides sichtbar
  parts.sidebar.classList.add('lg:flex');
  parts.main.classList.add('lg:flex');
  if (chat) parts.sidebar.classList.remove('lg:hidden');
}

function applyDetails(parts) {
  const open = store.detailsOpen && !!store.activeId && !store.thread;
  parts.details.className = open
    ? 'fixed inset-y-0 right-0 z-40 flex w-full max-w-sm shrink-0 flex-col border-l border-slate-200 bg-white shadow-pop xl:static xl:w-80 xl:max-w-none xl:shadow-none dark:border-neutral-800 dark:bg-neutral-900'
    : 'hidden';
  if (!open) clear(parts.details);
}

function applyThread(parts) {
  const open = !!store.thread && !!store.activeId;
  parts.thread.className = open
    ? 'fixed inset-y-0 right-0 z-40 flex w-full max-w-md shrink-0 flex-col border-l border-slate-200 bg-white shadow-pop xl:static xl:w-96 xl:max-w-none xl:shadow-none dark:border-neutral-800 dark:bg-neutral-900'
    : 'hidden';
  if (!open) clear(parts.thread);
}

function navigate(delta) {
  const list = [...store.sorted({ archived: false, type: store.tab === 'groups' ? 'group' : 'direct' })];
  if (!list.length) return;
  const i = list.findIndex((c) => c.id === store.activeId);
  const next = list[(i + delta + list.length) % list.length];
  if (next) openConversation(next.id);
}

function shortcuts() {
  document.addEventListener('keydown', (e) => {
    const mod = e.ctrlKey || e.metaKey;
    if (mod && !e.altKey && e.key.toLowerCase() === 'k') { e.preventDefault(); closeMenu(); focusSearch(); return; }
    if (mod && e.altKey && e.key.toLowerCase() === 'n') { e.preventDefault(); newChatDialog(); return; }
    if (e.altKey && !mod && (e.key === 'ArrowUp' || e.key === 'ArrowDown')) { e.preventDefault(); navigate(e.key === 'ArrowDown' ? 1 : -1); return; }
    const t = e.target;
    const typing = t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable);
    if (!typing && !mod && !e.altKey && e.key === '?') { e.preventDefault(); shortcutsDialog(); }
  });
}

async function main() {
  const parts = buildLayout();
  parts.sidebar.append(skeletonList(8));

  let boot;
  try {
    boot = await api.get('/api/bootstrap');
  } catch (e) {
    fatal(e.message);
    return;
  }
  api.csrf = boot.csrf || api.csrf;
  api.onAuthLost = () => { location.href = `${state.base}/login`; };
  state.me = boot.me;
  Object.assign(store, {
    me: boot.me, branding: boot.branding, limits: boot.limits, reactions: boot.reactions,
    cursor: boot.cursor, realtime: boot.realtime || 'poll', push: boot.push || null, notificationsUnread: boot.notifications_unread,
  });
  for (const c of boot.conversations) store.upsertConversation(c);
  store.ready = true;
  store.meets = boot.meets || null;
  store.license = boot.license || { read_only: false, features: [] };
  if (store.license.read_only) {
    const row = app.firstElementChild;
    app.classList.add('flex', 'flex-col');
    row.classList.remove('h-full');
    row.classList.add('min-h-0', 'flex-1');
    app.insertBefore(h('div', { class: 'flex items-center justify-center gap-2 bg-amber-100 px-3 py-1.5 text-xs font-medium text-amber-900 dark:bg-amber-950 dark:text-amber-200', role: 'status' },
      icon('lock-alt', 'text-base'), `Nur-Lesen-Modus: ${store.license.message} Bitte wende dich an die Administration.`), row);
  }

  clear(parts.sidebar);
  initSidebar(parts.sidebar);
  initChat(parts.main);
  const empty = document.getElementById('chat-empty');
  empty.classList.replace('hidden', 'flex');
  empty.classList.add('flex-1');
  initDetails(parts.details);
  initThreads(parts.thread);
  loadDrafts(boot.drafts);

  store.on('mobile-view', () => applyResponsive(parts));
  store.on('details-toggle', () => applyDetails(parts));
  store.on('active', () => { applyDetails(parts); applyThread(parts); });
  store.on('thread-toggle', () => applyThread(parts));
  store.on('new-chat', () => newChatDialog());
  store.on('conversations', refreshTitle);
  store.on('me-changed', () => { state.me = store.me; });
  store.on('settings', () => { /* Einstellungen sind in store.me.settings aktuell */ });
  applyResponsive(parts);
  refreshTitle();
  shortcuts();

  // Deep-Link ?c=ID&m=ID
  const params = new URLSearchParams(location.search);
  const c = Number(params.get('c'));
  const m = Number(params.get('m')) || null;
  if (c && store.conv(c)) openConversation(c, { around: m, focus: false });
  else if (c) toast('Diese Unterhaltung ist nicht verfügbar.', 'warn');

  // Anruf angenommen, während die App geschlossen war (Benachrichtigung → «Annehmen»)
  const callId = Number(params.get('meeting'));
  if (c && callId) {
    acceptCall(callId, c);
    params.delete('meeting');
    const q = params.toString();
    history.replaceState(null, '', location.pathname + (q ? '?' + q : '') + location.hash);
  }

  initViewport();
  startPolling();
  registerServiceWorker().then(() => syncPush());
  clearBadge();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) clearBadge(); });
}

main().catch((e) => { console.error(e); fatal('Unerwarteter Fehler beim Start.'); });
