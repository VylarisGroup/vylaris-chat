// Service Worker: zeigt Push-Hinweise an, auch wenn die App geschlossen ist.
// Die Nutzlast ist zum Gerät hin verschlüsselt und enthält nur den Hinweistext (nie Nachrichteninhalt).
// Ältere Abos ohne Schlüssel erhalten ein leeres Signal; dann wird der Text von diesem Server geholt.
const scope = self.registration.scope;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

async function readPayload(event) {
  if (event.data) {
    try {
      const j = event.data.json();
      if (j && typeof j === 'object') return j;
    } catch {
      try { const t = event.data.text(); if (t) return { body: t }; } catch { /* leer */ }
    }
  }
  try {
    const res = await fetch(new URL('api/push/latest', scope), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (res.ok) return await res.json();
  } catch { /* offline: generischer Text */ }
  return null;
}

// Eingehender Anruf (Videomeeting): nur mit gültiger Meeting-Nummer, sonst gewöhnlicher Hinweis.
function callOf(d) {
  if (!d || d.type !== 'INCOMING_CALL') return null;
  const id = Number(d.callId);
  const conv = Number(d.convId);
  if (!Number.isInteger(id) || id <= 0 || !Number.isInteger(conv) || conv <= 0) return null;
  return { id, conv, name: String(d.callerName || 'Unbekannt').slice(0, 80) };
}

async function show(event) {
  const d = (await readPayload(event)) || {};
  const call = callOf(d);
  if (call) {
    // Gleiche tag: ein erneuter Push für dasselbe Meeting ersetzt den Hinweis (keine Doppelten).
    // requireInteraction und Aktionen sind nicht überall verfügbar; Browser ohne Unterstützung ignorieren sie.
    await self.registration.showNotification(call.name, {
      body: String(d.body || 'Videomeeting'),
      tag: 'meet-' + call.id,
      renotify: true,
      requireInteraction: true,
      lang: 'de',
      icon: new URL('assets/icons/icon-192.png', scope).href,
      badge: new URL('assets/icons/badge-96.png', scope).href,
      actions: [{ action: 'accept', title: 'Annehmen' }, { action: 'decline', title: 'Ablehnen' }],
      data: { url: '?c=' + call.conv, call: { id: call.id, conv: call.conv } },
    });
    return;
  }
  const title = String(d.title || 'Messenger');
  // Wichtig (iOS und Chrome): Jeder Push muss einen sichtbaren Hinweis erzeugen, sonst wird das Abo entzogen.
  // Ob die App sichtbar ist, entscheidet bereits der Server; hier wird deshalb immer angezeigt.
  const tasks = [self.registration.showNotification(title, {
    body: String(d.body || 'Neue Aktivität im Messenger'),
    tag: String(d.tag || 'msgr-generic'),
    renotify: true,
    lang: 'de',
    icon: new URL('assets/icons/icon-192.png', scope).href,
    badge: new URL('assets/icons/badge-96.png', scope).href,
    data: { url: String(d.url || '') },
  })];
  const n = Number(d.badge);
  if (n > 0 && self.navigator && 'setAppBadge' in self.navigator) {
    tasks.push(self.navigator.setAppBadge(n).catch(() => {}));
  }
  await Promise.all(tasks);
}

self.addEventListener('push', (event) => { event.waitUntil(show(event)); });

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const call = event.notification.data?.call;
  if (call && event.action === 'decline') return; // Ablehnen: Hinweis schliessen, das Meeting läuft für die anderen weiter
  if (call && event.action === 'accept') {
    event.waitUntil((async () => {
      const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
      for (const w of wins) {
        if (w.url.startsWith(scope) && 'focus' in w) {
          await w.focus();
          w.postMessage({ type: 'meet-accept', meetingId: call.id, convId: call.conv });
          return;
        }
      }
      await self.clients.openWindow(new URL('?c=' + call.conv + '&meeting=' + call.id, scope).href);
    })());
    return;
  }
  const target = new URL(event.notification.data?.url || '', scope).href;
  event.waitUntil((async () => {
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    for (const w of wins) {
      if (w.url.startsWith(scope) && 'focus' in w) {
        await w.focus();
        if ('navigate' in w) { try { await w.navigate(target); } catch { /* ignorieren */ } }
        return;
      }
    }
    await self.clients.openWindow(target);
  })());
});

// Der Browser hat die Push-Adresse erneuert (läuft ab, rotiert): neu anmelden und dem Server die neue Adresse melden.
function b64url(buf) {
  let s = '';
  for (const b of new Uint8Array(buf)) s += String.fromCharCode(b);
  return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

self.addEventListener('pushsubscriptionchange', (event) => {
  event.waitUntil((async () => {
    const old = event.oldSubscription || null;
    let sub = event.newSubscription || null;
    if (!sub) {
      let key = old && old.options && old.options.applicationServerKey ? old.options.applicationServerKey : null;
      if (!key) {
        const res = await fetch(new URL('api/push/key', scope), { headers: { Accept: 'application/json' } });
        if (!res.ok) return;
        const b64 = (await res.json()).public_key;
        const raw = atob((b64 + '='.repeat((4 - (b64.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/'));
        key = Uint8Array.from(raw, (c) => c.charCodeAt(0));
      }
      sub = await self.registration.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key });
    }
    const p256dh = sub.getKey('p256dh');
    const auth = sub.getKey('auth');
    await fetch(new URL('api/push/resubscribe', scope), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ old_endpoint: old ? old.endpoint : '', endpoint: sub.endpoint, p256dh: p256dh ? b64url(p256dh) : '', auth: auth ? b64url(auth) : '' }),
    });
  })());
});
