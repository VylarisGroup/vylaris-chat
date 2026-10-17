"""UI-Smoketest 1.9.0 mit Chromium/Playwright. Voraussetzung: Dev-Server auf :8080, Konten anna/bob."""
import subprocess, sys, time
from playwright.sync_api import sync_playwright
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
sql('delete from rate_limits')
name = 'UI ' + str(int(time.time()))
B = 'http://localhost:8080'
def login(ctx, email, pw):
    pg = ctx.new_page(); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/login'); pg.fill('input[name=email]', email); pg.fill('input[name=password]', pw); pg.click('button[type=submit]'); pg.wait_for_url(B + '/'); pg.wait_for_selector('#sidebar'); return pg
errs = []
def opengrp(pg):
    pg.locator('#sidebar [role=tab]', has_text='Gruppen').first.click(); pg.wait_for_timeout(300); pg.locator(f'#sidebar >> text={name}').first.click()
with sync_playwright() as p:
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox'])
    ca = br.new_context(viewport={'width': 1400, 'height': 900}); ca2 = br.new_context(viewport={'width': 1400, 'height': 900}); cb = br.new_context(viewport={'width': 1400, 'height': 900})
    a = login(ca, 'anna@muster.ch', 'Sehr-sicher-2026!')
    # Gruppe über die API anlegen (UI dafür existiert bereits)
    a.evaluate("""async (name) => { const csrf = document.querySelector('meta[name=csrf-token]')?.content; }""", name)
    r = a.evaluate("""async (name) => { const b = await (await fetch('/api/bootstrap')).json(); const bob = (await (await fetch('/api/users?q=bob')).json()); const u = (bob.users||[]).find(x=>/bob/i.test(x.username||x.display_name)); const res = await fetch('/api/conversations/group', {method:'POST', headers:{'X-CSRF-Token': b.csrf,'Content-Type':'application/json'}, body: JSON.stringify({name, member_ids:[u.id]})}); return res.status; }""", name)
    chk('Gruppe angelegt', r == 201, r)
    a.reload(); a.wait_for_selector('#sidebar')
    a.click('text=Gruppen') if a.locator('text=Gruppen').count() else None
    a.wait_for_timeout(500)
    a.locator(f'#sidebar >> text={name}').first.click(); a.wait_for_selector('#composer-input')
    # Nachricht, Thread
    a.fill('#composer-input', 'Hallo Team'); a.keyboard.press('Enter'); a.wait_for_selector('text=Hallo Team')
    row = a.locator('[data-mid]', has_text='Hallo Team').first
    row.locator('button[aria-label="Im Thread antworten"]').dispatch_event('click')
    a.wait_for_selector('#thread-input'); a.fill('#thread-input', 'Erste Thread-Antwort'); a.keyboard.press('Enter')
    a.wait_for_selector('#thread-list >> text=Erste Thread-Antwort')
    chk('Thread-Antwort im Panel', True)
    chk('Antwort nicht im Hauptverlauf', a.locator('#message-list >> text=Erste Thread-Antwort').count() == 0)
    a.wait_for_selector('#message-list [data-thread-summary]'); chk('Zusammenfassung «1 Antwort»', '1 Antwort' in a.locator('[data-thread-summary]').first.inner_text())
    a.screenshot(path='/tmp/ui_thread.png')
    a.click('button[aria-label="Thread schliessen"]'); chk('Thread schliessbar', a.locator('#thread-input').count() == 0)
    # Umfrage
    a.click('button[aria-label="Umfrage erstellen"]'); a.wait_for_selector('[role=dialog]')
    dlg = a.locator('[role=dialog]')
    dlg.locator('input').nth(0).fill('Mittagessen heute?'); dlg.locator('input').nth(1).fill('Pizza'); dlg.locator('input').nth(2).fill('Sushi')
    dlg.get_by_text('Umfrage senden').click(); a.wait_for_selector('[data-poll]')
    chk('Umfrage sichtbar', a.locator('[data-poll]').count() == 1)
    a.locator('[data-poll] button', has_text='Pizza').click(); a.wait_for_timeout(600)
    chk('Stimme gezählt', '100%' in a.locator('[data-poll] button', has_text='Pizza').inner_text(), a.locator('[data-poll]').inner_text())
    # Termin
    a.click('button[aria-label="Termin erstellen"]'); a.wait_for_selector('[role=dialog]')
    dlg = a.locator('[role=dialog]'); dlg.locator('input').nth(0).fill('Kickoff-Meeting'); dlg.locator('input[placeholder^="Ort"]').fill('Raum 4')
    dlg.get_by_text('Termin senden').click(); a.wait_for_selector('[data-event]')
    chk('Termin sichtbar', 'Kickoff-Meeting' in a.locator('[data-event]').inner_text())
    chk('ICS-Link vorhanden', a.locator('[data-event] a[href*="calendar/event/"]').count() == 1)
    a.locator('[data-event] button', has_text='Zusage').click(); a.wait_for_timeout(600)
    chk('Zusage gesetzt', a.locator('[data-event] button[aria-pressed=true]').count() == 1)
    a.screenshot(path='/tmp/ui_cards.png')
    # Bob sieht alles live
    b = login(cb, 'bob@muster.ch', 'Bob-Passwort-2026!')
    opengrp(b); b.wait_for_selector('[data-poll]', timeout=15000)
    chk('Bob sieht Umfrage + Termin', b.locator('[data-poll]').count() == 1 and b.locator('[data-event]').count() == 1)
    chk('Bob sieht Thread-Zusammenfassung', b.locator('[data-thread-summary]').count() == 1)
    b.locator('[data-poll] button', has_text='Sushi').click(); b.wait_for_timeout(500)
    a.wait_for_timeout(4000)
    chk('Anna sieht Bobs Stimme live', '2 Personen haben abgestimmt' in a.locator('[data-poll]').inner_text(), a.locator('[data-poll]').inner_text())
    b.locator('[data-thread-summary]').first.click(); b.wait_for_selector('#thread-input'); chk('Bob öffnet Thread', b.locator('#thread-list >> text=Erste Thread-Antwort').count() == 1)
    b.fill('#thread-input', 'Bob antwortet'); b.keyboard.press('Enter'); a.wait_for_timeout(4500)
    chk('Anna sieht «2 Antworten» live', '2 Antworten' in a.locator('[data-thread-summary]').first.inner_text(), a.locator('[data-thread-summary]').first.inner_text())
    # Entwürfe: zweites Gerät von Anna
    a2 = login(ca2, 'anna@muster.ch', 'Sehr-sicher-2026!')
    opengrp(a2); a2.wait_for_selector('#composer-input')
    a.click('#composer-input'); a.keyboard.type('Entwurf von Gerät eins'); a.wait_for_timeout(1500)
    a.locator('#sidebar').click(position={'x': 5, 'y': 400})
    a2.wait_for_timeout(4500)
    chk('Entwurf erscheint auf Gerät 2', a2.input_value('#composer-input') == 'Entwurf von Gerät eins', a2.input_value('#composer-input'))
    a2.click('#composer-input'); a2.keyboard.press('End'); a2.keyboard.type(' und zwei'); a2.wait_for_timeout(1500)
    a2.locator('#sidebar').click(position={'x': 5, 'y': 400}); a.wait_for_timeout(4500)
    chk('Entwurf zurück auf Gerät 1', a.input_value('#composer-input') == 'Entwurf von Gerät eins und zwei', a.input_value('#composer-input'))
    a.click('#composer-input'); a.keyboard.press('Enter'); a2.wait_for_timeout(4500)
    chk('Senden leert Entwurf auf Gerät 2', a2.input_value('#composer-input') == '', a2.input_value('#composer-input'))
    # Kalender-Abo in Einstellungen
    a.click('button[aria-label="Einstellungen"]') if a.locator('button[aria-label="Einstellungen"]').count() else a.keyboard.press('Escape')
    a.screenshot(path='/tmp/ui_main.png')
    # Mobile
    cm = br.new_context(viewport={'width': 390, 'height': 800}); m = login(cm, 'bob@muster.ch', 'Bob-Passwort-2026!')
    opengrp(m); m.wait_for_selector('[data-poll]'); m.screenshot(path='/tmp/ui_mobile.png')
    ov = m.evaluate("document.documentElement.scrollWidth > window.innerWidth"); chk('Mobil: kein horizontales Scrollen', not ov)
    chk('Keine JS-Fehler', not errs, errs)
    br.close()
print('\nERGEBNIS:', 'ALLES BESTANDEN' if ok else 'FEHLER'); sys.exit(0 if ok else 1)
