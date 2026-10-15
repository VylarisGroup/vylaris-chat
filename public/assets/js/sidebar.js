// Seitenleiste: Unterhaltungsliste, Tabs, Suche mit Filtern, Kontakte, Profilbereich.

import { api } from './api.js';
import { store } from './store.js';
import { h, icon, clear, userAvatar, convAvatar, listTime, url, debounce, humanSize, state } from './util.js';
import { toast, menu, skeletonList } from './ui.js';
import { convMenu } from './chat.js';
import { notificationsPanel, settingsDialog, profileDialog, newChatDialog, shortcutsDialog } from './dialogs.js';

let root;
const el = {};
let search = { q: '', type: 'all', from_user: 0, from: '', to: '', conversation_id: 0, convName: '', filtersOpen: false, seq: 0 };
let contacts = { loaded: false, items: [], q: '', seq: 0 };

export function initSidebar(container) {
  root = container;
  el.logo = h('div', { class: 'flex min-w-0 items-center gap-2.5' });
  el.bellBadge = h('span', { class: 'absolute right-1 top-1 hidden min-w-[1.1rem] rounded-full bg-red-500 px-1 text-center text-[10px] font-bold leading-[1.1rem] text-white' });
  el.bell = h('button', { class: 'btn-icon relative', 'aria-label': 'Benachrichtigungen', title: 'Benachrichtigungen', onclick: (e) => notificationsPanel(e.currentTarget) }, icon('bell'), el.bellBadge);
  el.header = h('div', { class: 'flex h-16 shrink-0 items-center justify-between gap-2 px-4' },
    el.logo,
    h('div', { class: 'flex items-center gap-0.5' },
      el.bell,
      h('button', { class: 'btn-icon', 'aria-label': 'Neue Unterhaltung', title: 'Neue Unterhaltung (Strg+Alt+N)', onclick: () => newChatDialog() }, icon('edit-alt'))));

  el.input = h('input', {
    type: 'search', class: 'input !rounded-xl !bg-slate-100 !pl-10 !pr-10 !shadow-none dark:!bg-neutral-800', placeholder: 'Suchen (Strg+K)', 'aria-label': 'Suchen',
    autocomplete: 'off', oninput: onSearchInput,
  });
  el.filterBtn = h('button', { class: 'btn-icon absolute right-1 top-1/2 h-8 w-8 -translate-y-1/2 text-lg', 'aria-label': 'Suchfilter', 'aria-expanded': 'false', title: 'Suchfilter', onclick: toggleFilters }, icon('filter-alt'));
  el.filters = h('div', { class: 'hidden space-y-2 px-3 pb-2' });
  el.searchBox = h('div', { class: 'shrink-0 px-3 pb-2' },
    h('div', { class: 'relative' }, icon('search', 'pointer-events-none absolute left-3.5 top-1/2 -translate-y-1/2 text-lg text-slate-400'), el.input, el.filterBtn));

  el.tabs = h('div', { class: 'flex shrink-0 gap-1 px-3 pb-2', role: 'tablist', 'aria-label': 'Bereiche' });
  el.list = h('div', { class: 'scroll-thin min-h-0 flex-1 overflow-y-auto overscroll-contain', id: 'conversation-list', tabindex: '-1' });
  el.footer = h('div', { class: 'flex shrink-0 items-center gap-2 border-t border-slate-200 p-3 dark:border-neutral-800' });

  root.append(el.header, el.searchBox, el.filters, el.tabs, el.list, el.footer);
  renderLogo();
  renderTabs();
  renderFooter();
  renderList();
  renderBell();

  store.on('conversations', () => { if (!searching()) renderList(); renderTabs(); });
  store.on('active', () => { if (!searching()) markActive(); });
  store.on('notifications', renderBell);
  store.on('me-changed', renderFooter);
  store.on('online', () => renderFooter());
  store.on('search-in', (convId) => searchIn(convId));
}

const searching = () => search.q.trim().length > 0 || search.from_user || search.from || search.to || search.conversation_id;

function renderLogo() {
  clear(el.logo);
  const b = store.branding;
  el.logo.append(b.has_logo
    ? h('img', { src: url('branding/logo'), alt: '', class: 'h-8 w-8 shrink-0 rounded-lg object-contain' })
    : h('span', { class: 'flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-accent text-white' }, icon('message-rounded-dots', 'text-xl')),
  h('span', { class: 'truncate text-base font-semibold tracking-tight' }, b.company_name));
}

function renderBell() {
  const n = store.notificationsUnread;
  el.bellBadge.textContent = n > 99 ? '99+' : String(n);
  el.bellBadge.classList.toggle('hidden', n <= 0);
  el.bell.setAttribute('aria-label', n > 0 ? `Benachrichtigungen, ${n} ungelesen` : 'Benachrichtigungen');
}

function renderTabs() {
  clear(el.tabs);
  const counts = { chats: 0, groups: 0 };
  for (const c of store.conversations.values()) {
    if (c.archived) continue;
    const n = c.muted ? c.unread_mentions : c.unread;
    if (c.type === 'direct') counts.chats += n; else counts.groups += n;
  }
  for (const [key, label] of [['chats', 'Chats'], ['groups', 'Gruppen'], ['contacts', 'Kontakte']]) {
    const active = store.tab === key;
    const n = counts[key] || 0;
    el.tabs.append(h('button', {
      role: 'tab', type: 'button', 'aria-selected': String(active),
      class: `relative flex-1 rounded-lg px-2 py-1.5 text-sm font-medium transition ${active ? 'bg-accent/10 text-accent' : 'text-slate-500 hover:bg-slate-100 dark:text-neutral-400 dark:hover:bg-neutral-800'}`,
      onclick: () => { store.tab = key; store.showArchive = false; clearSearch(); renderTabs(); renderList(); },
    }, label, n > 0 ? h('span', { class: 'ml-1.5 inline-block min-w-[1.1rem] rounded-full bg-accent px-1 text-[10px] font-bold leading-[1.1rem] text-white' }, n > 99 ? '99+' : String(n)) : null));
  }
}

function renderFooter() {
  clear(el.footer);
  const me = store.me;
  const presence = me.presence_pref === 'invisible' ? 'offline' : me.presence_pref === 'away' ? 'away' : store.online ? 'online' : 'offline';
  const btn = h('button', {
    class: 'flex min-w-0 flex-1 items-center gap-3 rounded-xl p-1.5 text-left transition hover:bg-slate-100 dark:hover:bg-neutral-800', 'aria-haspopup': 'menu',
    onclick: (e) => profileMenu(e.currentTarget),
  }, userAvatar({ ...me, presence }, 'md', true),
  h('span', { class: 'min-w-0' }, h('span', { class: 'block truncate text-sm font-medium' }, me.display_name), h('span', { class: 'block truncate text-xs text-slate-500 dark:text-neutral-400' }, me.status_message || (presence === 'online' ? 'Online' : presence === 'away' ? 'Abwesend' : 'Offline'))));
  el.footer.append(btn, h('button', { class: 'btn-icon', 'aria-label': 'Einstellungen', title: 'Einstellungen', onclick: () => settingsDialog() }, icon('cog')));
}

function profileMenu(anchor) {
  const setPresence = async (v) => {
    try { const res = await api.patch('/api/me', { presence_pref: v }); store.me = { ...store.me, ...res.me }; store.emit('me-changed'); } catch (e) { toast(e.message, 'error'); }
  };
  menu(anchor, [
    { label: 'Mein Profil bearbeiten', icon: 'user', onClick: () => settingsDialog('profile') },
    { divider: true },
    { label: 'Automatisch', icon: 'circle', checked: store.me.presence_pref === 'auto', onClick: () => setPresence('auto') },
    { label: 'Abwesend', icon: 'time', checked: store.me.presence_pref === 'away', onClick: () => setPresence('away') },
    { label: 'Unsichtbar', icon: 'hide', checked: store.me.presence_pref === 'invisible', onClick: () => setPresence('invisible') },
    { divider: true },
    { label: 'Einstellungen', icon: 'cog', onClick: () => settingsDialog() },
    { label: 'Meine Daten', icon: 'data', onClick: () => settingsDialog('mydata') },
    { label: 'Datenschutzerklärung', icon: 'file', onClick: () => { window.open(url('privacy'), '_blank', 'noopener'); } },
    { label: 'Tastenkürzel', icon: 'command', onClick: () => shortcutsDialog() },
    store.me.role === 'admin' ? { label: 'Administration', icon: 'shield', onClick: () => { location.href = url('admin'); } } : null,
    { divider: true },
    { label: 'Abmelden', icon: 'log-out', danger: true, onClick: logout },
  ], { align: 'left' });
}

function logout() {
  const f = h('form', { method: 'post', action: url('logout'), class: 'hidden' }, h('input', { type: 'hidden', name: '_csrf', value: api.csrf }));
  document.body.append(f);
  f.submit();
}

// ------------------------------------------------------------------- Liste

function preview(c) {
  const lm = c.last_message;
  if (!lm) return 'Noch keine Nachrichten';
  if (lm.deleted) return 'Nachricht gelöscht';
  const text = String(lm.text || '').replace(/```|\*\*|~~|`/g, '').replace(/\s+/g, ' ');
  if (lm.kind === 'system') return text;
  if (lm.sender_id === store.me.id) return `Du: ${text}`;
  return c.type === 'group' && lm.sender ? `${lm.sender.split(' ')[0]}: ${text}` : text;
}

function convItem(c) {
  const active = store.activeId === c.id;
  const unread = c.unread > 0;
  const badge = c.unread > 0
    ? h('span', { class: `inline-flex min-w-[1.25rem] items-center justify-center rounded-full px-1.5 text-[11px] font-bold leading-5 text-white ${c.muted ? 'bg-slate-400 dark:bg-neutral-600' : 'bg-accent'}`, 'aria-label': `${c.unread} ungelesen` }, c.unread > 99 ? '99+' : String(c.unread))
    : null;
  const item = h('a', {
    href: `?c=${c.id}`, dataset: { cid: c.id },
    class: `group relative flex cursor-pointer items-center gap-3 rounded-xl px-2.5 py-2.5 transition ${active ? 'bg-accent/10' : 'hover:bg-slate-100 dark:hover:bg-neutral-800'}`,
    'aria-current': active ? 'true' : null,
    onclick: (e) => { if (e.ctrlKey || e.metaKey || e.shiftKey) return; e.preventDefault(); store.emit('open-conversation', { id: c.id }); },
    oncontextmenu: (e) => { e.preventDefault(); convMenu(c, { x: e.clientX, y: e.clientY }); },
  },
  convAvatar(c, 'lg'),
  h('div', { class: 'min-w-0 flex-1' },
    h('div', { class: 'flex items-baseline justify-between gap-2' },
      h('span', { class: `truncate text-sm ${unread ? 'font-semibold' : 'font-medium'}` }, c.name),
      h('span', { class: `shrink-0 text-[11px] ${unread && !c.muted ? 'font-semibold text-accent' : 'text-slate-400'}` }, c.last_message ? listTime(c.last_message_at) : '')),
    h('div', { class: 'mt-0.5 flex items-center justify-between gap-2' },
      h('span', { class: `truncate text-[13px] ${unread ? 'text-slate-800 dark:text-neutral-200' : 'text-slate-500 dark:text-neutral-400'}` }, preview(c)),
      h('span', { class: 'flex shrink-0 items-center gap-1 text-slate-400' },
        c.unread_mentions > 0 ? h('span', { class: 'flex h-5 w-5 items-center justify-center rounded-full bg-amber-400 text-amber-950', title: 'Du wurdest erwähnt', 'aria-label': 'Erwähnung' }, icon('at', 'text-xs')) : null,
        c.muted ? icon('bell-off', 'text-sm') : null,
        c.pinned ? icon('pin', 'text-sm') : null,
        badge))));
  const more = h('button', {
    class: 'btn-icon absolute right-1.5 top-1.5 hidden h-7 w-7 bg-white/90 text-lg shadow-sm group-hover:flex group-focus-within:flex dark:bg-neutral-800/90', 'aria-label': `Optionen für ${c.name}`,
    onclick: (e) => { e.preventDefault(); e.stopPropagation(); convMenu(c, e.currentTarget); },
  }, icon('dots-horizontal-rounded'));
  item.append(more);
  return item;
}

function markActive() {
  for (const a of el.list.querySelectorAll('[data-cid]')) {
    const on = Number(a.dataset.cid) === store.activeId;
    a.classList.toggle('bg-accent/10', on);
    a.setAttribute('aria-current', on ? 'true' : 'false');
  }
}

function empty(iconName, title, text, action) {
  return h('div', { class: 'flex flex-col items-center gap-2 px-6 py-12 text-center' },
    h('span', { class: 'flex h-14 w-14 items-center justify-center rounded-2xl bg-slate-100 text-3xl text-slate-400 dark:bg-neutral-800' }, icon(iconName)),
    h('p', { class: 'text-sm font-semibold' }, title), h('p', { class: 'text-sm text-slate-500 dark:text-neutral-400' }, text), action);
}

export function renderList() {
  if (searching()) return;
  el.filters.classList.add('hidden');
  clear(el.list);
  if (!store.ready) { el.list.append(skeletonList(7)); return; }
  if (store.tab === 'contacts') { renderContacts(); return; }
  const type = store.tab === 'groups' ? 'group' : 'direct';
  const archived = store.sorted({ archived: true, type });
  const items = store.showArchive ? archived : store.sorted({ archived: false, type });
  const box = h('div', { class: 'space-y-0.5 px-2 pb-2' });
  if (store.showArchive) {
    box.append(h('button', { class: 'mb-1 flex w-full items-center gap-2 rounded-xl px-2.5 py-2 text-sm font-medium text-accent hover:bg-slate-100 dark:hover:bg-neutral-800', onclick: () => { store.showArchive = false; renderList(); } }, icon('chevron-left', 'text-lg'), 'Zurück', h('span', { class: 'ml-auto text-xs text-slate-400' }, 'Archiv')));
  }
  if (!items.length) {
    box.append(store.showArchive
      ? empty('archive', 'Archiv ist leer', 'Archivierte Unterhaltungen erscheinen hier.')
      : empty(type === 'group' ? 'group' : 'message-rounded-dots', type === 'group' ? 'Noch keine Gruppen' : 'Noch keine Chats', 'Starte eine neue Unterhaltung mit deinen Kolleginnen und Kollegen.',
        h('button', { class: 'btn btn-primary btn-sm mt-2', onclick: () => newChatDialog(type === 'group' ? 'group' : 'direct') }, icon('plus', 'text-base'), type === 'group' ? 'Gruppe erstellen' : 'Neuer Chat')));
  }
  for (const c of items) box.append(convItem(c));
  if (!store.showArchive && archived.length) {
    box.append(h('button', { class: 'mt-1 flex w-full items-center gap-2 rounded-xl px-2.5 py-2.5 text-sm text-slate-500 hover:bg-slate-100 dark:text-neutral-400 dark:hover:bg-neutral-800', onclick: () => { store.showArchive = true; renderList(); } },
      icon('archive', 'text-lg'), 'Archiv', h('span', { class: 'ml-auto rounded-full bg-slate-200 px-2 text-xs dark:bg-neutral-700' }, String(archived.length))));
  }
  el.list.append(box);
}

// ---------------------------------------------------------------- Kontakte

async function renderContacts() {
  const mine = ++contacts.seq;
  const box = h('div', { class: 'px-2 pb-2' });
  clear(el.list);
  el.list.append(box);
  if (!contacts.loaded) box.append(skeletonList(6));
  try {
    const res = await api.get('/api/users?q=');
    contacts.items = res.users;
    contacts.loaded = true;
  } catch (e) {
    if (mine === contacts.seq) { clear(box); box.append(empty('error-circle', 'Kontakte nicht verfügbar', e.message)); }
    return;
  }
  if (mine !== contacts.seq || store.tab !== 'contacts' || searching()) return;
  clear(box);
  if (!contacts.items.length) { box.append(empty('user', 'Keine Kontakte', 'Es gibt noch keine weiteren Benutzer.')); return; }
  let letter = '';
  for (const u of contacts.items) {
    const l = (u.display_name[0] || '#').toUpperCase();
    if (l !== letter) { letter = l; box.append(h('p', { class: 'px-2.5 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400' }, letter)); }
    box.append(h('div', { class: 'group flex items-center gap-1' },
      h('button', {
        class: 'flex min-w-0 flex-1 items-center gap-3 rounded-xl px-2.5 py-2 text-left transition hover:bg-slate-100 dark:hover:bg-neutral-800',
        onclick: () => openDirect(u),
      }, userAvatar(u, 'lg', true),
      h('span', { class: 'min-w-0' }, h('span', { class: 'block truncate text-sm font-medium' }, u.display_name), h('span', { class: 'block truncate text-xs text-slate-500 dark:text-neutral-400' }, u.status_message || `@${u.username}`))),
      h('button', { class: 'btn-icon h-8 w-8 text-lg', 'aria-label': `Profil von ${u.display_name}`, title: 'Profil', onclick: () => profileDialog(u) }, icon('id-card'))));
  }
}

export async function openDirect(u) {
  try {
    const res = await api.post('/api/conversations/direct', { user_id: u.id });
    store.upsertConversation(res.conversation);
    store.emit('conversations');
    store.emit('open-conversation', { id: res.conversation.id });
  } catch (e) { toast(e.message, 'error'); }
}

// ------------------------------------------------------------------- Suche

export function focusSearch() {
  store.mobileView = 'list';
  store.emit('mobile-view');
  el.input.focus();
  el.input.select();
}

function searchIn(convId) {
  const c = store.conv(convId);
  search.conversation_id = convId;
  search.convName = c?.name || 'Unterhaltung';
  store.mobileView = 'list';
  store.emit('mobile-view');
  el.input.focus();
  renderFilters();
  runSearch();
}

function clearSearch() {
  search = { q: '', type: 'all', from_user: 0, from: '', to: '', conversation_id: 0, convName: '', filtersOpen: false, seq: search.seq };
  el.input.value = '';
  el.filters.classList.add('hidden');
  el.filterBtn.setAttribute('aria-expanded', 'false');
}

function onSearchInput() {
  search.q = el.input.value;
  if (!searching()) { renderList(); return; }
  debouncedSearch();
}
const debouncedSearch = debounce(() => runSearch(), 300);

function toggleFilters() {
  search.filtersOpen = !search.filtersOpen;
  el.filterBtn.setAttribute('aria-expanded', String(search.filtersOpen));
  renderFilters();
}

let senderCache = null;
async function renderFilters() {
  clear(el.filters);
  const show = search.filtersOpen || search.conversation_id;
  el.filters.classList.toggle('hidden', !show);
  if (!show) return;
  if (search.conversation_id) {
    el.filters.append(h('div', { class: 'flex items-center gap-2' },
      h('span', { class: 'inline-flex items-center gap-1 rounded-full bg-accent/10 py-1 pl-3 pr-1 text-xs font-medium text-accent' }, `In: ${search.convName}`,
        h('button', { class: 'flex h-5 w-5 items-center justify-center rounded-full hover:bg-accent/20', 'aria-label': 'Einschränkung auf Unterhaltung aufheben', onclick: () => { search.conversation_id = 0; search.convName = ''; renderFilters(); if (searching()) runSearch(); else renderList(); } }, icon('x', 'text-sm')))));
  }
  if (!search.filtersOpen) return;
  const type = h('select', { class: 'input !py-1.5 text-xs', 'aria-label': 'Art', onchange: (e) => { search.type = e.target.value; runSearch(); } },
    ...[['all', 'Alles'], ['users', 'Personen'], ['conversations', 'Unterhaltungen'], ['messages', 'Nachrichten'], ['files', 'Dateien']].map(([v, l]) => h('option', { value: v, selected: search.type === v }, l)));
  const sender = h('select', { class: 'input !py-1.5 text-xs', 'aria-label': 'Absender', onchange: (e) => { search.from_user = Number(e.target.value); runSearch(); } }, h('option', { value: '0' }, 'Alle Absender'));
  const fill = (users) => { for (const u of users) sender.append(h('option', { value: u.id, selected: search.from_user === u.id }, u.display_name)); };
  if (senderCache) fill(senderCache); else api.get('/api/users?q=').then((r) => { senderCache = [store.me, ...r.users]; fill(senderCache); }).catch(() => {});
  const from = h('input', { type: 'date', class: 'input !py-1.5 text-xs', 'aria-label': 'Von Datum', value: search.from, onchange: (e) => { search.from = e.target.value; runSearch(); } });
  const to = h('input', { type: 'date', class: 'input !py-1.5 text-xs', 'aria-label': 'Bis Datum', value: search.to, onchange: (e) => { search.to = e.target.value; runSearch(); } });
  el.filters.append(h('div', { class: 'grid grid-cols-2 gap-2' }, type, sender, from, to),
    h('button', { class: 'text-xs font-medium text-accent hover:underline', onclick: () => { Object.assign(search, { type: 'all', from_user: 0, from: '', to: '' }); renderFilters(); if (searching()) runSearch(); else renderList(); } }, 'Filter zurücksetzen'));
}

function nextDay(d) {
  const t = new Date(`${d}T00:00:00`);
  t.setDate(t.getDate() + 1);
  const p = (n) => String(n).padStart(2, '0');
  return `${t.getFullYear()}-${p(t.getMonth() + 1)}-${p(t.getDate())}`;
}

async function runSearch() {
  if (!searching()) { renderList(); return; }
  const mine = ++search.seq;
  const q = search.q.trim();
  const hasFilter = search.from_user || search.from || search.to || search.conversation_id;
  clear(el.list);
  if (q.length < 2 && !hasFilter) {
    el.list.append(empty('search', 'Suche', 'Gib mindestens 2 Zeichen ein.'));
    return;
  }
  el.list.append(skeletonList(5));
  const params = new URLSearchParams({ q, type: search.type });
  if (search.from_user) params.set('from_user', String(search.from_user));
  if (search.from) params.set('from', `${search.from}T00:00:00`);
  if (search.to) params.set('to', `${nextDay(search.to)}T00:00:00`);
  if (search.conversation_id) params.set('conversation_id', String(search.conversation_id));
  // Browser-Zeitzone berücksichtigen: Datum lokal -> UTC
  for (const k of ['from', 'to']) {
    if (params.has(k)) params.set(k, new Date(params.get(k)).toISOString());
  }
  let res;
  try { res = await api.get(`/api/search?${params}`); } catch (e) {
    if (mine !== search.seq) return;
    clear(el.list); el.list.append(empty('error-circle', 'Suche fehlgeschlagen', e.message));
    return;
  }
  if (mine !== search.seq) return;
  clear(el.list);
  const total = res.users.length + res.conversations.length + res.messages.length + res.files.length;
  if (!total) { el.list.append(empty('search-alt', 'Keine Treffer', 'Versuche einen anderen Suchbegriff oder lockere die Filter.')); return; }
  const box = h('div', { class: 'space-y-0.5 px-2 pb-3' });
  const section = (title) => box.append(h('h3', { class: 'px-2.5 pb-1 pt-3 text-xs font-semibold uppercase tracking-wide text-slate-400' }, title));
  if (res.users.length) {
    section('Personen');
    for (const u of res.users) box.append(h('button', { class: 'flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left hover:bg-slate-100 dark:hover:bg-neutral-800', onclick: () => openDirect(u) },
      userAvatar(u, 'md', true), h('span', { class: 'min-w-0' }, h('span', { class: 'block truncate text-sm font-medium' }, highlight(u.display_name, q)), h('span', { class: 'block truncate text-xs text-slate-500' }, `@${u.username}`))));
  }
  if (res.conversations.length) {
    section('Unterhaltungen');
    for (const c0 of res.conversations) {
      const c = store.conv(c0.id) || c0;
      box.append(h('button', { class: 'flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left hover:bg-slate-100 dark:hover:bg-neutral-800', onclick: () => openFromSearch(c.id) },
        convAvatar(c, 'md', false), h('span', { class: 'truncate text-sm font-medium' }, highlight(c.name, q))));
    }
  }
  if (res.messages.length) {
    section('Nachrichten');
    for (const m of res.messages) {
      box.append(h('button', { class: 'block w-full rounded-xl px-2.5 py-2 text-left hover:bg-slate-100 dark:hover:bg-neutral-800', onclick: () => openFromSearch(m.conversation_id, m.id) },
        h('span', { class: 'flex items-baseline justify-between gap-2' }, h('span', { class: 'truncate text-sm font-medium' }, m.conversation_name), h('span', { class: 'shrink-0 text-[11px] text-slate-400' }, listTime(m.created_at))),
        h('span', { class: 'block truncate text-xs font-medium text-accent' }, m.sender_name),
        h('span', { class: 'line-clamp-2 break-words text-[13px] text-slate-600 dark:text-neutral-300' }, highlight(m.snippet, q))));
    }
  }
  if (res.files.length) {
    section('Dateien');
    for (const f of res.files) {
      box.append(h('button', { class: 'flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left hover:bg-slate-100 dark:hover:bg-neutral-800', onclick: () => openFromSearch(f.conversation_id, f.message_id) },
        h('span', { class: 'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xl text-slate-500 dark:bg-neutral-800' }, icon(f.is_image ? 'image' : 'file')),
        h('span', { class: 'min-w-0' }, h('span', { class: 'block truncate text-sm font-medium' }, highlight(f.name, q)),
          h('span', { class: 'block truncate text-xs text-slate-500' }, `${f.conversation_name} · ${f.sender_name} · ${humanSize(f.size)}`))));
    }
  }
  el.list.append(box);
}

function openFromSearch(convId, messageId = null) {
  store.emit('open-conversation', { id: convId, around: messageId });
}

/** Treffer markieren, ohne HTML zu erzeugen. */
function highlight(text, q) {
  const frag = document.createDocumentFragment();
  const t = String(text);
  if (!q) { frag.append(t); return frag; }
  const low = t.toLowerCase();
  const needle = q.toLowerCase();
  let i = 0;
  for (;;) {
    const j = low.indexOf(needle, i);
    if (j < 0) break;
    frag.append(t.slice(i, j), h('mark', { class: 'rounded bg-amber-200 px-0.5 text-inherit dark:bg-amber-500/40' }, t.slice(j, j + needle.length)));
    i = j + needle.length;
  }
  frag.append(t.slice(i));
  return frag;
}

export { state };
