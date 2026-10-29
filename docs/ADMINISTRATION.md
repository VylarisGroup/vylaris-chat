# Administration

Die Administration erreichst du als Administrator über dein Profilmenü unten links im Messenger („Administration“) oder unter `/admin`.

## Übersicht

Kennzahlen (Benutzer, Gruppen, Nachrichten, Dateien, offene Meldungen), Systemzustand (PHP, HTTPS, SMTP, Speicherplatz, letzte Bereinigung, Backup, 2FA der Administratoren) und die letzten Ereignisse.

## Benutzer

- **Einladen:** Person erhält einen Link (7 Tage gültig) und legt Name und Passwort selbst fest. Die E-Mail-Adresse gilt dadurch als bestätigt. Ohne funktionierendes SMTP zeigt die Seite den Link zur manuellen Weitergabe.
- **Konto anlegen:** Ohne Anfangspasswort erhält die Person eine E-Mail, um eines zu setzen (empfohlen). Mit Anfangspasswort musst du es sicher übermitteln.
- **Gäste:** Rolle «Gast» für externe Personen (Einladung, direkt anlegen oder Rolle ändern; auch im Assistenten). Ein Gast braucht mindestens eine Gruppe und einen Ablauf (Standard 30 Tage, höchstens 365). Gäste sehen nur ihre Gruppen, haben keine Direktchats, sehen keine E-Mail-Adressen und können weder Gruppen, Umfragen noch Termine erstellen; in der Benutzerliste erscheinen sie mit «bis <Datum>». Auf der Bearbeitungsseite eines Gasts lassen sich Ablaufdatum und Gruppen anpassen. Nach Ablauf wird das Konto automatisch deaktiviert (beim nächsten Anmeldeversuch bzw. durch den Hintergrundlauf); mit neuem Datum ist es wieder nutzbar.
- **Verwalten:** Name, E-Mail, Rolle ändern; Passwort-Link senden; Bestätigungs-Mail erneut senden oder manuell bestätigen; alle Sitzungen beenden; deaktivieren/aktivieren; löschen.
- **Löschen = Anonymisieren:** Name, E-Mail und Bild werden entfernt, Sitzungen beendet. Bisherige Nachrichten bleiben als „Gelöschter Benutzer“ erhalten (DSG: Personenbezug wird entfernt).
- **Schutzregeln:** Du kannst dich nicht selbst deaktivieren, löschen oder herabstufen. Der letzte Administrator kann weder deaktiviert noch gelöscht noch herabgestuft werden.

## Zusammenarbeit im Messenger

- **Threads:** Antworten auf eine Nachricht (Symbol «Im Thread antworten») erscheinen in einem eigenen Seitenbereich und nicht im Hauptverlauf.
- **Umfragen und Termine:** Über die Symbole im Eingabefeld. Termine lassen sich als `.ics` herunterladen; unter Einstellungen → Kalender gibt es einen persönlichen Abo-Link (geheim, erneuerbar, abschaltbar).
- **Entwürfe:** Werden bis zu 30 Tage serverseitig gespeichert, damit sie auf allen Geräten verfügbar sind.
- Der Kalender-Link ist öffentlich erreichbar (ohne Anmeldung), aber nur mit dem 48-stelligen Schlüssel; Fehlversuche werden je IP-Adresse begrenzt.

## Sprachnachrichten

Unter *Einstellungen → System* schaltest du Sprachnachrichten ein oder aus und legst die maximale Dauer fest (5 bis 1800 Sekunden, Standard 300). Sie werden wie Dateien im Speicherverzeichnis abgelegt (rund 0,5 MB pro Minute), unterliegen der Dateigrössen-Grenze, der Aufbewahrungsfrist und der Löschung der Nachricht. Das Mikrofon braucht eine HTTPS-Verbindung. Es gibt keine automatische Umwandlung oder Texterkennung.

## KI-Assistent

Unter *System → KI-Assistent* verbindest du einen OpenAI-kompatiblen Dienst: Basis-Adresse (z. B. `https://api.openai.com/v1`, bei lokalen Servern `http://host:11434/v1`), API-Schlüssel (leer bei lokalen Servern), Modellname, Systemprompt, Temperatur (leer = Modellstandard), maximale Antwortlänge, Kontext-Limit (höchstens 90 000 Tokens, geschätzt mit 3 Zeichen pro Token) und Zeitlimit. «Verbindung testen» sendet eine kurze Testfrage mit den gespeicherten Werten. Es gibt genau eine KI; sie legt beim Aktivieren das Systemkonto @ki an (ohne Anmeldung). Wer sie nutzen darf, steuerst du unter «Wer darf die KI nutzen?» (alle ohne Gäste / nur Administratoren / Administratoren und Liste von Benutzernamen) sowie über das Stundenlimit pro Person. Gruppen holen die KI über «Mitglieder hinzufügen» (nur Moderation), den mitgelesenen Verlauf wählt die Moderation in den Gruppendetails. Wird die KI deaktiviert, bleiben Chats erhalten, sie antwortet aber nicht mehr. Datenschutz: Der Anbieter erhält Nachrichtentexte (siehe Datenschutzerklärung); Vertrag (AVV) und Standort prüfen. Fehler der Anfragen stehen im Protokoll (`storage/logs`).

## Videokonferenzen

Unter *System → Videokonferenzen* trägst du den API-Schlüssel (`trsm_…`) und das Webhook-Geheimnis (`whsec_…`) von TheRedStonee Meets ein und aktivierst die Funktion. Beim Anbieter müssen zwei Dinge hinterlegt sein: die Adresse dieses Messengers als erlaubte Einbettungs-Domain (`embedOrigins`) und die auf der Seite angezeigte Webhook-Adresse (`…/api/meets/webhook`). «Verbindung testen» prüft Schlüssel, Einbettungs-Domain und Webhook. Schlüssel und Geheimnis lassen sich dort speichern, ersetzen und entfernen (alternativ als Rückfall in `config/config.php` unter `'meets' => ['api_key' => …, 'webhook_secret' => …]`; ein in der Administration gespeicherter Wert hat Vorrang). Mit «Geräte benachrichtigen» steuerst du den Push beim Meeting-Start (setzt aktivierten Push voraus). Ein Schlüssel erlaubt standardmässig 20 gleichzeitige Räume, ein Meeting pro Unterhaltung. Datenschutz: Der Anbieter verarbeitet Audio, Video, Namen und IP-Adressen; einen Auftragsverarbeitungsvertrag abschliessen.

## Gruppen

Liste aller Gruppen mit Mitgliederzahl und Nachrichtenanzahl. **Es werden keine Nachrichten angezeigt.** In der Detailansicht kannst du in Notfällen (Gruppe ohne aktiven Administrator) ein bestehendes Mitglied zum Gruppenadministrator ernennen. Die Ernennung wird im Audit-Log festgehalten und der Gruppe als Systemhinweis angezeigt.

## Gemeldete Nachrichten

Mitglieder können fremde Nachrichten melden. Du siehst ausschliesslich die gemeldete Einzelnachricht mit Begründung, nicht die übrige Unterhaltung. Jede Einsicht wird protokolliert. Du kannst die Nachricht entfernen (Inhalt und Anhänge werden gelöscht) oder die Meldung ablehnen.

## Einstellungen

- **Organisation:** Firmenname, Akzentfarbe (zu helle Farben werden abgelehnt, damit weisse Schrift lesbar bleibt), Logo, Selbstregistrierung (aus Standardeinstellung), erlaubte E-Mail-Domains, E-Mail-Bestätigung.
- **System:** maximale Dateigrösse (begrenzt durch PHP-Limits des Servers), Bearbeitungsfrist für Nachrichten, Aufbewahrungsfristen für Nachrichten und Protokoll, E-Mail-Benachrichtigungen insgesamt.
- **SMTP:** Server, Port, Verschlüsselung, Zugangsdaten (Passwort wird mit dem Anwendungsschlüssel verschlüsselt gespeichert). „Testen“ prüft die Verbindung oder sendet eine Testmail, ohne vorher zu speichern.
- **Wartung:** Bereinigung manuell auslösen.

### Aufbewahrung

Mit „Nachrichten aufbewahren (Tage)“ werden ältere Nachrichten samt Dateien endgültig gelöscht (0 = nie). Informiere die Mitarbeitenden über die Regel (Datenschutzerklärung). Das Protokoll wird nach der eingestellten Frist (Standard 365 Tage) gelöscht.

## Sicherheit

Unter **Sicherheit**: 2FA für Administratoren vorschreiben (wer keine hat, wird nach der Anmeldung zur Einrichtung geleitet), Passwort-Ablauf (Tage), Sperre nach Fehlversuchen (Anzahl/Minuten), Liste **gesperrter Konten** mit „Sperre aufheben“, Kennzahlen zu 2FA und Fehlversuchen sowie die Konfiguration der **Einmalanmeldung (OpenID Connect)**. Richte zuerst dein eigenes 2FA ein, bevor du es vorschreibst.

Pro Person (Benutzer → Verwalten): *Sperre aufheben*, *Passwortwechsel verlangen* (bei der nächsten Aktion), *2FA zurücksetzen* (bei Geräteverlust).

**SSO einrichten (OIDC):** Beim Anbieter eine „Web“-Anwendung registrieren, als Weiterleitungs-URL die auf der Seite angezeigte Adresse eintragen, dann Issuer-URL, Client-ID und Secret eintragen (Entra ID: `https://login.microsoftonline.com/<Tenant>/v2.0`). Bestehende Konten werden über die bestätigte E-Mail-Adresse verknüpft; „automatisch anlegen“ gilt nur für die genannten Domains.

## Betrieb

**Betrieb** zeigt den Zustand (Datenbank, Speicherplatz, Wartung, Backup, Aufträge, Fehler, HTTPS), die Fehlerüberwachung (Alarm-Mail ab *n* Fehlern in *m* Minuten, höchstens eine pro Stunde; benötigt SMTP) und die letzten Log-Einträge. Für externe Überwachung liefert `/health` nur `ok` oder `fail`; `php bin/health.php` endet bei Problemen mit Exit-Code 1.

**Echtzeit:** Polling (Standard) oder Server-Sent Events. SSE ist schneller, belegt aber pro offenem Tab einen PHP-Prozess. **Push-Benachrichtigungen:** ein Schalter erzeugt die VAPID-Schlüssel; Nutzende aktivieren Push unter Einstellungen → Benachrichtigungen. Braucht HTTPS. Die App lässt sich zusätzlich als PWA installieren.

Empfohlene Cron-Einträge: `*/15 * * * * php bin/cleanup.php` (Wartung), `* * * * * php bin/worker.php` (Hintergrundaufträge, optional), `30 2 * * * php bin/backup.php …` (Backup).

## Protokoll (Audit-Log)

Anmeldungen (auch fehlgeschlagene und blockierte), Registrierungen, Passwortänderungen, Sitzungsereignisse, Konto- und Gruppenänderungen durch Administratoren, Einsicht in Meldungen, gemeldete und moderierte Nachrichten, Einstellungsänderungen. Filter nach Aktion, Benutzer und Zeitraum. Das Protokoll enthält keine Nachrichteninhalte und keine Passwörter.

## Betrieb

### Datensicherung (Backup)

Unter **Backup** erstellst du ein ZIP mit Datenbank-Dump (`database.sql`), allen Dateien aus `storage/` (Uploads, Profilbilder, Logo) und `meta.json` (Prüfsumme). Optional mit Passphrase (AES-256) und mit `config/config.php`.

* **Ein Backup enthält alle Nachrichten im Klartext.** Deshalb liegt es nur auf dem Server (`storage/backups/`, nicht öffentlich). Den **Download im Browser** schaltest du bewusst unter „Automatisierung und Aufbewahrung“ ein (verlangt dein Passwort, wird protokolliert). Standard: aus, damit Administratoren keine Nachrichteninhalte erhalten. Hole Backups per SFTP oder über das Hosting-Panel.
* **Automatisch:** Einstellung „alle … Tage“ (läuft bei der Wartung im Hintergrund) oder per Cron: `30 2 * * * php /pfad/bin/backup.php --passphrase-file=/pfad/pass.txt --config`. Es werden die letzten *n* Backups behalten.
* **Prüfen:** Der Knopf „Prüfen“ (bzw. `php bin/restore.php datei.zip`) kontrolliert Lesbarkeit und Prüfsumme.
* **Wiederherstellen** nur per Kommandozeile: `php bin/restore.php storage/backups/backup-….zip --yes` (überschreibt Datenbank-Tabellen und Dateien; `config.php` wird nie automatisch überschrieben). Teste das regelmässig auf einer Kopie.
* **config.php nicht vergessen:** Sie enthält den Schlüssel `app.key`. Ohne ihn lassen sich SMTP-Passwort, SSO-Secret und 2FA-Geheimnisse nicht entschlüsseln. Bewahre sie getrennt und geschützt auf.
* Gelöschte Daten können bis zum Ablauf der Aufbewahrung noch in Backups liegen (steht in der Datenschutzerklärung).

### Updates

Siehe [INSTALLATION.md](INSTALLATION.md#update).

### Protokolle

PHP-/Anwendungsfehler stehen in `storage/logs/`. Fehlermeldungen an Benutzer enthalten keine internen Details.

### Passwort vergessen (Administrator)

Über „Passwort vergessen“ auf der Anmeldeseite. Sind alle Administratoren gesperrt und SMTP nicht nutzbar, kann der Hoster per Datenbank ein neues Passwort setzen (`password_hash` mit PHP erzeugen und in `users.password_hash` eintragen).

## Datenschutz (DSG, Schweiz)

Der Messenger unterstützt datenschutzfreundlichen Betrieb: Datensparsamkeit (keine Nachrichten in E-Mails, keine Link-Vorschau mit externen Abrufen), Zugriff nur für Mitglieder, nachvollziehbares Audit-Log, einstellbare Aufbewahrung, Löschung/Anonymisierung von Konten, Auskunft über die gespeicherten Daten über den Administrator (Datenbank). Als Betreiber bleibst du für Rechtsgrundlagen, Verzeichnis der Bearbeitungstätigkeiten, Information der Mitarbeitenden und Auftragsverarbeiter-Verträge (Hoster, SMTP-Anbieter) selbst verantwortlich.


## Datenschutz und Betroffenenrechte

Unter **Datenschutz** hinterlegst du die Angaben des Verantwortlichen (erscheinen auf `/privacy`), bearbeitest Anfragen (Frist 30 Tage) und erfasst Anfragen, die per E-Mail eingehen. In **Benutzer > Verwalten** findest du pro Person: Datenauszug (nur Metadaten), Status und Profilbild entfernen, Bearbeitung einschränken bzw. aufheben und Konto löschen (wahlweise inkl. aller Nachrichten und Dateien). Details und Vorlagen: `DATENSCHUTZ.md`. Das **Prüfpaket** (Datenschutz → Prüfpaket) bündelt für die rechtliche Prüfung: Checkliste (automatische und manuelle Punkte), das automatisch erzeugte Verzeichnis der Bearbeitungstätigkeiten (als Markdown herunterladbar) und einen Prüfvermerk, der meldet, wenn sich Angaben seit der Prüfung geändert haben. Es ersetzt keine Rechtsberatung.

## Funktionstest (Systemtest)

Im Admin-Dashboard zeigt die Kachel **Funktionstest** das Ergebnis des letzten Laufs; unter **Systemtest** siehst du alle Prüfungen mit Details. Mit "Tests ausführen" (oder `php bin/selftest.php` auf der Kommandozeile, Exit-Code 1 bei Fehlern) startest du einen neuen Lauf. Er prüft:

* **Umgebung:** PHP-Version, Erweiterungen, Schlüssel, Datenbank (Zeit, Schema, Migrationen), Speicherverzeichnisse, HTTPS, Upload-Limits, SMTP, Bereinigung, Datenschutz-Angaben.
* **Sicherheit:** Passwortrichtlinie, Hashing, Verschlüsselung, CSRF, Rate-Limits, Dateinamen, Sicherheits-Header.
* **Routen:** Alle Routen verweisen auf vorhandenen Code, jede zustandsändernde Route ist CSRF-geschützt, Admin-, API- und Medienrouten sind geschützt.
* **Anwendungslogik:** Chat, Zugriffsschutz (IDOR), Suche, Reaktionen, Gruppen, Melden, Dateien und alle Datenschutz-Funktionen.
* **2FA, SSO, Push, Aufträge:** TOTP-Testvektor und Replay-Schutz, Wiederherstellungscodes, OIDC-Prüfung, Push-Allowlist, Export-Job.
* **Backup, Betrieb, Prüfpaket:** Backup erstellen/prüfen/Manipulation erkennen, Verschlüsselung, Download-Schutz, Zustandsprüfungen, PWA-Dateien, Verzeichnis und Checkliste.

Die Logiktests verwenden Testkonten in einer Datenbank-Transaktion, die am Ende zurückgerollt wird. Es bleiben keine Daten zurück und es werden keine E-Mails versendet. Hinweise (gelb) sind Empfehlungen, Fehler (rot) sind Funktionsstörungen.


## Aktualisierung

Unter *Administration → Aktualisierung* holst du Updates aus einem GitHub-Repository. Details: [UPDATE.md](UPDATE.md).

## Assistenten

Unter *Administration → Assistenten* gibt es geführte Einrichtungen für: Person hinzufügen, E-Mail-Versand, Einmalanmeldung (SSO) und Backup. Jeder Assistent fragt Schritt für Schritt die nötigen Angaben ab, prüft sie, zeigt eine Übersicht und sendet dann an dieselbe Adresse wie das klassische Formular. Die klassischen Seiten bleiben bestehen; der Knopf «Mit Assistent» oben rechts führt von dort zum passenden Assistenten. Neue Assistenten werden in `app/Services/Wizards.php` beschrieben.
