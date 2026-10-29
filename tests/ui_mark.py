"""UI-Test 1.18.0: Herkunftshinweis «powered by VylarisChat» (Messenger- und Admin-Seitenleiste, Manipulationsschutz). Dev-Server :8080, Konten anna/bob."""
import sys, re, urllib.request
from playwright.sync_api import sync_playwright
B = 'http://localhost:8080'; ok = True; errs = []
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
def login(ctx, email='anna@muster.ch', pw='Sehr-sicher-2026!'):
    pg = ctx.new_page(); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/login'); pg.fill('input[name=email]', email); pg.fill('input[name=password]', pw); pg.click('button[type=submit]'); pg.wait_for_url(B + '/'); pg.wait_for_selector('#sidebar'); return pg
def good(pg, scope):
    m = pg.locator(f'{scope} a[data-vl-mark]')
    return m.count() == 1 and m.is_visible() and m.inner_text().strip() == 'powered by VylarisChat' and m.get_attribute('href') == 'https://vylaris.ch'
with sync_playwright() as p:
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox'])
    ctx = br.new_context(viewport={'width': 1400, 'height': 900}); pg = login(ctx)
    chk('Messenger: Hinweis unten in der Seitenleiste', good(pg, '#sidebar'))
    box = pg.locator('#sidebar a[data-vl-mark]').bounding_box(); sb = pg.locator('#sidebar').bounding_box()
    chk('Messenger: am unteren Rand der Leiste', abs((box['y'] + box['height']) - (sb['y'] + sb['height'])) < 3 and box['width'] > 100, (box, sb))
    chk('Messenger: öffnet vylaris.ch in neuem Tab (noopener)', pg.locator('#sidebar a[data-vl-mark]').get_attribute('target') == '_blank' and 'noopener' in pg.locator('#sidebar a[data-vl-mark]').get_attribute('rel'))
    pg.screenshot(path='/tmp/ai/mark_app.png')
    # Manipulation: entfernen, ausblenden, Text und Link ändern, per CSS verkleinern
    pg.evaluate("document.querySelector('#sidebar a[data-vl-mark]').remove()"); pg.wait_for_timeout(300); chk('Entfernen wird rückgängig gemacht', good(pg, '#sidebar'))
    pg.evaluate("document.querySelector('#sidebar a[data-vl-mark]').style.display='none'"); pg.wait_for_timeout(300); chk('Ausblenden (style) wird rückgängig gemacht', good(pg, '#sidebar'))
    pg.evaluate("document.querySelector('#sidebar a[data-vl-mark]').textContent='eigener Text'; "); pg.wait_for_timeout(300); chk('Text ändern wird rückgängig gemacht', good(pg, '#sidebar'))
    pg.evaluate("document.querySelector('#sidebar a[data-vl-mark]').href='https://example.org'"); pg.wait_for_timeout(300); chk('Link ändern wird rückgängig gemacht', good(pg, '#sidebar'))
    pg.evaluate("const s=document.createElement('style'); s.textContent='.vl-mark{opacity:0 !important;font-size:0 !important;height:0 !important;overflow:hidden}'; document.head.append(s)"); pg.wait_for_timeout(2600)
    chk('Ausblenden per CSS (Zusatz-Stylesheet) bleibt wirkungslos', good(pg, '#sidebar') and pg.locator('#sidebar a[data-vl-mark]').bounding_box()['height'] > 10)
    pg.evaluate("document.getElementById('vl-mark-tpl').remove(); document.querySelector('#sidebar a[data-vl-mark]').remove()"); pg.wait_for_timeout(300)
    chk('Auch ohne Vorlage kommt der Hinweis zurück', pg.locator('#sidebar a[data-vl-mark]').count() == 1 and pg.locator('#sidebar a[data-vl-mark]').get_attribute('href') == 'https://vylaris.ch')
    # Administration
    pg.goto(B + '/admin'); pg.wait_for_selector('nav[data-admin-nav]')
    chk('Administration: Hinweis in der Seitenleiste', good(pg, 'aside'))
    pg.screenshot(path='/tmp/ai/mark_admin.png')
    for path in ['/admin/users', '/admin/ai', '/admin/settings']:
        pg.goto(B + path); chk(f'Administration {path}: genau ein Hinweis', good(pg, 'aside'))
    # Mobil
    m = login(br.new_context(viewport={'width': 390, 'height': 780}, has_touch=True, is_mobile=True))
    chk('Mobil: Hinweis in der Liste sichtbar', good(m, '#sidebar'))
    m.locator('#sidebar [data-cid]').first.tap(); m.wait_for_selector('#composer-input', state='visible'); m.wait_for_timeout(500)
    chk('Mobil: im Chat (Leiste verborgen) kein Fehler', m.locator('a[data-vl-mark]').count() == 1)
    m.go_back(); m.wait_for_timeout(500); chk('Mobil: zurück in der Liste wieder sichtbar', good(m, '#sidebar'))
    # Server: veränderte Vorlagen werden zurückgesetzt, fehlende ergänzt (Login-Seiten haben keine Seitenleiste)
    html = urllib.request.urlopen(B + '/login').read().decode(); chk('Login-Seite ohne Hinweis (keine Seitenleiste)', 'data-vl-mark' not in html)
    chk('Keine JS-Fehler', not errs, errs)
    br.close()
print('ALLES OK' if ok else 'FEHLER'); sys.exit(0 if ok else 1)
