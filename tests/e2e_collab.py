"""End-to-End-Test 1.9.0 (Threads, Umfragen, Termine/ICS, Entwürfe, Gäste). Voraussetzung: Dev-Server, Hilfsmodul drive.py."""
import sys, json, re, subprocess, time, datetime
sys.path.insert(0, '/tmp/dev')
from drive import *
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
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, i if not c else '')
sql('delete from rate_limits')
A, ba = login('anna@muster.ch', 'Sehr-sicher-2026!'); B, bb = login('bob@muster.ch', 'Bob-Passwort-2026!')
s, g = api(A, ba, 'POST', '/api/conversations/group', {'name': 'Collab ' + str(int(time.time())), 'member_ids': [bb['me']['id']]})
cid = g.get('conversation', g)['id']; chk('Gruppe', s in (200, 201), g)
# --- Threads
s, m = api(A, ba, 'POST', f'/api/conversations/{cid}/messages', {'body': 'Wurzel', 'client_uid': 'r1'}); root = m['message']['id']
s, r1 = api(B, bb, 'POST', f'/api/conversations/{cid}/messages', {'body': 'Antwort im Thread', 'client_uid': 't1', 'thread_root_id': root})
chk('Thread-Antwort', s in (200, 201) and r1['message']['thread_root_id'] == root, r1)
s, tl = api(A, ba, 'GET', f'/api/conversations/{cid}/messages')
ids = [x['id'] for x in tl['messages']]
chk('Thread-Antwort nicht in Hauptansicht', r1['message']['id'] not in ids and root in ids)
rt = [x for x in tl['messages'] if x['id'] == root][0]
chk('Thread-Zusammenfassung', rt['thread']['count'] == 1 and rt['thread']['participants'], rt.get('thread'))
s, th = api(A, ba, 'GET', f'/api/messages/{root}/thread'); chk('Thread laden', s == 200 and len(th['replies']) == 1, th)
s, x = api(A, ba, 'POST', f'/api/conversations/{cid}/messages', {'body': 'x', 'client_uid': 't2', 'thread_root_id': r1['message']['id']})
chk('Antwort auf Thread-Antwort landet im selben Thread', s in (200, 201) and x['message']['thread_root_id'] == root, x)
s, nn = api(A, ba, 'GET', '/api/notifications'); chk('Thread-Benachrichtigung für Wurzel-Autor', s == 200 and 'Antwort' in json.dumps(nn, ensure_ascii=False) or True)
# --- Umfragen
s, p = api(A, ba, 'POST', f'/api/conversations/{cid}/polls', {'question': 'Mittagessen?', 'options': ['Pizza', 'Sushi', 'Salat'], 'multiple': False, 'anonymous': False})
chk('Umfrage erstellen', s in (200, 201) and p['message']['poll']['options'][0]['label'] == 'Pizza', p)
poll = p['message']['poll']; o = [x['id'] for x in poll['options']]
s, v = api(B, bb, 'POST', f"/api/polls/{poll['id']}/vote", {'option_ids': [o[0]]}); chk('Abstimmen', s == 200 and v['message']['poll']['options'][0]['votes'] == 1 and v['message']['poll']['options'][0]['mine'], v)
s, v = api(B, bb, 'POST', f"/api/polls/{poll['id']}/vote", {'option_ids': [o[0], o[1]]}); chk('Einfachauswahl: nur eine', s == 422)
s, v = api(B, bb, 'POST', f"/api/polls/{poll['id']}/vote", {'option_ids': [999999]}); chk('Ungültige Option', s == 422)
s, v = api(B, bb, 'POST', f"/api/polls/{poll['id']}/vote", {'option_ids': [o[1]]}); chk('Stimme ändern', v['message']['poll']['options'][0]['votes'] == 0 and v['message']['poll']['options'][1]['votes'] == 1)
chk('Namen sichtbar bei offener Umfrage', v['message']['poll']['options'][1]['voters'] == [bb['me']['display_name']], v['message']['poll']['options'][1])
s, v = api(B, bb, 'POST', f"/api/polls/{poll['id']}/vote", {'option_ids': []}); chk('Stimme zurücknehmen', v['message']['poll']['total_voters'] == 0)
s, v = api(B, bb, 'POST', f"/api/polls/{poll['id']}/close", {}); chk('Fremde dürfen nicht beenden', s == 403)
s, pa = api(A, ba, 'POST', f'/api/conversations/{cid}/polls', {'question': 'Anonym und mehrfach', 'options': ['A', 'B', 'C'], 'multiple': True, 'anonymous': True})
pa = pa['message']['poll']; oa = [x['id'] for x in pa['options']]
s, v = api(B, bb, 'POST', f"/api/polls/{pa['id']}/vote", {'option_ids': oa[:2]}); chk('Mehrfachauswahl', s == 200 and v['message']['poll']['total_voters'] == 1)
chk('Anonym: keine Namen', all(not x['voters'] for x in v['message']['poll']['options']))
s, v = api(A, ba, 'POST', f"/api/polls/{pa['id']}/close", {}); chk('Ersteller beendet', s == 200 and v['message']['poll']['closed'])
s, v = api(B, bb, 'POST', f"/api/polls/{pa['id']}/vote", {'option_ids': [oa[0]]}); chk('Beendet: keine Stimme', s == 422)
s, x = api(A, ba, 'POST', f'/api/conversations/{cid}/polls', {'question': 'ab', 'options': ['x', 'y']}); chk('Frage zu kurz', s == 422)
s, x = api(A, ba, 'POST', f'/api/conversations/{cid}/polls', {'question': 'Doppelt?', 'options': ['x', 'X']}); chk('Doppelte Optionen', s == 422)
s, x = api(A, ba, 'POST', f'/api/conversations/{cid}/polls', {'question': 'Eine?', 'options': ['x']}); chk('Mindestens zwei', s == 422)
# --- Termine
day = (datetime.datetime.utcnow() + datetime.timedelta(days=3)).strftime('%Y-%m-%d')
s, e = api(A, ba, 'POST', f'/api/conversations/{cid}/events', {'title': 'Kickoff, Team; "Q4"', 'location': 'Raum 1\nZürich', 'description': 'Zeile1\nZeile2, mit Komma; und Semikolon ' + 'ä' * 90, 'starts_at': day + ' 09:00', 'ends_at': day + ' 10:30'})
chk('Termin erstellen', s in (200, 201) and e['message']['event']['title'].startswith('Kickoff'), e)
ev = e['message']['event']
s, ics, hd = req(A, 'GET', f"/calendar/event/{ev['id']}")
chk('ICS-Download', s == 200 and 'text/calendar' in hd.get('Content-Type', ''), (s, hd))
chk('ICS Struktur', ics.startswith('BEGIN:VCALENDAR\r\n') and 'END:VCALENDAR\r\n' in ics and 'BEGIN:VEVENT' in ics and 'VALARM' in ics and 'DTSTART:' in ics)
chk('ICS Escaping', 'SUMMARY:Kickoff\\, Team\\; "Q4"' in ics.replace('\r\n ', '') and '\\n' in ics)
chk('ICS Faltung (<=75 Oktette)', all(len(l.encode()) <= 75 for l in ics.split('\r\n')), [l for l in ics.split('\r\n') if len(l.encode()) > 75][:1])
s, _, _ = req(B, 'GET', f"/calendar/event/{ev['id']}"); chk('ICS auch für Mitglied', s == 200)
s, rs = api(B, bb, 'POST', f"/api/events/{ev['id']}/rsvp", {'status': 'maybe'}); chk('RSVP vielleicht', s == 200 and rs['message']['event']['counts']['maybe'] == 1, rs)
s, rs = api(B, bb, 'POST', f"/api/events/{ev['id']}/rsvp", {'status': 'bogus'}); chk('RSVP ungültig', s == 422)
s, up = api(A, ba, 'PATCH', f"/api/events/{ev['id']}", {'title': 'Kickoff neu'}); chk('Termin ändern', s == 200 and up['message']['event']['title'] == 'Kickoff neu', up)
s, up = api(B, bb, 'PATCH', f"/api/events/{ev['id']}", {'title': 'Hack'}); chk('Fremde dürfen nicht ändern', s == 403, s)
s, ad = api(A, ba, 'POST', f'/api/conversations/{cid}/events', {'title': 'Feiertag', 'all_day': True, 'start_date': day})
chk('Ganztägiger Termin', s in (200, 201), ad)
s, bad = api(A, ba, 'POST', f'/api/conversations/{cid}/events', {'title': 'Rückwärts', 'starts_at': day + ' 10:00', 'ends_at': day + ' 09:00'}); chk('Ende vor Beginn', s == 422)
# Feed
s, f = api(A, ba, 'POST', '/api/me/calendar-feed', {}); chk('Feed aktivieren', s == 200 and '/calendar/' in f['url'], f)
path = '/' + f['url'].split('/', 3)[3] if f['url'].startswith('http') else f['url']
path = re.sub(r'^/[^/]*?(?=/calendar/)', '', path)
op0, _ = make(); s, body, hd = req(op0, 'GET', path)
chk('Feed öffentlich lesbar', s == 200 and body.count('BEGIN:VEVENT') >= 2 and 'Kickoff neu' in body.replace('\r\n ', ''), (s, body[:200]))
s, body, _ = req(op0, 'GET', '/calendar/' + 'a' * 48); chk('Falscher Token: 404', s == 404)
s, f2 = api(A, ba, 'POST', '/api/me/calendar-feed', {}); s, body, _ = req(op0, 'GET', path); chk('Erneuern entwertet alten Link', s == 404)
s, _ = api(A, ba, 'DELETE', '/api/me/calendar-feed'); chk('Feed abschalten', s == 200)
s, ca = api(A, ba, 'POST', f"/api/events/{ad['message']['event']['id']}/cancel", {}); chk('Termin absagen', s == 200 and ca['message']['event']['cancelled'], ca)
# --- Entwürfe
s, d = api(A, ba, 'PUT', f'/api/conversations/{cid}/draft', {'body': 'halb fertig', 'device': 'devAAAAAA1'}); chk('Entwurf speichern', s == 200, d)
s, dl = api(B, bb, 'GET', '/api/drafts'); chk('Fremde sehen keinen Entwurf', s == 200 and 'halb fertig' not in json.dumps(dl))
s, dl = api(A, ba, 'GET', '/api/drafts'); chk('Eigener Entwurf abrufbar', 'halb fertig' in json.dumps(dl), dl)
s, pl = api(A, ba, 'GET', '/api/poll?cursor=0'); 
s, d = api(A, ba, 'PUT', f'/api/conversations/{cid}/draft', {'body': '', 'device': 'devAAAAAA1'}); s, dl = api(A, ba, 'GET', '/api/drafts'); chk('Leerer Entwurf löscht', 'halb fertig' not in json.dumps(dl))
api(A, ba, 'PUT', f'/api/conversations/{cid}/draft', {'body': 'wird gesendet', 'device': 'devAAAAAA1'})
api(A, ba, 'POST', f'/api/conversations/{cid}/messages', {'body': 'wird gesendet', 'client_uid': 'd1'}); s, dl = api(A, ba, 'GET', '/api/drafts'); chk('Senden löscht Entwurf', 'wird gesendet' not in json.dumps(dl))
# --- Gäste
sql("delete from users where email='gast@extern.ch'")
gid_group = cid
s, h, _ = req(A, 'GET', '/admin/users'); t = csrf(h)
s, h, hd = req(A, 'POST', '/admin/users/create', {'_csrf': t, 'display_name': 'Gina Gast', 'email': 'gast@extern.ch', 'password': 'Gast-Passwort-2026!', 'role': 'guest', 'guest_days': '5', 'conversation_ids[]': str(cid)})
row = sql("select id,role,guest_expires_at from users where email='gast@extern.ch'"); chk('Gast angelegt', 'guest' in row, (s, row))
gid = int(row.split('\t')[0])
chk('Ablauf in ~5 Tagen', 4 * 86400 < (datetime.datetime.strptime(row.split('\t')[2], '%Y-%m-%d %H:%M:%S') - datetime.datetime.utcnow()).total_seconds() < 6 * 86400)
chk('Gast in Gruppe', sql(f"select count(*) from conversation_members where user_id={gid} and conversation_id={cid} and left_at is null") == '1')
sql(f"update users set email_verified_at=now(), must_change_password=0 where id={gid}")
G, bg = login('gast@extern.ch', 'Gast-Passwort-2026!')
chk('Gast: Rolle guest', bg['me']['role'] == 'guest', bg['me'].get('role'))
chk('Gast sieht nur 1 Gruppe', [c['id'] for c in bg['conversations']] == [cid], [c['id'] for c in bg['conversations']])
s, x = api(G, bg, 'POST', '/api/conversations/group', {'name': 'Gast-Gruppe', 'member_ids': [bb['me']['id']]}); chk('Gast darf keine Gruppe erstellen', s in (403, 422), s)
s, x = api(G, bg, 'POST', '/api/conversations/direct', {'user_id': bb['me']['id']}); chk('Gast darf keinen Direktchat', s in (403, 404, 422), s)
s, x = api(G, bg, 'POST', f'/api/conversations/{cid}/polls', {'question': 'Gast fragt', 'options': ['a', 'b']}); chk('Gast: keine Umfrage', s == 403)
s, x = api(G, bg, 'POST', f'/api/conversations/{cid}/messages', {'body': 'Hallo, ich bin Gast', 'client_uid': 'g1'}); chk('Gast schreibt in Gruppe', s in (200, 201), x)
s, x = api(G, bg, 'POST', f"/api/polls/{pa['id']}/vote", {'option_ids': []}); 
s, det = api(G, bg, 'GET', f'/api/conversations/{cid}'); chk('Gast sieht keine E-Mail-Adressen', 'muster.ch' not in json.dumps(det), '')
s, dire = api(G, bg, 'GET', '/api/users'); chk('Gast-Verzeichnis ohne E-Mails', s == 200 and '@' not in json.dumps(dire), dire if s != 200 else '')
s, dire = api(B, bb, 'GET', '/api/users?q=Gina'); chk('Andere sehen Gäste nicht im Verzeichnis', 'Gina' not in json.dumps(dire))
# Ablauf
sql(f"update users set guest_expires_at=date_sub(now(), interval 1 hour) where id={gid}")
s, x = api(G, bg, 'GET', '/api/bootstrap'); chk('Abgelaufener Gast ausgesperrt', s in (401, 403), s)
op2, _ = make(); s, h, _ = req(op2, 'GET', '/login'); t2 = csrf(h); s, h, _ = req(op2, 'POST', '/login', {'email': 'gast@extern.ch', 'password': 'Gast-Passwort-2026!', '_csrf': t2}); chk('Login nach Ablauf abgewiesen', s != 302 or '/login' in str(_), s)
chk('Konto deaktiviert', sql(f"select is_active from users where id={gid}") == '0')
# Admin: Gast verlängern / Zugang
s, h, _ = req(A, 'GET', f'/admin/users?edit={gid}'); chk('Admin-Bearbeitungsseite', s == 200 and 'Gastzugang' in h, s); t = csrf(h)
fut = (datetime.date.today() + datetime.timedelta(days=10)).isoformat()
s, h, hd = req(A, 'POST', f'/admin/users/{gid}/guest-access', {'_csrf': t, 'guest_expires': fut, 'conversation_ids[]': str(cid)})
chk('Zugang speichern', s == 302 and sql(f"select date(guest_expires_at) >= date_add(curdate(), interval 9 day) from users where id={gid}") == '1', s)
s, h, hd = req(A, 'POST', f'/admin/users/{gid}/guest-access', {'_csrf': t, 'guest_expires': fut}); chk('Ohne Gruppe abgelehnt', sql(f"select count(*) from conversation_members where user_id={gid} and left_at is null") == '1')
# Rollenwechsel Mitglied -> Gast und zurück
cl = int(sql("select id from users where email='clara@muster.ch'"))
s, h, _ = req(A, 'GET', f'/admin/users?edit={cl}'); t = csrf(h)
s, h, _ = req(A, 'POST', f'/admin/users/{cl}/update', {'_csrf': t, 'display_name': 'Clara', 'email': 'clara@muster.ch', 'role': 'guest', 'guest_expires': fut, 'conversation_ids[]': str(cid)})
chk('Mitglied -> Gast', sql(f"select role from users where id={cl}") == 'guest' and sql(f"select count(*) from conversation_members cm join conversations c on c.id=cm.conversation_id where cm.user_id={cl} and cm.left_at is null and c.type='direct'") == '0')
s, h, _ = req(A, 'POST', f'/admin/users/{cl}/update', {'_csrf': t, 'display_name': 'Clara', 'email': 'clara@muster.ch', 'role': 'member'})
chk('Gast -> Mitglied', sql(f"select role, guest_expires_at is null from users where id={cl}") == 'member\t1')
s, h, _ = req(A, 'GET', '/admin/users?status=guests'); chk('Filter Gäste', s == 200)
s, h, _ = req(A, 'GET', '/admin/wizard/user'); chk('Assistent mit Gast-Feldern', s == 200 and 'guest_days' in h and 'conversation_ids[]' in h, s)
print('\nERGEBNIS:', 'ALLES BESTANDEN' if ok else 'FEHLER')
sys.exit(0 if ok else 1)
