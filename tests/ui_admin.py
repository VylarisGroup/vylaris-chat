"""UI-Test 1.16.0: Administrations-Menü (Auffindbarkeit der Menüpunkte, alle Seiten erreichbar). Voraussetzung: Dev-Server :8080, Konto anna (Admin)."""
import sys
from playwright.sync_api import sync_playwright
B = 'http://localhost:8080'; ok = True; errs = []
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
def login(ctx):
    pg = ctx.new_page(); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/login'); pg.fill('input[name=email]', 'anna@muster.ch'); pg.fill('input[name=password]', 'Sehr-sicher-2026!'); pg.click('button[type=submit]'); pg.wait_for_url(B + '/'); return pg
with sync_playwright() as p:
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox'])
    # Desktop, frischer Browser (keine gespeicherten Menüzustände)
    ctx = br.new_context(viewport={'width': 1400, 'height': 900}); pg = login(ctx)
    pg.goto(B + '/admin'); pg.wait_for_selector('nav[data-admin-nav]')
    nav = pg.locator('nav[data-admin-nav]')
    chk('Gruppe «Funktionen» vorhanden', nav.get_by_text('Funktionen', exact=True).count() == 1)
    for label in ['Videokonferenzen', 'KI-Assistent']:
        link = nav.get_by_role('link', name=label)
        chk(f'Menüpunkt «{label}» ist beim ersten Besuch sichtbar', link.count() == 1 and link.is_visible())
    chk('Alle Gruppen sind beim ersten Besuch offen', pg.locator('details[data-nav-group]:not([open])').count() == 0)
    # Jeden Menüpunkt anklicken: Seite lädt, Titel vorhanden, Eintrag markiert
    hrefs = pg.eval_on_selector_all('nav[data-admin-nav] a', 'els => els.map(e => [e.getAttribute("href"), e.innerText.trim()])')
    chk('Menü enthält Einträge', len(hrefs) >= 18, len(hrefs))
    for href, label in hrefs:
        r = pg.goto(href if href.startswith('http') else B + href)
        h1 = pg.locator('main h1').first.inner_text() if pg.locator('main h1').count() else ''
        cur = pg.locator('nav[data-admin-nav] a[aria-current=page]').count()
        chk(f'Seite «{label}» lädt (HTTP {r.status}, Überschrift «{h1}»)', r.status == 200 and h1 != '' and cur == 1 and 'Fehler' not in h1)
    # KI-Seite: Formularfelder und Klick über das Menü
    pg.goto(B + '/admin'); nav = pg.locator('nav[data-admin-nav]')
    nav.get_by_role('link', name='KI-Assistent').click(); pg.wait_for_url('**/admin/ai')
    chk('KI-Seite zeigt Formular', pg.locator('#a-ep').count() == 1 and pg.locator('#a-model').count() == 1 and pg.locator('#a-ctx').count() == 1)
    nav.get_by_role('link', name='Videokonferenzen').click(); pg.wait_for_url('**/admin/meets')
    chk('Meets-Seite zeigt Formular', pg.locator('#m-key').count() == 1)
    # Geschlossene Gruppe bleibt geschlossen, die der aktuellen Seite öffnet sich
    pg.goto(B + '/admin/users'); pg.locator('details[data-nav-group="funktionen"] summary').click(); pg.wait_for_timeout(200)
    pg.reload(); pg.wait_for_selector('nav[data-admin-nav]')
    chk('Gruppe bleibt nach Schliessen zu', pg.locator('details[data-nav-group="funktionen"]').get_attribute('open') is None)
    pg.goto(B + '/admin/ai'); chk('Gruppe der aktuellen Seite ist offen', pg.locator('details[data-nav-group="funktionen"]').get_attribute('open') is not None and pg.get_by_role('link', name='KI-Assistent').first.is_visible())
    # Mobil
    mctx = br.new_context(viewport={'width': 390, 'height': 780}, has_touch=True, is_mobile=True); m = login(mctx)
    m.goto(B + '/admin'); m.wait_for_selector('nav[data-admin-nav]', state='attached')
    chk('Mobil: Menü eingeklappt', not m.locator('nav[data-admin-nav]').is_visible())
    m.locator('details[data-admin-menu] > summary').tap(); m.wait_for_timeout(300)
    chk('Mobil: KI-Assistent nach «Menü» sichtbar', m.get_by_role('link', name='KI-Assistent').is_visible())
    m.get_by_role('link', name='KI-Assistent').tap(); m.wait_for_url('**/admin/ai'); chk('Mobil: KI-Seite öffnet', m.locator('#a-ep').is_visible())
    chk('Keine JS-Fehler', not errs, errs)
    br.close()
print('ALLES OK' if ok else 'FEHLER'); sys.exit(0 if ok else 1)
