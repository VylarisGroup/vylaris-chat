"""End-to-End-Test 1.17.0 (Sprachnachrichten: Upload, Format- und Längenprüfung, Auslieferung, Weiterleiten, Notizen). Dev-Server :8080, ffmpeg."""
import sys, json, subprocess, os, uuid, tempfile
sys.path.insert(0, '/tmp/dev')
from drive import *
RUN = uuid.uuid4().hex[:6]
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
def login(email, pw):
    op, cj = make(); s, h, _ = req(op, 'GET', '/login'); t = csrf(h)
    s, h, _ = req(op, 'POST', '/login', {'email': email, 'password': pw, '_csrf': t}); assert s == 302, (email, s)
    s, h, _ = req(op, 'GET', '/api/bootstrap', headers={'Accept': 'application/json'}); return op, json.loads(h)
def api(op, b, method, path, data=None):
    hd = {'X-CSRF-Token': b['csrf'], 'Accept': 'application/json', 'Content-Type': 'application/json'}
    s, h, _ = req(op, method, path, raw=json.dumps(data).encode() if data is not None else None, headers=hd)
    try: j = json.loads(h)
    except Exception: j = h
    return s, j
def upload(op, b, cid, path, fields, name=None, mime='application/octet-stream'):
    bd = '----v' + RUN; body = b''
    for k, v in fields.items(): body += f'--{bd}\r\nContent-Disposition: form-data; name="{k}"\r\n\r\n{v}\r\n'.encode()
    body += f'--{bd}\r\nContent-Disposition: form-data; name="file"; filename="{name or os.path.basename(path)}"\r\nContent-Type: {mime}\r\n\r\n'.encode() + open(path, 'rb').read() + f'\r\n--{bd}--\r\n'.encode()
    s, h, _ = req(op, 'POST', f'/api/conversations/{cid}/uploads', raw=body, headers={'X-CSRF-Token': b['csrf'], 'Accept': 'application/json', 'Content-Type': 'multipart/form-data; boundary=' + bd})
    try: j = json.loads(h)
    except Exception: j = h
    return s, j
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
tmp = tempfile.mkdtemp()
def mk(name, args):
    p = os.path.join(tmp, name); subprocess.run(['ffmpeg', '-y', '-loglevel', 'error', '-f', 'lavfi', '-i', 'sine=frequency=440:duration=3'] + args + [p], check=True); return p
webm = mk('a.webm', ['-c:a', 'libopus']); m4a = mk('a.m4a', ['-c:a', 'aac']); ogg = mk('a.ogg', ['-c:a', 'libopus']); wav = mk('a.wav', [])
bad = os.path.join(tmp, 'fake.webm'); open(bad, 'w').write('<?php echo 1; ?> kein Audio')
sql('delete from rate_limits'); sql("update system_settings set v='1' where k='voice_enabled'"); sql("update system_settings set v='60' where k='voice_max_seconds'")
sql("insert into system_settings (k,v,updated_at) values ('voice_enabled','1',now()),('voice_max_seconds','60',now()) on duplicate key update v=values(v)")
A, ba = login('anna@muster.ch', 'Sehr-sicher-2026!'); B, bb = login('bob@muster.ch', 'Bob-Passwort-2026!')
chk('Bootstrap meldet Sprachnachrichten', ba['limits']['voice_enabled'] is True and ba['limits']['voice_max_seconds'] == 60, ba['limits'])
s, d = api(A, ba, 'POST', '/api/conversations/direct', {'user_id': bb['me']['id']}); cid = d['conversation']['id']
for nm, f, ext in [('WebM/Opus', webm, 'webm'), ('MP4/AAC (Safari)', m4a, 'm4a'), ('Ogg/Opus', ogg, 'ogg')]:
    s, j = upload(A, ba, cid, f, {'voice': 1, 'duration_ms': 3000}, name='x.bin')
    chk(f'Upload {nm}', s == 201 and j['attachment']['voice'] and j['attachment']['duration_ms'] == 3000 and j['attachment']['name'].endswith('.' + ext) and j['attachment']['name'].startswith('Sprachnachricht-'), (s, j))
s, j = upload(A, ba, cid, webm, {'voice': 1, 'duration_ms': 3000}); aid = j['attachment']['id']
s, j = upload(A, ba, cid, wav, {'voice': 1, 'duration_ms': 3000}); chk('WAV wird als Sprachnachricht abgelehnt', s == 422, (s, j))
s, j = upload(A, ba, cid, bad, {'voice': 1, 'duration_ms': 3000}); chk('Kein Audio (PHP-Text als .webm) abgelehnt', s == 422, (s, j))
s, j = upload(A, ba, cid, webm, {'voice': 1, 'duration_ms': 90000}); chk('Zu lange Aufnahme abgelehnt', s == 422 and 'zu lang' in json.dumps(j), (s, j))
s, j = upload(A, ba, cid, webm, {'voice': 1, 'duration_ms': 100}); chk('Zu kurze Aufnahme abgelehnt', s == 422, (s, j))
s, j = upload(A, ba, cid, webm, {'voice': 1, 'duration_ms': 'abc'}); chk('Ungültige Dauer abgelehnt', s == 422, (s, j))
s, j = upload(A, ba, cid, wav, {}); chk('WAV als normale Datei weiterhin erlaubt', s == 201 and not j['attachment']['voice'], (s, j))
# Senden
s, m = api(A, ba, 'POST', f'/api/conversations/{cid}/messages', {'body': '', 'client_uid': 'voice-1-' + RUN, 'attachment_ids': [aid]})
chk('Sprachnachricht ohne Text senden', s in (200, 201) and m['message']['attachments'][0]['voice'], (s, m))
mid = m['message']['id']
s, ml = api(B, bb, 'GET', f'/api/conversations/{cid}/messages'); got = [x for x in ml['messages'] if x['id'] == mid]
chk('Gegenüber sieht Sprachnachricht mit Dauer', got and got[0]['attachments'][0]['voice'] and got[0]['attachments'][0]['duration_ms'] == 3000, got)
s, h, hd = req(B, 'GET', f'/files/{aid}?download=1'); chk('Gegenüber kann Audio laden (Zugriffsprüfung)', s == 200 and len(h) > 500, s)
op, _ = make(); s, h2, _ = req(op, 'GET', f'/files/{aid}?download=1'); chk('Ohne Anmeldung kein Zugriff', s in (302, 401, 403, 404), s)
s, cl = api(B, bb, 'GET', '/api/conversations'); prev = [c for c in cl['conversations'] if c['id'] == cid][0]['last_message']['text']
chk('Vorschau in der Liste «Sprachnachricht»', prev == 'Sprachnachricht', prev)
# Antwort-Zitat
s, m2 = api(B, bb, 'POST', f'/api/conversations/{cid}/messages', {'body': 'Danke', 'reply_to_id': mid, 'client_uid': 'voice-2-' + RUN})
chk('Zitat zeigt «Sprachnachricht»', m2['message']['reply_to']['text'] == 'Sprachnachricht', m2['message'].get('reply_to'))
# Notizen und Weiterleiten behalten Dauer
s, sv = api(B, bb, 'POST', f'/api/messages/{mid}/notes'); nid = sv['conversation_id']
s, nl = api(B, bb, 'GET', f'/api/conversations/{nid}/messages'); cp = [x for x in nl['messages'] if x['attachments'] and x['attachments'][0]['voice']]
chk('In Notizen speichern behält Dauer und Typ', cp and cp[-1]['attachments'][0]['duration_ms'] == 3000, nl['messages'][-1:])
# Administration
sql("update system_settings set v='0' where k='voice_enabled'")
s, j = upload(A, ba, cid, webm, {'voice': 1, 'duration_ms': 3000}); chk('Deaktiviert: Upload verboten', s == 403, (s, j))
s, b3 = login('anna@muster.ch', 'Sehr-sicher-2026!'); chk('Deaktiviert: Bootstrap meldet aus', b3['limits']['voice_enabled'] is False)
sql("update system_settings set v='1' where k='voice_enabled'")
op, cj = make(); s, h, _ = req(op, 'GET', '/login'); t = csrf(h); req(op, 'POST', '/login', {'email': 'anna@muster.ch', 'password': 'Sehr-sicher-2026!', '_csrf': t})
s, h, hd = req(op, 'GET', '/'); pp = hd.get('Permissions-Policy', ''); csp = hd.get('Content-Security-Policy', '')
chk('Permissions-Policy erlaubt Mikrofon für die eigene Seite', 'microphone=(self' in pp, pp)
chk('CSP erlaubt blob:-Medien', "media-src 'self' blob:" in csp, csp)
s, h, _ = req(op, 'GET', '/admin/settings'); chk('Admin-Einstellungen zeigen Sprachnachrichten', 'voice_max_seconds' in h and 'Sprachnachrichten erlauben' in h)
print('ALLES OK' if ok else 'FEHLER'); sys.exit(0 if ok else 1)
