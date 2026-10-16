# Aktualisierung aus GitHub

Der Messenger aktualisiert sich aus den **GitHub-Releases** des festen, öffentlichen Repositories `VylarisGroup/vylaris-chat`. Es gibt keine Quelleneinstellungen: Repository, Kanal und Sichtbarkeit sind fest im Code (`Updater::REPO`). Die Seite *Administration → Aktualisierung* zeigt Status, Änderungsliste und Rückbau.

## Release veröffentlichen

* Das Repository enthält das Projekt **im Stamm** oder im Ordner **`messenger/`** (`app/`, `public/`, `database/`, `bin/` …).
* **`public/assets/css/app.css` muss eingecheckt sein** (auf dem Server wird nichts gebaut). Vor dem Push: `cd tailwind && npm run build`.
* Nicht einchecken: `config/config.php`, `storage/`, `node_modules/` (die mitgelieferte `.gitignore` regelt das). Diese Pfade werden bei einem Update ohnehin nie überschrieben.
* Ein Update entsteht, wenn du auf GitHub ein neues Release veröffentlichst (*Releases → Draft a new release*, Tag z. B. `v1.1.0`). Es zählt das neueste **veröffentlichte** Release; Entwürfe und Vorabversionen werden ignoriert. Tags werden als Versionsnummern verglichen (`v1.10.0` > `v1.9.0`); ein älteres Release wird nie angeboten. Der Release-Text dient als Änderungsliste.

## Ablauf

1. **Prüfen:** alle 6 Stunden (bei Aufruf der Bereinigung/Cron) und per Knopf. Neue Stände erscheinen als Hinweis in der Administration.
2. **Installieren (Klick):** optionales Backup → ZIP des geprüften Releases laden → prüfen (Pfade, Grösse, Commit stimmt mit dem geprüften Stand überein) → Code sichern → Dateien ersetzen (Wartungsmodus für wenige Sekunden) → neue Datenbank-Migrationen laufen beim nächsten Seitenaufruf automatisch.
3. **Rückbau:** stellt den gesicherten Code (die letzten 3 Stände) wieder her. Die Datenbank wird **nicht** zurückgesetzt; dafür gibt es das Backup.
4. Dateien, die eine frühere Version mitbrachte und die neue nicht mehr hat, werden entfernt (nur solche, die der Updater selbst installiert hat).

**Erstes Mal:** Der installierte Stand ist unbekannt. Entweder «Update installieren» (überschreibt den Code mit dem Release) oder «Als installiert vermerken», wenn der Code schon dem Release entspricht.

## Kommandozeile

```
php bin/update.php check
php bin/update.php apply [--no-backup]
php bin/update.php rollback
```

Exit-Code 10 bedeutet «Update verfügbar».

## Sicherheit

Die Echtheit wird über HTTPS und den Abgleich des Commits geprüft, nicht über eine Signatur. Wer Schreibzugriff auf das Repository hat, kann Code auf allen Installationen ausführen: Schütze das Repository (Zwei-Faktor, Branch-Schutz, wenige Schreibberechtigte).

## Entwicklung/Tests

Nur für Tests lässt sich die Quelle in `config/config.php` überschreiben: `'update' => ['repo' => 'owner/repo']`. Das ist nicht in der Oberfläche einstellbar.
