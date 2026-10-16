const fs = require('fs'), vm = require('vm'), assert = require('assert');
const L = {}; const calls = { fetch: [], shown: [], opened: [], focused: [], navigated: [] };
const win = (url, visible = true) => ({ url, visibilityState: visible ? 'visible' : 'hidden', focus: async () => calls.focused.push(url), navigate: async (u) => calls.navigated.push(u) });
let windows = [];
const sub = (ep) => ({ endpoint: ep, getKey: (n) => Uint8Array.from(n === 'p256dh' ? Array(65).fill(4) : Array(16).fill(7)).buffer, options: { applicationServerKey: null } });
const self = {
  registration: { scope: 'https://chat.example/app/', showNotification: async (t, o) => calls.shown.push([t, o]), pushManager: { subscribe: async (o) => { calls.subscribeOpts = o; return sub('https://fcm.googleapis.com/new'); } } },
  clients: { claim: async () => {}, matchAll: async () => windows, openWindow: async (u) => calls.opened.push(u) },
  addEventListener: (n, f) => { L[n] = f; }, skipWaiting: () => {}, navigator: {},
};
const ctx = { self, URL, fetch: async (u, o) => { calls.fetch.push([String(u), o]); return { ok: true, json: async () => ({ public_key: 'BAUT' + 'A'.repeat(83) }) }; }, atob, btoa, Uint8Array, JSON, Promise, console };
vm.createContext(ctx); vm.runInContext(fs.readFileSync('/home/claude/messenger/public/sw.js', 'utf8'), ctx);
(async () => {
  // pushsubscriptionchange ohne newSubscription (Chrome/Firefox): Key vom Server holen, neu abonnieren, melden
  let p; await L.pushsubscriptionchange({ oldSubscription: sub('https://fcm.googleapis.com/old'), newSubscription: null, waitUntil: (x) => { p = x; } }); await p;
  const post = calls.fetch.find(([u, o]) => u.endsWith('api/push/resubscribe'));
  assert(post, 'resubscribe not called');
  const b = JSON.parse(post[1].body);
  assert.equal(b.old_endpoint, 'https://fcm.googleapis.com/old'); assert.equal(b.endpoint, 'https://fcm.googleapis.com/new');
  assert.equal(b.p256dh.length, 87); assert.equal(b.auth.length, 22);
  assert(calls.fetch.some(([u]) => u.endsWith('api/push/key')), 'key fetched when old sub has no key');
  assert.equal(calls.subscribeOpts.userVisibleOnly, true);
  console.log('PASS pushsubscriptionchange re-subscribes and reports new endpoint + keys');
  // mit newSubscription (Safari/Firefox liefern sie)
  calls.fetch.length = 0; await L.pushsubscriptionchange({ oldSubscription: null, newSubscription: sub('https://web.push.apple.com/abc'), waitUntil: (x) => { p = x; } }); await p;
  assert.equal(JSON.parse(calls.fetch[0][1].body).endpoint, 'https://web.push.apple.com/abc'); console.log('PASS newSubscription is used as given');
  // notificationclick: vorhandenes Fenster fokussieren + navigieren
  windows = [win('https://chat.example/app/')]; let closed = false;
  await L.notificationclick({ notification: { close: () => { closed = true; }, data: { url: '?c=4&m=9' } }, waitUntil: (x) => { p = x; } }); await p;
  assert(closed && calls.focused.length === 1 && calls.navigated[0] === 'https://chat.example/app/?c=4&m=9'); console.log('PASS click focuses existing window and opens the conversation');
  // kein Fenster (App geschlossen): neues Fenster öffnen
  windows = []; await L.notificationclick({ notification: { close: () => {}, data: { url: '?c=4' } }, waitUntil: (x) => { p = x; } }); await p;
  assert.equal(calls.opened[0], 'https://chat.example/app/?c=4'); console.log('PASS click with closed app opens a new window');
  // push mit App-Badge
  self.navigator.setAppBadge = async (n) => { calls.badge = n; };
  await L.push({ data: { json: () => ({ title: 'T', body: 'B', tag: 'conv-1', badge: 5, url: '' }), text: () => '' }, waitUntil: (x) => { p = x; } }); await p;
  assert.equal(calls.badge, 5); assert.equal(calls.shown.at(-1)[1].tag, 'conv-1'); console.log('PASS badge count set, notification shown');
  // setAppBadge schlägt fehl: Hinweis wird trotzdem gezeigt
  self.navigator.setAppBadge = async () => { throw new Error('x'); }; const before = calls.shown.length;
  await L.push({ data: { json: () => ({ title: 'T', body: 'B2', badge: 2 }), text: () => '' }, waitUntil: (x) => { p = x; } }); await p; assert.equal(calls.shown.length, before + 1); console.log('PASS badge failure does not prevent the notification');
  // Offline, leerer Push: generischer Text, Hinweis wird trotzdem gezeigt (iOS-Pflicht)
  ctx.fetch = async () => { throw new Error('offline'); };
  await L.push({ data: null, waitUntil: (x) => { p = x; } }); await p; assert.equal(calls.shown.at(-1)[1].body, 'Neue Aktivität im Messenger'); console.log('PASS offline + empty push still shows a generic notification');
})().catch((e) => { console.error('FAIL', e); process.exit(1); });
