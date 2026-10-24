// Herkunftshinweis «powered by VylarisChat» am Fuss der Seitenleiste. Der Text kommt vom Server (Vorlage in der Seite),
// der Browser prüft ihn laufend und stellt ihn bei Veränderung oder Entfernung wieder her.
const URL_OK = 'https://vylaris.ch';
const TEXT_OK = 'powered by VylarisChat';

// Sichtbarkeit per CSSOM mit !important setzen (Inline-!important schlägt jedes Zusatz-Stylesheet; die CSP erlaubt nur CSSOM, kein style-Attribut)
const LOCK = { display: 'block', visibility: 'visible', opacity: '1', 'font-size': '11px', height: 'auto', 'max-height': 'none', overflow: 'visible', transform: 'none', 'clip-path': 'none', filter: 'none', 'pointer-events': 'auto' };
function lock(a) { for (const [k, v] of Object.entries(LOCK)) a.style.setProperty(k, v, 'important'); return a; }
const CANON = lock(document.createElement('a')).style.cssText;

function fresh() {
  const tpl = document.getElementById('vl-mark-tpl');
  const src = tpl && tpl.content && tpl.content.querySelector('a[data-vl-mark]');
  const a = src && src.getAttribute('href') === URL_OK && src.textContent === TEXT_OK ? src.cloneNode(true) : null;
  if (a) return lock(a);
  const n = document.createElement('a');
  n.className = 'vl-mark'; n.href = URL_OK; n.target = '_blank'; n.rel = 'noopener'; n.setAttribute('data-vl-mark', '');
  n.title = 'VylarisChat – vylaris.ch';
  n.append('powered by ', Object.assign(document.createElement('strong'), { textContent: 'VylarisChat' }));
  return lock(n);
}

function intact(host) {
  const a = host.querySelector(':scope > a[data-vl-mark]');
  if (!a || a !== host.lastElementChild) return false;
  if (a.getAttribute('href') !== URL_OK || a.textContent !== TEXT_OK) return false;
  if (a.style.cssText !== CANON || a.hidden || a.getAttribute('class') !== 'vl-mark') return false;
  const r = a.getBoundingClientRect();
  const cs = getComputedStyle(a);
  // In der Seitenleiste verborgen (Chat-Ansicht auf dem Handy) ist erlaubt, solange die Leiste selbst nicht sichtbar ist
  if (host.getBoundingClientRect().width === 0) return true;
  return r.width > 20 && r.height > 8 && cs.visibility === 'visible' && cs.display !== 'none' && parseFloat(cs.opacity) === 1 && cs.fontSize !== '0px';
}

export function mountMark(host) {
  let watch = null;
  const put = () => {
    if (!intact(host)) {
      host.querySelectorAll(':scope > a[data-vl-mark]').forEach((n) => n.remove());
      host.append(fresh());
    }
    const a = host.lastElementChild;
    if (watch) watch.disconnect();
    watch = new MutationObserver(put);
    if (a) watch.observe(a, { attributes: true, childList: true, characterData: true, subtree: true });
  };
  put();
  // Die Leiste selbst: nur Kinder beobachten (nicht die ganze Liste), dazu regelmässige Prüfung
  new MutationObserver(() => { if (host.lastElementChild?.hasAttribute('data-vl-mark') !== true) put(); }).observe(host, { childList: true });
  setInterval(put, 2000);
}
