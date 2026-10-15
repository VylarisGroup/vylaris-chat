// Wiederverwendbare Oberflächen-Bausteine: Toasts, Dialoge, Kontextmenüs, Emoji-Auswahl.

import { h, icon, clear } from './util.js';

// ------------------------------------------------------------------- Toasts

let toastHost = null;
export function toast(message, type = 'info', ms = 4500) {
  if (!toastHost) {
    toastHost = h('div', { class: 'pointer-events-none fixed inset-x-0 bottom-0 z-[100] flex flex-col items-center gap-2 p-4 sm:items-end', role: 'region', 'aria-label': 'Hinweise' });
    document.body.append(toastHost);
  }
  const cfg = {
    success: ['check-circle', 'text-emerald-500'],
    error: ['error-circle', 'text-red-500'],
    info: ['info-circle', 'text-accent'],
    warn: ['error', 'text-amber-500'],
  }[type] || ['info-circle', 'text-accent'];
  const el = h('div', {
    class: 'pointer-events-auto flex w-full max-w-sm animate-slide-up items-start gap-3 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm shadow-pop dark:border-neutral-700 dark:bg-neutral-800',
    role: type === 'error' ? 'alert' : 'status',
  },
  icon(cfg[0], `mt-0.5 text-xl ${cfg[1]}`),
  h('span', { class: 'min-w-0 flex-1 break-words' }, message),
  h('button', { class: 'btn-icon -mr-2 -mt-1 h-7 w-7 text-base', 'aria-label': 'Schliessen', onclick: () => dismiss() }, icon('x')));
  const dismiss = () => { el.remove(); };
  toastHost.append(el);
  while (toastHost.children.length > 4) toastHost.firstChild.remove();
  if (ms > 0) setTimeout(dismiss, ms);
  return dismiss;
}

// ------------------------------------------------------------------ Dialoge

const focusable = 'a[href],button:not([disabled]),textarea:not([disabled]),input:not([disabled]):not([type=hidden]),select:not([disabled]),[tabindex]:not([tabindex="-1"])';
const stack = [];

/**
 * modal({ title, body, footer, size: 'sm'|'md'|'lg', onClose })
 * Gibt { el, body, close } zurück. Esc schliesst, Fokus bleibt im Dialog und kehrt danach zurück.
 */
export function modal({ title, body, footer, size = 'md', onClose, label }) {
  const opener = document.activeElement;
  const widths = { sm: 'max-w-sm', md: 'max-w-lg', lg: 'max-w-2xl', xl: 'max-w-4xl' };
  const titleId = `dlg-${Math.random().toString(36).slice(2, 8)}`;
  const bodyEl = h('div', { class: 'scroll-thin min-h-0 flex-1 overflow-y-auto px-5 py-4' }, body);
  const panel = h('div', {
    class: `flex max-h-[92vh] w-full ${widths[size] || widths.md} animate-pop-in flex-col overflow-hidden rounded-2xl bg-white shadow-pop dark:bg-neutral-900`,
    role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': titleId, tabindex: '-1',
  },
  h('div', { class: 'flex items-center justify-between gap-3 border-b border-slate-200 px-5 py-3.5 dark:border-neutral-800' },
    h('h2', { id: titleId, class: 'text-base font-semibold tracking-tight' }, title || label || ''),
    h('button', { class: 'btn-icon -mr-2', 'aria-label': 'Schliessen', onclick: () => close() }, icon('x'))),
  bodyEl,
  footer ? h('div', { class: 'flex flex-wrap items-center justify-end gap-2 border-t border-slate-200 px-5 py-3 dark:border-neutral-800' }, footer) : null);
  const overlay = h('div', {
    class: 'fixed inset-0 z-[90] flex animate-fade-in items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4',
    onmousedown: (e) => { if (e.target === overlay) close(); },
  }, panel);
  let closed = false;
  const entry = { overlay };
  function close(result) {
    if (closed) return;
    closed = true;
    overlay.remove();
    stack.splice(stack.indexOf(entry), 1);
    document.removeEventListener('keydown', onKey, true);
    if (opener && opener.isConnected) opener.focus?.();
    onClose?.(result);
  }
  function onKey(e) {
    if (stack[stack.length - 1] !== entry) return;
    if (e.key === 'Escape') { e.stopPropagation(); e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') return;
    const items = [...panel.querySelectorAll(focusable)].filter((el) => el.offsetParent !== null || el === document.activeElement);
    if (!items.length) { e.preventDefault(); return; }
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  }
  stack.push(entry);
  document.addEventListener('keydown', onKey, true);
  document.body.append(overlay);
  const auto = panel.querySelector('[autofocus]') || panel.querySelector('input:not([type=hidden]),textarea,select');
  (auto || panel).focus();
  return { el: panel, body: bodyEl, close };
}

/** Bestätigungsdialog. Liefert true/false. */
export function confirmDialog({ title, text, confirmLabel = 'Bestätigen', danger = false, cancelLabel = 'Abbrechen' }) {
  return new Promise((resolve) => {
    let decided = false;
    const done = (v) => { decided = true; dlg.close(); resolve(v); };
    const dlg = modal({
      title,
      size: 'sm',
      body: h('p', { class: 'text-sm text-slate-600 dark:text-neutral-300' }, text),
      footer: [
        h('button', { class: 'btn btn-secondary', onclick: () => done(false) }, cancelLabel),
        h('button', { class: `btn ${danger ? 'btn-danger' : 'btn-primary'}`, autofocus: true, onclick: () => done(true) }, confirmLabel),
      ],
      onClose: () => { if (!decided) resolve(false); },
    });
  });
}

// --------------------------------------------------------------- Kontextmenü

let openMenu = null;
export function closeMenu() {
  if (openMenu) { openMenu.cleanup(); openMenu = null; }
}

/**
 * menu(anchor | {x,y}, [{label, icon, onClick, danger, checked, divider}])
 * Tastatur: Pfeile, Enter, Esc.
 */
export function menu(anchor, items, { align = 'right' } = {}) {
  closeMenu();
  const opener = document.activeElement;
  const list = h('div', { class: 'min-w-[200px] max-w-[280px] animate-pop-in overflow-hidden rounded-xl border border-slate-200 bg-white p-1 text-sm shadow-pop dark:border-neutral-700 dark:bg-neutral-800', role: 'menu' });
  for (const it of items) {
    if (!it) continue;
    if (it.divider) { list.append(h('div', { class: 'my-1 h-px bg-slate-200 dark:bg-neutral-700', role: 'separator' })); continue; }
    list.append(h('button', {
      role: it.checked !== undefined ? 'menuitemcheckbox' : 'menuitem',
      'aria-checked': it.checked !== undefined ? String(!!it.checked) : null,
      class: `flex w-full items-center gap-2.5 rounded-lg px-3 py-2 text-left transition hover:bg-slate-100 focus:bg-slate-100 focus:outline-none dark:hover:bg-neutral-700 dark:focus:bg-neutral-700 ${it.danger ? 'text-red-600 dark:text-red-400' : ''}`,
      onclick: () => { closeMenu(); it.onClick?.(); },
    }, it.icon ? icon(it.icon, 'text-lg opacity-80') : null, h('span', { class: 'flex-1 truncate' }, it.label), it.checked ? icon('check', 'text-accent text-lg') : null));
  }
  const wrap = h('div', { class: 'fixed z-[95]' }, list);
  document.body.append(wrap);
  const rect = anchor instanceof Element ? anchor.getBoundingClientRect() : { left: anchor.x, right: anchor.x, top: anchor.y, bottom: anchor.y, width: 0, height: 0 };
  const w = list.offsetWidth;
  const hgt = list.offsetHeight;
  let left = align === 'right' ? rect.right - w : rect.left;
  let top = rect.bottom + 6;
  if (top + hgt > window.innerHeight - 8) top = Math.max(8, rect.top - hgt - 6);
  left = Math.min(Math.max(8, left), window.innerWidth - w - 8);
  wrap.style.left = `${left}px`;
  wrap.style.top = `${top}px`;
  const onDown = (e) => { if (!wrap.contains(e.target)) closeMenu(); };
  const onKey = (e) => {
    const btns = [...list.querySelectorAll('button')];
    const i = btns.indexOf(document.activeElement);
    if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); closeMenu(); opener?.focus?.(); }
    else if (e.key === 'ArrowDown') { e.preventDefault(); btns[(i + 1) % btns.length]?.focus(); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); btns[(i - 1 + btns.length) % btns.length]?.focus(); }
    else if (e.key === 'Tab') { closeMenu(); }
  };
  setTimeout(() => document.addEventListener('mousedown', onDown, true), 0);
  document.addEventListener('keydown', onKey, true);
  window.addEventListener('resize', closeMenu);
  window.addEventListener('blur', closeMenu);
  list.querySelector('button')?.focus();
  openMenu = {
    cleanup() {
      document.removeEventListener('mousedown', onDown, true);
      document.removeEventListener('keydown', onKey, true);
      window.removeEventListener('resize', closeMenu);
      window.removeEventListener('blur', closeMenu);
      wrap.remove();
    },
  };
}

// ----------------------------------------------------------- Emoji-Auswahl

const EMOJI = {
  Smileys: '😀 😃 😄 😁 😆 😅 🤣 😂 🙂 😉 😊 😇 🥰 😍 🤩 😘 😋 😛 😜 🤪 🤗 🤔 🤨 😐 😑 😶 🙄 😏 😬 😌 😔 😴 😷 🤒 🥵 🥶 😎 🤓 😕 😟 🙁 😮 😲 😳 🥺 😢 😭 😱 😖 😞 😤 😡 😈 💀 🤡 👻 🤖',
  Gesten: '👍 👎 👌 ✌️ 🤞 🤟 🤘 🤙 👈 👉 👆 👇 ☝️ ✋ 🖐️ 🖖 👋 🤝 👏 🙌 🙏 💪 ✍️ 🤳',
  Herzen: '❤️ 🧡 💛 💚 💙 💜 🖤 🤍 💔 ❣️ 💕 💞 💓 💗 💖 💘 💝',
  Objekte: '🎉 🎊 🎁 🏆 🥇 ⭐ 🌟 ✨ 🔥 💡 📌 📎 📅 📁 📝 📊 📈 💻 📱 ☕ 🍕 🍰 🍻 🚀 ⏰ 🔒 🔑 ✅ ❌ ⚠️ ❓ ❗ 💯',
};

export function emojiPicker(anchor, onPick) {
  const grid = h('div', { class: 'scroll-thin max-h-64 w-72 overflow-y-auto p-2', role: 'listbox' });
  for (const [group, chars] of Object.entries(EMOJI)) {
    grid.append(h('div', { class: 'px-1 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wide text-slate-400' }, group));
    const row = h('div', { class: 'grid grid-cols-8 gap-0.5' });
    for (const ch of chars.split(' ')) {
      row.append(h('button', { class: 'flex h-8 w-8 items-center justify-center rounded-lg text-xl transition hover:bg-slate-100 dark:hover:bg-neutral-700', 'aria-label': ch, onclick: () => { closeMenu(); onPick(ch); } }, ch));
    }
    grid.append(row);
  }
  openPopover(anchor, grid);
}

/** Beliebiger Inhalt im Popover (gleiche Schliess-Logik wie Menüs). */
export function openPopover(anchor, content, { align = 'left' } = {}) {
  closeMenu();
  const box = h('div', { class: 'animate-pop-in overflow-hidden rounded-xl border border-slate-200 bg-white shadow-pop dark:border-neutral-700 dark:bg-neutral-800' }, content);
  const wrap = h('div', { class: 'fixed z-[95]' }, box);
  document.body.append(wrap);
  const rect = anchor.getBoundingClientRect();
  const w = box.offsetWidth;
  const hgt = box.offsetHeight;
  let left = align === 'right' ? rect.right - w : rect.left;
  let top = rect.top - hgt - 8;
  if (top < 8) top = rect.bottom + 8;
  left = Math.min(Math.max(8, left), window.innerWidth - w - 8);
  wrap.style.left = `${left}px`;
  wrap.style.top = `${top}px`;
  const onDown = (e) => { if (!wrap.contains(e.target)) closeMenu(); };
  const onKey = (e) => { if (e.key === 'Escape') { e.stopPropagation(); closeMenu(); anchor.focus?.(); } };
  setTimeout(() => document.addEventListener('mousedown', onDown, true), 0);
  document.addEventListener('keydown', onKey, true);
  openMenu = {
    cleanup() {
      document.removeEventListener('mousedown', onDown, true);
      document.removeEventListener('keydown', onKey, true);
      wrap.remove();
    },
  };
  return wrap;
}

export function skeletonList(n = 6) {
  const box = h('div', { class: 'space-y-1 p-2', 'aria-hidden': 'true' });
  for (let i = 0; i < n; i++) {
    box.append(h('div', { class: 'flex items-center gap-3 rounded-xl px-2 py-2.5' },
      h('div', { class: 'skeleton h-10 w-10 shrink-0 rounded-full' }),
      h('div', { class: 'min-w-0 flex-1 space-y-2' }, h('div', { class: 'skeleton h-3 w-2/5' }), h('div', { class: 'skeleton h-3 w-4/5' }))));
  }
  return box;
}

export function skeletonMessages() {
  const box = h('div', { class: 'space-y-4 p-6', 'aria-hidden': 'true' });
  [['w-56', false], ['w-72', false], ['w-40', true], ['w-64', false], ['w-48', true]].forEach(([w, own]) => {
    box.append(h('div', { class: `flex ${own ? 'justify-end' : 'justify-start'}` }, h('div', { class: `skeleton h-12 ${w} rounded-2xl` })));
  });
  return box;
}

export { clear };
