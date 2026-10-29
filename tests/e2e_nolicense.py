"""End-to-End-Test 1.19.0 (ohne Lizenz: Registrierung ohne Schlüssel, keine Lizenzseite, keine Sperren). Dev-Server :8080."""
import sys, json, subprocess, uuid, re
sys.path.insert(0, '/tmp/dev')
from drive import *
RUN = uuid.uuid4().hex[:6]
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
def login(email, pw):
    op, cj = make(); s, h, _ = req(op, 'GET', '/login'); t = csrf(h)
    s, h, _ = req(op, 'POST', '/login', {'email': email, 'password': pw, '_csrf': t}); assert s == 302, (email, s)
    return op
sql('delete from rate_limits'); sql("insert into system_settings (k,v,updated_at) values ('registration_enabled','1',now()) on duplicate key update v='1'")
sql("delete from system_settings where k='registration_domains'")
op, cj = make(); s, h, _ = req(op, 'GET', '/register')
chk('Registrierungsformular ohne Lizenzfeld', s == 200 and 'license' not in h.lower() and 'lizenz' not in h.lower(), s)
email = f'frei{RUN}@muster.ch'
s, h, _ = req(op, 'POST', '/register', {'name': 'Frei Test', 'email': email, 'password': 'Sehr-frei-2026!', 'password2': 'Sehr-frei-2026!', '_csrf': csrf(h)})
chk('Registrierung ohne Schlüssel möglich', s in (200, 302) and sql(f"select count(*) from users where email='{email}'") == '1', (s, h[:200]))
A = login('anna@muster.ch', 'Sehr-sicher-2026!')
for path in ['/admin/license', '/admin/license/save']:
    s, h, _ = req(A, 'GET', path); chk(f'{path} existiert nicht mehr', s == 404, s)
for path in ['/admin/users', '/admin/settings', '/admin/tests', '/admin/wizard/user']:
    s, h, _ = req(A, 'GET', path); chk(f'{path} ohne Lizenzhinweis', s == 200 and 'lizenz' not in h.lower() and 'nur-lesen' not in h.lower(), s)
s, h, _ = req(A, 'GET', '/api/bootstrap', headers={'Accept': 'application/json'}); b = json.loads(h)
chk('Bootstrap ohne Lizenzfeld', 'license' not in b, list(b))
sql(f"delete from users where email='{email}'")
print('ALLES OK' if ok else 'FEHLER'); sys.exit(0 if ok else 1)
