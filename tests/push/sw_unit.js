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
  // --- Eingehender Anruf (Videomeeting)
  const callPush = (o) => ({ data: { json: () => o, text: () => '' }, waitUntil: (x) => { p = x; } });
  await L.push(callPush({ type: 'INCOMING_CALL', callerName: 'Max Mustermann', callId: 7, convId: 3, body: 'Max ruft dich an' })); await p;
  let [t, o] = calls.shown.at(-1);
  assert.equal(t, 'Max Mustermann'); assert.equal(o.tag, 'meet-7'); assert.equal(o.requireInteraction, true);
  assert.deepEqual(o.actions.map((a) => a.action), ['accept', 'decline']); assert.deepEqual(o.data.call, { id: 7, conv: 3 });
  console.log('PASS INCOMING_CALL shows caller, requireInteraction, accept/decline, dedupe tag');
  for (const bad of [{ type: 'INCOMING_CALL' }, { type: 'INCOMING_CALL', callId: 'x', convId: 3 }, { type: 'INCOMING_CALL', callId: -1, convId: 3 }, { type: 'INCOMING_CALL', callId: 1.5, convId: 3 }]) {
    const n = calls.shown.length; await L.push(callPush(bad)); await p;
    assert.equal(calls.shown.length, n + 1); assert(!calls.shown.at(-1)[1].actions, 'fehlerhafte Anrufdaten dürfen keine Aktionen zeigen');
  }
  console.log('PASS malformed call payloads fall back to a plain notification');
  // Annehmen mit offenem Fenster: fokussieren und Frontend benachrichtigen
  const posted = []; const w1 = win('https://chat.example/app/'); w1.postMessage = (m) => posted.push(m); windows = [w1]; calls.focused.length = 0;
  await L.notificationclick({ action: 'accept', notification: { close: () => {}, data: { url: '?c=3', call: { id: 7, conv: 3 } } }, waitUntil: (x) => { p = x; } }); await p;
  assert.equal(calls.focused.length, 1); assert.deepEqual(posted[0], { type: 'meet-accept', meetingId: 7, convId: 3 });
  console.log('PASS accept focuses window and posts meet-accept');
  // Annehmen ohne Fenster: neues Fenster mit Meeting-Parameter
  windows = []; calls.opened.length = 0;
  await L.notificationclick({ action: 'accept', notification: { close: () => {}, data: { url: '?c=3', call: { id: 7, conv: 3 } } }, waitUntil: (x) => { p = x; } }); await p;
  assert.equal(calls.opened[0], 'https://chat.example/app/?c=3&meeting=7'); console.log('PASS accept with closed app opens window with meeting id');
  // Ablehnen: nur schliessen, nichts öffnen
  calls.opened.length = 0; calls.focused.length = 0; let cl = false; windows = [w1];
  await L.notificationclick({ action: 'decline', notification: { close: () => { cl = true; }, data: { url: '?c=3', call: { id: 7, conv: 3 } } }, waitUntil: (x) => { p = x; } }); if (p) await p;
  assert(cl && !calls.opened.length && !calls.focused.length); console.log('PASS decline only closes the notification');
  // Klick auf den Hinweis selbst: Unterhaltung öffnen, nicht beitreten
  calls.navigated.length = 0; windows = [w1];
  await L.notificationclick({ action: '', notification: { close: () => {}, data: { url: '?c=3', call: { id: 7, conv: 3 } } }, waitUntil: (x) => { p = x; } }); await p;
  assert.equal(calls.navigated[0], 'https://chat.example/app/?c=3'); console.log('PASS plain click opens the conversation without joining');
})().catch((e) => { console.error('FAIL', e); process.exit(1); });
