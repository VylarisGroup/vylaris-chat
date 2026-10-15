# Installation

## Voraussetzungen

- PHP **8.3 oder neuer** mit den Erweiterungen `pdo_mysql`, `mbstring`, `openssl`, `sodium`, `fileinfo`, `json`, `ctype`, `gd` (mit PNG, JPEG, GIF, WebP)
- MySQL 8 oder MariaDB 10.5+ (utf8mb4, InnoDB), eine leere Datenbank samt Benutzer
- Webserver Apache (mit `mod_rewrite`) oder nginx
- **HTTPS** mit gültigem Zertifikat (im Produktivbetrieb zwingend)
- Für E-Mails: ein SMTP-Konto (Einladungen, Passwort-Reset, Bestätigung)

Docker, Node.js oder Composer sind auf dem Server **nicht** nötig. Alle Abhängigkeiten (PHPMailer, kompiliertes CSS, Boxicons) liegen bei.

## Schritt für Schritt

### 1. Dateien hochladen

Lade den gesamten Projektordner auf den Server. **Der Document Root der Domain muss auf `public/` zeigen.** Nur dieser Ordner ist öffentlich; `app/`, `config/`, `storage/`, `database/`, `lib/` und `bin/` dürfen nie direkt aus dem Web erreichbar sein.

Kann der Document Root nicht geändert werden (manche Hoster), funktioniert die mitgelieferte `.htaccess` im Hauptordner, die Anfragen intern an `public/` weiterreicht. In allen Unterordnern mit sensiblen Daten liegt zusätzlich eine `.htaccess`, die den Zugriff verbietet. Prüfe nach der Installation, dass `https://ihre-domain/config/config.php` und `https://ihre-domain/storage/` **nicht** erreichbar sind.

### 2. Schreibrechte

PHP muss in `storage/` (und darunter) sowie beim Setup in `config/` schreiben dürfen, typischerweise `chmod 750` (oder 755/775 je nach Hoster).

Empfehlung: Lege `storage/` **ausserhalb** des Webroots ab. Dann in `config/config.php` den Eintrag `'paths' => ['storage' => '/absoluter/pfad/storage']` setzen und den Ordner dorthin verschieben.

### 3. Datenbank anlegen

Lege beim Hoster eine leere Datenbank und einen Datenbankbenutzer mit allen Rechten auf diese Datenbank an.

### 4. Setup-Assistent

Öffne `https://ihre-domain/setup.php`.

1. **Setup-Code:** Zum Schutz vor fremder Installation erzeugt die Anwendung die Datei `storage/setup_code.txt`. Lies den Code per FTP/SFTP/Dateimanager und gib ihn ein.
2. **Voraussetzungen:** PHP-Version, Erweiterungen, Schreibrechte und HTTPS werden geprüft.
3. **Datenbank:** Verbindungsdaten eingeben; die Verbindung wird getestet.
4. **Organisation und Administrator:** Firmenname, Akzentfarbe, öffentliche URL, erstes Administratorkonto (eigenes Passwort, keine Standardzugangsdaten).
5. **SMTP:** Server, Port, Verschlüsselung, Benutzer, Absender. Verbindungstest und optionale Testmail.
6. **Installation:** Tabellen werden angelegt, `config/config.php` geschrieben (Anwendungsschlüssel zufällig erzeugt), die Installation mit `storage/installed.lock` gesperrt und der Setup-Code gelöscht.

Der Assistent lässt sich danach nicht mehr ausführen und überschreibt keine bestehende Installation. Löschst du `public/setup.php` nach der Installation, ist das zusätzlich sauber.

### 5. Abschluss

- Als Administrator anmelden, unter **Administration → Einstellungen** den SMTP-Versand testen.
- Personen einladen (Administration → Benutzer).
- **Cron-Job** einrichten (empfohlen, alle 15 Minuten):
  ```
  0,15,30,45 * * * * php /pfad/zum/projekt/bin/cleanup.php >/dev/null 2>&1
  ```
  Ohne Cron läuft die Bereinigung opportunistisch etwa stündlich während des normalen Betriebs.
- Optional weitere Cron-Jobs: `* * * * * php bin/worker.php` (Hintergrundaufträge wie Export/Backup, sonst laufen sie nach der Anfrage), `30 2 * * * php bin/backup.php` (Backup, siehe Administration).
- **Push, PWA und sichere Cookies** brauchen HTTPS.
- **Update:** Dateien überschreiben (`config/`, `storage/` und Datenbank behalten). Neue Migrationen werden beim ersten Aufruf automatisch angewendet (oder `php bin/migrate.php`).
- **Datensicherung** einrichten (siehe [ADMINISTRATION.md](ADMINISTRATION.md)).

## nginx

```nginx
server {
    listen 443 ssl http2;
    server_name chat.example.com;
    root /var/www/messenger/public;
    index index.php;
    client_max_body_size 30m;            # etwas mehr als die maximale Dateigrösse

    location / {
        try_files $uri /index.php?$query_string;
    }
    location ~ \.php$ {
        # Nur der Front Controller und das Setup dürfen ausgeführt werden
        location ~ ^/(index|setup)\.php$ {
            include fastcgi_params;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
            fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        }
        return 404;
    }
    location ~ /\. { deny all; }
    location ^~ /assets/ {
        location ~ \.php$ { return 404; }
        add_header Cache-Control "no-cache";
    }
}
```

Hinter einem Reverse-Proxy/Load-Balancer im Setup „Reverse-Proxy“ aktivieren, damit `X-Forwarded-Proto` und `X-Forwarded-For` berücksichtigt werden. Setze das nur, wenn **ausschliesslich** der eigene Proxy diese Header schreibt.

## Update

1. Sicherung von Datenbank und `storage/` anlegen.
2. Neue Dateien über die alten legen, **ohne** `config/config.php` und `storage/` zu überschreiben.
3. `php bin/migrate.php` ausführen (wendet neue Migrationen an).

## Fehlersuche

| Symptom | Ursache / Abhilfe |
|---|---|
| Weisse Seite oder „Interner Fehler“ | `storage/logs/` prüfen (Fehlertext steht dort, nie im Browser) |
| Setup-Code nicht gefunden | `storage/` ist für PHP nicht beschreibbar |
| Anmeldung klappt, wird aber sofort abgemeldet | Sessions: `storage/sessions` beschreibbar? HTTPS-Erkennung hinter Proxy (Setup: Reverse-Proxy) |
| Uploads bis nur 2 MB | PHP-Limits `upload_max_filesize` und `post_max_size` beim Hoster erhöhen; die Admin-Übersicht zeigt die wirksamen Werte |
| Keine E-Mails | Administration → Einstellungen → SMTP → „Testen“; Fehlermeldung wird dort angezeigt |
| Seiten ohne Styling | Document Root zeigt nicht auf `public/`, oder `assets/` wird geblockt |


## Lizenz eintragen

Nach dem Setup meldest du dich als Administrator an und trägst unter *Administration → Lizenz* den Lizenzschlüssel ein (siehe [LIZENZ.md](LIZENZ.md)). Ohne gültige Lizenz läuft die Installation im Nur-Lesen-Modus; die Selbstregistrierung verlangt den Schlüssel ebenfalls.
