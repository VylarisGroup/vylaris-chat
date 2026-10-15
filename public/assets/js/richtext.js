// Sichere Nachrichtendarstellung. Der Text wird NIE als HTML interpretiert: Es werden ausschliesslich DOM-Knoten
// erzeugt (Textknoten, <strong>, <em>, <code>, <a> mit geprüftem http(s)-Link, Erwähnungs-Chips).

import { h, icon } from './util.js';

const TOKEN_SRC = (
  [
    '(```[\\s\\S]+?```)', // 1 Codeblock
    '(`[^`\\n]+`)', // 2 Inline-Code
    '(\\*\\*[^*\\n]+?\\*\\*)', // 3 fett
    '(~~[^~\\n]+?~~)', // 4 durchgestrichen
    '((?<![\\p{L}\\p{N}_*])_[^_\\n]+?_(?![\\p{L}\\p{N}_]))', // 5 kursiv
    '(https?:\\/\\/[^\\s<>"\']+)', // 6 Link
    '((?<![\\p{L}\\p{N}_@.])@[A-Za-z0-9][A-Za-z0-9._-]{1,39})', // 7 Erwähnung
  ].join('|'));

const TRAILING = /[.,;:!?)\]}'"»]+$/;

/** Gültige http(s)-URL oder null. */
export function safeUrl(raw) {
  try {
    const u = new URL(raw);
    if (u.protocol !== 'http:' && u.protocol !== 'https:') return null;
    return u;
  } catch {
    return null;
  }
}

export function linkWarning(u) {
  if (u.username || u.password) return 'Die Adresse enthält Zugangsdaten: Vorsicht, das Ziel kann abweichen.';
  if (u.hostname.split('.').some((p) => p.startsWith('xn--'))) return 'Internationalisierter Domainname: prüfe das Ziel sorgfältig.';
  return '';
}

/**
 * @param {string} text
 * @param {{mentions?: {username:string,id:number}[], selfUsername?: string, own?: boolean}} opts
 */
export function renderRich(text, opts = {}, depth = 0) {
  const frag = document.createDocumentFragment();
  const mentionSet = new Set((opts.mentions || []).map((m) => m.username.toLowerCase()));
  let last = 0;
  // Eigene Regex-Instanz pro Aufruf: rekursive Aufrufe dürfen lastIndex der äusseren Schleife nicht verändern.
  const TOKEN = new RegExp(TOKEN_SRC, 'gu');
  let m;
  while ((m = TOKEN.exec(text)) !== null) {
    if (m.index > last) frag.append(text.slice(last, m.index));
    last = m.index + m[0].length;
    const tok = m[0];
    if (m[1]) {
      const code = tok.slice(3, -3).replace(/^\n/, '').replace(/\n$/, '');
      frag.append(h('pre', { class: 'my-1 overflow-x-auto rounded-lg bg-black/10 p-2.5 font-mono text-[13px] leading-snug dark:bg-white/10' }, h('code', code)));
    } else if (m[2]) {
      frag.append(h('code', { class: 'rounded bg-black/10 px-1 py-0.5 font-mono text-[0.9em] dark:bg-white/15' }, tok.slice(1, -1)));
    } else if (m[3] && depth < 2) {
      frag.append(h('strong', { class: 'font-semibold' }, renderRich(tok.slice(2, -2), opts, depth + 1)));
    } else if (m[4] && depth < 2) {
      frag.append(h('del', renderRich(tok.slice(2, -2), opts, depth + 1)));
    } else if (m[5] && depth < 2) {
      frag.append(h('em', renderRich(tok.slice(1, -1), opts, depth + 1)));
    } else if (m[6]) {
      let raw = tok;
      let tail = '';
      const t = raw.match(TRAILING);
      if (t) { tail = t[0]; raw = raw.slice(0, -tail.length); }
      const u = safeUrl(raw);
      if (u) {
        const shown = raw.length > 70 ? `${raw.slice(0, 67)}…` : raw;
        frag.append(h('a', { href: u.href, target: '_blank', rel: 'noopener noreferrer nofollow ugc', class: 'underline decoration-1 underline-offset-2 hover:opacity-80', title: u.href }, shown));
        if (tail) frag.append(tail);
      } else {
        frag.append(tok);
      }
    } else if (m[7]) {
      const name = tok.slice(1).toLowerCase();
      const known = mentionSet.has(name);
      const self = known && opts.selfUsername && name === opts.selfUsername.toLowerCase();
      frag.append(known
        ? h('span', { class: `rounded px-1 font-medium ${self ? 'bg-amber-300/80 text-amber-950' : opts.own ? 'bg-white/25 text-white' : 'bg-accent/15 text-accent'}`, dataset: { mention: name } }, tok)
        : tok);
    } else {
      frag.append(tok);
    }
  }
  if (last < text.length) frag.append(text.slice(last));
  return frag;
}

/** Erster Link einer Nachricht (für die sichere Vorschaukarte). */
export function firstLink(text) {
  const m = text.match(/https?:\/\/[^\s<>"']+/);
  if (!m) return null;
  return safeUrl(m[0].replace(TRAILING, ''));
}

/** Vorschaukarte ohne Abruf externer Inhalte: zeigt nur das tatsächliche Ziel (Host und Pfad). */
export function linkCard(u, isOwn) {
  const warn = linkWarning(u);
  const path = (u.pathname === '/' ? '' : u.pathname) + u.search;
  return h('a', {
    href: u.href, target: '_blank', rel: 'noopener noreferrer nofollow ugc',
    class: `mt-1.5 flex items-center gap-2.5 rounded-xl border px-3 py-2 text-left text-xs transition ${isOwn ? 'border-white/25 bg-white/10 hover:bg-white/20' : 'border-slate-200 bg-slate-50 hover:bg-slate-100 dark:border-neutral-700 dark:bg-neutral-800/60 dark:hover:bg-neutral-800'}`,
    title: u.href,
  },
  icon(warn ? 'error' : 'link-external', `text-base ${warn ? 'text-amber-500' : ''}`),
  h('span', { class: 'min-w-0' },
    h('span', { class: 'block truncate font-semibold' }, u.hostname.replace(/^www\./, '')),
    h('span', { class: 'block truncate opacity-70' }, warn || (path.length > 1 ? decodeSafe(path) : u.origin))));
}

function decodeSafe(s) {
  try { return decodeURIComponent(s); } catch { return s; }
}
