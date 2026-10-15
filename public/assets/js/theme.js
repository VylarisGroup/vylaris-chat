// Wird synchron im <head> geladen, damit beim Laden kein Aufblitzen entsteht.
(function () {
  var mode = 'system';
  try { mode = localStorage.getItem('theme') || 'system'; } catch (e) {}
  function apply() {
    var dark = mode === 'dark' || (mode === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
  }
  apply();
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    (mq.addEventListener ? mq.addEventListener.bind(mq, 'change') : mq.addListener.bind(mq))(function () { if (mode === 'system') apply(); });
  }
  window.__setTheme = function (m) {
    mode = m;
    try { localStorage.setItem('theme', m); } catch (e) {}
    apply();
  };
})();
