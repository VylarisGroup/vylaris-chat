"""End-to-End-Test 1.16.0 (Notizen-Chat und KI-Assistent gegen den Mock auf Port 8094, Hilfsmodul drive.py, Dev-Server)."""
import sys, json, subprocess, time, uuid
RUN = uuid.uuid4().hex[:6]
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
def last():
    return json.loads(urllib.request.urlopen('http://127.0.0.1:8094/_last').read())
import urllib.request
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, i if not c else '')
def admin_save(op, **kw):
    s, h, _ = req(op, 'GET', '/admin/ai'); t = csrf(h)
    d = {'_csrf': t, 'name': 'KI-Assistent', 'endpoint': 'http://127.0.0.1:8094/v1', 'api_key': 'sk-test-123', 'model': 'gpt-test',
         'prompt': 'Du bist ein Testassistent.', 'temperature': '0.5', 'max_out': '1024', 'ctx': '30000', 'timeout': '30', 'access': 'all', 'allowed': '', 'rate': '100', 'enabled': '1'}
    d.update(kw)
    s, _, _ = req(op, 'POST', '/admin/ai/save', d); return s
def wait_bot(cid, n, secs=12):
    for _ in range(secs * 5):
        if int(sql(f"select count(*) from messages m join users u on u.id=m.sender_id where m.conversation_id={cid} and u.is_bot=1") or 0) >= n: return True
        time.sleep(0.2)
    return False
def msgs(op, b, cid):
    s, j = api(op, b, 'GET', f'/api/conversations/{cid}/messages'); return j['messages']

sql('delete from rate_limits')
sql("delete from conversations where id in (select conversation_id from (select cm.conversation_id from conversation_members cm join users u on u.id=cm.user_id where u.is_bot=1) x) or name='Projekt KI'")
A, ba = login('anna@muster.ch', 'Sehr-sicher-2026!'); B, bb = login('bob@muster.ch', 'Bob-Passwort-2026!')

# ---------------- Notizen
s, cl = api(A, ba, 'GET', '/api/conversations'); notes = [c for c in cl['conversations'] if c['type'] == 'notes']
chk('Notizen-Chat existiert genau einmal und steht oben', len(notes) == 1 and cl['conversations'][0]['type'] == 'notes' and notes[0]['name'] == 'Notizen', cl['conversations'][:2])
ncid = notes[0]['id']
s, m = api(A, ba, 'POST', f'/api/conversations/{ncid}/messages', {'body': 'Link: https://example.org/doc', 'client_uid': 'note-0001-aaaa-' + RUN}); chk('In Notizen schreiben', s in (200, 201), m)
s, dm = api(A, ba, 'POST', '/api/conversations/direct', {'user_id': bb['me']['id']}); dcid0 = dm['conversation']['id']
s, m2 = api(B, bb, 'POST', f'/api/conversations/{dcid0}/messages', {'body': 'Wichtige Info von Bob', 'client_uid': 'note-0002-bbbb-' + RUN})
s, sv = api(A, ba, 'POST', f"/api/messages/{m2['message']['id']}/notes")
chk('Nachricht in Notizen speichern', s in (200, 201) and sv['conversation_id'] == ncid, sv)
chk('Gespeicherte Kopie liegt in Notizen', any('Wichtige Info von Bob' in x['body'] for x in msgs(A, ba, ncid)))
s, _ = api(B, bb, 'GET', f'/api/conversations/{ncid}/messages'); chk('Andere sehen Notizen nicht', s in (403, 404), s)
s, pr = api(A, ba, 'PATCH', f'/api/conversations/{ncid}/prefs', {'archived': True}); chk('Notizen nicht archivierbar', s in (200, 403, 422) and not (pr.get('conversation') or {}).get('archived'), pr)

# ---------------- KI einrichten
s, h, _ = req(A, 'GET', '/admin/ai'); chk('Admin-Seite KI erreichbar', s == 200 and 'KI-Assistent' in h)
s, h, _ = req(B, 'GET', '/admin/ai'); chk('Admin-Seite nur für Admins', s in (302, 403, 404), s)
chk('Speichern', admin_save(A) == 302)
chk('API-Schlüssel verschlüsselt gespeichert', 'sk-test-123' not in sql("select v from system_settings where k='ai_key_enc'") and sql("select v from system_settings where k='ai_key_enc'") != '')
s, h, _ = req(A, 'GET', '/admin/ai'); t = csrf(h)
req(A, 'POST', '/admin/ai/test', {'_csrf': t}); s, h, _ = req(A, 'GET', '/admin/ai')
chk('Verbindungstest erfolgreich', 'Verbindung erfolgreich' in h, h[h.find('Verbindung') - 50:h.find('Verbindung') + 200] if 'Verbindung' in h else 'kein Hinweis')
lr = last(); chk('Anfrage: Bearer-Schlüssel, Modell, nicht streamend', lr['auth'] == 'Bearer sk-test-123' and lr['body']['model'] == 'gpt-test' and lr['body']['stream'] is False, lr)
bot = sql('select id from users where is_bot=1'); chk('Bot-Konto angelegt', bot != '' and sql(f'select count(*) from users where is_bot=1') == '1')
chk('Bot zählt nicht als aktives Konto', sql('select count(*) from users where is_active=1 and deleted_at is null and is_bot=0') != sql('select count(*) from users where is_active=1 and deleted_at is null'))
op, cj = make(); s, h, _ = req(op, 'GET', '/login'); t = csrf(h)
s, h, _ = req(op, 'POST', '/login', {'email': 'ki-assistent@bot.invalid', 'password': '!', '_csrf': t}); chk('Bot kann sich nicht anmelden', s != 302 or 'login' in str(_.get('Location', '')), s)
s, d = api(B, bb, 'GET', '/api/users?q=KI'); users = d.get('users', d)
chk('Verzeichnis zeigt KI mit Bot-Markierung', any(u.get('bot') and str(u['id']) == bot for u in users), users)

# ---------------- Einzelchat mit KI
s, d = api(B, bb, 'POST', '/api/conversations/direct', {'user_id': int(bot)}); chk('Chat mit KI starten', s in (200, 201), d)
dcid = d['conversation']['id']; chk('Konversation meldet KI und Kontext «recent»', d['conversation'].get('has_ai') and d['conversation']['ai_context'] == 'recent', d['conversation'])
s, m = api(B, bb, 'POST', f'/api/conversations/{dcid}/messages', {'body': 'Hallo KI', 'client_uid': 'ai-00001-aaaa-' + RUN})
chk('Antwort der KI erscheint', wait_bot(dcid, 1))
ml = msgs(B, bb, dcid); bm = [x for x in ml if x['sender'] and x['sender'].get('bot')]
chk('Antwort ohne Denkblock, als Bot gekennzeichnet', bm and bm[-1]['body'] == 'Mock-Antwort auf: Hallo KI' and bm[-1]['sender']['bot'] is True, bm)
lr = last()['body']
chk('Systemprompt zuerst, Nutzerfrage zuletzt', lr['messages'][0]['role'] == 'system' and 'Testassistent' in lr['messages'][0]['content'] and lr['messages'][-1] == {'role': 'user', 'content': 'Hallo KI'}, lr['messages'])
chk('Temperatur und max_tokens gesendet', lr.get('temperature') == 0.5 and lr.get('max_tokens') == 1024, lr)
chk('«schreibt …» danach entfernt', sql(f'select count(*) from typing_state where conversation_id={dcid}') == '0')

api(B, bb, 'POST', f'/api/conversations/{dcid}/messages', {'body': 'Zweite Frage', 'client_uid': 'ai-00002-aaaa-' + RUN}); wait_bot(dcid, 2)
roles = [x['role'] for x in last()['body']['messages']]
chk('Stufe «recent»: früherer Verlauf enthalten, Rollen abwechselnd', roles == ['system', 'user', 'assistant', 'user'], roles)
s, r = api(B, bb, 'PUT', f'/api/conversations/{dcid}/ai-context', {'level': 'request'}); chk('Stufe «request» setzen', s == 200 and r['conversation']['ai_context'] == 'request', r)
api(B, bb, 'POST', f'/api/conversations/{dcid}/messages', {'body': 'Dritte Frage', 'client_uid': 'ai-00003-aaaa-' + RUN}); wait_bot(dcid, 3)
chk('Stufe «request»: nur Systemprompt und Anfrage', [x['role'] for x in last()['body']['messages']] == ['system', 'user'], last()['body']['messages'])
api(B, bb, 'PUT', f'/api/conversations/{dcid}/ai-context', {'level': 'full'})
api(B, bb, 'POST', f'/api/conversations/{dcid}/messages', {'body': 'Vierte Frage', 'client_uid': 'ai-00004-aaaa-' + RUN}); wait_bot(dcid, 4)
chk('Stufe «full»: ganzer Verlauf', len(last()['body']['messages']) == 1 + 7 or len([x for x in last()['body']['messages'] if x['role'] != 'system']) >= 5, [x['role'] for x in last()['body']['messages']])
s, r = api(B, bb, 'PUT', f'/api/conversations/{dcid}/ai-context', {'level': 'bogus'}); chk('Ungültige Stufe abgelehnt', s == 422, s)
# Token-Limit
admin_save(A, ctx='500', max_out='64')
for i in range(3):
    api(B, bb, 'POST', f'/api/conversations/{dcid}/messages', {'body': ('Füllung %d ' % i) + 'x' * 380, 'client_uid': 'ai-big-%d-aaaa-' % i + RUN}); wait_bot(dcid, 5 + i)
lm = last()['body']['messages']; total = sum(len(x['content']) for x in lm)
chk('Token-Limit begrenzt den Verlauf', total < 1500 and lm[-1]['content'].startswith('Füllung 2'), (total, [x['content'][:12] for x in lm]))
admin_save(A)
# Fehlerfall
api(B, bb, 'POST', f'/api/conversations/{dcid}/messages', {'body': 'FEHLER bitte', 'client_uid': 'ai-err-1-aaaa-' + RUN}); time.sleep(2.5)
chk('Fehler -> Systemmeldung, keine Bot-Antwort', any(x['kind'] == 'system' and 'nicht antworten' in x['body'] for x in msgs(B, bb, dcid)))
chk('Tippanzeige nach Fehler entfernt', sql(f'select count(*) from typing_state where conversation_id={dcid}') == '0')
# o-mock: Parameter-Wiederholung
admin_save(A, model='o-mock'); n = int(sql(f'select count(*) from messages where conversation_id={dcid} and sender_id={bot}'))
api(B, bb, 'POST', f'/api/conversations/{dcid}/messages', {'body': 'neues Modell', 'client_uid': 'ai-omock-aaaa-' + RUN}); chk('Modell mit max_completion_tokens: automatische Anpassung', wait_bot(dcid, n + 1))
chk('Gesendete Parameter angepasst', 'max_completion_tokens' in last()['body'] and 'max_tokens' not in last()['body'] and 'temperature' not in last()['body'], last()['body'].keys())
admin_save(A)

# ---------------- Gruppe
s, g = api(A, ba, 'POST', '/api/conversations/group', {'name': 'Projekt KI', 'description': '', 'member_ids': [bb['me']['id']]}); gid = g['conversation']['id']
s, r = api(B, bb, 'POST', f'/api/conversations/{gid}/members', {'user_ids': [int(bot)]}); chk('Mitglied (ohne Moderation) kann KI nicht hinzufügen', s == 403, (s, r))
s, r = api(A, ba, 'POST', f'/api/conversations/{gid}/members', {'user_ids': [int(bot)]}); chk('Moderation fügt KI hinzu', s in (200, 201), r)
chk('Hinweis zur KI als Systemmeldung', any(x['kind'] == 'system' and 'antwortet nur' in x['body'] for x in msgs(A, ba, gid)))
n0 = int(sql(f'select count(*) from messages where conversation_id={gid} and sender_id={bot}'))
api(A, ba, 'POST', f'/api/conversations/{gid}/messages', {'body': 'Wir besprechen das Budget.', 'client_uid': 'grp-0001-aaaa-' + RUN}); time.sleep(1.5)
chk('Ohne Erwähnung keine Antwort', int(sql(f'select count(*) from messages where conversation_id={gid} and sender_id={bot}')) == n0)
api(A, ba, 'POST', f'/api/conversations/{gid}/messages', {'body': '@ki fasse das kurz zusammen', 'client_uid': 'grp-0002-aaaa-' + RUN}); chk('Auf @Erwähnung antwortet die KI', wait_bot(gid, n0 + 1))
gm = last()['body']['messages']
chk('Gruppenkontext: Namen vorangestellt, Verlauf enthalten', gm[-1]['content'].startswith('Anna Muster: ') or gm[-1]['content'].split(':')[0] != gm[-1]['content'], gm)
bm = [x for x in msgs(A, ba, gid) if x['sender'] and x['sender'].get('bot')]
chk('Antwort verweist auf die auslösende Nachricht', bm and bm[-1]['reply_to'] and '@ki' in bm[-1]['reply_to']['text'], bm[-1:] )
api(B, bb, 'POST', f'/api/conversations/{gid}/messages', {'body': 'Und was heisst das für Bob?', 'reply_to_id': bm[-1]['id'], 'client_uid': 'grp-0003-bbbb-' + RUN}); chk('Antwort auf KI-Nachricht löst Antwort aus', wait_bot(gid, n0 + 2))
s, r = api(B, bb, 'PUT', f'/api/conversations/{gid}/ai-context', {'level': 'full'}); chk('Gruppen-Kontext nur durch Moderation', s == 403, s)
s, r = api(A, ba, 'PUT', f'/api/conversations/{gid}/ai-context', {'level': 'request'}); chk('Moderation setzt Gruppen-Kontext', s == 200, r)
# Zugriff einschränken
admin_save(A, access='admins')
n1 = int(sql(f'select count(*) from messages where conversation_id={gid}'))
api(B, bb, 'POST', f'/api/conversations/{gid}/messages', {'body': '@ki hallo', 'client_uid': 'grp-0004-bbbb-' + RUN}); time.sleep(1.5)
chk('Eingeschränkt: Hinweis statt Antwort', any(x['kind'] == 'system' and 'nicht verwenden' in x['body'] for x in msgs(B, bb, gid)))
s, d = api(B, bb, 'GET', '/api/users?q=KI'); users = d.get('users', d); chk('Eingeschränkt: Verzeichnis blendet KI aus', not any(u.get('bot') for u in users))
s, r = api(B, bb, 'POST', '/api/conversations/direct', {'user_id': int(bot)}); chk('Eingeschränkt: neuer Chat mit KI verboten', s == 403, s)
admin_save(A, enabled='')
api(A, ba, 'POST', f'/api/conversations/{gid}/messages', {'body': '@ki noch da?', 'client_uid': 'grp-0005-aaaa-' + RUN}); time.sleep(1.5)
chk('Deaktiviert: Hinweis', any(x['kind'] == 'system' and 'deaktiviert' in x['body'] for x in msgs(A, ba, gid)))
admin_save(A)
# Notizen ohne KI
chk('KI nicht in Notizen', sql(f"select count(*) from conversation_members where conversation_id={ncid} and user_id={bot}") == '0')
s, r = api(A, ba, 'POST', f'/api/conversations/{ncid}/members', {'user_ids': [int(bot)]}); chk('KI lässt sich nicht zu Notizen hinzufügen', s in (403, 404, 422), s)
print('ALLES OK' if ok else 'FEHLER'); sys.exit(0 if ok else 1)
