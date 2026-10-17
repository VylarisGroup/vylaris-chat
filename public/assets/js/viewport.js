// PWA-Feinheiten für Touch-Geräte: Tastatur, Zoom und Seitenhöhe.
// - Sichtbare Höhe (visualViewport) wird als --vvh gesetzt, solange die Bildschirmtastatur offen ist. iOS verkleinert die Seite
//   dann nicht von selbst, sondern schiebt sie hoch und versteckt die Kopfzeile.
// - Bleibt die Seite nach dem Tippen herangezoomt, wird der Zoom zurückgesetzt.

import { store } from './store.js';

const root = document.documentElement;
const KEYBOARD_MIN = 120;
let keyboard = false;

const nearBottom = (list) => !!list && list.scrollHeight - list.scrollTop - list.clientHeight < 160;

function fit() {
  const vv = window.visualViewport;
  if (!vv) return;
  const shrunk = window.innerHeight - vv.height > KEYBOARD_MIN;
  const list = document.getElementById('message-list');
  const stick = shrunk !== keyboard && nearBottom(list);
  if (shrunk) {
    root.style.setProperty('--vvh', `${Math.round(vv.height)}px`);
    // iOS scrollt die ganze Seite nach oben, damit das Feld sichtbar bleibt. Das Layout reagiert selbst, also zurück an den Anfang.
    if (window.scrollY !== 0 || vv.offsetTop !== 0) window.scrollTo(0, 0);
  } else {
    root.style.removeProperty('--vvh');
  }
  keyboard = shrunk;
  if (stick && list) requestAnimationFrame(() => { list.scrollTop = list.scrollHeight; });
}

let resetting = false;
function resetZoom() {
  const vv = window.visualViewport;
  if (!vv || vv.scale <= 1.01 || resetting) return;
  const meta = document.querySelector('meta[name=viewport]');
  if (!meta) return;
  resetting = true;
  const original = meta.getAttribute('content');
  meta.setAttribute('content', `${original}, maximum-scale=1`);
  setTimeout(() => { meta.setAttribute('content', original); resetting = false; }, 350);
}

/**
 * Zurück-Geste/-Taste (Android, Browser) und iOS-Wischgeste: Aus einem Chat geht es zur Liste statt aus der App.
 * Gilt nur in der schmalen Ansicht, in der Liste und Chat nacheinander angezeigt werden.
 */
function initBackNav() {
  const narrow = () => !window.matchMedia('(min-width: 1024px)').matches;
  let internal = false;
  store.on('mobile-view', () => {
    if (!narrow()) return;
    if (store.mobileView === 'chat' && history.state?.view !== 'chat') {
      history.pushState({ view: 'chat' }, '');
    } else if (store.mobileView === 'list' && history.state?.view === 'chat' && !internal) {
      internal = true;
      history.back();
      setTimeout(() => { internal = false; }, 400);
    }
  });
  window.addEventListener('popstate', (e) => {
    if (internal) return;
    if (e.state?.meet) return; // Meeting-Overlay regelt das selbst (meets.js)
    if (narrow() && store.mobileView === 'chat' && e.state?.view !== 'chat') {
      internal = true;
      store.mobileView = 'list';
      store.emit('mobile-view');
      setTimeout(() => { internal = false; }, 100);
    }
  });
}

export function initViewport() {
  initBackNav();
  const vv = window.visualViewport;
  if (vv) {
    vv.addEventListener('resize', fit);
    vv.addEventListener('scroll', () => { if (keyboard && (window.scrollY !== 0 || vv.offsetTop !== 0)) window.scrollTo(0, 0); });
    fit();
  }
  document.addEventListener('focusout', (e) => {
    if (e.target && /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName)) setTimeout(resetZoom, 100);
  });
  // Tastatur-Taste «Fertig»/Wegtippen auf iOS: Seite nicht verschoben lassen
  window.addEventListener('orientationchange', () => setTimeout(fit, 300));
}
