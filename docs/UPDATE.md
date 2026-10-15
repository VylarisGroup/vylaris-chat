# Aktualisierung aus GitHub

Der Messenger kann sich aus einem GitHub-Repository aktualisieren. Standard-Quelle sind die **GitHub-Releases** (Kanal «Release»); alternativ ein **Branch**. Einrichtung: bei der Installation (Schritt «Installation starten», optional) oder später unter *Administration → Aktualisierung*.

## Repository vorbereiten

* Das Repository enthält das Projekt **im Stamm** oder im Ordner **`messenger/`** (`app/`, `public/`, `database/`, `bin/` …).
* **`public/assets/css/app.css` muss eingecheckt sein** (auf dem Server wird nichts gebaut). Vor dem Push: `cd tailwind && npm run build`.
* Nicht einchecken: `config/config.php`, `storage/`, `node_modules/` (die mitgelieferte `.gitignore` regelt das). Diese Pfade werden bei einem Update ohnehin nie überschrieben.
* **Kanal «Release» (Standard):** Ein Update entsteht, wenn du auf GitHub ein neues Release veröffentlichst (*Releases → Draft a new release*, Tag z. B. `v1.1.0`). Es zählt das neueste **veröffentlichte** Release; Entwürfe und Vorabversionen (Pre-release) werden ignoriert. Tags werden als Versionsnummer verglichen (`v1.10.0` > `v1.9.0`); ein älteres Release wird nie als Update angeboten. Der Release-Text dient als Änderungsliste. Der getaggte Stand muss das Projekt samt `app.css` enthalten.
* **Kanal «Branch»:** Jeder neue Commit auf dem eingestellten Branch gilt als Update. Arbeite für Entwicklung in einem anderen Branch und führe nur getestete Stände in den Update-Branch zusammen.

## Öffentlich oder privat

* **Öffentlich:** kein Token nötig. (GitHub begrenzt anonyme Abfragen; der Messenger fragt nur alle 6 Stunden.)
* **Privat:** ein *fine-grained* Personal Access Token, nur für dieses Repository, Berechtigung **Contents: Read-only**. Es wird mit `app.key` verschlüsselt gespeichert und ausschliesslich an die GitHub-API gesendet.

## Ablauf

1. **Prüfen:** alle 6 Stunden (bei Aufruf der Bereinigung/Cron) und per Knopf. Neue Stände erscheinen als Hinweis in der Administration; die Commit-Titel dienen als Änderungsliste.
2. **Installieren (Klick):** optionales Backup → ZIP des geprüften Commits laden → prüfen (Pfade, Grösse, Commit stimmt mit dem geprüften Stand überein) → Code sichern → Dateien ersetzen (Wartungsmodus für wenige Sekunden) → neue Datenbank-Migrationen laufen beim nächsten Seitenaufruf automatisch.
3. **Rückbau:** stellt den gesicherten Code (die letzten 3 Stände) wieder her. Die Datenbank wird **nicht** zurückgesetzt; dafür gibt es das Backup.
4. Dateien, die eine frühere Version mitbrachte und die neue nicht mehr hat, werden entfernt (nur solche, die der Updater selbst installiert hat).

**Erstes Mal:** Der installierte Stand ist unbekannt. Entweder «Update installieren» (überschreibt den Code mit dem Repository-Stand) oder «Als installiert vermerken», wenn der Code schon dem Repository entspricht.

## Kommandozeile

```
php bin/update.php check
php bin/update.php apply [--no-backup]
php bin/update.php rollback
```

`check` beendet mit Code 10, wenn ein Update verfügbar ist. Nützlich für Cron (nur prüfen) oder wenn die Webanfrage zu lange dauern würde.

## Voraussetzungen

PHP-Erweiterungen `zip` und `curl`; die Verzeichnisse `app`, `public`, `database`, `bin` und `storage/cache` müssen für den PHP-Benutzer beschreibbar sein (auf Shared Hosting meist der Fall; bei eigenen Servern Rechte prüfen).

## Sicherheit – bitte lesen

Gesichert wird über **HTTPS** und den Abgleich des Commits, **nicht über eine Signatur**. Wer Schreibzugriff auf den Update-Branch oder das Token hat, kann Code auf allen verbundenen Installationen ausführen. Schütze deshalb das GitHub-Konto (Zwei-Faktor), aktiviere Branch-Schutz mit Review und nutze ein Token mit Nur-Lese-Recht. Wer mehr Schutz braucht, kann später eine Signaturprüfung ergänzen.

Datenschutz: GitHub erhält bei der Prüfung die IP-Adresse des Servers und den Repository-Namen, keine Daten von Nutzenden.
