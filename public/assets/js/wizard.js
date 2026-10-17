(() => {
  'use strict';
  const form = document.querySelector('form[data-wizard]');
  if (!form) return;
  const steps = [...form.querySelectorAll('[data-step]')];
  const dots = [...form.querySelectorAll('[data-dot]')];
  const prev = form.querySelector('[data-wizard-prev]');
  const next = form.querySelector('[data-wizard-next]');
  const submit = form.querySelector('[data-wizard-submit]');
  const counter = form.querySelector('[data-wizard-counter]');
  const list = form.querySelector('[data-review-list]');
  const baseAction = form.getAttribute('action');
  let i = 0;

  const val = (name) => {
    const els = [...form.querySelectorAll('[data-field="' + name + '"]')];
    if (!els.length) return '';
    if (els[0].type === 'radio') { const c = els.find((e) => e.checked); return c ? c.value : ''; }
    if (els[0].type === 'checkbox') return els[0].checked ? '1' : '';
    return els[0].value;
  };
  const visible = (el) => {
    const w = el.getAttribute('data-when');
    if (!w) return true;
    try { return Object.entries(JSON.parse(w)).every(([k, v]) => val(k) === v); } catch (e) { return true; }
  };
  // Felder, die gerade nicht gelten, werden nicht gesendet und nicht geprüft.
  const sync = () => {
    form.querySelectorAll('[data-when]').forEach((box) => {
      const on = visible(box);
      box.hidden = !on;
      box.querySelectorAll('input,select').forEach((f) => { f.disabled = !on; });
    });
    steps.forEach((s) => {
      const a = s.getAttribute('data-actions');
      if (!a) return;
      try {
        const map = JSON.parse(a); const k = Object.keys(map)[0];
        const target = map[k][val(k)];
        if (target) form.setAttribute('action', baseAction.replace(/admin\/.*$/, target));
      } catch (e) { /* Standardadresse bleibt */ }
    });
  };
  const usable = (s) => !s.hasAttribute('data-when') || visible(s);
  const review = () => {
    list.textContent = '';
    form.querySelectorAll('[data-field]').forEach((f) => {
      if (f.disabled || (f.type === 'radio' && !f.checked)) return;
      const row = document.createElement('div'); row.className = 'flex justify-between gap-4 p-3';
      const dt = document.createElement('dt'); dt.className = 'text-slate-500 dark:text-neutral-400';
      const dd = document.createElement('dd'); dd.className = 'min-w-0 break-words text-right font-medium';
      let v;
      if (f.type === 'checkbox') { dt.textContent = f.getAttribute('data-label'); v = f.checked ? 'Ja' : 'Nein'; }
      else if (f.type === 'radio') { dt.textContent = f.getAttribute('data-label'); v = f.getAttribute('data-text'); }
      else if (f.tagName === 'SELECT') { dt.textContent = f.getAttribute('data-label'); v = f.options[f.selectedIndex].text; }
      else { dt.textContent = f.getAttribute('data-label'); v = f.value === '' ? '–' : (f.hasAttribute('data-secret') ? '••••••••' : f.value); }
      dd.textContent = v; row.append(dt, dd); list.append(row);
    });
    // Mehrfachauswahl (Gruppen): gewählte Einträge in einer Zeile
    const groups = new Map();
    form.querySelectorAll('[data-checks]').forEach((c) => {
      if (c.disabled) return;
      const k = c.getAttribute('data-checks');
      if (!groups.has(k)) groups.set(k, []);
      if (c.checked) groups.get(k).push(c.getAttribute('data-text'));
    });
    groups.forEach((names, label) => {
      const row = document.createElement('div'); row.className = 'flex justify-between gap-4 p-3';
      const dt = document.createElement('dt'); dt.className = 'text-slate-500 dark:text-neutral-400'; dt.textContent = label;
      const dd = document.createElement('dd'); dd.className = 'min-w-0 break-words text-right font-medium'; dd.textContent = names.length ? names.join(', ') : '–';
      row.append(dt, dd); list.append(row);
    });
  };
  const show = (n) => {
    i = n; sync();
    steps.forEach((s, k) => { s.hidden = k !== i; });
    dots.forEach((d, k) => { d.classList.toggle('bg-accent', k <= i); d.classList.toggle('bg-slate-200', k > i); });
    const last = i === steps.length - 1;
    prev.classList.toggle('hidden', i === 0); next.classList.toggle('hidden', last); submit.classList.toggle('hidden', !last);
    counter.textContent = last ? 'Überprüfung' : 'Schritt ' + (i + 1) + ' von ' + (steps.length - 1);
    if (last) review();
    const h = steps[i].querySelector('h2'); if (h) { h.setAttribute('tabindex', '-1'); h.focus({ preventScroll: false }); }
  };
  const valid = () => {
    for (const f of steps[i].querySelectorAll('input,select')) {
      if (!f.disabled && !f.checkValidity()) { f.reportValidity(); return false; }
    }
    return true;
  };
  const move = (d) => { let k = i + d; while (k > 0 && k < steps.length - 1 && !usable(steps[k])) k += d; show(Math.max(0, Math.min(steps.length - 1, k))); };
  next.addEventListener('click', () => { if (valid()) move(1); });
  prev.addEventListener('click', () => move(-1));
  form.addEventListener('change', sync);
  form.addEventListener('keydown', (e) => {
    if (e.key === 'Enter' && e.target.tagName !== 'BUTTON' && i < steps.length - 1) { e.preventDefault(); if (valid()) move(1); }
  });
  form.addEventListener('submit', (e) => {
    // Beim Abschicken alle Schritte prüfen; bei einem Fehler zum betroffenen Schritt springen.
    for (let k = 0; k < steps.length - 1; k++) {
      for (const f of steps[k].querySelectorAll('input,select')) {
        if (!f.disabled && !f.checkValidity()) { e.preventDefault(); show(k); f.reportValidity(); return; }
      }
    }
  });
  form.removeAttribute('novalidate');
  form.classList.add('wizard-ready');
  show(0);
})();
