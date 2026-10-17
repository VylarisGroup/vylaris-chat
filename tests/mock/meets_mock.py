"""Mock der TheRedStonee-Meets-API für Tests. Port 8093."""
import json, hmac, hashlib, time, re, secrets, urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
KEY = 'trsm_testkey1234567890'; SECRET = 'whsec_testsecret123456'
HOOK = 'http://127.0.0.1:8080/api/meets/webhook'
ORIGIN = 'http://127.0.0.1:8093'
rooms = {}; calls = []; limit = {'max': 20}
def hook(event, room, extra=None, secret=SECRET, ts=None, tamper=False):
    body = json.dumps({'event': event, 'deliveryId': 'whd_' + secrets.token_hex(4), 'sentAt': '2026-01-01T00:00:00Z', 'data': dict({'room': {'code': room, 'title': 't', 'metadata': {}}}, **(extra or {}))}).encode()
    ts = str(ts if ts is not None else int(time.time()))
    sig = 'sha256=' + hmac.new(secret.encode(), ts.encode() + b'.' + body, hashlib.sha256).hexdigest()
    if tamper: body = body.replace(b't"', b'x"')
    r = urllib.request.Request(HOOK, data=body, method='POST', headers={'Content-Type': 'application/json', 'X-TRS-Event': event, 'X-TRS-Timestamp': ts, 'X-TRS-Signature': sig})
    try: return urllib.request.urlopen(r).status
    except urllib.error.HTTPError as e: return e.code
def room_json(r): return {'code': r['code'], 'title': r['title'], 'participantCount': len(r['p']), 'expiresAt': r['exp'], 'joinUrl': f"{ORIGIN}/?room={r['code']}"}
class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def send(self, st, obj=None, ctype='application/json'):
        b = (json.dumps(obj) if ctype == 'application/json' else obj).encode() if obj is not None else b''
        self.send_response(st); self.send_header('Content-Type', ctype); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def err(self, st, code): self.send(st, {'statusCode': st, 'statusMessage': code, 'data': {'code': code, 'error': code}})
    def body(self):
        n = int(self.headers.get('Content-Length') or 0); return json.loads(self.rfile.read(n) or b'{}') if n else {}
    def handle_any(self, m):
        path = self.path.split('?')[0]
        if path == '/' and m == 'GET':
            return self.send(200, '<!doctype html><title>mock</title><body id=b>Meeting-Mock</body><script>parent.postMessage({source:"trs-meets",type:"joined",isHost:true,name:"x"},"*");window.addEventListener("message",e=>{});</script>', 'text/html')
        if path.startswith('/_test/'):
            d = self.body(); op = path[7:]
            if op == 'join':
                r = rooms[d['code']]; r['p'].append(d.get('name', 'x')); return self.send(200, {'status': hook('participant.joined', d['code'], {'participant': {'peerId': 'p', 'name': d.get('name', 'x'), 'isHost': False}})})
            if op == 'leave':
                r = rooms[d['code']]; r['p'].pop(); return self.send(200, {'status': hook('participant.left', d['code'], {'participant': {'peerId': 'p', 'name': 'x'}, 'reason': 'left'})})
            if op == 'end':
                rooms.pop(d['code'], None); return self.send(200, {'status': hook('room.ended', d['code'], {'reason': d.get('reason', 'host')})})
            if op == 'badhook':
                return self.send(200, {'status': hook('room.ended', d['code'], {'reason': 'host'}, secret='whsec_wrong', tamper=d.get('tamper', False)), 'old': hook('room.ended', d['code'], {'reason': 'host'}, ts=int(time.time()) - 900)})
            if op == 'calls': return self.send(200, {'calls': calls})
            if op == 'reset': calls.clear(); rooms.clear(); limit['max'] = 20; return self.send(200, {})
            if op == 'limit': limit['max'] = d['max']; return self.send(200, {})
            if op == 'direct': return self.send(200, {'p': rooms[d['code']]['p'] and len(rooms[d['code']]['p'])}) 
            if op == 'setcount':
                r = rooms[d['code']]; r['p'] = ['x'] * d['n']; return self.send(200, {})
        if not path.startswith('/api/meets/v1'): return self.err(404, 'not_found')
        if self.headers.get('Authorization') != 'Bearer ' + KEY: return self.err(401, 'unauthorized')
        p = path[len('/api/meets/v1'):]
        calls.append([m, p])
        if p == '/me' and m == 'GET':
            return self.send(200, {'key': {'id': 'mk_1', 'name': 'Testschlüssel', 'rateLimit': 120, 'maxRooms': limit['max'], 'embedOrigins': ['http://localhost:8080', 'http://127.0.0.1:8080'], 'webhook': {'configured': True}}})
        if p == '/rooms' and m == 'POST':
            d = self.body()
            if len(rooms) >= limit['max']: return self.err(409, 'room_limit')
            code = 'tst-' + secrets.token_hex(2) + '-' + secrets.token_hex(2)
            exp = time.strftime('%Y-%m-%dT%H:%M:%S.000Z', time.gmtime(time.time() + d.get('expiresInMinutes', 1440) * 60))
            rooms[code] = {'code': code, 'title': d.get('title', ''), 'p': [], 'exp': exp, 'meta': d.get('metadata')}
            return self.send(201, {'room': room_json(rooms[code])})
        mm = re.match(r'^/rooms/([a-z0-9\-]+)(/links)?$', p)
        if mm:
            r = rooms.get(mm.group(1))
            if not r: return self.err(404, 'room_not_found')
            if mm.group(2) and m == 'POST':
                d = self.body(); t = secrets.token_urlsafe(24)
                u = f"{ORIGIN}/?room={r['code']}&t={t}"
                return self.send(201, {'link': {'role': d['role'], 'name': d.get('name'), 'expiresAt': r['exp'], 'url': u, 'embedUrl': u + '&embed=1'}})
            if m == 'GET': return self.send(200, {'room': room_json(r), 'participants': [{'name': n} for n in r['p']]})
            if m == 'DELETE': rooms.pop(r['code']); return self.send(200, {'ok': True})
        return self.err(404, 'not_found')
    def do_GET(self): self.handle_any('GET')
    def do_POST(self): self.handle_any('POST')
    def do_DELETE(self): self.handle_any('DELETE')
ThreadingHTTPServer(('127.0.0.1', 8093), H).serve_forever()
