// Zusammenarbeit: Umfragen, Termine, Threads, synchrone Entwürfe, Kalender-Abo.

import { api } from './api.js';
import { store } from './store.js';
import { renderRich } from './richtext.js';
import { h, icon, clear, userAvatar, timeOf, fullDate, dayLabel, relativeSeen, url, debounce, copyText } from './util.js';
import { meetingNode } from './meets.js';
import { toast, modal, confirmDialog, menu } from './ui.js';

const isGuest = () => store.me?.role === 'guest';
const canCreate = () => !isGuest();

// ================================================================== Umfragen

async function vote(m, optionIds) {
  try {
    const res = await api.post(`/api/polls/${m.poll.id}/vote`, { option_ids: optionIds });
    store.emit('message-update', res.message);
  } catch (e) { toast(e.message, 'error'); }
}

async function closePoll(m) {
  const ok = await confirmDialog({ title: 'Umfrage beenden?', text: 'Danach können keine Stimmen mehr abgegeben oder geändert werden.', confirmLabel: 'Beenden' });
  if (!ok) return;
  try {
    const res = await api.post(`/api/polls/${m.poll.id}/close`, {});
    store.emit('message-update', res.message);
  } catch (e) { toast(e.message, 'error'); }
}

export function pollNode(m) {
  const p = m.poll;
  if (!p) return h('p', { class: 'italic opacity-70' }, 'Umfrage nicht verfügbar.');
  const mine = new Set(p.options.filter((o) => o.mine).map((o) => o.id));
  const total = p.options.reduce((s, o) => s + o.votes, 0) || 0;
  const wrap = h('div', { class: 'w-full min-w-[15rem]', 'data-poll': p.id });
  wrap.append(
    h('div', { class: 'mb-1 flex items-center gap-1.5 text-[11px] font-semibold uppercase tracking-wide text-accent' }, icon('poll', 'text-base'), 'Umfrage'),
    h('p', { class: 'break-words text-sm font-semibold' }, p.question),
    h('p', { class: 'mb-2 mt-0.5 text-xs text-slate-500 dark:text-neutral-400' },
      [p.multiple ? 'Mehrfachauswahl' : 'Eine Antwort', p.anonymous ? 'anonym' : null,
        p.closed ? 'beendet' : (p.closes_at ? `endet ${fullDate(p.closes_at)}` : null)].filter(Boolean).join(' · ')));
  const list = h('div', { class: 'space-y-1.5', role: p.multiple ? 'group' : 'radiogroup', 'aria-label': p.question });
  for (const o of p.options) {
    const pct = total ? Math.round((o.votes / total) * 100) : 0;
    const chosen = mine.has(o.id);
    const btn = h('button', {
      type: 'button', disabled: p.closed || null,
      role: p.multiple ? 'checkbox' : 'radio', 'aria-checked': String(chosen),
      class: `relative block w-full overflow-hidden rounded-lg border px-3 py-2 text-left text-sm transition ${chosen ? 'border-accent' : 'border-slate-200 dark:border-neutral-600'} ${p.closed ? 'cursor-default' : 'hover:border-accent'}`,
      onclick: () => {
        let next;
        if (p.multiple) { next = new Set(mine); if (chosen) next.delete(o.id); else next.add(o.id); next = [...next]; }
        else next = chosen ? [] : [o.id];
        vote(m, next);
      },
    },
    h('span', { class: `absolute inset-y-0 left-0 ${chosen ? 'bg-accent/25' : 'bg-slate-200/70 dark:bg-neutral-600/50'}`, style: { width: `${pct}%` }, 'aria-hidden': 'true' }),
    h('span', { class: 'relative flex items-center gap-2' },
      icon(chosen ? (p.multiple ? 'checkbox-checked' : 'radio-circle-marked') : (p.multiple ? 'checkbox' : 'radio-circle'), `text-lg ${chosen ? 'text-accent' : 'opacity-50'}`),
      h('span', { class: 'min-w-0 flex-1 break-words' }, o.label),
      h('span', { class: 'shrink-0 text-xs font-semibold tabular-nums' }, `${o.votes}`, h('span', { class: 'font-normal opacity-60' }, ` · ${pct}%`))));
    list.append(btn);
    if (!p.anonymous && o.voters.length) {
      list.append(h('p', { class: 'truncate px-1 text-[11px] text-slate-500 dark:text-neutral-400', title: o.voters.join(', ') }, o.voters.join(', ') + (o.votes > o.voters.length ? ` +${o.votes - o.voters.length}` : '')));
    }
  }
  wrap.append(list);
  const canClose = !p.closed && (p.creator_id === store.me.id || ['admin', 'moderator'].includes(store.active?.role));
  wrap.append(h('div', { class: 'mt-2 flex items-center justify-between gap-2 text-xs text-slate-500 dark:text-neutral-400' },
    h('span', {}, `${p.total_voters} ${p.total_voters === 1 ? 'Person hat' : 'Personen haben'} abgestimmt`),
    canClose ? h('button', { class: 'font-medium text-accent hover:underline', type: 'button', onclick: () => closePoll(m) }, 'Beenden') : null));
  return wrap;
}

export function pollDialog(convId) {
  const q = h('input', { class: 'input', maxlength: '300', placeholder: 'Was möchtest du fragen?', required: true });
  const optBox = h('div', { class: 'space-y-2' });
  const mk = (v = '') => {
    const i = h('input', { class: 'input', maxlength: '100', placeholder: `Antwort ${optBox.children.length + 1}`, value: v, 'aria-label': `Antwort ${optBox.children.length + 1}` });
    const row = h('div', { class: 'flex gap-2' }, i, h('button', { class: 'btn-icon shrink-0', type: 'button', 'aria-label': 'Antwort entfernen', onclick: () => { if (optBox.children.length > 2) { row.remove(); relabel(); } } }, icon('x')));
    return row;
  };
  const relabel = () => { [...optBox.children].forEach((r, i) => { const inp = r.querySelector('input'); inp.placeholder = `Antwort ${i + 1}`; inp.setAttribute('aria-label', `Antwort ${i + 1}`); }); add.hidden = optBox.children.length >= 10; };
  const add = h('button', { class: 'btn btn-secondary btn-sm', type: 'button', onclick: () => { optBox.append(mk()); relabel(); optBox.lastChild.querySelector('input').focus(); } }, icon('plus', 'text-base'), 'Antwort hinzufügen');
  optBox.append(mk(), mk());
  const multi = h('input', { type: 'checkbox', class: 'h-4 w-4 rounded border-slate-300 text-accent' });
  const anon = h('input', { type: 'checkbox', class: 'h-4 w-4 rounded border-slate-300 text-accent' });
  const closes = h('input', { type: 'datetime-local', class: 'input' });
  const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
  const submit = h('button', { class: 'btn btn-primary', type: 'submit' }, 'Umfrage senden');
  const form = h('form', { class: 'space-y-4', novalidate: true },
    h('div', {}, h('label', { class: 'label' }, 'Frage'), q),
    h('div', {}, h('span', { class: 'label' }, 'Antworten (2–10)'), optBox, h('div', { class: 'mt-2' }, add)),
    h('label', { class: 'flex items-center gap-2 text-sm' }, multi, 'Mehrere Antworten erlauben'),
    h('label', { class: 'flex items-center gap-2 text-sm' }, anon, 'Anonym abstimmen (keine Namen sichtbar)'),
    h('div', {}, h('label', { class: 'label' }, 'Endet am (optional)'), closes),
    err, h('div', { class: 'flex justify-end gap-2' }, h('button', { class: 'btn btn-secondary', type: 'button', onclick: () => dlg.close() }, 'Abbrechen'), submit));
  const dlg = modal({ title: 'Umfrage erstellen', body: form });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.classList.add('hidden');
    submit.disabled = true;
    try {
      const options = [...optBox.querySelectorAll('input')].map((i) => i.value.trim()).filter(Boolean);
      const body = { question: q.value.trim(), options, multiple: multi.checked, anonymous: anon.checked };
      if (closes.value) body.closes_at = new Date(closes.value).toISOString();
      const res = await api.post(`/api/conversations/${convId}/polls`, body);
      dlg.close();
      store.emit('message-new', res.message);
    } catch (ex) { err.textContent = ex.message; err.classList.remove('hidden'); submit.disabled = false; }
  });
}

// ================================================================== Termine

const fmtDate = new Intl.DateTimeFormat('de-CH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
const fmtMon = new Intl.DateTimeFormat('de-CH', { month: 'short' });
const fmtTime = new Intl.DateTimeFormat('de-CH', { hour: '2-digit', minute: '2-digit' });

function eventDates(ev) {
  if (ev.all_day) {
    const s = new Date(`${ev.start_date}T00:00:00`);
    const e = new Date(`${ev.end_date || ev.start_date}T00:00:00`);
    return { start: s, end: e, text: ev.end_date && ev.end_date !== ev.start_date ? `${fmtDate.format(s)} – ${fmtDate.format(e)}` : fmtDate.format(s), time: 'Ganztägig' };
  }
  const s = new Date(ev.starts_at);
  const e = ev.ends_at ? new Date(ev.ends_at) : null;
  const same = e && s.toDateString() === e.toDateString();
  return {
    start: s, end: e, text: fmtDate.format(s),
    time: e ? (same ? `${fmtTime.format(s)} – ${fmtTime.format(e)}` : `${fmtTime.format(s)} – ${fmtDate.format(e)}, ${fmtTime.format(e)}`) : fmtTime.format(s),
  };
}

async function rsvp(m, status) {
  try {
    const res = await api.post(`/api/events/${m.event.id}/rsvp`, { status });
    store.emit('message-update', res.message);
  } catch (e) { toast(e.message, 'error'); }
}

async function cancelEvent(m) {
  const ok = await confirmDialog({ title: 'Termin absagen?', text: 'Der Termin bleibt sichtbar, wird aber als abgesagt markiert. Alle in der Unterhaltung erhalten einen Hinweis.', confirmLabel: 'Absagen', danger: true });
  if (!ok) return;
  try {
    const res = await api.post(`/api/events/${m.event.id}/cancel`, {});
    store.emit('message-update', res.message);
  } catch (e) { toast(e.message, 'error'); }
}

export function eventNode(m) {
  const ev = m.event;
  if (!ev) return h('p', { class: 'italic opacity-70' }, 'Termin nicht verfügbar.');
  const d = eventDates(ev);
  const mine = ev.mine;
  const own = ev.creator_id === store.me.id;
  const canManage = !ev.cancelled && (own || ['admin', 'moderator'].includes(store.active?.role));
  const date = h('div', { class: `flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-xl text-center ${ev.cancelled ? 'bg-slate-200 text-slate-500 dark:bg-neutral-700' : 'bg-accent/10 text-accent'}`, 'aria-hidden': 'true' },
    h('span', { class: 'text-[10px] font-semibold uppercase leading-none' }, fmtMon.format(d.start).replace('.', '')),
    h('span', { class: 'text-xl font-bold leading-tight' }, String(d.start.getDate())));
  const info = h('div', { class: 'min-w-0 flex-1' },
    h('p', { class: 'mb-0.5 flex items-center gap-1 text-[11px] font-semibold uppercase tracking-wide text-accent' }, icon('calendar-event', 'text-base'), ev.cancelled ? 'Termin abgesagt' : 'Termin'),
    h('p', { class: `break-words text-sm font-semibold ${ev.cancelled ? 'line-through opacity-70' : ''}` }, ev.title),
    h('p', { class: 'text-xs text-slate-600 dark:text-neutral-300' }, `${d.text} · ${d.time}`),
    ev.location ? h('p', { class: 'mt-0.5 flex items-start gap-1 text-xs text-slate-600 dark:text-neutral-300' }, icon('map-pin', 'mt-px text-sm'), h('span', { class: 'min-w-0 break-words' }, ev.location)) : null);
  const wrap = h('div', { class: 'w-full min-w-[15rem]', 'data-event': ev.id }, h('div', { class: 'flex gap-3' }, date, info));
  if (ev.description) wrap.append(h('div', { class: 'msg-text mt-2 text-xs text-slate-600 dark:text-neutral-300' }, renderRich(ev.description, { mentions: [], selfUsername: store.me.username, own: false })));
  if (!ev.cancelled) {
    const choices = [['yes', 'Zusage', 'check'], ['maybe', 'Vielleicht', 'question-mark'], ['no', 'Absage', 'x']];
    wrap.append(h('div', { class: 'mt-3 grid grid-cols-3 gap-1.5', role: 'group', 'aria-label': 'Antwort auf den Termin' },
      choices.map(([k, label, ic]) => h('button', {
        type: 'button', 'aria-pressed': String(mine === k),
        class: `flex flex-col items-center rounded-lg border px-1 py-1.5 text-xs transition ${mine === k ? 'border-accent bg-accent/10 font-semibold text-accent' : 'border-slate-200 hover:border-accent dark:border-neutral-600'}`,
        title: ev.names[k].length ? ev.names[k].join(', ') : label,
        onclick: () => rsvp(m, k),
      }, h('span', { class: 'flex items-center gap-1' }, icon(ic, 'text-sm'), label), h('span', { class: 'tabular-nums opacity-70' }, String(ev.counts[k]))))));
  }
  const links = h('div', { class: 'mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs' },
    h('a', { class: 'inline-flex items-center gap-1 font-medium text-accent hover:underline', href: url(`calendar/event/${ev.id}`), download: '' }, icon('calendar-plus', 'text-sm'), 'Zum Kalender (.ics)'),
    canManage ? h('button', { class: 'font-medium text-accent hover:underline', type: 'button', onclick: () => eventDialog(m.conversation_id, m) }, 'Bearbeiten') : null,
    canManage ? h('button', { class: 'font-medium text-red-600 hover:underline dark:text-red-400', type: 'button', onclick: () => cancelEvent(m) }, 'Absagen') : null);
  wrap.append(links);
  return wrap;
}

const pad = (n) => String(n).padStart(2, '0');
const toLocalInput = (iso) => { const d = new Date(iso); return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}T${pad(d.getHours())}:${pad(d.getMinutes())}`; };

export function eventDialog(convId, existing = null) {
  const ev = existing?.event || null;
  const title = h('input', { class: 'input', maxlength: '150', required: true, value: ev?.title || '', placeholder: 'z. B. Projekt-Kickoff' });
  const loc = h('input', { class: 'input', maxlength: '200', value: ev?.location || '', placeholder: 'Ort oder Link (optional)' });
  const desc = h('textarea', { class: 'input min-h-[4.5rem]', maxlength: '2000', placeholder: 'Beschreibung (optional)' }, ev?.description || '');
  const allDay = h('input', { type: 'checkbox', class: 'h-4 w-4 rounded border-slate-300 text-accent', checked: ev?.all_day || null });
  const soon = new Date(Date.now() + 86400000); soon.setMinutes(0, 0, 0);
  const soonEnd = new Date(soon.getTime() + 3600000);
  const startT = h('input', { type: 'datetime-local', class: 'input', value: ev && !ev.all_day ? toLocalInput(ev.starts_at) : toLocalInput(soon) });
  const endT = h('input', { type: 'datetime-local', class: 'input', value: ev && !ev.all_day && ev.ends_at ? toLocalInput(ev.ends_at) : toLocalInput(soonEnd) });
  const startD = h('input', { type: 'date', class: 'input', value: ev?.all_day ? ev.start_date : toLocalInput(soon).slice(0, 10) });
  const endD = h('input', { type: 'date', class: 'input', value: ev?.all_day ? (ev.end_date || ev.start_date) : toLocalInput(soon).slice(0, 10) });
  const timed = h('div', { class: 'grid gap-3 sm:grid-cols-2' }, h('div', {}, h('label', { class: 'label' }, 'Beginn'), startT), h('div', {}, h('label', { class: 'label' }, 'Ende'), endT));
  const days = h('div', { class: 'grid gap-3 sm:grid-cols-2' }, h('div', {}, h('label', { class: 'label' }, 'Von'), startD), h('div', {}, h('label', { class: 'label' }, 'Bis (einschliesslich)'), endD));
  const paint = () => { timed.hidden = allDay.checked; days.hidden = !allDay.checked; };
  allDay.addEventListener('change', paint);
  paint();
  const err = h('p', { class: 'hidden text-sm text-red-600', role: 'alert' });
  const submit = h('button', { class: 'btn btn-primary', type: 'submit' }, ev ? 'Speichern' : 'Termin senden');
  const form = h('form', { class: 'space-y-4', novalidate: true },
    h('div', {}, h('label', { class: 'label' }, 'Titel'), title),
    h('label', { class: 'flex items-center gap-2 text-sm' }, allDay, 'Ganztägig'), timed, days,
    h('div', {}, h('label', { class: 'label' }, 'Ort'), loc),
    h('div', {}, h('label', { class: 'label' }, 'Beschreibung'), desc),
    err, h('div', { class: 'flex justify-end gap-2' }, h('button', { class: 'btn btn-secondary', type: 'button', onclick: () => dlg.close() }, 'Abbrechen'), submit));
  const dlg = modal({ title: ev ? 'Termin bearbeiten' : 'Termin erstellen', body: form });
  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    err.classList.add('hidden');
    submit.disabled = true;
    const body = { title: title.value.trim(), location: loc.value.trim(), description: desc.value.trim(), all_day: allDay.checked };
    if (allDay.checked) { body.start_date = startD.value; body.end_date = endD.value || startD.value; }
    else {
      if (!startT.value) { err.textContent = 'Bitte einen Beginn angeben.'; err.classList.remove('hidden'); submit.disabled = false; return; }
      body.starts_at = new Date(startT.value).toISOString();
      body.ends_at = endT.value ? new Date(endT.value).toISOString() : '';
    }
    try {
      if (ev) {
        const res = await api.patch(`/api/events/${ev.id}`, body);
        dlg.close();
        store.emit('message-update', res.message);
      } else {
        const res = await api.post(`/api/conversations/${convId}/events`, body);
        dlg.close();
        store.emit('message-new', res.message);
      }
    } catch (ex) { err.textContent = ex.message; err.classList.remove('hidden'); submit.disabled = false; }
  });
}

// ================================================================== Threads

/** Zusammenfassung unter der Ausgangsnachricht: «3 Antworten». */
export function threadSummary(m, own) {
  const t = m.thread;
  if (!t || !t.count) return null;
  return h('button', {
    type: 'button', 'data-thread-summary': m.id,
    class: `mt-1 inline-flex max-w-full items-center gap-2 rounded-full border px-2.5 py-1 text-xs font-medium text-accent transition hover:bg-accent/10 ${own ? 'self-end' : 'self-start'} border-accent/30 bg-white dark:bg-neutral-800`,
    onclick: () => openThread(m.conversation_id, m.id),
    'aria-label': `${t.count} ${t.count === 1 ? 'Antwort' : 'Antworten'} im Thread anzeigen`,
  },
  h('span', { class: 'flex -space-x-1.5' }, (t.participants || []).slice(0, 3).map((u) => userAvatar(u, 'xs'))),
  icon('message-square-detail', 'text-base'),
  `${t.count} ${t.count === 1 ? 'Antwort' : 'Antworten'}`,
  t.last_at ? h('span', { class: 'font-normal text-slate-500 dark:text-neutral-400' }, relativeSeen(t.last_at)) : null);
}

const thread = { convId: null, rootId: null, root: null, replies: [], el: null, list: null, input: null, loading: false };

export function initThreads(container) {
  thread.el = container;
  store.thread = null;
  store.on('active', () => { if (store.thread && store.thread.convId !== store.activeId) closeThread(); });
}

export async function openThread(convId, messageId) {
  if (store.activeId !== convId) store.emit('open-conversation', { id: convId });
  thread.convId = convId;
  thread.rootId = null;
  thread.root = null;
  thread.replies = [];
  thread.loading = true;
  store.thread = { convId, rootId: messageId };
  store.detailsOpen = false;
  store.emit('details-toggle');
  store.emit('thread-toggle');
  renderThread();
  try {
    const res = await api.get(`/api/messages/${messageId}/thread`);
    thread.rootId = res.root.id;
    thread.root = res.root;
    thread.replies = res.replies;
    store.thread = { convId, rootId: res.root.id };
    thread.loading = false;
    renderThread(true);
    restoreThreadDraft();
  } catch (e) {
    toast(e.message, 'error');
    closeThread();
  }
}

export function closeThread() {
  flushThreadDraft();
  store.thread = null;
  thread.rootId = null;
  clear(thread.el);
  store.emit('thread-toggle');
}

const rootOf = () => thread.root;

function threadBubble(m, { root = false } = {}) {
  const own = m.sender?.id === store.me.id;
  const body = m.deleted ? h('p', { class: 'italic opacity-70' }, 'Diese Nachricht wurde gelöscht.')
    : h('div', {},
      m.kind === 'poll' ? pollNode(m) : m.kind === 'event' ? eventNode(m) : m.kind === 'meeting' ? meetingNode(m) : null,
      m.body && m.kind === 'text' ? h('div', { class: 'msg-text text-sm' }, renderRich(m.body, { mentions: m.mentions, selfUsername: store.me.username, own: false })) : null,
      (m.attachments || []).map((a) => h('a', { class: 'mt-1 flex items-center gap-1 text-xs text-accent underline', href: url(`files/${a.id}?download=1`) }, icon('paperclip', 'text-sm'), a.name)));
  const reacts = !m.deleted && m.reactions?.length
    ? h('div', { class: 'mt-1 flex flex-wrap gap-1' }, m.reactions.map((r) => h('span', { class: `rounded-full border px-1.5 py-0.5 text-xs ${r.mine ? 'border-accent bg-accent/10' : 'border-slate-200 dark:border-neutral-700'}`, title: r.names.join(', ') }, `${r.emoji} ${r.count}`)))
    : null;
  return h('div', { class: `flex gap-2 ${root ? '' : 'mt-3'}`, 'data-mid': m.id },
    userAvatar(m.sender || { display_name: '?' }, 'sm'),
    h('div', { class: 'min-w-0 flex-1' },
      h('div', { class: 'flex items-baseline gap-2' },
        h('span', { class: 'truncate text-sm font-semibold' }, own ? 'Du' : (m.sender?.display_name || 'Unbekannt')),
        h('time', { class: 'shrink-0 text-[11px] text-slate-400', title: fullDate(m.created_at) }, `${dayLabel(m.created_at)}, ${timeOf(m.created_at)}`)),
      body, reacts));
}

function renderThread(scroll = false) {
  const el = thread.el;
  clear(el);
  el.append(h('div', { class: 'flex h-16 shrink-0 items-center justify-between gap-2 border-b border-slate-200 px-4 dark:border-neutral-800' },
    h('div', { class: 'min-w-0' }, h('h2', { class: 'text-sm font-semibold' }, 'Thread'), store.active ? h('p', { class: 'truncate text-xs text-slate-500 dark:text-neutral-400' }, store.active.name) : null),
    h('button', { class: 'btn-icon', 'aria-label': 'Thread schliessen', onclick: closeThread }, icon('x'))));
  const list = h('div', { class: 'scroll-thin min-h-0 flex-1 overflow-y-auto px-4 py-4', id: 'thread-list', role: 'log', 'aria-live': 'polite', 'aria-label': 'Thread' });
  thread.list = list;
  el.append(list);
  if (thread.loading || !thread.root) {
    list.append(h('p', { class: 'text-sm text-slate-500' }, 'Wird geladen …'));
    return;
  }
  list.append(threadBubble(thread.root, { root: true }),
    h('div', { class: 'my-3 flex items-center gap-3 text-xs text-slate-400' }, h('span', { class: 'h-px flex-1 bg-slate-200 dark:bg-neutral-700' }),
      thread.replies.length ? `${thread.replies.length} ${thread.replies.length === 1 ? 'Antwort' : 'Antworten'}` : 'Noch keine Antworten', h('span', { class: 'h-px flex-1 bg-slate-200 dark:bg-neutral-700' })));
  for (const r of thread.replies) list.append(threadBubble(r));
  thread.input = h('textarea', {
    class: 'scroll-thin max-h-40 min-h-[44px] w-full resize-none bg-transparent px-3 py-3 text-sm outline-none placeholder:text-slate-400',
    rows: '1', placeholder: 'Im Thread antworten …', 'aria-label': 'Antwort im Thread', maxlength: '8000', id: 'thread-input',
    oninput: () => { thread.input.style.height = 'auto'; thread.input.style.height = `${Math.min(thread.input.scrollHeight, 160)}px`; saveThreadDraft(); },
    onkeydown: (e) => { if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); sendThread(); } },
  });
  el.append(h('div', { class: 'shrink-0 border-t border-slate-200 p-3 dark:border-neutral-800' },
    h('div', { class: 'flex items-end gap-2 rounded-2xl border border-slate-300 bg-white focus-within:border-accent focus-within:ring-2 focus-within:ring-accent/25 dark:border-neutral-700 dark:bg-neutral-800' },
      thread.input,
      h('button', { class: 'btn btn-primary m-1 h-9 w-9 shrink-0 rounded-xl p-0', type: 'button', 'aria-label': 'Antwort senden', onclick: sendThread }, icon('send', 'text-lg')))));
  if (scroll) { list.scrollTop = list.scrollHeight; }
}

let threadSending = false;
async function sendThread() {
  const text = thread.input?.value.trim();
  if (!text || threadSending || !thread.rootId) return;
  threadSending = true;
  try {
    const res = await api.post(`/api/conversations/${thread.convId}/messages`, { body: text, client_uid: crypto.randomUUID?.() || String(Date.now()), thread_root_id: thread.rootId });
    thread.input.value = '';
    thread.input.style.height = 'auto';
    queueDraft(thread.convId, thread.rootId, '', null, true);
    addThreadReply(res.message);
  } catch (e) { toast(e.message, 'error'); }
  threadSending = false;
  thread.input?.focus();
}

function addThreadReply(m) {
  if (!thread.rootId || m.thread_root_id !== thread.rootId) return;
  const i = thread.replies.findIndex((x) => x.id === m.id);
  if (i >= 0) thread.replies[i] = m; else thread.replies.push(m);
  thread.replies.sort((a, b) => a.id - b.id);
  const keep = thread.input?.value || '';
  const near = thread.list ? thread.list.scrollHeight - thread.list.scrollTop - thread.list.clientHeight < 160 : true;
  const hadFocus = document.activeElement === thread.input;
  renderThread(near);
  if (thread.input) { thread.input.value = keep; if (hadFocus) thread.input.focus(); }
}

/** Vom Poll aufgerufen: neue/geänderte Nachrichten, die zum geöffneten Thread gehören. Gibt true zurück, wenn behandelt. */
export function threadMessage(m) {
  if (!m.thread_root_id) {
    if (thread.rootId && m.id === thread.rootId) { thread.root = m; renderThreadKeep(); }
    return false;
  }
  if (thread.rootId === m.thread_root_id) addThreadReply(m);
  return true;
}

function renderThreadKeep() {
  const keep = thread.input?.value || '';
  const hadFocus = document.activeElement === thread.input;
  const top = thread.list?.scrollTop || 0;
  renderThread();
  if (thread.input) { thread.input.value = keep; if (hadFocus) thread.input.focus(); }
  if (thread.list) thread.list.scrollTop = top;
}

export const threadIsOpenFor = (rootId) => !!store.thread && store.thread.rootId === rootId && !document.hidden;

// ================================================================ Entwürfe

const DEVICE_KEY = 'messenger-device';
export const draftDevice = (() => {
  let id = '';
  try { id = sessionStorage.getItem(DEVICE_KEY) || ''; } catch { /* egal */ }
  if (!/^[A-Za-z0-9]{6,12}$/.test(id)) {
    id = Array.from(crypto.getRandomValues(new Uint8Array(10)), (b) => 'abcdefghijklmnopqrstuvwxyz0123456789'[b % 36]).join('');
    try { sessionStorage.setItem(DEVICE_KEY, id); } catch { /* egal */ }
  }
  return id;
})();

const pendingDrafts = new Map(); // key -> { convId, rootId, body, replyTo }
const draftKey = (c, r) => `${c}:${r || 0}`;
async function doFlush() {
  const items = [...pendingDrafts.values()];
  pendingDrafts.clear();
  for (const d of items) {
    try {
      await api.request('PUT', `/api/conversations/${d.convId}/draft`, { body: d.body, thread_root_id: d.rootId || 0, reply_to_id: d.replyTo || null, device: draftDevice });
      if (!d.rootId) { const cur = store.drafts.get(d.convId); if (cur) cur.synced = d.body; }
    } catch { /* beim nächsten Tastendruck erneut */ }
  }
}
const flushDrafts = debounce(doFlush, 900);

/** Entwurf an den Server melden (verzögert). immediate: sofort, z. B. nach dem Senden. */
export function queueDraft(convId, rootId, body, replyTo = null, immediate = false) {
  pendingDrafts.set(draftKey(convId, rootId), { convId, rootId, body, replyTo });
  if (immediate) { flushDrafts.cancel(); doFlush(); } else flushDrafts();
}

/** Beim Start: gespeicherte Entwürfe in den Speicher laden. */
export function loadDrafts(list) {
  for (const d of list || []) {
    if (d.thread_root_id) { store.threadDrafts.set(draftKey(d.conversation_id, d.thread_root_id), d.body); continue; }
    const cur = store.drafts.get(d.conversation_id) || {};
    cur.text = d.body;
    cur.synced = d.body;
    store.drafts.set(d.conversation_id, cur);
  }
}

/**
 * Entwürfe anderer Geräte übernehmen. convs = Unterhaltungen, für die sich etwas geändert hat; list = aktuelle Entwürfe darin.
 * Einträge, die fehlen, wurden gelöscht/gesendet. Der gerade bearbeitete Entwurf wird nie überschrieben.
 */
export function applyRemoteDrafts(list, convs, focusedText) {
  const seen = new Set();
  for (const d of list || []) {
    seen.add(draftKey(d.conversation_id, d.thread_root_id));
    if (d.device === draftDevice) continue;
    if (d.thread_root_id) {
      const k = draftKey(d.conversation_id, d.thread_root_id);
      if (store.thread && store.thread.rootId === d.thread_root_id && document.activeElement === thread.input) continue;
      store.threadDrafts.set(k, d.body);
      if (store.thread && store.thread.rootId === d.thread_root_id && thread.input && document.activeElement !== thread.input) thread.input.value = d.body;
      continue;
    }
    if (focusedText && focusedText(d.conversation_id)) continue;
    const cur = store.drafts.get(d.conversation_id) || {};
    cur.text = d.body;
    cur.synced = d.body;
    store.drafts.set(d.conversation_id, cur);
    store.emit('draft-remote', d.conversation_id);
  }
  for (const cid of convs || []) {
    if (!seen.has(draftKey(cid, 0)) && !(focusedText && focusedText(cid))) {
      const cur = store.drafts.get(cid);
      if (cur && cur.text && !pendingDrafts.has(draftKey(cid, 0))) { cur.text = ''; cur.synced = ''; store.emit('draft-remote', cid); }
    }
    for (const k of [...store.threadDrafts.keys()]) {
      if (k.startsWith(`${cid}:`) && !seen.has(k) && !pendingDrafts.has(k)) store.threadDrafts.delete(k);
    }
  }
}

function saveThreadDraft() {
  if (!thread.rootId) return;
  const v = thread.input?.value || '';
  store.threadDrafts.set(draftKey(thread.convId, thread.rootId), v);
  queueDraft(thread.convId, thread.rootId, v);
}
function restoreThreadDraft() {
  const v = store.threadDrafts.get(draftKey(thread.convId, thread.rootId)) || '';
  if (thread.input && v) { thread.input.value = v; thread.input.dispatchEvent(new Event('input')); }
}
function flushThreadDraft() { /* Entwurf wird bereits laufend synchronisiert */ }

// ======================================================= Kalender abonnieren

export async function calendarFeedSection(pane) {
  const box = h('div', { class: 'rounded-xl border border-slate-200 p-4 dark:border-neutral-700' });
  pane.append(h('h3', { class: 'mb-1 mt-6 text-sm font-semibold' }, 'Kalender abonnieren'),
    h('p', { class: 'mb-3 text-sm text-slate-600 dark:text-neutral-400' }, 'Alle Termine deiner Unterhaltungen als persönlicher Kalender-Feed für Outlook, Apple Kalender, Google Kalender und andere (nur lesen).'), box);
  let status = { enabled: false };
  try { status = await api.get('/api/me/calendar-feed'); } catch { /* egal */ }
  const paint = (shownUrl = null) => {
    clear(box);
    if (!status.enabled) {
      box.append(h('button', { class: 'btn btn-secondary btn-sm', type: 'button', onclick: enable }, icon('rss', 'text-base'), 'Kalender-Link erstellen'));
      return;
    }
    if (shownUrl) {
      const inp = h('input', { class: 'input font-mono text-xs', readonly: true, value: shownUrl, 'aria-label': 'Kalender-Link', onfocus: (e) => e.target.select() });
      box.append(h('div', { class: 'flex gap-2' }, inp, h('button', { class: 'btn btn-secondary btn-sm shrink-0', type: 'button', onclick: async () => toast((await copyText(shownUrl)) ? 'Link kopiert.' : 'Kopieren nicht möglich.', 'success', 2000) }, icon('copy', 'text-base'), 'Kopieren')),
        h('p', { class: 'mt-2 flex items-start gap-1.5 text-xs text-amber-700 dark:text-amber-300' }, icon('lock-alt', 'mt-px text-sm'), 'Der Link ist geheim: Wer ihn kennt, sieht Titel, Zeit und Ort deiner Termine. Er wird nur jetzt angezeigt. Teile ihn nicht.'));
    } else {
      box.append(h('p', { class: 'text-sm' }, 'Der Kalender-Link ist aktiv. Aus Sicherheitsgründen wird er nicht erneut angezeigt.'));
    }
    box.append(h('div', { class: 'mt-3 flex flex-wrap gap-2' },
      h('button', { class: 'btn btn-secondary btn-sm', type: 'button', onclick: enable }, icon('refresh', 'text-base'), 'Neuen Link erstellen'),
      h('button', { class: 'btn btn-secondary btn-sm !text-red-600 dark:!text-red-400', type: 'button', onclick: disable }, icon('block', 'text-base'), 'Abschalten')));
  };
  async function enable() {
    if (status.enabled) {
      const ok = await confirmDialog({ title: 'Neuen Link erstellen?', text: 'Der bisherige Link funktioniert danach nicht mehr. Abonnierte Kalender müssen mit dem neuen Link neu eingerichtet werden.', confirmLabel: 'Neuen Link erstellen' });
      if (!ok) return;
    }
    try { const r = await api.post('/api/me/calendar-feed', {}); status = { enabled: true }; paint(r.url); } catch (e) { toast(e.message, 'error'); }
  }
  async function disable() {
    const ok = await confirmDialog({ title: 'Kalender-Link abschalten?', text: 'Abonnierte Kalender zeigen danach keine Termine mehr an.', confirmLabel: 'Abschalten', danger: true });
    if (!ok) return;
    try { await api.del('/api/me/calendar-feed'); status = { enabled: false }; paint(); } catch (e) { toast(e.message, 'error'); }
  }
  paint();
}

export { canCreate };
