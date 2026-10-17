// Dialoge: neue Unterhaltung, Weiterleiten, Melden, Bildansicht, Profil, Einstellungen, Benachrichtigungen, Tastenkürzel.

import { api } from './api.js';
import { store } from './store.js';
import { h, icon, clear, userAvatar, convAvatar, humanSize, relativeSeen, presenceLabel, url, debounce, listTime, state } from './util.js';
import { toast, modal, openPopover, closeMenu } from './ui.js';
import { calendarFeedSection } from './collab.js';
import { pushAvailable, pushEnabledHere, enablePush, disablePush, pushHint } from './push.js';

// ------------------------------------------------------------ Formular-Helfer

export function field(label, control, hint) {
  const id = `f-${Math.random().toString(36).slice(2, 8)}`;
  control.id = id;
  return h('div', { class: 'mb-4' },
    h('label', { class: 'label', for: id }, label),
    control,
    hint ? h('p', { class: 'mt-1 text-xs text-slate-500 dark:text-neutral-400' }, hint) : null);
}

/** Ein/Aus-Schalter mit korrekter Semantik (role=switch). */
export function toggleRow(label, description, checked, onChange) {
  let on = !!checked;
  const id = `t-${Math.random().toString(36).slice(2, 8)}`;
  const btn = h('button', { type: 'button', role: 'switch', 'aria-checked': String(on), 'aria-labelledby': `${id}-l`, class: 'relative mt-0.5 inline-flex h-6 w-11 shrink-0 items-center rounded-full transition-colors focus:outline-none focus-visible:ring-2 focus-visible:ring-accent focus-visible:ring-offset-2 dark:focus-visible:ring-offset-neutral-900' });
  const knob = h('span', { class: 'inline-block h-5 w-5 transform rounded-full bg-white shadow transition-transform' });
  btn.append(knob);
  const paint = () => {
    btn.setAttribute('aria-checked', String(on));
    btn.classList.toggle('bg-accent', on);
    btn.classList.toggle('bg-slate-300', !on);
    btn.classList.toggle('dark:bg-neutral-600', !on);
    knob.classList.toggle('translate-x-5', on);
    knob.classList.toggle('translate-x-0.5', !on);
  };
  paint();
  btn.addEventListener('click', async () => {
    const prev = on;
    on = !on;
    paint();
    btn.disabled = true;
    try { await onChange(on); } catch (e) { on = prev; paint(); toast(e.message, 'error'); } finally { btn.disabled = false; }
  });
  return h('div', { class: 'flex items-start justify-between gap-4 py-3' },
    h('div', { class: 'min-w-0' },
      h('p', { id: `${id}-l`, class: 'text-sm font-medium' }, label),
      description ? h('p', { class: 'mt-0.5 text-xs text-slate-500 dark:text-neutral-400' }, description) : null),
    btn);
}

function busy(btn, on, label) {
  btn.disabled = on;
  if (label) btn.dataset.label ??= btn.textContent;
  if (label) btn.textContent = on ? label : btn.dataset.label;
}

function errorBox() {
  const box = h('div', { class: 'alert alert-error hidden', role: 'alert' });
  return {
    el: box,
    show(msg) { clear(box); box.append(icon('error-circle', 'mt-0.5 text-lg'), h('span', msg)); box.classList.remove('hidden'); },
    hide() { box.classList.add('hidden'); },
  };
}

// --------------------------------------------------------- Benutzer-Auswahl

/**
 * Durchsuchbare Benutzerliste. multi=true erlaubt Mehrfachauswahl.
 * Gibt { el, selected: Map<id, user>, focus() } zurück.
 */
export function userPicker({ multi = false, exclude = [], onChange, onPick } = {}) {
  const selected = new Map();
  const skip = new Set(exclude);
  const chips = h('div', { class: 'mb-2 flex flex-wrap gap-1.5' });
  const list = h('ul', { class: 'scroll-thin max-h-64 min-h-[8rem] divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-neutral-800 dark:border-neutral-700', role: 'listbox', 'aria-multiselectable': String(multi), 'aria-label': 'Benutzer' });
  const input = h('input', { type: 'search', class: 'input', placeholder: 'Name, Benutzername oder E-Mail suchen …', 'aria-label': 'Benutzer suchen', autocomplete: 'off' });
  let seq = 0;

  const renderChips = () => {
    clear(chips);
    for (const u of selected.values()) {
      chips.append(h('span', { class: 'inline-flex items-center gap-1 rounded-full bg-accent/10 py-1 pl-2.5 pr-1 text-xs font-medium text-accent' }, u.display_name,
        h('button', { type: 'button', class: 'flex h-4 w-4 items-center justify-center rounded-full hover:bg-accent/20', 'aria-label': `${u.display_name} entfernen`, onclick: () => { selected.delete(u.id); renderChips(); paintRows(); onChange?.(selected); } }, icon('x', 'text-sm'))));
    }
    chips.classList.toggle('hidden', !selected.size);
  };
  const paintRows = () => {
    for (const li of list.children) {
      const on = selected.has(Number(li.dataset.uid));
      li.setAttribute('aria-selected', String(on));
      li.querySelector('.pick-mark')?.classList.toggle('opacity-0', !on);
    }
  };
  const pick = (u) => {
    if (!multi) { onPick?.(u); return; }
    if (selected.has(u.id)) selected.delete(u.id); else selected.set(u.id, u);
    renderChips();
    paintRows();
    onChange?.(selected);
  };

  const load = async () => {
    const mine = ++seq;
    try {
      const res = await api.get(`/api/users?q=${encodeURIComponent(input.value.trim())}`);
      if (mine !== seq) return;
      clear(list);
      const users = res.users.filter((u) => !skip.has(u.id));
      if (!users.length) {
        list.append(h('li', { class: 'p-6 text-center text-sm text-slate-500 dark:text-neutral-400' }, 'Keine Benutzer gefunden.'));
        return;
      }
      for (const u of users) {
        list.append(h('li', { role: 'option', 'aria-selected': String(selected.has(u.id)), dataset: { uid: u.id } },
          h('button', { type: 'button', class: 'flex w-full items-center gap-3 px-3 py-2.5 text-left transition hover:bg-slate-50 focus:bg-slate-50 focus:outline-none dark:hover:bg-neutral-800 dark:focus:bg-neutral-800', onclick: () => pick(u) },
            userAvatar(u, 'md', true),
            h('span', { class: 'min-w-0 flex-1' },
              h('span', { class: 'block truncate text-sm font-medium' }, u.display_name),
              h('span', { class: 'block truncate text-xs text-slate-500 dark:text-neutral-400' }, `@${u.username}${u.status_message ? ` · ${u.status_message}` : ''}`)),
            multi ? icon('check-circle', `pick-mark text-xl text-accent ${selected.has(u.id) ? '' : 'opacity-0'}`) : null)));
      }
    } catch (e) {
      if (mine !== seq) return;
      clear(list);
      list.append(h('li', { class: 'p-6 text-center text-sm text-red-600' }, e.message));
    }
  };
  input.addEventListener('input', debounce(load, 250));
  clear(list);
  list.append(h('li', { class: 'p-3' }, h('div', { class: 'skeleton h-10 w-full' })));
  load();
  renderChips();
  return { el: h('div', {}, chips, input, h('div', { class: 'mt-2' }, list)), selected, focus: () => input.focus() };
}

// ------------------------------------------------------ Neue Unterhaltung

export function newChatDialog(initialTab = 'direct') {
  let tab = initialTab;
  const body = h('div');
  const err = errorBox();
  const footer = h('div', { class: 'contents' });
  const dlg = modal({ title: 'Neue Unterhaltung', body: [err.el, body], footer });

  const openConv = (conversation) => {
    if (conversation) store.upsertConversation(conversation);
    store.emit('conversations');
    dlg.close();
    store.emit('open-conversation', { id: conversation.id });
  };

  const tabs = h('div', { class: 'mb-4 flex gap-1 rounded-xl bg-slate-100 p-1 dark:bg-neutral-800', role: 'tablist' });
  const content = h('div');
  body.append(tabs, content);

  function render() {
    clear(tabs);
    clear(content);
    clear(footer);
    err.hide();
    for (const [key, label, ic] of [['direct', 'Einzelchat', 'user'], ['group', 'Gruppe', 'group']]) {
      tabs.append(h('button', {
        role: 'tab', 'aria-selected': String(tab === key), type: 'button',
        class: `flex flex-1 items-center justify-center gap-2 rounded-lg px-3 py-2 text-sm font-medium transition ${tab === key ? 'bg-white text-accent shadow-sm dark:bg-neutral-700' : 'text-slate-600 hover:text-slate-900 dark:text-neutral-400'}`,
        onclick: () => { tab = key; render(); },
      }, icon(ic, 'text-lg'), label));
    }
    if (tab === 'direct') {
      const picker = userPicker({
        onPick: async (u) => {
          try {
            const res = await api.post('/api/conversations/direct', { user_id: u.id });
            openConv(res.conversation);
          } catch (e) { err.show(e.message); }
        },
      });
      content.append(picker.el);
      picker.focus();
    } else {
      const name = h('input', { class: 'input', maxlength: '100', placeholder: 'z. B. Projekt Alpha', required: true });
      const desc = h('textarea', { class: 'input', rows: '2', maxlength: '500', placeholder: 'Worum geht es in dieser Gruppe? (optional)' });
      const picker = userPicker({ multi: true });
      const submit = h('button', { class: 'btn btn-primary', type: 'button' }, 'Gruppe erstellen');
      content.append(field('Gruppenname', name), field('Beschreibung', desc), h('p', { class: 'label' }, 'Mitglieder'), picker.el);
      footer.append(h('button', { class: 'btn btn-secondary', type: 'button', onclick: () => dlg.close() }, 'Abbrechen'), submit);
      submit.addEventListener('click', async () => {
        err.hide();
        if (name.value.trim().length < 2) { err.show('Bitte gib einen Gruppennamen mit mindestens 2 Zeichen an.'); name.focus(); return; }
        busy(submit, true, 'Wird erstellt …');
        try {
          const res = await api.post('/api/conversations/group', { name: name.value.trim(), description: desc.value.trim(), member_ids: [...picker.selected.keys()] });
          openConv(res.conversation);
        } catch (e) { err.show(e.message); busy(submit, false, 'x'); }
      });
    }
  }
  render();
}

// ------------------------------------------------------- Weiterleiten / Melden

export function forwardDialog(m) {
  const selected = new Set();
  const err = errorBox();
  const q = h('input', { type: 'search', class: 'input mb-3', placeholder: 'Unterhaltung suchen …', 'aria-label': 'Unterhaltung suchen' });
  const list = h('ul', { class: 'scroll-thin max-h-72 divide-y divide-slate-100 overflow-y-auto rounded-xl border border-slate-200 dark:divide-neutral-800 dark:border-neutral-700' });
  const send = h('button', { class: 'btn btn-primary', type: 'button', disabled: true }, 'Weiterleiten');
  const render = () => {
    clear(list);
    const term = q.value.trim().toLowerCase();
    const items = store.sorted().filter((c) => !term || c.name.toLowerCase().includes(term));
    if (!items.length) list.append(h('li', { class: 'p-6 text-center text-sm text-slate-500' }, 'Keine Unterhaltung gefunden.'));
    for (const c of items) {
      list.append(h('li', {},
        h('label', { class: 'flex cursor-pointer items-center gap-3 px-3 py-2.5 hover:bg-slate-50 dark:hover:bg-neutral-800' },
          h('input', { type: 'checkbox', class: 'h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent', checked: selected.has(c.id), onchange: (e) => { if (e.target.checked) selected.add(c.id); else selected.delete(c.id); send.disabled = !selected.size; } }),
          convAvatar(c, 'md', false),
          h('span', { class: 'min-w-0 flex-1 truncate text-sm font-medium' }, c.name))));
    }
  };
  q.addEventListener('input', render);
  render();
  const preview = m.body || (m.attachments?.length ? `Anhang: ${m.attachments[0].name}` : '');
  const dlg = modal({
    title: 'Nachricht weiterleiten',
    body: [err.el, h('blockquote', { class: 'mb-3 line-clamp-3 rounded-xl border-l-4 border-accent bg-slate-50 px-3 py-2 text-sm text-slate-600 dark:bg-neutral-800 dark:text-neutral-300' }, preview), q, list],
    footer: [h('button', { class: 'btn btn-secondary', type: 'button', onclick: () => dlg.close() }, 'Abbrechen'), send],
  });
  send.addEventListener('click', async () => {
    busy(send, true, 'Wird gesendet …');
    try {
      await api.post(`/api/messages/${m.id}/forward`, { conversation_ids: [...selected] });
      toast(selected.size > 1 ? 'Nachricht weitergeleitet.' : 'Nachricht weitergeleitet.', 'success', 2500);
      dlg.close();
    } catch (e) { err.show(e.message); busy(send, false, 'x'); }
  });
}

export function reportDialog(m) {
  const err = errorBox();
  const reason = h('textarea', { class: 'input', rows: '4', maxlength: '500', placeholder: 'Was stimmt an dieser Nachricht nicht?' });
  const send = h('button', { class: 'btn btn-danger', type: 'button' }, 'Melden');
  const dlg = modal({
    title: 'Nachricht melden',
    size: 'sm',
    body: [
      err.el,
      h('p', { class: 'mb-3 text-sm text-slate-600 dark:text-neutral-300' }, 'Die gemeldete Nachricht wird den Administratoren zur Prüfung vorgelegt. Sie sehen nur diese eine Nachricht, nicht die übrige Unterhaltung. Die Meldung wird protokolliert.'),
      field('Begründung', reason),
    ],
    footer: [h('button', { class: 'btn btn-secondary', type: 'button', onclick: () => dlg.close() }, 'Abbrechen'), send],
  });
  send.addEventListener('click', async () => {
    if (reason.value.trim().length < 3) { err.show('Bitte gib eine kurze Begründung an.'); reason.focus(); return; }
    busy(send, true, 'Wird gesendet …');
    try {
      await api.post(`/api/messages/${m.id}/report`, { reason: reason.value.trim() });
      toast('Danke, die Meldung wurde übermittelt.', 'success');
      dlg.close();
    } catch (e) { err.show(e.message); busy(send, false, 'x'); }
  });
}

// ------------------------------------------------------------- Bildansicht

export function lightbox(a) {
  const opener = document.activeElement;
  const close = () => {
    overlay.remove();
    document.removeEventListener('keydown', onKey, true);
    opener?.focus?.();
  };
  const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); close(); } };
  const closeBtn = h('button', { class: 'btn-icon text-white hover:bg-white/15 hover:text-white', 'aria-label': 'Schliessen', onclick: close }, icon('x', 'text-2xl'));
  const overlay = h('div', {
    class: 'fixed inset-0 z-[96] flex animate-fade-in flex-col bg-black/90', role: 'dialog', 'aria-modal': 'true', 'aria-label': `Bildansicht: ${a.name}`,
    onclick: (e) => { if (e.target === overlay || e.target.dataset.backdrop) close(); },
  },
  h('div', { class: 'flex items-center gap-3 px-4 py-3 text-white' },
    h('div', { class: 'min-w-0 flex-1' }, h('p', { class: 'truncate text-sm font-medium' }, a.name), h('p', { class: 'text-xs opacity-70' }, humanSize(a.size))),
    h('a', { class: 'btn-icon text-white hover:bg-white/15 hover:text-white', href: url(`files/${a.id}?download=1`), download: a.name, 'aria-label': 'Herunterladen', title: 'Herunterladen' }, icon('download', 'text-2xl')),
    closeBtn),
  h('div', { class: 'flex min-h-0 flex-1 items-center justify-center p-4', dataset: { backdrop: '1' } },
    h('img', { src: url(`files/${a.id}`), alt: a.name, class: 'max-h-full max-w-full rounded-lg object-contain shadow-pop' })));
  document.body.append(overlay);
  document.addEventListener('keydown', onKey, true);
  closeBtn.focus();
}

// ------------------------------------------------------------------ Profil

export function profileDialog(u) {
  const actions = [];
  const dlg = modal({
    title: 'Profil',
    size: 'sm',
    body: h('div', { class: 'flex flex-col items-center text-center' },
      userAvatar(u, 'xl', true),
      h('h3', { class: 'mt-3 text-lg font-semibold' }, u.display_name),
      h('p', { class: 'text-sm text-slate-500 dark:text-neutral-400' }, `@${u.username}`),
      u.status_message ? h('p', { class: 'mt-3 rounded-xl bg-slate-100 px-3 py-2 text-sm dark:bg-neutral-800' }, u.status_message) : null,
      h('dl', { class: 'mt-4 w-full space-y-2 text-left text-sm' },
        row('E-Mail', u.email), row('Status', presenceLabel(u.presence)),
        u.presence !== 'online' && u.last_seen_at ? row('Zuletzt aktiv', relativeSeen(u.last_seen_at)) : null,
        row('Rolle', u.role === 'admin' ? 'Administrator' : 'Mitglied'))),
    footer: u.id === store.me.id ? null : [
      h('button', {
        class: 'btn btn-primary', type: 'button',
        onclick: async () => {
          try {
            const res = await api.post('/api/conversations/direct', { user_id: u.id });
            store.upsertConversation(res.conversation);
            store.emit('conversations');
            dlg.close();
            store.emit('open-conversation', { id: res.conversation.id });
          } catch (e) { toast(e.message, 'error'); }
        },
      }, icon('message-rounded', 'text-lg'), 'Nachricht schreiben'),
      ...actions,
    ],
  });
}
function row(k, v) {
  return h('div', { class: 'flex justify-between gap-4 border-b border-slate-100 pb-2 dark:border-neutral-800' }, h('dt', { class: 'text-slate-500 dark:text-neutral-400' }, k), h('dd', { class: 'min-w-0 break-words text-right font-medium' }, v));
}

// ----------------------------------------------------------- Einstellungen

function saveSetting(patch) {
  return api.patch('/api/me/settings', patch).then((res) => { store.me.settings = res.settings; store.emit('settings'); return res; });
}

export function settingsDialog(startTab = 'profile') {
  const tabs = [
    ['profile', 'Profil', 'user'],
    ['notifications', 'Benachrichtigungen', 'bell'],
    ['privacy', 'Privatsphäre', 'lock-alt'],
    ['mydata', 'Meine Daten', 'data'],
    ['appearance', 'Darstellung', 'palette'],
    ['calendar', 'Kalender', 'calendar'],
    ['security', 'Sicherheit', 'shield-quarter'],
  ];
  let current = startTab;
  const nav = h('nav', { class: 'flex shrink-0 gap-1 overflow-x-auto sm:w-48 sm:flex-col', role: 'tablist', 'aria-label': 'Einstellungen' });
  const pane = h('div', { class: 'min-w-0 flex-1', role: 'tabpanel' });
  const dlg = modal({ title: 'Einstellungen', size: 'lg', body: h('div', { class: 'flex min-h-[26rem] flex-col gap-5 sm:flex-row' }, nav, pane) });

  const paint = () => {
    clear(nav);
    for (const [key, label, ic] of tabs) {
      nav.append(h('button', {
        role: 'tab', type: 'button', 'aria-selected': String(current === key),
        class: `flex shrink-0 items-center gap-2.5 rounded-xl px-3 py-2 text-left text-sm font-medium transition ${current === key ? 'bg-accent/10 text-accent' : 'text-slate-600 hover:bg-slate-100 dark:text-neutral-300 dark:hover:bg-neutral-800'}`,
        onclick: () => { current = key; paint(); },
      }, icon(ic, 'text-lg'), label));
    }
    clear(pane);
    ({ profile: profileTab, notifications: notificationsTab, privacy: privacyTab, mydata: myDataTab, appearance: appearanceTab, calendar: calendarTab, security: securityTab })[current](pane, dlg, (tab) => { current = tab; paint(); });
  };
  paint();
}

function profileTab(pane) {
  const me = store.me;
  const err = errorBox();
  const avatarHost = h('div');
  const renderAvatar = () => {
    clear(avatarHost);
    const file = h('input', { type: 'file', accept: 'image/png,image/jpeg,image/gif,image/webp', class: 'sr-only', 'aria-label': 'Profilbild auswählen' });
    file.addEventListener('change', async () => {
      if (!file.files[0]) return;
      try {
        const res = await api.upload('/api/me/avatar', file.files[0]);
        store.me = { ...store.me, ...res.me };
        store.emit('me-changed');
        renderAvatar();
        toast('Profilbild aktualisiert.', 'success', 2500);
      } catch (e) { err.show(e.message); }
    });
    avatarHost.append(h('div', { class: 'mb-5 flex items-center gap-4' },
      userAvatar({ ...store.me, presence: undefined }, 'xl'),
      h('div', { class: 'flex flex-wrap gap-2' },
        h('label', { class: 'btn btn-secondary btn-sm cursor-pointer focus-within:ring-2 focus-within:ring-accent' }, icon('image-add', 'text-base'), 'Bild ändern', file),
        store.me.has_avatar ? h('button', {
          class: 'btn btn-ghost btn-sm', type: 'button',
          onclick: async () => {
            try { const res = await api.del('/api/me/avatar'); store.me = { ...store.me, ...res.me }; store.emit('me-changed'); renderAvatar(); } catch (e) { err.show(e.message); }
          },
        }, 'Entfernen') : null)));
  };
  renderAvatar();
  const name = h('input', { class: 'input', value: me.display_name, maxlength: '80', autocomplete: 'name' });
  const status = h('input', { class: 'input', value: me.status_message || '', maxlength: '140', placeholder: 'z. B. In einem Meeting bis 14 Uhr' });
  const pres = h('select', { class: 'input' },
    ...[['auto', 'Automatisch (online, wenn aktiv)'], ['away', 'Abwesend'], ['invisible', 'Unsichtbar (erscheint offline)']].map(([v, l]) => h('option', { value: v, selected: me.presence_pref === v }, l)));
  const save = h('button', { class: 'btn btn-primary', type: 'button' }, 'Speichern');
  save.addEventListener('click', async () => {
    err.hide();
    busy(save, true, 'Wird gespeichert …');
    try {
      const res = await api.patch('/api/me', { display_name: name.value.trim(), status_message: status.value.trim(), presence_pref: pres.value });
      store.me = { ...store.me, ...res.me };
      store.emit('me-changed');
      toast('Profil gespeichert.', 'success', 2500);
    } catch (e) { err.show(e.message); } finally { busy(save, false, 'x'); }
  });
  pane.append(err.el, avatarHost,
    field('Anzeigename', name),
    h('div', { class: 'mb-4 text-sm text-slate-500 dark:text-neutral-400' }, `Benutzername: @${me.username} · E-Mail: ${me.email}`),
    field('Statusnachricht', status), field('Präsenz', pres), save);
}

function notificationsTab(pane) {
  const s = store.me.settings;
  const perm = 'Notification' in window ? Notification.permission : 'unsupported';
  const pushHost = h('div');
  if (store.push) {
    const paintPush = async () => {
      const on = await pushEnabledHere();
      clear(pushHost);
      pushHost.append(toggleRow(
        'Push-Benachrichtigungen auf diesem Gerät',
        pushAvailable()
          ? 'Hinweise auch bei geschlossener App – nur bei Direktnachrichten und Erwähnungen. Der Push-Dienst des Geräteherstellers sieht nur verschlüsselte Daten; der Nachrichtentext wird nie übertragen.'
          : (pushHint() || 'Auf diesem Gerät nicht verfügbar.'),
        on,
        async (v) => { if (!pushAvailable()) throw new Error('Nicht verfügbar.'); if (v) await enablePush(); else await disablePush(); },
      ));
    };
    paintPush();
  }
  pane.append(
    h('div', { class: 'divide-y divide-slate-100 dark:divide-neutral-800' },
      pushHost,
      toggleRow('Benachrichtigungen in der App', 'Hinweise in der Glocke, wenn dich jemand erwähnt oder in eine Gruppe einlädt.', s.notify_inapp, (v) => saveSetting({ notify_inapp: v })),
      toggleRow('Desktop-Benachrichtigungen', perm === 'unsupported' ? 'Dein Browser unterstützt keine Benachrichtigungen.' : perm === 'denied' ? 'Im Browser blockiert. Erlaube Benachrichtigungen in den Seiteneinstellungen des Browsers.' : 'Zeigt neue Nachrichten an, solange das Fenster geöffnet ist. Der Browser fragt dich um Erlaubnis.', s.notify_browser && perm === 'granted', async (v) => {
        if (v) {
          if (!('Notification' in window)) throw new Error('Dein Browser unterstützt keine Benachrichtigungen.');
          const p = await Notification.requestPermission();
          if (p !== 'granted') throw new Error('Die Erlaubnis wurde nicht erteilt.');
        }
        await saveSetting({ notify_browser: v });
      }),
      toggleRow('Ton bei neuen Nachrichten', 'Kurzer Hinweiston, wenn eine Nachricht in einer anderen Unterhaltung eintrifft.', s.notify_sound, (v) => saveSetting({ notify_sound: v })),
      toggleRow('E-Mail bei Erwähnungen', 'Nur wenn du gerade nicht aktiv bist. Die E-Mail enthält nie den Nachrichtentext.', s.notify_email_mentions, (v) => saveSetting({ notify_email_mentions: v })),
      toggleRow('E-Mail bei Direktnachrichten', 'Nur wenn du gerade nicht aktiv bist. Die E-Mail enthält nie den Nachrichtentext.', s.notify_email_direct, (v) => saveSetting({ notify_email_direct: v }))));
}

function privacyTab(pane) {
  const s = store.me.settings;
  pane.append(
    h('div', { class: 'divide-y divide-slate-100 dark:divide-neutral-800' },
      toggleRow('„Zuletzt gesehen“ anzeigen', 'Andere sehen, wann du zuletzt aktiv warst. Ausgeschaltet siehst du es bei anderen ebenfalls nicht.', s.show_last_seen, (v) => saveSetting({ show_last_seen: v })),
      toggleRow('Lesebestätigungen senden', 'Andere sehen, dass du ihre Nachrichten gelesen hast.', s.show_read_receipts, (v) => saveSetting({ show_read_receipts: v })),
      toggleRow('Mit Enter senden', 'Aus: Enter erzeugt einen Zeilenumbruch, gesendet wird mit Strg+Enter.', s.enter_sends, (v) => saveSetting({ enter_sends: v }))),
    h('div', { class: 'alert alert-info mt-5' }, icon('info-circle', 'mt-0.5 text-lg'),
      h('p', {}, 'Nachrichten werden verschlüsselt übertragen (HTTPS), liegen auf dem Server aber nicht Ende-zu-Ende-verschlüsselt. Administratoren haben keinen allgemeinen Zugriff auf private Unterhaltungen; eingesehen werden nur Nachrichten, die jemand meldet. Details und deine Rechte: ',
        h('a', { class: 'font-medium underline', href: url('privacy'), target: '_blank', rel: 'noopener' }, 'Datenschutzerklärung'), '.')));
}

// ------------------------------------------------- Meine Daten (Betroffenenrechte)

const REQUEST_TYPES = [
  ['rectification', 'Berichtigung unrichtiger Daten (z. B. E-Mail-Adresse)'],
  ['erasure', 'Löschung bestimmter Daten'],
  ['restriction', 'Einschränkung (Sperrung) der Bearbeitung'],
  ['objection', 'Widerspruch gegen eine Bearbeitung'],
  ['access', 'Ergänzende Auskunft (Herkunft, Empfänger, Aufbewahrung)'],
  ['portability', 'Übertragung meiner Daten an Dritte'],
  ['other', 'Anderes Anliegen zum Datenschutz'],
];

function myDataTab(pane, dlg, goto) {
  const sect = (title, ...children) => h('section', { class: 'mb-7' }, h('h3', { class: 'mb-2 text-sm font-semibold' }, title), ...children);
  const note = (text) => h('p', { class: 'mb-3 text-xs text-slate-600 dark:text-neutral-400' }, text);

  // 1. Download
  const dlErr = errorBox();
  const dlPw = h('input', { type: 'password', class: 'input', autocomplete: 'current-password', 'aria-label': 'Passwort zur Bestätigung' });
  const dlBtn = h('button', { class: 'btn btn-primary', type: 'button' }, icon('download', 'text-lg'), 'Export anfordern');
  const dlList = h('ul', { class: 'mt-3 space-y-2 text-sm' });
  let dlTimer = null;
  async function loadExports() {
    try {
      const res = await api.get('/api/me/exports');
      clear(dlList);
      let pending = false;
      for (const j of res.exports) {
        if (j.status === 'queued' || j.status === 'running') pending = true;
        const label = { queued: 'In Warteschlange …', running: 'Wird erstellt …', failed: j.error ? `Nicht verfügbar (${j.error})` : 'Fehlgeschlagen' }[j.status];
        dlList.append(h('li', { class: 'flex items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 dark:border-neutral-700' },
          icon(j.status === 'done' ? 'package' : 'time-five', 'text-xl text-slate-400'),
          h('div', { class: 'min-w-0 flex-1' },
            h('p', { class: 'truncate font-medium' }, `Export vom ${new Date(j.created_at).toLocaleString('de-CH')}`),
            h('p', { class: 'text-xs text-slate-500 dark:text-neutral-400' }, j.status === 'done' ? `${humanSize(j.size)} · verfügbar bis ${new Date(j.expires_at).toLocaleDateString('de-CH')}` : label)),
          j.status === 'done' ? h('button', {
            class: 'btn btn-secondary btn-sm', type: 'button',
            onclick: async () => { try { await api.download(`/api/me/exports/${j.id}/download`, null); } catch (e) { toast(e.message, 'error'); } },
          }, 'Herunterladen') : null));
      }
      clearTimeout(dlTimer);
      if (pending && dlList.isConnected) dlTimer = setTimeout(loadExports, 3000);
    } catch { /* optional */ }
  }
  dlBtn.addEventListener('click', async () => {
    dlErr.hide();
    if (!dlPw.value) { dlErr.show('Bitte gib zur Bestätigung dein Passwort ein.'); return; }
    dlBtn.disabled = true;
    try {
      await api.post('/api/me/export', { password: dlPw.value });
      dlPw.value = '';
      toast('Export wird im Hintergrund erstellt. Du kannst dieses Fenster schliessen und später zurückkehren.', 'success');
      loadExports();
    } catch (e) { dlErr.show(e.message); } finally { dlBtn.disabled = false; }
  });
  loadExports();

  // 2. Anfrage stellen
  const rqErr = errorBox();
  const rqType = h('select', { class: 'input' }, REQUEST_TYPES.map(([v, l]) => h('option', { value: v }, l)));
  const rqMsg = h('textarea', { class: 'input', rows: '3', maxlength: '2000', placeholder: 'Worum geht es? (z. B. welche Angabe falsch ist und wie sie lauten soll)' });
  const rqBtn = h('button', { class: 'btn btn-secondary', type: 'button' }, icon('send', 'text-lg'), 'Anfrage senden');
  const rqList = h('ul', { class: 'mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm dark:divide-neutral-800 dark:border-neutral-700' });
  const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString('de-CH') : '–');
  async function loadRequests() {
    try {
      const res = await api.get('/api/me/privacy-requests');
      clear(rqList);
      rqList.classList.toggle('hidden', !res.requests.length);
      for (const q of res.requests) {
        const done = q.status === 'completed' || q.status === 'rejected';
        rqList.append(h('li', { class: 'px-3 py-2.5' },
          h('div', { class: 'flex flex-wrap items-center gap-x-3 gap-y-1' },
            h('span', { class: 'font-medium' }, q.type_label),
            h('span', { class: `rounded-full px-2 py-0.5 text-[11px] font-semibold ${done ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-200'}` }, q.status_label),
            h('span', { class: 'text-xs text-slate-500 dark:text-neutral-400' }, `gestellt ${fmt(q.created_at)}${done ? '' : ` · Antwort bis ${fmt(q.due_at)}`}`)),
          q.response ? h('p', { class: 'mt-1 whitespace-pre-line break-words text-xs text-slate-600 dark:text-neutral-300' }, `Antwort: ${q.response}`) : null));
      }
    } catch { /* Liste ist optional */ }
  }
  rqBtn.addEventListener('click', async () => {
    rqErr.hide();
    rqBtn.disabled = true;
    try {
      await api.post('/api/me/privacy-requests', { type: rqType.value, message: rqMsg.value });
      rqMsg.value = '';
      toast('Anfrage gesendet. Du erhältst innert 30 Tagen eine Antwort.', 'success');
      loadRequests();
    } catch (e) { rqErr.show(e.message); } finally { rqBtn.disabled = false; }
  });
  rqList.classList.add('hidden');
  loadRequests();

  pane.append(
    ...(store.me.restricted ? [h('div', { class: 'alert alert-info mb-5' }, icon('lock-alt', 'mt-0.5 text-lg'), h('p', {}, 'Die Bearbeitung deiner Daten ist eingeschränkt: Du kannst lesen, deine Daten herunterladen und Anfragen stellen, aber nichts senden oder hochladen.'))] : []),
    h('p', { class: 'mb-5 text-sm text-slate-600 dark:text-neutral-300' }, 'Hier übst du deine Rechte nach dem Schweizer Datenschutzgesetz (DSG) und der DSGVO aus. Mehr dazu in der ',
      h('a', { class: 'font-medium text-accent underline', href: url('privacy'), target: '_blank', rel: 'noopener' }, 'Datenschutzerklärung'), '.'),
    sect('Auskunft und Datenherausgabe',
      note('Du erhältst eine ZIP-Datei mit allen zu dir gespeicherten Daten (JSON), deinen eigenen Nachrichten und deinen hochgeladenen Dateien. Nachrichten anderer Personen sind nicht enthalten.'),
      dlErr.el, field('Passwort zur Bestätigung', dlPw), dlBtn, dlList),
    sect('Berichtigung',
      note('Name, Statusnachricht und Profilbild änderst du selbst. Für andere Angaben (z. B. E-Mail-Adresse) stelle unten eine Anfrage.'),
      h('button', { class: 'btn btn-secondary btn-sm', type: 'button', onclick: () => goto('profile') }, icon('user', 'text-base'), 'Profil bearbeiten')),
    sect('Löschung, Einschränkung, Widerspruch und weitere Anliegen',
      note('Dein Anliegen geht an die Administration. Du erhältst innert 30 Tagen eine Antwort.'),
      rqErr.el, field('Art der Anfrage', rqType), field('Beschreibung', rqMsg), rqBtn, rqList),
    sect('Konto löschen',
      note('Das Löschen deines Kontos (mit Wahl, ob auch deine Nachrichten und Dateien gelöscht werden) findest du unter „Sicherheit“.'),
      h('button', { class: 'btn btn-secondary btn-sm', type: 'button', onclick: () => goto('security') }, icon('shield-quarter', 'text-base'), 'Zu „Sicherheit“')));
}

function appearanceTab(pane) {
  const cur = store.me.settings.theme || 'system';
  const group = h('div', { class: 'grid grid-cols-3 gap-3', role: 'radiogroup', 'aria-label': 'Farbschema' });
  const paint = (val) => {
    clear(group);
    for (const [v, l, ic] of [['system', 'System', 'desktop'], ['light', 'Hell', 'sun'], ['dark', 'Dunkel', 'moon']]) {
      group.append(h('button', {
        type: 'button', role: 'radio', 'aria-checked': String(val === v),
        class: `flex flex-col items-center gap-2 rounded-2xl border-2 px-3 py-4 text-sm font-medium transition ${val === v ? 'border-accent bg-accent/10 text-accent' : 'border-slate-200 hover:border-slate-300 dark:border-neutral-700 dark:hover:border-neutral-600'}`,
        onclick: async () => {
          window.__setTheme?.(v);
          paint(v);
          try { await saveSetting({ theme: v }); } catch (e) { toast(e.message, 'error'); }
        },
      }, icon(ic, 'text-3xl'), l));
    }
  };
  paint(cur);
  pane.append(h('p', { class: 'label' }, 'Farbschema'), group,
    h('p', { class: 'mt-4 text-xs text-slate-500 dark:text-neutral-400' }, 'Die Akzentfarbe wird vom Unternehmen festgelegt. Das Farbschema gilt für alle deine Geräte.'));
}

function calendarTab(pane) {
  pane.append(h('h3', { class: 'text-sm font-semibold' }, 'Termine'),
    h('p', { class: 'mt-1 text-sm text-slate-600 dark:text-neutral-400' }, 'Termine, die in Unterhaltungen erstellt werden, kannst du einzeln als .ics-Datei herunterladen (Knopf «Zum Kalender» am Termin) oder alle zusammen als Kalender abonnieren.'));
  calendarFeedSection(pane);
}

function describeAgent(ua = '') {
  const browser = /Edg\//.test(ua) ? 'Edge' : /OPR\//.test(ua) ? 'Opera' : /Firefox\//.test(ua) ? 'Firefox' : /Chrome\//.test(ua) ? 'Chrome' : /Safari\//.test(ua) ? 'Safari' : 'Browser';
  const os = /Windows/.test(ua) ? 'Windows' : /Android/.test(ua) ? 'Android' : /iPhone|iPad/.test(ua) ? 'iOS' : /Mac OS/.test(ua) ? 'macOS' : /Linux/.test(ua) ? 'Linux' : 'unbekanntes System';
  return `${browser} auf ${os}`;
}

function securityTab(pane, dlg) {
  const err = errorBox();
  const cur = h('input', { type: 'password', class: 'input', autocomplete: 'current-password' });
  const next = h('input', { type: 'password', class: 'input', autocomplete: 'new-password', minlength: '10' });
  const rep = h('input', { type: 'password', class: 'input', autocomplete: 'new-password' });
  const save = h('button', { class: 'btn btn-primary', type: 'button' }, 'Passwort ändern');
  save.addEventListener('click', async () => {
    err.hide();
    if (next.value !== rep.value) { err.show('Die neuen Passwörter stimmen nicht überein.'); return; }
    busy(save, true, 'Wird geändert …');
    try {
      await api.post('/api/me/password', { current: cur.value, new: next.value });
      cur.value = next.value = rep.value = '';
      toast('Passwort geändert. Andere Geräte wurden abgemeldet.', 'success');
      loadSessions();
    } catch (e) { err.show(e.message); } finally { busy(save, false, 'x'); }
  });

  const sessHost = h('ul', { class: 'divide-y divide-slate-100 rounded-xl border border-slate-200 dark:divide-neutral-800 dark:border-neutral-700' });
  const sessAll = h('button', { class: 'btn btn-secondary btn-sm', type: 'button' }, 'Alle anderen abmelden');
  async function loadSessions() {
    try {
      const res = await api.get('/api/me/sessions');
      clear(sessHost);
      sessAll.classList.toggle('hidden', res.sessions.length < 2);
      for (const s of res.sessions) {
        sessHost.append(h('li', { class: 'flex items-center gap-3 px-3 py-2.5' },
          icon(/Android|iPhone|iPad|Mobile/.test(s.user_agent || '') ? 'mobile' : 'laptop', 'text-2xl text-slate-400'),
          h('div', { class: 'min-w-0 flex-1' },
            h('p', { class: 'truncate text-sm font-medium' }, describeAgent(s.user_agent), s.current ? h('span', { class: 'ml-2 rounded-full bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300' }, 'Dieses Gerät') : null),
            h('p', { class: 'truncate text-xs text-slate-500 dark:text-neutral-400' }, `${s.ip} · zuletzt aktiv ${relativeSeen(s.last_activity_at)}`)),
          s.current ? null : h('button', {
            class: 'btn btn-ghost btn-sm', type: 'button',
            onclick: async () => { try { await api.del(`/api/me/sessions/${s.id}`); loadSessions(); } catch (e) { toast(e.message, 'error'); } },
          }, 'Abmelden')));
      }
    } catch (e) { clear(sessHost); sessHost.append(h('li', { class: 'p-4 text-sm text-red-600' }, e.message)); }
  }
  sessAll.addEventListener('click', async () => {
    try { await api.post('/api/me/sessions/revoke-others'); toast('Alle anderen Geräte wurden abgemeldet.', 'success'); loadSessions(); } catch (e) { toast(e.message, 'error'); }
  });
  clear(sessHost);
  sessHost.append(h('li', { class: 'p-3' }, h('div', { class: 'skeleton h-10 w-full' })));
  loadSessions();

  const tfaHost = h('div', { class: 'rounded-xl border border-slate-200 p-4 dark:border-neutral-700' }, h('div', { class: 'skeleton h-10 w-full' }));
  async function loadTfa() {
    try {
      const st = await api.get('/api/me/2fa');
      clear(tfaHost);
      if (!st.enabled) {
        tfaHost.append(
          h('p', { class: 'mb-3 text-sm text-slate-600 dark:text-neutral-300' }, 'Schütze dein Konto zusätzlich mit einem Code aus einer Authenticator-App.'),
          h('a', { class: 'btn btn-primary btn-sm', href: url('/security/2fa') }, icon('shield-alt-2', 'text-base'), 'Einrichten'));
        return;
      }
      const pw = h('input', { type: 'password', class: 'input mb-3', autocomplete: 'current-password', placeholder: 'Passwort zur Bestätigung', 'aria-label': 'Passwort zur Bestätigung' });
      const codes = h('div');
      const regen = h('button', { class: 'btn btn-secondary btn-sm', type: 'button' }, 'Neue Wiederherstellungscodes');
      const off = h('button', { class: 'btn btn-danger btn-sm', type: 'button' }, 'Deaktivieren');
      regen.addEventListener('click', async () => {
        try {
          const res = await api.post('/api/me/2fa/recovery', { password: pw.value });
          clear(codes);
          codes.append(h('p', { class: 'mb-1 mt-3 text-xs text-slate-500' }, 'Neue Codes (die alten sind ungültig). Nur jetzt sichtbar:'),
            h('ul', { class: 'grid grid-cols-2 gap-1 rounded-lg border border-slate-200 p-2 font-mono text-sm dark:border-neutral-700' }, res.codes.map((c) => h('li', {}, c))));
        } catch (e) { toast(e.message, 'error'); }
      });
      off.addEventListener('click', async () => {
        try { await api.post('/api/me/2fa/disable', { password: pw.value }); toast('Zwei-Faktor-Authentifizierung deaktiviert.', 'success'); loadTfa(); } catch (e) { toast(e.message, 'error'); }
      });
      tfaHost.append(
        h('p', { class: 'mb-3 flex items-center gap-2 text-sm' }, icon('check-shield', 'text-xl text-emerald-600'), `Aktiv · ${st.recovery_left} Wiederherstellungscodes übrig`),
        pw, h('div', { class: 'flex flex-wrap gap-2' }, regen, st.required ? null : off), codes);
    } catch (e) { clear(tfaHost); tfaHost.append(h('p', { class: 'text-sm text-red-600' }, e.message)); }
  }
  loadTfa();

  const del = h('button', { class: 'btn btn-danger btn-sm', type: 'button', onclick: () => deleteAccountDialog(dlg) }, icon('trash', 'text-base'), 'Konto löschen');
  pane.append(err.el,
    h('h3', { class: 'mb-3 text-sm font-semibold' }, 'Passwort ändern'),
    field('Aktuelles Passwort', cur), field('Neues Passwort', next, 'Mindestens 10 Zeichen, mit Buchstaben sowie einer Ziffer oder einem Sonderzeichen.'), field('Neues Passwort wiederholen', rep), save,
    h('h3', { class: 'mb-3 mt-8 text-sm font-semibold' }, 'Zwei-Faktor-Authentifizierung'), tfaHost,
    h('div', { class: 'mb-3 mt-8 flex items-center justify-between gap-2' }, h('h3', { class: 'text-sm font-semibold' }, 'Angemeldete Geräte'), sessAll),
    sessHost,
    h('div', { class: 'mt-8 rounded-xl border border-red-200 p-4 dark:border-red-900' },
      h('h3', { class: 'text-sm font-semibold text-red-700 dark:text-red-400' }, 'Konto löschen'),
      h('p', { class: 'mb-3 mt-1 text-xs text-slate-600 dark:text-neutral-400' }, 'Dein Profil wird anonymisiert, Sitzungen werden beendet. Du wählst, ob deine bisherigen Nachrichten und Dateien ebenfalls endgültig gelöscht werden oder für die Gesprächspartner als „Gelöschter Benutzer“ erhalten bleiben. Das lässt sich nicht rückgängig machen.'),
      del));
}

function deleteAccountDialog(parent) {
  const err = errorBox();
  const pw = h('input', { type: 'password', class: 'input', autocomplete: 'current-password' });
  const go = h('button', { class: 'btn btn-danger', type: 'button' }, 'Konto endgültig löschen');
  const erase = h('input', { type: 'checkbox', class: 'mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent' });
  const dlg = modal({
    title: 'Konto löschen', size: 'sm',
    body: [err.el, h('p', { class: 'mb-4 text-sm' }, 'Bestätige mit deinem Passwort, dass du dein Konto dauerhaft löschen möchtest.'), field('Passwort', pw),
      h('label', { class: 'mb-2 flex items-start gap-2 text-sm' }, erase,
        h('span', {}, 'Auch alle meine Nachrichten und Dateien endgültig löschen.',
          h('span', { class: 'mt-0.5 block text-xs text-slate-500 dark:text-neutral-400' }, 'Die Inhalte verschwinden dann auch bei deinen Gesprächspartnern. Ohne Haken bleiben sie ohne Namensbezug erhalten.')))],
    footer: [h('button', { class: 'btn btn-secondary', type: 'button', onclick: () => dlg.close() }, 'Abbrechen'), go],
  });
  go.addEventListener('click', async () => {
    busy(go, true, 'Wird gelöscht …');
    try {
      const res = await api.post('/api/me/delete', { password: pw.value, erase_content: erase.checked });
      parent?.close?.();
      location.href = res.redirect || url('login');
    } catch (e) { err.show(e.message); busy(go, false, 'x'); }
  });
}

// ------------------------------------------------- Benachrichtigungs-Panel

export async function notificationsPanel(anchor) {
  const list = h('ul', { class: 'scroll-thin max-h-96 divide-y divide-slate-100 overflow-y-auto dark:divide-neutral-700' }, h('li', { class: 'p-4' }, h('div', { class: 'skeleton h-10 w-full' })));
  const markAll = h('button', { class: 'btn btn-ghost btn-sm', type: 'button' }, 'Alle gelesen');
  const box = h('div', { class: 'w-[22rem] max-w-[calc(100vw-1rem)]' },
    h('div', { class: 'flex items-center justify-between border-b border-slate-200 px-4 py-2.5 dark:border-neutral-700' }, h('h2', { class: 'text-sm font-semibold' }, 'Benachrichtigungen'), markAll), list);
  openPopover(anchor, box, { align: 'left' });
  const typeIcon = { mention: 'at', invite: 'user-plus', added: 'group', message: 'message-rounded' };
  try {
    const res = await api.get('/api/notifications');
    store.notificationsUnread = res.unread;
    store.emit('notifications');
    clear(list);
    if (!res.notifications.length) list.append(h('li', { class: 'p-8 text-center text-sm text-slate-500 dark:text-neutral-400' }, icon('bell-off', 'mb-2 block text-3xl'), 'Keine Benachrichtigungen.'));
    for (const n of res.notifications) {
      list.append(h('li', {},
        h('button', {
          type: 'button', class: `flex w-full items-start gap-3 px-4 py-3 text-left transition hover:bg-slate-50 dark:hover:bg-neutral-700/60 ${n.read ? '' : 'bg-accent/5'}`,
          onclick: async () => {
            closeMenu();
            if (!n.read) { try { const r = await api.post('/api/notifications/read', { ids: [n.id] }); store.notificationsUnread = r.unread; store.emit('notifications'); } catch { /* egal */ } }
            if (n.conversation_id && store.conv(n.conversation_id)) store.emit('open-conversation', { id: n.conversation_id, around: n.message_id });
          },
        },
        h('span', { class: 'mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-accent/10 text-accent' }, icon(typeIcon[n.type] || 'bell', 'text-lg')),
        h('span', { class: 'min-w-0 flex-1' }, h('span', { class: 'block text-sm' }, n.text), h('span', { class: 'block text-xs text-slate-500 dark:text-neutral-400' }, listTime(n.created_at))),
        n.read ? null : h('span', { class: 'mt-2 h-2 w-2 shrink-0 rounded-full bg-accent', 'aria-label': 'ungelesen' }))));
    }
    markAll.addEventListener('click', async () => {
      try { const r = await api.post('/api/notifications/read', { all: true }); store.notificationsUnread = r.unread; store.emit('notifications'); closeMenu(); } catch (e) { toast(e.message, 'error'); }
    });
  } catch (e) {
    clear(list);
    list.append(h('li', { class: 'p-6 text-center text-sm text-red-600' }, e.message));
  }
}

// ---------------------------------------------------------- Tastenkürzel

export function shortcutsDialog() {
  const rows = [
    ['Strg + K', 'Suche öffnen'],
    ['Strg + Alt + N', 'Neue Unterhaltung'],
    ['Alt + ↑ / Alt + ↓', 'Vorherige / nächste Unterhaltung'],
    ['Enter', 'Nachricht senden (oder Strg + Enter, je nach Einstellung)'],
    ['Umschalt + Enter', 'Zeilenumbruch'],
    ['↑ (im leeren Eingabefeld)', 'Eigene letzte Nachricht bearbeiten'],
    ['@', 'Person erwähnen'],
    ['Esc', 'Dialog schliessen, Antwort oder Bearbeiten abbrechen'],
    ['?', 'Diese Übersicht'],
  ];
  modal({
    title: 'Tastenkürzel',
    body: h('dl', { class: 'divide-y divide-slate-100 text-sm dark:divide-neutral-800' },
      rows.map(([k, d]) => h('div', { class: 'flex items-center justify-between gap-4 py-2.5' },
        h('dd', { class: 'text-slate-600 dark:text-neutral-300' }, d),
        h('dt', { class: 'shrink-0' }, ...k.split(' + ').flatMap((p, i) => [i ? ' + ' : '', h('kbd', { class: 'rounded-md border border-slate-300 bg-slate-100 px-1.5 py-0.5 font-mono text-xs dark:border-neutral-600 dark:bg-neutral-800' }, p)]))))),
  });
}

export { state };
