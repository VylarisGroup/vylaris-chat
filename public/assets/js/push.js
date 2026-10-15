// Web-Push: Service Worker registrieren und Geräte-Abo verwalten. Die Push-Nachricht enthält nie Inhalte (siehe sw.js).

import { api } from './api.js';
import { store } from './store.js';
import { url } from './util.js';

export const pushAvailable = () => !!store.push && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window && window.isSecureContext;

export async function registerServiceWorker() {
  if (!('serviceWorker' in navigator) || !window.isSecureContext) return null;
  try {
    return await navigator.serviceWorker.register(url('sw.js'), { scope: url('') });
  } catch { return null; }
}

function keyBytes(b64) {
  const pad = '='.repeat((4 - (b64.length % 4)) % 4);
  const raw = atob((b64 + pad).replace(/-/g, '+').replace(/_/g, '/'));
  return Uint8Array.from(raw, (c) => c.charCodeAt(0));
}

export async function pushEnabledHere() {
  if (!pushAvailable()) return false;
  try {
    const reg = await navigator.serviceWorker.getRegistration(url(''));
    return !!(reg && await reg.pushManager.getSubscription());
  } catch { return false; }
}

export async function enablePush() {
  if (!pushAvailable()) throw new Error('Push-Benachrichtigungen sind hier nicht verfügbar (HTTPS und ein unterstützter Browser nötig).');
  const perm = await Notification.requestPermission();
  if (perm !== 'granted') throw new Error('Die Erlaubnis wurde nicht erteilt.');
  const reg = (await registerServiceWorker()) && await navigator.serviceWorker.ready;
  if (!reg) throw new Error('Der Service Worker konnte nicht gestartet werden.');
  let sub = await reg.pushManager.getSubscription();
  sub ??= await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(store.push.public_key) });
  await api.post('/api/push/subscribe', { endpoint: sub.endpoint });
}

export async function disablePush() {
  const reg = await navigator.serviceWorker.getRegistration(url(''));
  const sub = reg ? await reg.pushManager.getSubscription() : null;
  if (sub) {
    try { await api.post('/api/push/unsubscribe', { endpoint: sub.endpoint }); } catch { /* serverseitig läuft das Abo später ab */ }
    await sub.unsubscribe();
  }
}
