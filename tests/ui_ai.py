"""UI-Smoketest 1.16.0 (Notizen und KI) mit Chromium/Playwright. Voraussetzung: Dev-Server :8080, KI-Mock :8094, KI aktiv (e2e_ai.py), Konten anna/bob."""
import subprocess, sys, time
from playwright.sync_api import sync_playwright
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
sql('delete from rate_limits'); B = 'http://localhost:8080'; errs = []
def wait_text(pg, sel, text, t=15):
    end = time.time() + t
    while time.time() < end:
        if pg.locator(sel).count() and text.lower() in pg.locator(sel).last.inner_text().lower(): return True
        pg.wait_for_timeout(300)
    return False
with sync_playwright() as p:
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox'])
    ctx = br.new_context(viewport={'width': 1400, 'height': 900}); pg = ctx.new_page(); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/login'); pg.fill('input[name=email]', 'bob@muster.ch'); pg.fill('input[name=password]', 'Bob-Passwort-2026!'); pg.click('button[type=submit]'); pg.wait_for_url(B + '/'); pg.wait_for_selector('#sidebar')
    # Notizen
    first = pg.locator('#sidebar [data-cid], #sidebar li').first
    chk('Notizen stehen in der Liste', pg.locator('#sidebar >> text=Notizen').count() >= 1)
    pg.locator('#sidebar >> text=Notizen').first.click(); pg.wait_for_selector('#composer-input')
    chk('Kopfzeile Notizen', 'nur für dich sichtbar' in pg.locator('#chat-header, header').first.inner_text().lower() or pg.get_by_text('Nur für dich sichtbar').count() >= 1)
    chk('Keine Umfrage/Termin-Werkzeuge in Notizen', pg.locator('button[aria-label="Umfrage erstellen"]').count() == 0 or not pg.locator('button[aria-label="Umfrage erstellen"]').first.is_visible())
    pg.fill('#composer-input', 'Notiz https://example.org/ablage'); pg.keyboard.press('Enter')
    chk('Notiz erscheint', wait_text(pg, '#message-list', 'Notiz https://example.org/ablage'))
    # Neue Unterhaltung mit KI
    pg.click('button[aria-label="Neue Unterhaltung"]'); pg.wait_for_timeout(500)
    pg.fill('input[type=search]', 'KI'); pg.wait_for_timeout(900)
    chk('KI im Verzeichnis mit Kennzeichnung', pg.get_by_text('KI-Assistent · @ki').count() >= 1)
    pg.get_by_text('KI-Assistent · @ki').first.click(); pg.wait_for_timeout(300)
    btns = pg.locator('[role=dialog] button.btn-primary')
    if btns.count(): btns.first.click()
    pg.wait_for_selector('#composer-input'); pg.wait_for_timeout(600)
    chk('Kopfzeile nennt KI', 'ki-assistent' in pg.locator('main, body').first.inner_text().lower())
    pg.fill('#composer-input', 'Wie spät ist es?'); pg.keyboard.press('Enter')
    chk('Antwort mit Kennzeichnung «KI-Antwort»', wait_text(pg, '#message-list', 'Mock-Antwort auf: Wie spät ist es?') and 'KI-Antwort' in pg.locator('#message-list').inner_text())
    chk('Keine «null»-Texte in der Kopfzeile', 'null' not in pg.locator('header').first.inner_text().lower())
    pg.screenshot(path='/tmp/ai/ui_chat.png')
    # Details: Kontext
    pg.click('button[aria-label="Unterhaltungsdetails anzeigen"]'); pg.wait_for_selector('#ai-ctx')
    chk('Kontext-Auswahl mit drei Stufen', pg.locator('#ai-ctx option').count() == 3)
    pg.select_option('#ai-ctx', 'full'); pg.wait_for_timeout(700)
    chk('Kontext gespeichert', sql("select ai_context from conversations where id=(select max(c.id) from conversations c join conversation_members m on m.conversation_id=c.id join users u on u.id=m.user_id where u.is_bot=1 and c.type='direct')") == 'full')
    pg.screenshot(path='/tmp/ai/ui_details.png')
    # Meeting-Knopf bei KI-Chat nicht vorhanden
    chk('Kein Meeting-Button im KI-Chat', pg.locator('button[aria-label="Videomeeting starten"]').count() == 0)
    chk('Keine JS-Fehler', not errs, errs)
    br.close()
print('ALLES OK' if ok else 'FEHLER'); sys.exit(0 if ok else 1)
