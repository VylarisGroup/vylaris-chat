"""UI-Smoketest 1.10.0 (Videokonferenzen) mit Chromium/Playwright. Voraussetzung: Dev-Server :8080, Mock :8093, Konten anna/bob."""
import subprocess, sys, time, json, urllib.request
from playwright.sync_api import sync_playwright
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
def mock(op, d=None): return json.loads(urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:8093/_test/' + op, data=json.dumps(d or {}).encode(), method='POST')).read())
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
sql('delete from rate_limits'); mock('reset')
name = 'Meet ' + str(int(time.time())); B = 'http://localhost:8080'; errs = []
def login(ctx, email, pw):
    pg = ctx.new_page(); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/login'); pg.fill('input[name=email]', email); pg.fill('input[name=password]', pw); pg.click('button[type=submit]'); pg.wait_for_url(B + '/'); pg.wait_for_selector('#sidebar'); return pg
def wait_text(pg, sel, text, t=15):
    end = time.time() + t
    while time.time() < end:
        if pg.locator(sel).count() and text.lower() in pg.locator(sel).first.inner_text().lower(): return True
        pg.wait_for_timeout(300)
    return False
def opengrp(pg):
    pg.locator('#sidebar [role=tab]', has_text='Gruppen').first.click(); pg.wait_for_timeout(300); pg.locator(f'#sidebar >> text={name}').first.click(); pg.wait_for_selector('#composer-input')
with sync_playwright() as p:
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox'])
    ca = br.new_context(viewport={'width': 1400, 'height': 900}); cb = br.new_context(viewport={'width': 1400, 'height': 900})
    a = login(ca, 'anna@muster.ch', 'Sehr-sicher-2026!'); b = login(cb, 'bob@muster.ch', 'Bob-Passwort-2026!')
    r = a.evaluate("""async (name) => { const b = await (await fetch('/api/bootstrap')).json(); const bob = (await (await fetch('/api/users?q=bob')).json()); const u = (bob.users||[]).find(x=>/bob/i.test(x.username||x.display_name)); const res = await fetch('/api/conversations/group', {method:'POST', headers:{'X-CSRF-Token': b.csrf,'Content-Type':'application/json'}, body: JSON.stringify({name, member_ids:[u.id]})}); return res.status; }""", name)
    chk('Gruppe angelegt', r == 201, r)
    a.reload(); a.wait_for_selector('#sidebar'); b.reload(); b.wait_for_selector('#sidebar')
    opengrp(a); opengrp(b)
    chk('Moderation sieht Meeting-Button', a.locator('button[aria-label="Videomeeting starten"]').count() == 1)
    chk('Normales Mitglied sieht keinen Start-Button', b.locator('button[aria-label="Videomeeting starten"]').count() == 0)
    a.click('button[aria-label="Videomeeting starten"]')
    a.wait_for_selector('.meet-overlay iframe')
    src = a.locator('.meet-overlay iframe').get_attribute('src')
    chk('iframe zeigt auf Meeting-Dienst', src.startswith('http://127.0.0.1:8093/?room='), src)
    chk('allow-Attribut gesetzt', 'camera' in a.locator('.meet-overlay iframe').get_attribute('allow') and 'microphone' in a.locator('.meet-overlay iframe').get_attribute('allow'))
    fr = a.frame_locator('.meet-overlay iframe'); fr.locator('#b').wait_for(); chk('iframe lädt trotz CSP', fr.locator('#b').inner_text() == 'Meeting-Mock')
    b.wait_for_selector('[data-meeting]'); chk('Bob sieht Karte «Videomeeting läuft»', 'läuft' in b.locator('[data-meeting]').first.inner_text().lower())
    chk('Bob kann beitreten, aber nicht beenden', b.locator('[data-meeting] >> text=Beitreten').count() == 1 and b.locator('[data-meeting] >> text=Beenden').count() == 0)
    code = sql("select room_code from meetings order by id desc limit 1")
    mock('join', {'code': code, 'name': 'Anna'}); mock('join', {'code': code, 'name': 'Bob'})
    chk('Live-Teilnehmerzahl bei Bob (2 Personen)', wait_text(b, '[data-meeting-count]', '2 Personen'))
    b.click('[data-meeting] >> text=Beitreten'); b.wait_for_selector('.meet-overlay iframe')
    chk('Bob tritt eingebettet bei', 'role' not in (b.locator('.meet-overlay iframe').get_attribute('src') or '') and b.locator('.meet-overlay').count() == 1)
    a.screenshot(path='/tmp/ui_meet_full.png')
    a.click('button[aria-label^="Zurück zum Chat"]'); a.wait_for_timeout(300)
    box = a.locator('.meet-overlay').bounding_box()
    chk('Minimiert: kleines Fenster, Chat bedienbar', box['width'] < 400 and box['height'] < 250, box)
    a.fill('#composer-input', 'Während des Meetings'); a.keyboard.press('Enter'); a.wait_for_selector('text=Während des Meetings')
    a.screenshot(path='/tmp/ui_meet_mini.png')
    chk('Statusleiste «Meeting läuft» sichtbar', a.locator('button[aria-label="Zurück zum Meeting"]').is_visible())
    a.screenshot(path='/tmp/ui_meet_mini.png')
    a.click('button[aria-label="Meeting wieder gross anzeigen"]'); a.wait_for_timeout(300)
    chk('Wieder gross', a.locator('.meet-overlay').bounding_box()['width'] > 800)
    a.keyboard.press('Escape'); a.wait_for_timeout(300)
    chk('Esc minimiert', a.locator('.meet-overlay').bounding_box()['width'] < 400)
    a.click('button[aria-label="Zurück zum Meeting"]'); a.wait_for_timeout(300)
    chk('Statusleiste öffnet wieder gross', a.locator('.meet-overlay').bounding_box()['width'] > 800)
    a.go_back(); a.wait_for_timeout(400)
    chk('Zurück-Taste minimiert statt die App zu verlassen', a.locator('.meet-overlay').count() == 1 and a.locator('.meet-overlay').bounding_box()['width'] < 400 and a.url.startswith(B))
    chk('Verlassen auch bei minimiertem Meeting möglich', a.locator('.fixed button[aria-label="Meeting verlassen"]:visible').count() >= 1)
    a.click('button[aria-label="Meeting verlassen"]:visible'); a.wait_for_timeout(500)
    chk('Verlassen entfernt das iframe', a.locator('.meet-overlay').count() == 0)
    a.on('dialog', lambda d: d.accept())
    a.click('[data-meeting] >> text=Beenden')
    a.locator('.modal button, [role=dialog] button', has_text='Beenden').last.click()
    chk('Bob sieht «Videomeeting beendet» live', wait_text(b, '[data-meeting]', 'beendet'))
    chk('Anna sieht «Videomeeting beendet»', wait_text(a, '[data-meeting]', 'beendet'))
    # Anruf per Benachrichtigung angenommen (App war geschlossen): ?c=..&meeting=..
    cidn = sql(f"select id from conversations where name='{name}'")
    mid2 = a.evaluate("async (c) => { const b = await (await fetch('/api/bootstrap')).json(); const r = await fetch('/api/conversations/' + c + '/meeting', {method:'POST', headers:{'X-CSRF-Token': b.csrf,'Content-Type':'application/json'}, body:'{}'}); return (await r.json()).meeting.id; }", int(cidn))
    b2 = cb.new_page(); b2.on('pageerror', lambda e: errs.append(str(e)))
    b2.goto(f'{B}/?c={cidn}&meeting={mid2}'); b2.wait_for_selector('.meet-overlay iframe', timeout=15000)
    chk('?meeting= tritt direkt eingebettet bei', b2.locator('.meet-overlay iframe').count() == 1)
    chk('Parameter aus der Adresse entfernt', 'meeting=' not in b2.url, b2.url)
    chk('Keine JS-Fehler', not errs, errs)
    br.close()
print('ALL OK' if ok else 'FAILURES'); sys.exit(0 if ok else 1)
