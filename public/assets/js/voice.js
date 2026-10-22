// Sprachnachrichten: Aufnahme (MediaRecorder) und Player in der Nachricht.
import { h, icon, url, uuid } from './util.js';
import { toast } from './ui.js';

const MIME_CANDIDATES = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4;codecs=mp4a.40.2', 'audio/mp4'];

export function voiceSupported() {
  return !!(window.isSecureContext && navigator.mediaDevices?.getUserMedia && window.MediaRecorder);
}

function pickMime() {
  return MIME_CANDIDATES.find((m) => { try { return MediaRecorder.isTypeSupported(m); } catch { return false; } }) || '';
}

export const fmtDur = (sec) => {
  const s = Math.max(0, Math.round(sec));
  return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
};

// ------------------------------------------------------------------ Aufnahme

let active = null; // laufende Aufnahme

export function voiceRecording() { return !!active; }
export function cancelVoice() { if (active) active.cancel(); }

/**
 * Startet eine Aufnahme und liefert die Bedienleiste. onFinish(file, durationMs) bei «Senden», onClose() immer am Ende.
 * @returns {Promise<HTMLElement|null>} null, wenn das Mikrofon nicht freigegeben wurde
 */
export async function startVoice({ maxSeconds, onFinish, onClose }) {
  if (active) return null;
  let stream;
  try {
    stream = await navigator.mediaDevices.getUserMedia({ audio: { echoCancellation: true, noiseSuppression: true } });
  } catch (e) {
    const denied = e && (e.name === 'NotAllowedError' || e.name === 'SecurityError');
    toast(denied ? 'Kein Zugriff auf das Mikrofon. Bitte erlaube es in den Browser-Einstellungen für diese Seite.' : 'Es wurde kein Mikrofon gefunden.', 'error', 6000);
    return null;
  }
  const mime = pickMime();
  let rec;
  try { rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined); } catch {
    stream.getTracks().forEach((t) => t.stop());
    toast('Dieser Browser kann keine Sprachnachrichten aufnehmen.', 'error');
    return null;
  }
  const chunks = [];
  let startedAt = 0; let timer = 0; let raf = 0; let ctx = null; let finished = false; let send = false;
  const maxMs = maxSeconds * 1000;

  const label = h('span', { class: 'w-24 shrink-0 text-sm font-medium tabular-nums', 'aria-live': 'off' }, '0:00');
  const bars = Array.from({ length: 28 }, () => h('span', { class: 'block w-1 shrink-0 rounded-full bg-accent/70', style: { height: '4px' } }));
  const meter = h('div', { class: 'flex h-8 min-w-0 flex-1 items-center justify-center gap-[3px] overflow-hidden', 'aria-hidden': 'true' }, bars);
  const cancelBtn = h('button', { type: 'button', class: 'btn-icon h-10 w-10 text-xl text-red-600', 'aria-label': 'Aufnahme verwerfen', title: 'Verwerfen', onclick: () => cancel() }, icon('trash'));
  const sendBtn = h('button', { type: 'button', class: 'btn btn-primary h-10 w-10 shrink-0 rounded-xl p-0', 'aria-label': 'Sprachnachricht senden', title: 'Senden', onclick: () => stop(true) }, icon('send', 'text-xl'));
  const bar = h('div', { class: 'flex items-center gap-2 px-2 py-2', role: 'group', 'aria-label': 'Sprachnachricht aufnehmen', dataset: { voiceBar: '1' } },
    cancelBtn, h('span', { class: 'h-2.5 w-2.5 shrink-0 animate-pulse rounded-full bg-red-500', 'aria-hidden': 'true' }), label, meter, sendBtn);

  // Pegelanzeige
  try {
    const AC = window.AudioContext || window.webkitAudioContext;
    ctx = new AC();
    const an = ctx.createAnalyser(); an.fftSize = 256;
    ctx.createMediaStreamSource(stream).connect(an);
    const buf = new Uint8Array(an.fftSize);
    let i = 0;
    const tick = () => {
      an.getByteTimeDomainData(buf);
      let sum = 0; for (const v of buf) { const d = (v - 128) / 128; sum += d * d; }
      const lvl = Math.min(1, Math.sqrt(sum / buf.length) * 4);
      bars[i % bars.length].style.height = `${Math.max(4, Math.round(lvl * 28))}px`; i++;
      raf = requestAnimationFrame(tick);
    };
    tick();
  } catch { /* Pegelanzeige ist optional */ }

  const cleanup = () => {
    clearInterval(timer); cancelAnimationFrame(raf);
    stream.getTracks().forEach((t) => t.stop());
    try { ctx?.close(); } catch { /* egal */ }
    active = null;
    onClose?.();
  };
  function stop(doSend) {
    if (finished) return;
    finished = true; send = doSend;
    sendBtn.disabled = true;
    const ms = Math.round(performance.now() - startedAt);
    rec.onstop = () => {
      const type = (rec.mimeType || mime || 'audio/webm').split(';')[0];
      const blob = new Blob(chunks, { type });
      cleanup();
      if (!send) return;
      if (ms < 800 || blob.size < 200) { toast('Die Aufnahme ist zu kurz.', 'warn'); return; }
      const ext = type.includes('mp4') ? 'm4a' : type.includes('ogg') ? 'ogg' : 'webm';
      onFinish(new File([blob], `Sprachnachricht-${uuid().slice(0, 6)}.${ext}`, { type }), Math.min(ms, maxMs));
    };
    try { rec.stop(); } catch { cleanup(); }
  }
  function cancel() { stop(false); }
  active = { cancel };

  rec.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
  rec.onerror = () => { toast('Die Aufnahme ist fehlgeschlagen.', 'error'); stop(false); };
  startedAt = performance.now();
  rec.start(1000);
  timer = setInterval(() => {
    const el = performance.now() - startedAt;
    label.textContent = `${fmtDur(el / 1000)} / ${fmtDur(maxSeconds)}`;
    if (el >= maxMs) { toast('Maximale Dauer erreicht, die Nachricht wird gesendet.', 'info', 3000); stop(true); }
  }, 250);
  label.textContent = `0:00 / ${fmtDur(maxSeconds)}`;
  return bar;
}

// -------------------------------------------------------------------- Player

const blobUrls = new Map();
let playing = null; // { audio, reset }
const SPEEDS = [1, 1.5, 2];

function waveHeights(seed, n = 36) {
  // Deterministische «Wellenform» aus der Anhangs-ID (keine Analyse der Audiodaten nötig)
  let x = (seed * 2654435761) >>> 0; const out = [];
  for (let i = 0; i < n; i++) { x = (Math.imul(x, 1664525) + 1013904223) >>> 0; out.push(25 + (x >>> 24) % 75); }
  return out;
}

async function loadBlob(a) {
  if (blobUrls.has(a.id)) return blobUrls.get(a.id);
  const res = await fetch(url(`files/${a.id}?download=1`), { credentials: 'same-origin' });
  if (!res.ok) throw new Error('Die Sprachnachricht konnte nicht geladen werden.');
  const blob = new Blob([await res.arrayBuffer()], { type: (a.mime || 'audio/webm').split(';')[0] });
  const u = URL.createObjectURL(blob);
  blobUrls.set(a.id, u);
  return u;
}

export function voiceNode(a, own) {
  const total = (a.duration_ms || 0) / 1000;
  const heights = waveHeights(a.id);
  const on = own ? 'bg-white' : 'bg-accent';
  const off = own ? 'bg-white/40' : 'bg-slate-300 dark:bg-neutral-600';
  const spans = heights.map((p) => h('span', { class: `block w-[3px] shrink-0 rounded-full ${off}`, style: { height: `${Math.max(12, Math.round(p * 0.28))}px` } }));
  const wave = h('div', { class: 'flex h-8 min-w-0 flex-1 cursor-pointer items-center justify-between gap-px', role: 'slider', tabindex: '0', 'aria-label': 'Position in der Sprachnachricht', 'aria-valuemin': '0', 'aria-valuemax': String(Math.round(total)), 'aria-valuenow': '0' }, spans);
  const time = h('span', { class: 'ml-1 w-10 shrink-0 text-right text-xs tabular-nums opacity-80' }, fmtDur(total));
  const playIcon = icon('play', 'text-2xl');
  const btn = h('button', { type: 'button', class: `flex h-10 w-10 shrink-0 items-center justify-center rounded-full ${own ? 'bg-white/20 hover:bg-white/30' : 'bg-accent/10 text-accent hover:bg-accent/20'}`, 'aria-label': 'Sprachnachricht abspielen' }, playIcon);
  const speedBtn = h('button', { type: 'button', class: `hidden h-6 shrink-0 rounded-full px-1.5 text-[11px] font-semibold ${own ? 'bg-white/20' : 'bg-slate-100 dark:bg-neutral-700'}`, 'aria-label': 'Wiedergabetempo ändern', title: 'Tempo' }, '1×');
  const root = h('div', { class: 'mt-1 flex w-64 max-w-full items-center gap-2', dataset: { voice: String(a.id) } }, btn, wave, time, speedBtn);

  let audio = null; let speed = 1; let busy = false;
  const dur = () => (a.duration_ms ? total : (audio && Number.isFinite(audio.duration) ? audio.duration : 0));
  const paint = (cur) => {
    const d = dur(); const frac = d ? Math.min(1, cur / d) : 0;
    const n = Math.round(frac * spans.length);
    spans.forEach((s, i) => { s.className = `block w-[3px] shrink-0 rounded-full ${i < n ? on : off}`; });
    wave.setAttribute('aria-valuenow', String(Math.round(cur)));
    time.textContent = fmtDur(cur > 0 ? cur : d);
  };
  const setIcon = (name) => { playIcon.className = `bx bx-${name} text-2xl`; btn.setAttribute('aria-label', name === 'pause' ? 'Pausieren' : 'Sprachnachricht abspielen'); };
  const reset = () => { setIcon('play'); };

  const ensure = async () => {
    if (audio) return audio;
    busy = true; btn.disabled = true; setIcon('loader-alt');
    try {
      const src = await loadBlob(a);
      audio = new Audio(src);
      audio.preload = 'auto';
      audio.addEventListener('timeupdate', () => paint(audio.currentTime));
      audio.addEventListener('ended', () => { reset(); audio.currentTime = 0; paint(0); playing = null; });
      audio.addEventListener('pause', reset);
      audio.addEventListener('play', () => setIcon('pause'));
      return audio;
    } finally { busy = false; btn.disabled = false; }
  };
  const toggle = async () => {
    if (busy) return;
    try {
      if (!document.createElement('audio').canPlayType((a.mime || '').split(';')[0])) {
        toast('Dieses Gerät kann das Format der Sprachnachricht nicht abspielen. Du kannst sie herunterladen.', 'warn', 6000);
        window.open(url(`files/${a.id}?download=1`), '_self'); return;
      }
      const au = await ensure();
      if (au.paused) {
        if (playing && playing.audio !== au) playing.audio.pause();
        au.playbackRate = speed;
        await au.play();
        playing = { audio: au };
        speedBtn.classList.remove('hidden');
      } else au.pause();
    } catch (e) { reset(); toast(e.message || 'Wiedergabe nicht möglich.', 'error'); }
  };
  btn.addEventListener('click', toggle);
  speedBtn.addEventListener('click', () => {
    speed = SPEEDS[(SPEEDS.indexOf(speed) + 1) % SPEEDS.length];
    speedBtn.textContent = `${speed === 1.5 ? '1,5' : speed}×`;
    if (audio) audio.playbackRate = speed;
  });
  const seekTo = async (frac) => {
    try {
      const au = await ensure(); const d = dur();
      if (d) { au.currentTime = Math.max(0, Math.min(d - 0.05, frac * d)); paint(au.currentTime); }
      if (au.paused) await toggle();
    } catch (e) { toast(e.message || 'Wiedergabe nicht möglich.', 'error'); }
  };
  wave.addEventListener('click', (e) => { const r = wave.getBoundingClientRect(); seekTo((e.clientX - r.left) / r.width); });
  wave.addEventListener('keydown', (e) => {
    if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
      e.preventDefault(); const d = dur(); if (!d) return;
      const cur = audio ? audio.currentTime : 0; seekTo((cur + (e.key === 'ArrowRight' ? 5 : -5)) / d);
    } else if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); toggle(); }
  });
  return root;
}
