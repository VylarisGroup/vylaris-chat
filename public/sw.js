// Service Worker: zeigt Push-Hinweise an. Die Push-Nachricht selbst ist leer (kein Inhalt über Drittserver);
// der Hinweistext wird mit der Sitzung der Person von diesem Server geholt.
const scope = self.registration.scope;

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));

self.addEventListener('push', (event) => {
  event.waitUntil((async () => {
    let data = { title: 'Messenger', body: 'Neue Aktivität im Messenger', url: '', tag: 'msgr-generic' };
    try {
      const res = await fetch(new URL('api/push/latest', scope), { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      if (res.ok) data = await res.json();
    } catch { /* offline: generischer Text */ }
    // Kein Hinweis, wenn die App im Vordergrund offen ist (sie zeigt Neues selbst an)
    const wins = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
    if (wins.some((w) => w.visibilityState === 'visible')) return;
    await self.registration.showNotification(data.title, {
      body: data.body,
      tag: data.tag,
      renotify: true,
      icon: new URL('assets/icons/icon-192.png', scope).href,
      badge: new URL('assets/icons/badge-96.png', scope).href,
      data: { url: data.url || '' },
    });
  })());
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
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
