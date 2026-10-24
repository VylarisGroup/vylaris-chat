# Sicherheit und bekannte Grenzen

## Was der Messenger schützt

| Bereich | Massnahme |
|---|---|
| Passwörter | Nur `password_hash()` (Argon2id, sonst bcrypt) und `password_verify()`; Länge 10–72 Byte, Buchstaben plus Ziffer oder Sonderzeichen; Rehash bei Bedarf |
| Anmeldung | Rate-Limits je IP (30/15 min) und je Konto (einstellbar, Standard 8 Fehlversuche/15 min = Kontosperre, in der Administration sichtbar und aufhebbar), einheitliche Fehlermeldung (keine Konto-Auflistung), optionaler Passwort-Ablauf, Passwortwechsel auf Verlangen, Audit-Log |
| Zwei-Faktor (TOTP) | RFC 6238 (SHA-1, 6 Stellen, 30 s, ±1 Schritt), Geheimnis mit libsodium verschlüsselt, Replay-Schutz (jeder Zeitschritt nur einmal), 10 Wiederherstellungscodes (nur als SHA-256 gespeichert, einmalig), eigenes Rate-Limit, Zwischenzustand nach dem Passwort läuft nach 5 Minuten ab; für Administratoren per Einstellung vorschreibbar |
| Einmalanmeldung (OIDC) | Authorization-Code-Flow mit PKCE, `state` und `nonce`; geprüft werden Aussteller, Zielgruppe, Ablauf und nonce; Verknüpfung nur über bestätigte E-Mail; automatische Kontoanlage nur für freigegebene Domains. SAML wird nicht unterstützt |
| Push | VAPID (RFC 8292) mit **verschlüsselter Nutzlast** (RFC 8291, aes128gcm): Push-Dienste sehen nur Chiffretext, den allein das Gerät entschlüsselt; die Nutzlast enthält nur den Hinweistext, nie Nachrichteninhalt; Endpunkte nur von erlaubten Push-Diensten (SSRF-Schutz) |
| Backup | SQL-Dump + Dateien als ZIP mit Prüfsumme, optional AES-256 per Passphrase; Web-Download standardmässig **aus** (ein Backup enthält alle Nachrichten), sonst nur mit Passwort und protokolliert; Wiederherstellung nur per Kommandozeile |
| Monitoring | Fehlerzähler (datenbankunabhängig), Alarm-Mail bei Häufung (max. 1/Stunde), Zustandsprüfungen, öffentlicher `/health` ohne Details |
| Sessions | HttpOnly, SameSite=Lax, Secure bei HTTPS, Strict Mode, ID-Wechsel bei Anmeldung; serverseitig widerrufbar (`user_sessions`); Passwortänderung beendet andere Sitzungen |
| CSRF | Token für alle zustandsändernden Anfragen (Header `X-CSRF-Token` oder Formularfeld) plus Origin-Prüfung |
| Datenbank | Ausschliesslich PDO mit nativen Prepared Statements; LIKE-Platzhalter werden maskiert |
| XSS | Ausgabe in Views per `e()`; im Browser werden Nachrichten ausschliesslich als DOM-Textknoten aufgebaut (kein `innerHTML`); Links nur `http(s)`; strenge CSP (`script-src 'self'`, keine Inline-Skripte, keine Fremdquellen) |
| Zugriff | Jede API-Anfrage prüft Mitgliedschaft und Rolle serverseitig (Schutz vor IDOR); Nachrichten vor Beitritt eines Mitglieds bleiben unsichtbar |
| Dateien | Ablage ausserhalb des Webroots unter Zufallsnamen ohne Endung; Endungs-Sperrliste (ausführbare Typen), MIME-Erkennung per `finfo`, Bildprüfung; Auslieferung nur über die Anwendung mit Berechtigungsprüfung, `nosniff`; Nicht-Bilder immer als Download mit Sandbox-CSP; Profilbilder und Logo werden neu kodiert (entfernt Metadaten und Nutzlast) |
| E-Mail | Reset-/Einladungs-/Bestätigungstokens zufällig, nur als SHA-256-Hash gespeichert, einmalig und befristet; Link-Basis stammt aus der Konfiguration, nicht aus dem Host-Header (kein Reset-Link-Poisoning); Antworten auf „Passwort vergessen“ verraten nicht, ob ein Konto existiert |
| Geheimnisse | SMTP-Passwort mit libsodium (secretbox) und Anwendungsschlüssel verschlüsselt |
| Rate-Limits | Senden, Reaktionen, Uploads, Suche, Meldungen, Registrierung, Passwortänderung, Reset |
| Transport | HTTPS-Weiterleitung (`force_https`), HSTS, `X-Content-Type-Options`, `Referrer-Policy`, `frame-ancestors 'none'` |
| Installer | Einmaliger Setup-Code, Sperrdatei nach Abschluss, keine Standardzugangsdaten, Rollback der Konfiguration bei Fehlern |
| Nachvollziehbarkeit | Audit-Log für sicherheitsrelevante Ereignisse (ohne Nachrichteninhalte und Passwörter) |

## Admin-Zugriff auf Nachrichten

Administratoren sehen **keine** privaten Nachrichten. Sie verwalten Konten, Gruppenstruktur und Einstellungen. Einsehbar ist nur eine einzelne **gemeldete** Nachricht, jede Einsicht wird protokolliert. Beachte: Wer **technischen** Zugriff auf Datenbank oder Server hat (Hoster, Systemadministration), kann Nachrichten technisch lesen, weil sie nicht Ende-zu-Ende-verschlüsselt sind. Das ist organisatorisch zu regeln.

## Keine Ende-zu-Ende-Verschlüsselung

Nachrichten sind **nicht** Ende-zu-Ende-verschlüsselt. Sie werden per HTTPS übertragen und im Klartext in der Datenbank gespeichert (Server-seitige Suche und Wiederherstellbarkeit setzen das voraus). Die Architektur ist für eine spätere Erweiterung vorbereitet (getrennte Nachrichtenspeicherung, Geräte-/Schlüsselverwaltung könnte ergänzt werden), implementiert ist sie nicht.

Empfehlungen für den Betrieb: Datenbank-Backups verschlüsseln, Plattenverschlüsselung beim Hoster, Zugriff auf Server und Datenbank minimieren.

## Bekannte Grenzen

- **Echtzeit:** Standard ist Polling (typisch 1–3 Sekunden Verzögerung, im Hintergrund bis 15 Sekunden; ca. 0,4 Anfragen/s je aktivem Nutzer). Optional gibt es Server-Sent Events (Administration → Betrieb): Nachrichten kommen nach ca. 1 Sekunde, **aber jeder offene Tab hält einen PHP-Prozess** (bis zu 25 s pro Verbindung). Auf Shared Hosting mit wenigen PHP-Prozessen kann das die Seite blockieren – vor dem Einsatz mit realistischer Nutzerzahl testen. Es gibt kein WebSocket.
- **Web-Push** ist optional (Administration → Betrieb) und braucht HTTPS. Auf dem iPhone funktioniert es erst, nachdem die App zum Home-Bildschirm hinzugefügt wurde. Push wird nur an Personen gesendet, die gerade nicht aktiv sind; wer auf dem Desktop aktiv ist, bekommt auf dem Handy keinen Push. Die App ist installierbar (PWA), aber nicht offlinefähig.
- **Vorschau nur für Bilder.** Andere Dateitypen werden als Download angeboten. Link-Vorschaukarten zeigen nur Host und Pfad und rufen keine externen Seiten ab (Datenschutz, SSRF-Schutz).
- **Suche per LIKE:** zuverlässig bis zu mittleren Datenmengen (einige Hunderttausend Nachrichten); kein Volltextindex, keine Suche in Dateiinhalten.
- **Kein Virenscan:** Hochgeladene Dateien werden nicht auf Schadsoftware geprüft. Bei Bedarf serverseitigen Scanner (z. B. ClamAV) vorschalten.
- **SSO:** nur OpenID Connect; kein SAML und kein LDAP. SSO-Anmeldungen umgehen die lokale 2FA (der Identitätsanbieter ist zuständig). 2FA nutzt TOTP-Apps, keine Hardware-Schlüssel (WebAuthn).
- **Verlust von Passwort und 2FA:** Wer sein Gerät und die Wiederherstellungscodes verliert, braucht eine Administratorin (Benutzer → „2FA zurücksetzen“). Sind alle Administratoren ausgesperrt, hilft nur ein Eingriff in die Datenbank (`UPDATE users SET totp_secret_enc=NULL, totp_enabled_at=NULL WHERE email=…`).
- **Herkunftshinweis:** «powered by VylarisChat» (Link vylaris.ch) am Fuss der Seitenleisten ist im Code fest verankert (`App\Services\Watermark`), nicht konfigurierbar und wird serverseitig bei jeder Seite mit Seitenleiste erzwungen. Technisch kann ein Betreiber mit Zugriff auf den Quelltext ihn trotzdem entfernen. Vor Manipulation im Browser schützt zusätzlich ein Wächter im Messenger (`mark.js`).
- **Sprachnachrichten:** Aufnahme nur nach Tipp und Freigabe im Browser, die Permissions-Policy gibt das Mikrofon nur der eigenen Seite (und bei aktiven Meetings dem Meeting-Dienst). Der Server akzeptiert als Sprachnachricht nur Audio-Inhalte (Prüfung per Dateiinhalt), erzwingt den Dateinamen und die Maximaldauer und liefert sie wie alle Anhänge nur an Mitglieder aus (kein öffentlicher Link). Wiedergabe über Blob-URL (`media-src 'self' blob:`).
- **KI-Assistent:** Der API-Schlüssel liegt verschlüsselt auf dem Server und gelangt nie in den Browser. Die KI ist ein Systemkonto ohne Anmeldung (Passwort-Hash ungültig, Anmeldung explizit gesperrt), sieht nur Chats, in denen sie Mitglied ist, und dort nur Nachrichten seit ihrem Beitritt. Gäste können sie nicht nutzen, Gruppen nimmt sie nur durch die Moderation auf. An den Anbieter gehen Nachrichtentexte und Anzeigenamen, keine E-Mail-Adressen oder Dateien; der Umfang ist pro Chat wählbar und durch das Token-Limit begrenzt. Antworten sind als «KI-Antwort» gekennzeichnet. Notizen sind nur für die Person selbst sichtbar und der KI nie zugänglich.
- **Videokonferenzen** laufen über den externen Dienst TheRedStonee Meets (nicht Ende-zu-Ende-verschlüsselt durch den Messenger; Audio/Video verarbeitet der Anbieter). Der API-Schlüssel liegt verschlüsselt auf dem Server, der Browser erhält nur kurzlebige persönliche Einladungslinks (10 Minuten). Der Webhook ist per HMAC-SHA256 mit Zeitstempelprüfung (5 Minuten) gesichert. Kamera und Mikrofon sind per Permissions-Policy nur für den Meeting-Dienst freigegeben, und nur wenn die Funktion aktiv ist. Keine Anrufe mit Klingeln.
- **Reaktionen** sind auf eine feste Auswahl von 12 Emojis beschränkt (serverseitig erzwungen).
- **Datenexport:** Die Selbstbedienung läuft als Hintergrundauftrag (Warteschlange in der Datenbank, wird nach der Anfrage, bei der Wartung oder per `bin/worker.php` abgearbeitet). Die Datei bleibt 7 Tage abrufbar.
- **Unterbrechungen:** Nach einem Verbindungsabbruch sendet der Client Nachrichten automatisch erneut (idempotent über eine Nachrichtenkennung, keine Duplikate); bei dauerhaftem Fehler erscheint „Erneut senden“. Entwürfe und noch nicht gesendete Nachrichten werden nur im geöffneten Tab gehalten und gehen beim Neuladen oder Schliessen verloren.

## Sicherheitsrelevante Betriebshinweise

- Document Root **muss** `public/` sein oder die mitgelieferten `.htaccess`-Dateien müssen greifen. Prüfe nach der Installation, dass `/config/config.php`, `/storage/`, `/app/` nicht abrufbar sind.
- Nutze HTTPS ausschliesslich; aktiviere im Setup die HTTPS-Erzwingung.
- Halte PHP und die Datenbank aktuell.
- Lösche `public/setup.php` nach der Installation (optional, da gesperrt).
- Schränke den Zugriff auf `/admin` bei Bedarf zusätzlich per IP oder VPN ein.
- Prüfe regelmässig das Audit-Log auf wiederholte fehlgeschlagene Anmeldungen.


## Datenschutz-Funktionen

* Export der eigenen Daten erfordert das Passwort und ist auf 5 Exporte pro Stunde begrenzt; temporäre Exportdateien liegen in `storage/tmp` (nicht öffentlich) und werden nach spätestens einer Stunde bereinigt.
* Administrative Exporte enthalten keine Nachrichteninhalte und werden im Protokoll vermerkt (`privacy.admin_export`).
* Einschränkung, Löschung und Bearbeitung von Anfragen werden protokolliert.
