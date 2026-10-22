"""UI-Test 1.15.0 (PWA-Feinheiten, Touch-Emulation): kein Doppeltippen, 16-px-Felder, Zurück-Geste, langes Drücken."""
import subprocess, sys, time
from playwright.sync_api import sync_playwright
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
ok = True
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
sql('delete from rate_limits')
B = 'http://localhost:8080'; errs = []
def login(ctx, email, pw):
    pg = ctx.new_page(); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/login'); pg.fill('input[name=email]', email); pg.fill('input[name=password]', pw); pg.click('button[type=submit]'); pg.wait_for_url(B + '/'); pg.wait_for_selector('#sidebar'); return pg
with sync_playwright() as p:
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox'])
    ctx = br.new_context(viewport={'width': 390, 'height': 780}, has_touch=True, is_mobile=True, device_scale_factor=2)
    a = login(ctx, 'anna@muster.ch', 'Sehr-sicher-2026!')
    chk('Touch: hover-Abfrage «none»', a.evaluate("matchMedia('(hover: none)').matches"))
    chk('html: touch-action manipulation', a.evaluate("getComputedStyle(document.documentElement).touchAction") == 'manipulation')
    chk('Kein Pull-to-Refresh / Gummiband', a.evaluate("getComputedStyle(document.body).overscrollBehaviorY") == 'none')
    chk('Viewport enthält interactive-widget', 'interactive-widget=resizes-content' in a.locator('meta[name=viewport]').get_attribute('content'))
    # Liste → ein Tipp öffnet den Chat
    row = a.locator('#conversation-list [data-cid]').first; cid = row.get_attribute('data-cid')
    chk('Optionen-Knopf ist auf Touch nicht per Hover eingeblendet', row.locator('button[aria-label^="Optionen"]').is_hidden())
    row.tap(); a.wait_for_selector('#composer-input')
    chk('Ein Tipp öffnet den Chat', a.locator('#composer-input').is_visible())
    fs = a.evaluate("getComputedStyle(document.getElementById('composer-input')).fontSize")
    chk('Eingabefeld hat 16 px (kein Auto-Zoom)', fs == '16px', fs)
    chk('Chat öffnet ohne automatisch die Tastatur zu zeigen', a.evaluate("document.activeElement?.id") != 'composer-input')
    # Nachricht senden, ein Tipp auf die Blase öffnet das Menü
    a.fill('#composer-input', 'PWA-Test ' + str(int(time.time()))); a.tap('button[aria-label^="Senden"]') if a.locator('button[aria-label^="Senden"]').count() else a.keyboard.press('Enter')
    a.wait_for_selector('#message-list >> text=PWA-Test'); a.wait_for_timeout(300)
    bubble = a.locator('[data-mid] .shadow-sm', has_text='PWA-Test').last
    bubble.tap(); a.wait_for_timeout(300)
    chk('Ein Tipp auf die Nachricht zeigt das Aktionsmenü (nicht erst Hover)', a.locator('[role=menu]').count() >= 1)
    a.keyboard.press('Escape'); a.wait_for_timeout(200)
    # Zurück-Geste
    a.go_back(); a.wait_for_timeout(500)
    chk('Zurück-Geste führt zur Liste statt aus der App', a.locator('#conversation-list').is_visible() and a.url.startswith(B), a.url)
    chk('Chat-Ansicht ist dabei verborgen', not a.locator('#composer-input').is_visible())
    # Wieder in den Chat, Zurück-Pfeil verbraucht den Verlaufseintrag
    row.tap(); a.wait_for_selector('#composer-input', state='visible'); a.tap('button[aria-label="Zurück zur Liste"]'); a.wait_for_timeout(600)
    chk('Pfeil «Zurück zur Liste» funktioniert', a.locator('#conversation-list').is_visible())
    a.go_back(); a.wait_for_timeout(500)
    chk('Danach kein verwaister Chat-Eintrag (App bleibt auf der Liste oder verlässt sie)', 'about:blank' in a.url or a.locator('#conversation-list').is_visible())
    br.close()
    # Langes Drücken auf eine Unterhaltung
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox'])
    ctx = br.new_context(viewport={'width': 390, 'height': 780}, has_touch=True, is_mobile=True)
    a = login(ctx, 'anna@muster.ch', 'Sehr-sicher-2026!')
    a.evaluate("""() => { const el = [...document.querySelectorAll('#conversation-list [data-cid]')].find((x) => !/Notizen/.test(x.innerText)); const r = el.getBoundingClientRect();
      const t = new Touch({identifier: 1, target: el, clientX: r.x + 40, clientY: r.y + 20});
      el.dispatchEvent(new TouchEvent('touchstart', {touches: [t], bubbles: true, cancelable: true})); }""")
    a.wait_for_timeout(800)
    chk('Langes Drücken öffnet das Unterhaltungsmenü', a.locator('[role=menu]').count() >= 1)
    br.close()
    # Desktop bleibt unverändert: Hover zeigt die Optionen, Feld 14 px
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox'])
    d = login(br.new_context(viewport={'width': 1400, 'height': 900}), 'anna@muster.ch', 'Sehr-sicher-2026!')
    r = d.locator('#conversation-list [data-cid]').first; r.hover(); d.wait_for_timeout(200)
    chk('Desktop: Hover zeigt Optionen weiterhin', r.locator('button[aria-label^="Optionen"]').is_visible())
    r.click(); d.wait_for_selector('#composer-input')
    chk('Desktop: Eingabefeld behält 14 px', d.evaluate("getComputedStyle(document.getElementById('composer-input')).fontSize") == '14px')
    br.close()
chk('Keine JS-Fehler', not errs, errs)
print('ALL OK' if ok else 'FAILURES'); sys.exit(0 if ok else 1)
