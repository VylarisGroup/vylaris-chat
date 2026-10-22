(() => {
  'use strict';
  const KEY = 'admin-nav-open';
  let saved = {};
  try { saved = JSON.parse(localStorage.getItem(KEY) || '{}') || {}; } catch (e) { saved = {}; }
  const persist = () => { try { localStorage.setItem(KEY, JSON.stringify(saved)); } catch (e) { /* optional */ } };
  document.querySelectorAll('details[data-nav-group]').forEach((d) => {
    const id = d.getAttribute('data-nav-group');
    // Die Gruppe der aktuellen Seite bleibt offen. Die übrigen sind beim ersten Besuch offen (damit neue Menüpunkte sichtbar sind)
    // und merken sich danach, ob man sie geschlossen hat.
    if (!d.hasAttribute('data-active') && saved[id] !== false) d.open = true;
    d.addEventListener('toggle', () => { saved[id] = d.open; persist(); });
  });
  // Mobil ist das ganze Menü eingeklappt, am Desktop immer offen.
  const menu = document.querySelector('details[data-admin-menu]');
  if (menu && window.matchMedia) {
    const mq = window.matchMedia('(min-width: 1024px)');
    const apply = () => { menu.open = mq.matches; };
    apply();
    (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(apply);
  }
})();
