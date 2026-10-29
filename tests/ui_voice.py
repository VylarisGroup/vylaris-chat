"""UI-Test 1.17.0: Sprachnachrichten aufnehmen, senden, abspielen (Chromium mit simuliertem Mikrofon). Dev-Server :8080, Konten anna/bob."""
import subprocess, sys, time
from playwright.sync_api import sync_playwright
def sql(q): return subprocess.run(['mysql','-N','-B','messenger_test','-e',q],capture_output=True,text=True).stdout.strip()
ok = True; errs = []
def chk(n, c, i=''):
    global ok; ok &= bool(c); print(('PASS ' if c else 'FAIL ') + n, '' if c else i)
B = 'http://localhost:8080'
sql("insert into system_settings (k,v,updated_at) values ('voice_enabled','1',now()),('voice_max_seconds','300',now()) on duplicate key update v=values(v)"); sql('delete from rate_limits')
def login(ctx, email, pw):
    pg = ctx.new_page(); pg.on('pageerror', lambda e: errs.append(str(e)))
    pg.goto(B + '/login'); pg.fill('input[name=email]', email); pg.fill('input[name=password]', pw); pg.click('button[type=submit]'); pg.wait_for_url(B + '/'); pg.wait_for_selector('#sidebar'); return pg
DIRECT = sql("select c.id from conversations c join conversation_members a on a.conversation_id=c.id join users ua on ua.id=a.user_id and ua.email='anna@muster.ch' join conversation_members b on b.conversation_id=c.id join users ub on ub.id=b.user_id and ub.email='bob@muster.ch' where c.type='direct' order by c.id limit 1")
def open_chat(pg, name):
    # Einzelchat Anna/Bob eindeutig über die Kennung (Gruppen anderer Tests erwähnen dieselben Namen)
    pg.locator('#sidebar [data-cid="' + DIRECT + '"]' if name in ('Anna', 'Bob') else '#sidebar [data-cid]', has_text=None if name in ('Anna', 'Bob') else name).first.click(); pg.wait_for_selector('#composer-input')
with sync_playwright() as p:
    br = p.chromium.launch(executable_path='/opt/pw-browsers/chromium-1194/chrome-linux/chrome', args=['--headless=new', '--no-sandbox', '--use-fake-ui-for-media-stream', '--use-fake-device-for-media-stream'])
    cb = br.new_context(viewport={'width': 1400, 'height': 900}, permissions=['microphone']); ca = br.new_context(viewport={'width': 1400, 'height': 900})
    b = login(cb, 'bob@muster.ch', 'Bob-Passwort-2026!'); a = login(ca, 'anna@muster.ch', 'Sehr-sicher-2026!')
    open_chat(b, 'Anna'); open_chat(a, 'Bob')
    n0 = int(sql("select count(*) from message_attachments where is_voice=1"))
    chk('Mikrofon-Knopf sichtbar', b.locator('button[aria-label="Sprachnachricht aufnehmen"]').is_visible())
    # Aufnahme verwerfen
    b.click('button[aria-label="Sprachnachricht aufnehmen"]'); b.wait_for_selector('[data-voice-bar]')
    chk('Aufnahmeleiste ersetzt Eingabefeld', not b.locator('#composer-input').is_visible() and b.locator('[data-voice-bar]').is_visible())
    b.wait_for_timeout(1500)
    t = b.locator('[data-voice-bar] span.tabular-nums').inner_text(); chk('Timer läuft', t.startswith('0:01') or t.startswith('0:02'), t)
    b.click('button[aria-label="Aufnahme verwerfen"]'); b.wait_for_timeout(500)
    chk('Verwerfen: Eingabefeld zurück, nichts hochgeladen', b.locator('#composer-input').is_visible() and int(sql("select count(*) from message_attachments where is_voice=1")) == n0)
    # Aufnehmen und senden
    b.click('button[aria-label="Sprachnachricht aufnehmen"]'); b.wait_for_selector('[data-voice-bar]'); b.wait_for_timeout(2600)
    b.click('button[aria-label="Sprachnachricht senden"]')
    b.wait_for_selector('#message-list [data-voice]', timeout=15000)
    chk('Sprachnachricht erscheint beim Absender', b.locator('#message-list [data-voice]').count() >= 1)
    end = time.time() + 10
    while time.time() < end and int(sql("select count(*) from message_attachments where is_voice=1 and message_id is not null")) <= n0: b.wait_for_timeout(300)
    row = sql("select duration_ms, mime_type, original_name from message_attachments where is_voice=1 and message_id is not null order by id desc limit 1").split('\t')
    chk('Gespeichert mit Dauer ~2,6 s und Audioformat', 2000 <= int(row[0]) <= 4500 and row[1].startswith(('audio/', 'video/')) and row[2].startswith('Sprachnachricht-'), row)
    chk('Eingabeleiste wieder normal', b.locator('#composer-input').is_visible())
    chk('Vorschau «Sprachnachricht» in der Liste', 'Sprachnachricht' in b.locator('#sidebar [data-cid]', has_text='Anna').first.inner_text())
    # Beim Empfänger abspielen
    a.wait_for_selector('#message-list [data-voice]', timeout=15000)
    node = a.locator('#message-list [data-voice]').last
    chk('Empfänger sieht Player mit Dauer', node.locator('span.tabular-nums').inner_text() in ('0:02', '0:03', '0:04'), node.inner_text())
    node.locator('button[aria-label="Sprachnachricht abspielen"]').click()
    a.wait_for_selector('#message-list [data-voice] button[aria-label="Pausieren"]', timeout=8000)
    chk('Wiedergabe startet (Pause-Knopf, Tempo-Knopf)', node.locator('button[title="Tempo"]').is_visible())
    a.wait_for_timeout(1200)
    chk('Fortschritt wird angezeigt', node.locator('span.tabular-nums').inner_text() not in ('0:02', '0:03', '0:04') or node.locator('div[role=slider]').get_attribute('aria-valuenow') != '0', node.inner_text())
    node.locator('button[title="Tempo"]').click(); chk('Tempo wechselt auf 1,5×', node.locator('button[title="Tempo"]').inner_text() == '1,5×')
    a.wait_for_selector('#message-list [data-voice] button[aria-label="Sprachnachricht abspielen"]', timeout=8000)
    chk('Nach Ende wieder Abspielen-Knopf', True)
    # Chatwechsel bricht Aufnahme ab
    b.click('button[aria-label="Sprachnachricht aufnehmen"]'); b.wait_for_selector('[data-voice-bar]'); b.wait_for_timeout(600)
    open_chat(b, 'Notizen'); b.wait_for_timeout(500)
    chk('Chatwechsel beendet Aufnahme', b.locator('[data-voice-bar]').count() == 0 and b.locator('#composer-input').is_visible())
    # Notizen: Aufnahme geht auch dort
    n1 = int(sql("select count(*) from message_attachments where is_voice=1 and message_id is not null"))
    b.click('button[aria-label="Sprachnachricht aufnehmen"]'); b.wait_for_selector('[data-voice-bar]'); b.wait_for_timeout(1800); b.click('button[aria-label="Sprachnachricht senden"]')
    end = time.time() + 10
    while time.time() < end and int(sql("select count(*) from message_attachments where is_voice=1 and message_id is not null")) <= n1: b.wait_for_timeout(300)
    chk('Sprachnachricht in Notizen', int(sql("select count(*) from message_attachments where is_voice=1 and message_id is not null")) == n1 + 1)
    # Deaktiviert: Knopf weg
    sql("update system_settings set v='0' where k='voice_enabled'"); b.reload(); b.wait_for_selector('#sidebar'); open_chat(b, 'Anna')
    chk('Deaktiviert: kein Mikrofon-Knopf', not b.locator('button[aria-label="Sprachnachricht aufnehmen"]').is_visible())
    sql("update system_settings set v='1' where k='voice_enabled'")
    # Mikrofon verweigert
    c3 = br.new_context(viewport={'width': 1400, 'height': 900}, permissions=[]); c3.add_init_script("navigator.mediaDevices.getUserMedia = () => Promise.reject(Object.assign(new Error('x'), {name: 'NotAllowedError'}))")
    d = login(c3, 'bob@muster.ch', 'Bob-Passwort-2026!'); open_chat(d, 'Anna'); d.click('button[aria-label="Sprachnachricht aufnehmen"]'); d.wait_for_timeout(800)
    chk('Mikrofon verweigert: Hinweis, Eingabe bleibt nutzbar', 'mikrofon' in d.locator('body').inner_text().lower() and d.locator('#composer-input').is_visible())
    d.screenshot(path='/tmp/ai/voice_denied.png'); b.screenshot(path='/tmp/ai/voice_b.png'); a.screenshot(path='/tmp/ai/voice_a.png')
    chk('Keine JS-Fehler', not errs, errs)
    br.close()
print('ALLES OK' if ok else 'FEHLER'); sys.exit(0 if ok else 1)
