# Unternehmens-Messenger

Ein sicherer, selbst gehosteter Messenger für Unternehmen: Einzel- und Gruppenchats, Dateien, Suche, Benachrichtigungen und eine Administrationsoberfläche. Reines PHP 8.3 mit MySQL/MariaDB, ohne Docker, ohne Node auf dem Server, lauffähig auf klassischem Webhosting.

**Wichtig vorab:** Der Messenger ist **nicht Ende-zu-Ende-verschlüsselt**. Die Übertragung ist per HTTPS geschützt, Nachrichten liegen aber im Klartext in der Datenbank. Siehe [Sicherheit und Grenzen](docs/SECURITY.md).

## Funktionen

- **Konten:** Anmeldung, Einladung per E-Mail, optionale Selbstregistrierung (Domain-Beschränkung), E-Mail-Bestätigung, Passwort vergessen/zurücksetzen, Profil mit Bild und Status, Präsenz (online/abwesend/unsichtbar), Sitzungsverwaltung, Konto löschen (Anonymisierung).
- **Chats:** Einzel- und Gruppenchats mit Rollen (Administrator, Moderator, Mitglied); Antworten, Bearbeiten, Löschen, Reaktionen, Weiterleiten, Kopieren, `@Erwähnungen`, Tipp-Anzeige, Zustell- und Lesestatus, Trennlinie für Ungelesenes, Stummschalten, Archivieren, Anpinnen, Entwürfe.
- **Dateien:** Upload mit Fortschritt und Drag-and-drop, Bildvorschau, geschützter Download mit Berechtigungsprüfung, Ablage ausserhalb des Webroots.
- **Suche:** Personen, Unterhaltungen, Nachrichten und Dateien; Filter nach Absender und Zeitraum; nur in Unterhaltungen, in denen man Mitglied ist.
- **Sicherheit:** Zwei-Faktor-Authentifizierung (TOTP, für Administratoren vorschreibbar), Kontosperre und Passwort-Ablauf, Einmalanmeldung per OpenID Connect.
- **Betrieb:** Backup/Restore (ZIP, optional verschlüsselt), Fehlerüberwachung mit Alarm-Mail, Zustandsprüfungen, optional SSE-Echtzeit, Web-Push (ohne Inhalt über Push-Dienste) und installierbare PWA, Datenexport als Hintergrundauftrag.
- **Benachrichtigungen:** in der App, Desktop-Hinweise (mit Einwilligung des Browsers), Ton, E-Mail (konfigurierbar, nur wenn die Person gerade nicht aktiv ist, nie mit Nachrichtentext).
- **Administration:** Übersicht und Systemzustand, Benutzer (einladen, anlegen, bearbeiten, deaktivieren, löschen), Gruppen, gemeldete Nachrichten, Einstellungen (Organisation, Registrierung, Aufbewahrung, SMTP), Audit-Protokoll. Administratoren haben **keinen Zugriff auf private Nachrichten**.
- **Oberfläche:** TailwindCSS und Boxicons, helles/dunkles Design, konfigurierbare Akzentfarbe und Logo, responsiv (Mobil/Tablet/Desktop), Tastaturbedienung, Skeletons, Hinweise und Dialoge mit Fokusführung.

## Technik im Überblick

| Bereich | Umsetzung |
|---|---|
| Backend | PHP 8.3, eigener Router und Middleware, PDO mit Prepared Statements, PSR-4-artiger Autoloader |
| Datenbank | MySQL 8 / MariaDB 10.5+, 25 Tabellen, versionierte Migrationen (`database/migrations`) |
| Echtzeit | Kurzes Polling auf ein Ereignisjournal (adaptiv 2,5 s aktiv / 6 s inaktiv / 15 s im Hintergrund); optional Server-Sent Events als Weckruf. Kein WebSocket nötig |
| Frontend | Vanilla-JavaScript-Module (kein Build für JS), TailwindCSS 3 (vorkompiliert), Boxicons (lokal) |
| Mail | PHPMailer 6.9 (SMTP, Zertifikatsprüfung aktiv); SMTP-Passwort verschlüsselt gespeichert |
| Sicherheit | CSP ohne Inline-Skripte, CSRF-Token, Rate-Limits, Argon2id (bzw. bcrypt als Rückfall) via `password_hash`, Audit-Log |

## Verzeichnisstruktur

```
public/        Document Root: index.php (Front Controller), setup.php, assets/
app/           Anwendungscode: Core, Controllers, Services, Models, Middleware, Views
database/      SQL-Migrationen
config/        config.php (vom Installer erzeugt, nicht öffentlich)
storage/       Uploads, Sessions, Logs, Profilbilder (nicht öffentlich)
lib/           PHPMailer
bin/           cleanup.php, worker.php, backup.php, restore.php, health.php, selftest.php, migrate.php
tailwind/      Quellen für das CSS (nur für Entwickler nötig)
docs/          Dokumentation
```

## Schnellstart

1. Dateien hochladen, Document Root auf `public/` setzen, leere Datenbank anlegen.
2. `https://ihre-domain/setup.php` öffnen und dem Assistenten folgen.
3. Als Administrator anmelden, SMTP prüfen, Personen einladen.

**Lizenz:** Der Messenger ist lizenzpflichtig (TRS License). Nach der Installation unter *Administration → Lizenz* den Firmenschlüssel eintragen, sonst läuft er im Nur-Lesen-Modus. Siehe [docs/LIZENZ.md](docs/LIZENZ.md).

**Updates:** Der Messenger kann sich aus den GitHub-Releases aktualisieren (Prüfung automatisch, Installation per Klick). Siehe [docs/UPDATE.md](docs/UPDATE.md).

Ausführlich: [docs/INSTALLATION.md](docs/INSTALLATION.md) · [docs/ADMINISTRATION.md](docs/ADMINISTRATION.md) · [docs/SECURITY.md](docs/SECURITY.md)

## Entwicklung

CSS neu bauen (nur nach Änderungen an Views oder Klassen in JS):

```
cd tailwind && npm install && npm run build
```

Lokaler Test ohne Webserver-Konfiguration: `php -S 127.0.0.1:8080 -t public` (Setup-Assistent erlaubt dort HTTP auf localhost).


## Datenschutz

Die Software enthält eine öffentliche Datenschutzerklärung (`/privacy`), Selbstbedienung für Betroffenenrechte (Einstellungen > Meine Daten) und Administrationsfunktionen (Datenschutz, Datenauszug, Einschränkung, Löschung). Siehe `docs/DATENSCHUTZ.md`. Nach einem Update werden Migrationen automatisch angewendet (oder `php bin/migrate.php`). Das **Prüfpaket** in der Administration unterstützt die rechtliche Prüfung, ersetzt sie aber nicht.
