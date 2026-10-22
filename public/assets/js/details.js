// Rechter Bereich: Unterhaltungsinfo, Mitglieder und Rollen, Dateien, Links.

import { api } from './api.js';
import { store } from './store.js';
import { h, icon, clear, userAvatar, convAvatar, humanSize, url, relativeSeen, presenceLabel, fullDate } from './util.js';
import { toast, modal, confirmDialog, menu, skeletonList } from './ui.js';
import { loadDetails, setPref, closeConversation } from './chat.js';
import { field, userPicker, profileDialog, lightbox } from './dialogs.js';

let root;
let tab = 'members';

export function initDetails(container) {
  root = container;
  const rerender = () => { if (store.detailsOpen) render(); };
  store.on('details', rerender);
  store.on('active', () => { tab = 'members'; if (store.detailsOpen) { render(); if (store.activeId) loadDetails(store.activeId); } });
  store.on('conversations', rerender);
  store.on('details-toggle', () => { if (store.detailsOpen && store.activeId) { render(); loadDetails(store.activeId); } });
  store.on('online', rerender);
}

function infoRow(label, value) {
  return h('div', { class: 'flex justify-between gap-3 py-1.5 text-sm' }, h('span', { class: 'text-slate-500 dark:text-neutral-400' }, label), h('span', { class: 'min-w-0 break-words text-right font-medium' }, value));
}

function render() {
  clear(root);
  const c = store.active;
  if (!c) return;
  const d = store.members.get(c.id);
  const isGroup = c.type === 'group';
  const myRole = d?.my_role || c.role;
  const canMod = isGroup && (myRole === 'admin' || myRole === 'moderator');

  root.append(h('div', { class: 'flex h-16 shrink-0 items-center justify-between border-b border-slate-200 px-4 dark:border-neutral-800' },
    h('h2', { class: 'text-sm font-semibold' }, isGroup ? 'Gruppeninfo' : c.type === 'notes' ? 'Notizen' : 'Kontaktinfo'),
    h('button', { class: 'btn-icon', 'aria-label': 'Info-Bereich schliessen', onclick: () => { store.detailsOpen = false; store.emit('details-toggle'); store.emit('details-changed'); } }, icon('x'))));

  const body = h('div', { class: 'scroll-thin min-h-0 flex-1 overflow-y-auto' });
  root.append(body);

  // Kopf
  const other = c.other;
  body.append(h('div', { class: 'flex flex-col items-center px-5 pb-4 pt-6 text-center' },
    convAvatar(c, 'xl'),
    h('h3', { class: 'mt-3 break-words text-lg font-semibold leading-tight' }, c.name),
    isGroup
      ? h('p', { class: 'mt-1 text-sm text-slate-500 dark:text-neutral-400' }, `${c.member_count} Mitglieder`)
      : c.type === 'notes' ? h('p', { class: 'mt-1 text-sm text-slate-500 dark:text-neutral-400' }, 'Nur für dich sichtbar. Texte, Links und Dateien, auf allen deinen Geräten.')
      : h('p', { class: 'mt-1 text-sm text-slate-500 dark:text-neutral-400' }, other?.bot ? 'KI-Assistent' : other ? (other.presence === 'online' ? 'Online' : other.last_seen_at ? `Zuletzt aktiv ${relativeSeen(other.last_seen_at)}` : presenceLabel(other.presence)) : ''),
    isGroup && (d?.description || c.description) ? h('p', { class: 'mt-3 whitespace-pre-wrap break-words text-sm text-slate-600 dark:text-neutral-300' }, d?.description ?? c.description) : null,
    !isGroup && other?.status_message ? h('p', { class: 'mt-3 rounded-xl bg-slate-100 px-3 py-2 text-sm dark:bg-neutral-800' }, other.status_message) : null,
    h('div', { class: 'mt-4 flex flex-wrap justify-center gap-2' },
      h('button', { class: 'btn btn-secondary btn-sm', onclick: () => store.emit('search-in', c.id) }, icon('search', 'text-base'), 'Suchen'),
      c.type === 'notes' ? null : h('button', { class: 'btn btn-secondary btn-sm', 'aria-pressed': String(c.muted), onclick: () => setPref(c, { muted: !c.muted }) }, icon(c.muted ? 'bell' : 'bell-off', 'text-base'), c.muted ? 'Ton an' : 'Stumm'),
      c.type === 'notes' ? null : h('button', { class: 'btn btn-secondary btn-sm', 'aria-pressed': String(c.pinned), onclick: () => setPref(c, { pinned: !c.pinned }) }, icon('pin', 'text-base'), c.pinned ? 'Losgelöst' : 'Anpinnen'),
      c.type === 'notes' ? null : h('button', { class: 'btn btn-secondary btn-sm', onclick: () => setPref(c, { archived: !c.archived }) }, icon('archive', 'text-base'), c.archived ? 'Wiederherstellen' : 'Archivieren'),
      canMod ? h('button', { class: 'btn btn-secondary btn-sm', onclick: () => editGroupDialog(c, d) }, icon('edit', 'text-base'), 'Bearbeiten') : null),
    !isGroup && other ? h('button', { class: 'mt-3 text-sm font-medium text-accent hover:underline', onclick: () => profileDialog(other) }, 'Profil anzeigen') : null));

  // KI-Kontext (nur wenn die KI in diesem Chat ist)
  if (c.has_ai && c.type !== 'notes') {
    const may = !isGroup || canMod;
    const levels = [['request', 'Nur die Anfrage'], ['recent', 'Letzte ca. 20 Nachrichten'], ['full', 'Ganzer Verlauf']];
    const sel = h('select', { class: 'input mt-1', id: 'ai-ctx', disabled: may ? null : '', onchange: async (e) => {
      try {
        const res = await api.put(`/api/conversations/${c.id}/ai-context`, { level: e.target.value });
        if (res.conversation) store.upsertConversation(res.conversation);
        store.emit('conversations');
        toast('KI-Kontext gespeichert.', 'success', 2000);
      } catch (err) { toast(err.message, 'error'); e.target.value = c.ai_context; }
    } }, levels.map(([v, l]) => h('option', { value: v, selected: c.ai_context === v ? '' : null }, l)));
    if (!may) sel.disabled = true;
    body.append(h('div', { class: 'mx-4 mb-3 rounded-xl border border-violet-200 bg-violet-50 p-3 text-sm dark:border-violet-900/50 dark:bg-violet-950/30' },
      h('label', { class: 'flex items-center gap-1.5 font-semibold text-violet-800 dark:text-violet-300', for: 'ai-ctx' }, icon('bot', 'text-lg'), 'Was die KI mitliest'),
      sel,
      h('p', { class: 'mt-1.5 text-xs text-slate-600 dark:text-neutral-400' }, isGroup
        ? 'Die KI antwortet nur auf @Erwähnung oder wenn du auf ihre Nachricht antwortest. Das hier Gewählte wird dann an den KI-Anbieter übermittelt. Ändern darf die Moderation.'
        : 'Das hier Gewählte wird bei jeder Frage an den KI-Anbieter übermittelt. Je weniger, desto weniger Daten verlassen den Messenger.')));
  }

  // Tabs
  const tabs = isGroup ? [['members', 'Mitglieder'], ['files', 'Dateien'], ['links', 'Links']] : [['files', 'Dateien'], ['links', 'Links']];
  if (!tabs.some(([k]) => k === tab)) tab = tabs[0][0];
  body.append(h('div', { class: 'sticky top-0 z-10 flex gap-1 border-b border-slate-200 bg-white px-3 dark:border-neutral-800 dark:bg-neutral-900', role: 'tablist' },
    tabs.map(([k, l]) => h('button', {
      role: 'tab', 'aria-selected': String(tab === k),
      class: `border-b-2 px-3 py-2.5 text-sm font-medium transition ${tab === k ? 'border-accent text-accent' : 'border-transparent text-slate-500 hover:text-slate-800 dark:text-neutral-400 dark:hover:text-neutral-100'}`,
      onclick: () => { tab = k; render(); },
    }, l))));

  const pane = h('div', { class: 'p-3', role: 'tabpanel' });
  body.append(pane);
  if (!d) { pane.append(skeletonList(4)); return; }

  if (tab === 'members') renderMembers(pane, c, d, canMod, myRole);
  else if (tab === 'files') renderFiles(pane, d);
  else renderLinks(pane, d);

  if (isGroup) {
    body.append(h('div', { class: 'border-t border-slate-200 p-4 dark:border-neutral-800' },
      h('button', { class: 'btn btn-secondary w-full !text-red-600 dark:!text-red-400', onclick: () => leaveGroup(c) }, icon('log-out', 'text-lg'), 'Gruppe verlassen')));
  }
}

const ROLE_LABEL = { admin: 'Administrator', moderator: 'Moderator', member: 'Mitglied' };

function renderMembers(pane, c, d, canMod, myRole) {
  if (canMod) {
    pane.append(h('button', { class: 'mb-2 flex w-full items-center gap-3 rounded-xl px-2.5 py-2 text-left text-sm font-medium text-accent hover:bg-slate-100 dark:hover:bg-neutral-800', onclick: () => addMembersDialog(c, d) },
      h('span', { class: 'flex h-10 w-10 items-center justify-center rounded-full bg-accent/10 text-xl' }, icon('user-plus')), 'Mitglieder hinzufügen'));
  }
  const list = h('ul', { class: 'space-y-0.5' });
  for (const u of d.members) {
    const mine = u.id === store.me.id;
    const canManage = !mine && canMod && (myRole === 'admin' || (myRole === 'moderator' && u.member_role === 'member'));
    list.append(h('li', { class: 'group flex items-center gap-1' },
      h('button', { class: 'flex min-w-0 flex-1 items-center gap-3 rounded-xl px-2.5 py-2 text-left hover:bg-slate-100 dark:hover:bg-neutral-800', onclick: () => profileDialog(mine ? { ...store.me, presence: 'online', last_seen_at: null } : u) },
        userAvatar(mine ? { ...store.me, presence: 'online' } : u, 'md', true),
        h('span', { class: 'min-w-0 flex-1' },
          h('span', { class: 'block truncate text-sm font-medium' }, u.display_name, mine ? h('span', { class: 'ml-1 text-xs font-normal text-slate-400' }, '(du)') : null),
          h('span', { class: 'block truncate text-xs text-slate-500 dark:text-neutral-400' }, u.status_message || `@${u.username}`)),
        u.member_role !== 'member' ? h('span', { class: `shrink-0 rounded-full px-2 py-0.5 text-[11px] font-semibold ${u.member_role === 'admin' ? 'bg-accent/15 text-accent' : 'bg-slate-200 text-slate-600 dark:bg-neutral-700 dark:text-neutral-300'}` }, ROLE_LABEL[u.member_role]) : null),
      canManage ? h('button', { class: 'btn-icon h-8 w-8 text-lg', 'aria-label': `Optionen für ${u.display_name}`, onclick: (e) => memberMenu(e.currentTarget, c, u, myRole) }, icon('dots-vertical-rounded')) : null));
  }
  pane.append(list);
}

function memberMenu(anchor, c, u, myRole) {
  const items = [];
  if (myRole === 'admin') {
    for (const r of ['admin', 'moderator', 'member']) {
      items.push({ label: `Als ${ROLE_LABEL[r]}`, icon: r === 'admin' ? 'shield' : r === 'moderator' ? 'shield-alt-2' : 'user', checked: u.member_role === r, onClick: () => setRole(c, u, r) });
    }
    items.push({ divider: true });
  }
  items.push({ label: 'Aus der Gruppe entfernen', icon: 'user-minus', danger: true, onClick: () => removeMember(c, u) });
  menu(anchor, items);
}

async function setRole(c, u, role) {
  try {
    const res = await api.patch(`/api/conversations/${c.id}/members/${u.id}`, { role });
    store.members.set(c.id, res.details);
    store.emit('details', c.id);
    toast(`${u.display_name} ist jetzt ${ROLE_LABEL[role]}.`, 'success', 2500);
  } catch (e) { toast(e.message, 'error'); }
}

async function removeMember(c, u) {
  if (!(await confirmDialog({ title: 'Mitglied entfernen', text: `${u.display_name} aus der Gruppe „${c.name}“ entfernen? Die Person sieht danach keine neuen Nachrichten mehr.`, confirmLabel: 'Entfernen', danger: true }))) return;
  try {
    const res = await api.del(`/api/conversations/${c.id}/members/${u.id}`);
    if (res.details) { store.members.set(c.id, res.details); store.emit('details', c.id); }
    const k = store.conv(c.id);
    if (k) { k.member_count = Math.max(1, k.member_count - 1); store.emit('conversations'); }
  } catch (e) { toast(e.message, 'error'); }
}

async function leaveGroup(c) {
  if (!(await confirmDialog({ title: 'Gruppe verlassen', text: `Möchtest du „${c.name}“ wirklich verlassen? Du kannst nur durch eine erneute Einladung wieder beitreten.`, confirmLabel: 'Verlassen', danger: true }))) return;
  try {
    await api.post(`/api/conversations/${c.id}/leave`);
    store.conversations.delete(c.id);
    store.msgs.delete(c.id);
    store.members.delete(c.id);
    store.detailsOpen = false;
    store.emit('details-toggle');
    closeConversation();
    store.emit('conversations');
    toast('Du hast die Gruppe verlassen.', 'success', 2500);
  } catch (e) { toast(e.message, 'error'); }
}

function addMembersDialog(c, d) {
  const exclude = d.members.map((m) => m.id);
  const picker = userPicker({ multi: true, exclude });
  const go = h('button', { class: 'btn btn-primary', type: 'button' }, 'Hinzufügen');
  const dlg = modal({
    title: 'Mitglieder hinzufügen', body: picker.el,
    footer: [h('button', { class: 'btn btn-secondary', type: 'button', onclick: () => dlg.close() }, 'Abbrechen'), go],
  });
  go.addEventListener('click', async () => {
    if (!picker.selected.size) { toast('Wähle mindestens eine Person aus.', 'warn'); return; }
    go.disabled = true;
    try {
      const res = await api.post(`/api/conversations/${c.id}/members`, { user_ids: [...picker.selected.keys()] });
      store.members.set(c.id, res.details);
      const k = store.conv(c.id);
      if (k) k.member_count = res.details.members.length;
      store.emit('details', c.id);
      store.emit('conversations');
      dlg.close();
      toast(res.added === 1 ? 'Eine Person hinzugefügt.' : `${res.added} Personen hinzugefügt.`, 'success', 2500);
    } catch (e) { toast(e.message, 'error'); go.disabled = false; }
  });
}

function editGroupDialog(c, d) {
  const name = h('input', { class: 'input', value: c.name, maxlength: '100' });
  const desc = h('textarea', { class: 'input', rows: '3', maxlength: '500' });
  desc.value = d?.description ?? c.description ?? '';
  const file = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', class: 'sr-only', 'aria-label': 'Gruppenbild auswählen' });
  const apply = (conv) => { if (conv) { store.upsertConversation(conv); store.emit('conversations'); } };
  file.addEventListener('change', async () => {
    if (!file.files[0]) return;
    try { const res = await api.upload(`/api/conversations/${c.id}/avatar`, file.files[0]); apply(res.conversation); toast('Gruppenbild aktualisiert.', 'success', 2500); } catch (e) { toast(e.message, 'error'); }
  });
  const go = h('button', { class: 'btn btn-primary', type: 'button' }, 'Speichern');
  const dlg = modal({
    title: 'Gruppe bearbeiten',
    body: [
      h('div', { class: 'mb-4 flex items-center gap-4' }, convAvatar(c, 'xl'),
        h('div', { class: 'flex flex-wrap gap-2' },
          h('label', { class: 'btn btn-secondary btn-sm cursor-pointer focus-within:ring-2 focus-within:ring-accent' }, icon('image-add', 'text-base'), 'Bild ändern', file),
          c.has_avatar ? h('button', { class: 'btn btn-ghost btn-sm', type: 'button', onclick: async () => { try { const res = await api.del(`/api/conversations/${c.id}/avatar`); apply(res.conversation); } catch (e) { toast(e.message, 'error'); } } }, 'Entfernen') : null)),
      field('Gruppenname', name), field('Beschreibung', desc),
    ],
    footer: [h('button', { class: 'btn btn-secondary', type: 'button', onclick: () => dlg.close() }, 'Abbrechen'), go],
  });
  go.addEventListener('click', async () => {
    go.disabled = true;
    try {
      const res = await api.patch(`/api/conversations/${c.id}`, { name: name.value.trim(), description: desc.value.trim() });
      apply(res.conversation);
      loadDetails(c.id);
      dlg.close();
    } catch (e) { toast(e.message, 'error'); go.disabled = false; }
  });
}

function renderFiles(pane, d) {
  if (!d.files.length) { pane.append(h('p', { class: 'p-6 text-center text-sm text-slate-500 dark:text-neutral-400' }, 'Noch keine Dateien geteilt.')); return; }
  const images = d.files.filter((f) => f.is_image);
  const others = d.files.filter((f) => !f.is_image);
  if (images.length) {
    pane.append(h('div', { class: 'mb-3 grid grid-cols-3 gap-1.5' }, images.map((f) => h('button', {
      class: 'aspect-square overflow-hidden rounded-lg bg-slate-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-accent dark:bg-neutral-800', 'aria-label': `Bild ${f.name}`, onclick: () => lightbox(f),
    }, h('img', { src: url(`files/${f.id}?thumb=1`), alt: '', loading: 'lazy', class: 'h-full w-full object-cover' })))));
  }
  const ul = h('ul', { class: 'space-y-0.5' });
  for (const f of others) {
    ul.append(h('li', {}, h('a', { href: url(`files/${f.id}?download=1`), download: f.name, class: 'flex items-center gap-3 rounded-xl px-2.5 py-2 hover:bg-slate-100 dark:hover:bg-neutral-800' },
      h('span', { class: 'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xl text-slate-500 dark:bg-neutral-800' }, icon('file')),
      h('span', { class: 'min-w-0 flex-1' }, h('span', { class: 'block truncate text-sm font-medium' }, f.name), h('span', { class: 'block text-xs text-slate-500 dark:text-neutral-400' }, `${humanSize(f.size)} · ${fullDate(f.created_at)}`)),
      icon('download', 'text-lg text-slate-400'))));
  }
  pane.append(ul);
}

function renderLinks(pane, d) {
  if (!d.links.length) { pane.append(h('p', { class: 'p-6 text-center text-sm text-slate-500 dark:text-neutral-400' }, 'Noch keine Links geteilt.')); return; }
  const ul = h('ul', { class: 'space-y-0.5' });
  for (const l of d.links) {
    ul.append(h('li', {}, h('a', { href: l.url, target: '_blank', rel: 'noopener noreferrer nofollow ugc', class: 'flex items-center gap-3 rounded-xl px-2.5 py-2 hover:bg-slate-100 dark:hover:bg-neutral-800' },
      h('span', { class: 'flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-slate-100 text-xl text-slate-500 dark:bg-neutral-800' }, icon('link')),
      h('span', { class: 'min-w-0 flex-1' }, h('span', { class: 'block truncate text-sm font-medium' }, l.host.replace(/^www\./, '')), h('span', { class: 'block truncate text-xs text-slate-500 dark:text-neutral-400' }, l.url)))));
  }
  pane.append(ul);
}
