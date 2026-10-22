// Hilfsfunktionen: DOM-Erzeugung ohne innerHTML (XSS-sicher), Formatierung, Avatare.

export const state = { base: '', me: null };

/**
 * h('div', { class: 'x', onclick: fn }, 'Text', child, [children])
 * Alle Texte werden als Textknoten eingefügt, nie als HTML.
 */
export function h(tag, props, ...children) {
  const el = document.createElement(tag);
  if (props !== null && props !== undefined && (typeof props !== 'object' || props instanceof Node || Array.isArray(props))) {
    children.unshift(props);
    props = null;
  }
  for (const [k, v] of Object.entries(props || {})) {
    if (v === undefined || v === null || v === false) continue;
    if (k === 'class') el.className = v;
    else if (k === 'style') Object.assign(el.style, v);
    else if (k === 'dataset') Object.assign(el.dataset, v);
    else if (k === 'text') el.textContent = v;
    else if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
    else if (v === true) el.setAttribute(k, '');
    else el.setAttribute(k, String(v));
  }
  append(el, children);
  return el;
}

/** Langes Drücken (Touch, 500 ms) als Ersatz für das Kontextmenü, das iOS nicht auslöst. Scrollen oder Bewegen bricht ab. */
export function longPress(el, handler, ms = 500) {
  let timer = null; let x = 0; let y = 0; let fired = false;
  const cancel = () => { clearTimeout(timer); timer = null; };
  el.addEventListener('touchstart', (e) => {
    if (e.touches.length !== 1) return;
    fired = false; x = e.touches[0].clientX; y = e.touches[0].clientY;
    timer = setTimeout(() => { fired = true; handler({ x, y }); }, ms);
  }, { passive: true });
  el.addEventListener('touchmove', (e) => { const t = e.touches[0]; if (Math.abs(t.clientX - x) > 10 || Math.abs(t.clientY - y) > 10) cancel(); }, { passive: true });
  el.addEventListener('touchend', cancel, { passive: true });
  el.addEventListener('touchcancel', cancel, { passive: true });
  // Nach dem langen Drücken soll der anschliessende «Klick» die Unterhaltung nicht öffnen
  el.addEventListener('click', (e) => { if (fired) { e.preventDefault(); e.stopImmediatePropagation(); fired = false; } }, true);
}

export function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return el;
}

export function clear(el) {
  while (el.firstChild) el.removeChild(el.firstChild);
  return el;
}

export function icon(name, cls = '') {
  return h('i', { class: `bx bx-${name} ${cls}`.trim(), 'aria-hidden': 'true' });
}
export function iconSolid(name, cls = '') {
  return h('i', { class: `bx bxs-${name} ${cls}`.trim(), 'aria-hidden': 'true' });
}

export const url = (p = '') => `${state.base}/${String(p).replace(/^\//, '')}`;

// ------------------------------------------------------------- Zeit & Format

const tz = undefined; // Zeitzone des Browsers
const fmtTime = new Intl.DateTimeFormat('de-CH', { hour: '2-digit', minute: '2-digit', timeZone: tz });
const fmtDayLong = new Intl.DateTimeFormat('de-CH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: tz });
const fmtDayShort = new Intl.DateTimeFormat('de-CH', { day: '2-digit', month: '2-digit', year: '2-digit', timeZone: tz });
const fmtWeekday = new Intl.DateTimeFormat('de-CH', { weekday: 'short', timeZone: tz });
const fmtFull = new Intl.DateTimeFormat('de-CH', { dateStyle: 'medium', timeStyle: 'short', timeZone: tz });

export const dayKey = (iso) => {
  const d = new Date(iso);
  return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}`;
};
const startOfDay = (d) => new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime();

export function timeOf(iso) { return iso ? fmtTime.format(new Date(iso)) : ''; }
export function fullDate(iso) { return iso ? fmtFull.format(new Date(iso)) : ''; }

export function dayLabel(iso) {
  const d = new Date(iso);
  const diff = Math.round((startOfDay(new Date()) - startOfDay(d)) / 86400000);
  if (diff === 0) return 'Heute';
  if (diff === 1) return 'Gestern';
  return fmtDayLong.format(d);
}

/** Kurze Zeitangabe für die Unterhaltungsliste. */
export function listTime(iso) {
  if (!iso) return '';
  const d = new Date(iso);
  const diff = Math.round((startOfDay(new Date()) - startOfDay(d)) / 86400000);
  if (diff === 0) return fmtTime.format(d);
  if (diff === 1) return 'Gestern';
  if (diff < 7) return fmtWeekday.format(d);
  return fmtDayShort.format(d);
}

export function relativeSeen(iso) {
  if (!iso) return '';
  const sec = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000));
  if (sec < 60) return 'gerade eben';
  if (sec < 3600) return `vor ${Math.floor(sec / 60)} Min.`;
  const d = new Date(iso);
  const diff = Math.round((startOfDay(new Date()) - startOfDay(d)) / 86400000);
  if (diff === 0) return `heute um ${fmtTime.format(d)}`;
  if (diff === 1) return `gestern um ${fmtTime.format(d)}`;
  return `am ${fmtDayShort.format(d)}`;
}

export function humanSize(bytes) {
  const u = ['B', 'KB', 'MB', 'GB'];
  let i = 0;
  let v = bytes;
  while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
  return `${i === 0 ? v : v.toFixed(1)} ${u[i]}`;
}

export function uuid() {
  if (crypto.randomUUID) return crypto.randomUUID();
  const b = crypto.getRandomValues(new Uint8Array(16));
  b[6] = (b[6] & 0x0f) | 0x40; b[8] = (b[8] & 0x3f) | 0x80;
  const x = [...b].map((n) => n.toString(16).padStart(2, '0')).join('');
  return `${x.slice(0, 8)}-${x.slice(8, 12)}-${x.slice(12, 16)}-${x.slice(16, 20)}-${x.slice(20)}`;
}

export function debounce(fn, ms) {
  let t;
  const d = (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  d.cancel = () => clearTimeout(t);
  return d;
}

export function throttle(fn, ms) {
  let last = 0;
  return (...a) => { const n = Date.now(); if (n - last >= ms) { last = n; fn(...a); } };
}

// ------------------------------------------------------------------ Avatare

const COLORS = ['bg-rose-500', 'bg-orange-500', 'bg-amber-500', 'bg-emerald-500', 'bg-teal-500', 'bg-sky-500', 'bg-indigo-500', 'bg-violet-500', 'bg-fuchsia-500', 'bg-pink-500'];
const SIZES = {
  xs: 'h-6 w-6 text-[10px]',
  sm: 'h-8 w-8 text-xs',
  md: 'h-10 w-10 text-sm',
  lg: 'h-14 w-14 text-lg',
  xl: 'h-24 w-24 text-3xl',
};
const DOTS = { xs: 'h-2 w-2', sm: 'h-2.5 w-2.5', md: 'h-3 w-3', lg: 'h-3.5 w-3.5', xl: 'h-4 w-4' };

function hashStr(s) {
  let x = 0;
  for (let i = 0; i < s.length; i++) x = (x * 31 + s.charCodeAt(i)) >>> 0;
  return x;
}

export function initials(name) {
  const parts = String(name || '?').trim().split(/\s+/).filter(Boolean);
  const a = parts[0] ? [...parts[0]][0] : '?';
  const b = parts.length > 1 ? [...parts[parts.length - 1]][0] : '';
  return (a + b).toUpperCase();
}

/** Avatar für Benutzer ({id, display_name, has_avatar, avatar_v, presence}). */
export function userAvatar(u, size = 'md', showPresence = false) {
  const name = u?.display_name || u?.name || '?';
  const box = h('span', { class: `relative inline-flex shrink-0 ${SIZES[size]}` });
  if (u?.bot) {
    box.append(h('span', { class: `${SIZES[size]} inline-flex items-center justify-center rounded-full bg-violet-100 text-violet-700 dark:bg-violet-900/40 dark:text-violet-300`, 'aria-hidden': 'true' }, icon('bot', size === 'xl' ? 'text-5xl' : size === 'lg' ? 'text-2xl' : 'text-xl')));
    return box;
  }
  const inner = u?.has_avatar
    ? h('img', { src: url(`avatar/u/${u.id}?v=${u.avatar_v || ''}`), alt: '', loading: 'lazy', class: `${SIZES[size]} rounded-full object-cover` })
    : h('span', { class: `${SIZES[size]} ${COLORS[hashStr(name) % COLORS.length]} inline-flex items-center justify-center rounded-full font-semibold text-white`, 'aria-hidden': 'true' }, initials(name));
  box.append(inner);
  if (showPresence) box.append(presenceDot(u?.presence, size));
  return box;
}

export function groupAvatar(c, size = 'md') {
  const box = h('span', { class: `relative inline-flex shrink-0 ${SIZES[size]}` });
  box.append(c?.has_avatar
    ? h('img', { src: url(`avatar/c/${c.id}?v=${c.avatar_v || ''}`), alt: '', loading: 'lazy', class: `${SIZES[size]} rounded-2xl object-cover` })
    : h('span', { class: `${SIZES[size]} inline-flex items-center justify-center rounded-2xl bg-accent/15 text-accent`, 'aria-hidden': 'true' }, icon('group', size === 'xl' ? 'text-5xl' : size === 'lg' ? 'text-3xl' : 'text-xl')));
  return box;
}

/** Avatar einer Unterhaltung (Einzelchat: Gegenüber mit Präsenz, Gruppe: Gruppenbild). */
export function noteAvatar(size = 'md') {
  return h('span', { class: `relative inline-flex shrink-0 ${SIZES[size]}` },
    h('span', { class: `${SIZES[size]} inline-flex items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300`, 'aria-hidden': 'true' }, icon('bookmark', size === 'xl' ? 'text-5xl' : size === 'lg' ? 'text-2xl' : 'text-xl')));
}

export function convAvatar(c, size = 'md', showPresence = true) {
  if (c.type === 'notes') return noteAvatar(size);
  if (c.type === 'direct') return userAvatar(c.other ? { ...c.other } : { display_name: c.name }, size, showPresence && !!c.other);
  return groupAvatar(c, size);
}

export function presenceDot(presence, size = 'md') {
  const color = presence === 'online' ? 'bg-emerald-500' : presence === 'away' ? 'bg-amber-400' : 'bg-slate-400';
  const label = presence === 'online' ? 'Online' : presence === 'away' ? 'Abwesend' : 'Offline';
  return h('span', { class: `absolute -bottom-0.5 -right-0.5 ${DOTS[size]} rounded-full ${color} ring-2 ring-white dark:ring-neutral-900`, role: 'img', 'aria-label': label, title: label });
}

export const presenceLabel = (p) => (p === 'online' ? 'Online' : p === 'away' ? 'Abwesend' : 'Offline');

export function setTitle(unread, company) {
  document.title = `${unread > 0 ? `(${unread > 99 ? '99+' : unread}) ` : ''}${company}`;
}

export async function copyText(text) {
  try {
    await navigator.clipboard.writeText(text);
    return true;
  } catch {
    const ta = h('textarea', { style: { position: 'fixed', opacity: '0' } });
    ta.value = text;
    document.body.append(ta);
    ta.select();
    let ok = false;
    try { ok = document.execCommand('copy'); } catch { ok = false; }
    ta.remove();
    return ok;
  }
}
