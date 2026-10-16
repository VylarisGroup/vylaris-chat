// Web-Push: Service Worker registrieren und Geräte-Abo verwalten. Die Nutzlast ist Ende-zu-Ende zum Gerät verschlüsselt
// (RFC 8291) und enthält nie Nachrichteninhalt (siehe sw.js).

import { api } from './api.js';
import { store } from './store.js';
import { url } from './util.js';

const SYNC_KEY = 'push-synced-at';
const SYNC_EVERY = 12 * 3600 * 1000;

export const isIOS = () => /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
export const isStandalone = () => (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || navigator.standalone === true;

export const pushAvailable = () => !!store.push && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;

/** Warum Push hier (noch) nicht geht – für eine verständliche Meldung. */
export function pushHint() {
  if (!window.isSecureContext) return 'Push funktioniert nur über HTTPS.';
  if (isIOS() && !isStandalone()) return 'Auf dem iPhone/iPad: In Safari auf «Teilen» tippen, «Zum Home-Bildschirm» wählen und die App von dort öffnen. Erst dann kann Push eingeschaltet werden (iOS 16.4 oder neuer).';
  if (!('PushManager' in window) || !('serviceWorker' in navigator)) return 'Dieser Browser unterstützt keine Push-Benachrichtigungen.';
  if ('Notification' in window && Notification.permission === 'denied') return 'Benachrichtigungen sind für diese App blockiert. Erlaube sie in den Einstellungen des Geräts bzw. Browsers.';
  return '';
}

export async function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return null;
  try {
    return await navigator.serviceWorker.register(url('sw.js'), { scope: url(''), updateViaCache: 'none' });
  } catch { return null; }
}

function keyBytes(b64) {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

function b64url(buf) {
  let s = '';
  for (const b of new Uint8Array(buf)) s += String.fromCharCode(b);
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** Endpunkt und Schlüssel des Abos für den Server. */
function subPayload(sub) {
  const p256dh = sub.getKey ? sub.getKey('p256dh') : null;
  const auth = sub.getKey ? sub.getKey('auth') : null;
  return { endpoint: sub.endpoint, p256dh: p256dh ? b64url(p256dh) : '', auth: auth ? b64url(auth) : '' };
}

async function registration() {
  try { return await navigator.serviceWorker.getRegistration(url('')); } catch { return null; }
}

export async function pushEnabledHere() {
  if (!pushAvailable()) return false;
  try {
    const reg = await registration();
    return !!(reg && await reg.pushManager.getSubscription());
  } catch { return false; }
}

export async function enablePush() {
  if (!pushAvailable()) throw new Error(pushHint() || 'Push-Benachrichtigungen sind hier nicht verfügbar.');
  // Die Abfrage der Erlaubnis muss direkt aus dem Klick heraus erfolgen (iOS verlangt das).
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') throw new Error('Die Erlaubnis wurde nicht erteilt.');
  const reg = (await registerServiceWorker()) && await navigator.serviceWorker.ready;
  if (!reg) throw new Error('Der Service Worker konnte nicht gestartet werden.');
  let sub = await reg.pushManager.getSubscription();
  // Ein Abo mit anderem Server-Schlüssel (z. B. nach Neuinstallation) ersetzen
  const want = keyBytes(store.push.public_key);
  const have = sub && sub.options && sub.options.applicationServerKey ? new Uint8Array(sub.options.applicationServerKey) : null;
  if (sub && have && (have.length !== want.length || have.some((v, i) => v !== want[i]))) {
    try { await api.post('/api/push/unsubscribe', { endpoint: sub.endpoint }); } catch { /* egal */ }
    await sub.unsubscribe();
    sub = null;
  }
  try {
    sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: want });
  } catch (e) {
    throw new Error('Der Push-Dienst des Geräts hat die Anmeldung abgelehnt (' + (e && e.name ? e.name : 'Fehler') + '). Prüfe die Internetverbindung und die Benachrichtigungs-Einstellungen des Geräts.');
  }
  await api.post('/api/push/subscribe', subPayload(sub));
  try { localStorage.setItem(SYNC_KEY, String(Date.now())); } catch { /* optional */ }
}

export async function disablePush() {
  const reg = await registration();
  const sub = reg ? await reg.pushManager.getSubscription() : null;
  if (sub) {
    try { await api.post('/api/push/unsubscribe', { endpoint: sub.endpoint }); } catch { /* serverseitig läuft das Abo später ab */ }
    await sub.unsubscribe();
  }
  try { localStorage.removeItem(SYNC_KEY); } catch { /* optional */ }
}

/**
 * Beim Start: Abo mit dem Server abgleichen (höchstens alle 12 Stunden). Fängt Fälle ab, in denen der Browser die
 * Push-Adresse erneuert hat oder der Server das Abo verloren hat – sonst käme still nichts mehr an.
 */
export async function syncPush({ force = false } = {}) {
  try {
    if (!pushAvailable() || Notification.permission !== 'granted') return;
    let last = 0;
    try { last = Number(localStorage.getItem(SYNC_KEY)) || 0; } catch { /* optional */ }
    if (!force && Date.now() - last < SYNC_EVERY) return;
    const reg = await registration();
    if (!reg) return;
    let sub = await reg.pushManager.getSubscription();
    if (!sub) return; // nie eingeschaltet oder bewusst ausgeschaltet
    await api.post('/api/push/subscribe', subPayload(sub));
    try { localStorage.setItem(SYNC_KEY, String(Date.now())); } catch { /* optional */ }
  } catch { /* beim nächsten Start erneut */ }
}

/** Zähler am App-Symbol zurücksetzen, sobald die App sichtbar ist. */
export function clearBadge() {
  try { if (navigator.clearAppBadge) navigator.clearAppBadge(); } catch { /* optional */ }
}
