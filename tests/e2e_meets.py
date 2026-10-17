"""End-to-End-Test 1.15.0 (Videokonferenzen gegen den Mock-Server auf Port 8093, Hilfsmodul drive.py, Dev-Server)."""
import sys, json, subprocess, time, urllib.request
sys.path.insert(0, '/tmp/dev')
from drive import *
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
def mock(op, d=None):
    r = urllib.request.Request('http://127.0.0.1:8093/_test/' + op, data=json.dumps(d or {}).encode(), method='POST')
    return json.loads(urllib.request.urlopen(r).read())
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
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, i if not c else '')
sql('delete from rate_limits'); mock('reset')
A, ba = login('anna@muster.ch', 'Sehr-sicher-2026!'); B, bb = login('bob@muster.ch', 'Bob-Passwort-2026!')
chk('Bootstrap meldet Meets', ba.get('meets') and ba['meets']['origin'] == 'http://127.0.0.1:8093', ba.get('meets'))
# CSP/Permissions-Policy
s, _, hd = req(A, 'GET', '/', headers={'Accept': 'text/html'})
csp = hd.get('Content-Security-Policy', ''); pp = hd.get('Permissions-Policy', '')
chk('CSP frame-src nur Meeting-Dienst', "frame-src http://127.0.0.1:8093;" in csp, csp)
chk('Permissions-Policy delegiert Kamera/Mikrofon', 'camera=(self "http://127.0.0.1:8093")' in pp and 'microphone=(self "http://127.0.0.1:8093")' in pp, pp)
# Direktchat
s, d = api(A, ba, 'POST', '/api/conversations/direct', {'user_id': bb['me']['id']}); dcid = d['conversation']['id']
s, st = api(B, bb, 'POST', f'/api/conversations/{dcid}/meeting')
chk('Direktchat: Gegenüber darf starten', s == 200 and st['message']['kind'] == 'meeting' and st['meeting']['active'] and st['link']['role'] == 'host', st)
chk('Einladungslink zeigt auf Meeting-Dienst', st['link']['embed_url'].startswith('http://127.0.0.1:8093/?room=') and st['link']['embed_url'].endswith('&embed=1'))
mid = st['meeting']['id']; code = sql(f'select room_code from meetings where id={mid}')
s, again = api(B, bb, 'POST', f'/api/conversations/{dcid}/meeting')
chk('Zweiter Start liefert dasselbe Meeting', s == 200 and again['existing'] and again['meeting']['id'] == mid, again)
chk('Nur ein Raum angelegt', sql(f"select count(*) from meetings where conversation_id={dcid} and ended_at is null") == '1')
s, j = api(A, ba, 'POST', f'/api/meetings/{mid}/join'); chk('Beitreten', s == 200 and j['link']['role'] == 'host' and j['meeting']['active'], j)
# Karte in Nachrichten
s, ml = api(A, ba, 'GET', f'/api/conversations/{dcid}/messages'); card = [x for x in ml['messages'] if x['kind'] == 'meeting'][-1]
chk('Karte im Verlauf', card['meeting']['id'] == mid and card['meeting']['participants'] == 0, card)
# Webhook live
s, cur = api(A, ba, 'GET', '/api/poll?cursor=0') ; cursor = cur.get('cursor', 0)
r = mock('join', {'code': code, 'name': 'Bob'}); chk('Webhook participant.joined akzeptiert (204)', r['status'] == 204, r)
mock('join', {'code': code, 'name': 'Anna'})
s, pl = api(A, ba, 'GET', f'/api/poll?cursor={cursor}')
upd = [m for m in pl.get('messages', []) if m['kind'] == 'meeting']
chk('Poll liefert aktualisierte Karte mit 2 Teilnehmern', upd and upd[-1]['meeting']['participants'] == 2, pl.get('messages'))
chk('Peak gespeichert', sql(f'select peak from meetings where id={mid}') == '2')
mock('leave', {'code': code}); s, stt = api(A, ba, 'GET', f'/api/meetings/{mid}')
chk('participant.left senkt Anzahl', stt['message']['meeting']['participants'] == 1, stt)
# Webhook-Sicherheit
r = mock('badhook', {'code': code}); chk('falsches Geheimnis -> 401, alter Zeitstempel -> 401', r['status'] == 401 and r['old'] == 401, r)
r = mock('badhook', {'code': code, 'tamper': True}); chk('manipulierter Rumpf -> 401', r['status'] == 401)
s, stt = api(A, ba, 'GET', f'/api/meetings/{mid}'); chk('Meeting nach ungültigen Webhooks weiter aktiv', stt['message']['meeting']['active'])
s, h, _ = req(op := make()[0], 'POST', '/api/meets/webhook', raw=b'{}', headers={'Content-Type': 'application/json'}); chk('Webhook ohne Signatur -> 401', s == 401, s)
# Beenden
s, e = api(A, ba, 'POST', f'/api/meetings/{mid}/end'); chk('Gegenüber darf im Direktchat beenden', s == 200 and not e['message']['meeting']['active'], e)
chk('Raum beim Dienst gelöscht', ['DELETE', f'/rooms/{code}'] in [c for c in json.loads(urllib.request.urlopen('http://127.0.0.1:8093/_test/calls', data=b'{}').read())['calls']])
s, j = api(A, ba, 'POST', f'/api/meetings/{mid}/join'); chk('Beitritt zu beendetem Meeting -> 410', s == 410, j)
# Gruppe: nur Moderation
s, g = api(A, ba, 'POST', '/api/conversations/group', {'name': 'Meets ' + str(int(time.time())), 'member_ids': [bb['me']['id']]}); gcid = g['conversation']['id']
s, j = api(B, bb, 'POST', f'/api/conversations/{gcid}/meeting'); chk('Gruppe: einfaches Mitglied darf nicht starten', s == 403, j)
s, j = api(A, ba, 'POST', f'/api/conversations/{gcid}/meeting'); chk('Gruppe: Ersteller (Admin) startet', s == 200 and j['meeting']['active'], j); gm = j['meeting']['id']; gcode = sql(f'select room_code from meetings where id={gm}')
s, j = api(B, bb, 'POST', f'/api/meetings/{gm}/join'); chk('Gruppe: Mitglied tritt als Gast-Rolle bei', s == 200 and j['link']['role'] == 'guest' and not j['meeting']['can_end'], j)
s, j = api(B, bb, 'POST', f'/api/meetings/{gm}/end'); chk('Gruppe: Mitglied darf nicht beenden', s == 403, j)
# Webhook room.ended
mock('end', {'code': gcode, 'reason': 'host'}); s, stt = api(A, ba, 'GET', f'/api/meetings/{gm}'); chk('room.ended beendet Meeting', not stt['message']['meeting']['active'], stt)
chk('ended_reason gespeichert', sql(f'select ended_reason from meetings where id={gm}') == 'host')
s, ga = api(A, ba, 'POST', f'/api/conversations/{gcid}/meeting'); gm2 = ga['meeting']['id']
# Raum-Limit
n0 = sql("select count(*) from messages where kind='meeting'"); mock('limit', {'max': 0}); s, j = api(A, ba, 'POST', f'/api/conversations/{dcid}/meeting'); chk('Raum-Limit -> 409 mit Klartext', s == 409 and 'zu viele' in json.dumps(j, ensure_ascii=False), (s, j))
chk('Bei Fehler keine Meeting-Nachricht angelegt', sql("select count(*) from messages where kind='meeting'") == n0); mock('limit', {'max': 20})
# Ablauf: abgelaufene Meetings schliessen
sql(f"update meetings set expires_at = '2000-01-01 00:00:00' where id={gm2}")
s, stt = api(A, ba, 'GET', f'/api/meetings/{gm2}'); chk('Abgelaufenes Meeting gilt als beendet', not stt['message']['meeting']['active'], stt)
# Funktion aus
subprocess.run(['php', '/tmp/dev/setmeets.php', '0'], capture_output=True)
s, j = api(A, ba, 'POST', f'/api/conversations/{dcid}/meeting'); chk('Deaktiviert: Start -> 404', s == 404, (s, j))
s, _, hd = req(A, 'GET', '/', headers={'Accept': 'text/html'})
chk('Deaktiviert: Kamera/Mikrofon gesperrt, frame-src none', "frame-src 'none'" in hd.get('Content-Security-Policy', '') and 'camera=()' in hd.get('Permissions-Policy', ''), hd.get('Permissions-Policy'))
s, nb = api(A, ba, 'GET', '/api/bootstrap'); chk('Deaktiviert: Bootstrap ohne Meets', nb.get('meets') is None)
subprocess.run(['php', '/tmp/dev/setmeets.php', '1'], capture_output=True)
print('ALL OK' if ok else 'FAILURES'); sys.exit(0 if ok else 1)
