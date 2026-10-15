# Lizenzsystem

Der Messenger ist lizenzpflichtig und prüft seine Lizenz über **TRS License** (Prüf-API, Ed25519-signierte Antworten, Dokumentation: `https://licence.vylaris.ch`).

## Modell

* **Ein Firmenschlüssel pro Installation.** Der Admin trägt ihn unter *Administration → Lizenz* ein.
* **Jedes aktive Konto belegt einen Geräteplatz** (`maxDevices`) des Schlüssels. Die Plätze werden per `activate` / `deactivate` beim Lizenzserver geführt. Als Platzkennung (`hwid`) dient ein HMAC aus der Konto-ID und `app.key` (`u-…`), also pseudonym; als Name wird nur «Konto» gesendet.
* **Selbstregistrierung** verlangt den Firmenschlüssel; Einladungen, vom Admin angelegte Konten und SSO-Autoanlage brauchen keine Eingabe, belegen aber einen Platz. Ist das Limit erreicht oder die Lizenz ungültig, entstehen keine neuen Konten.
* **Bestehende Konten** belegen beim ersten Abgleich Plätze. Überschreitet ihre Zahl das Limit, bleibt niemand ausgesperrt; es kommen nur keine neuen hinzu.
* Deaktivierte oder gelöschte/anonymisierte Konten geben ihren Platz frei (Abgleich nach der Aktion, stündlich per Bereinigung und per Knopf «Jetzt prüfen und Plätze abgleichen»).

## Prüfung und Offline-Verhalten

* Der Status wird alle 6 Stunden (bei ungültiger Lizenz alle 15 Minuten) nach der Antwort an den Benutzer neu geholt, ausserdem bei jeder Änderung im Admin.
* Jede Antwort wird mit dem öffentlichen Schlüssel geprüft (Signatur und Zeitstempel, max. 5 Minuten Abweichung). Der öffentliche Schlüssel ist als Standard hinterlegt und unter *Lizenz* änderbar – **vergleiche ihn mit dem Schlüssel im Lizenz-Panel**.
* Ist der Lizenzserver nicht erreichbar, gilt die letzte signierte Bestätigung weitere **168 Stunden** (Einstellung `license_grace_hours`). Danach wechselt die Installation in den Nur-Lesen-Modus. Ein abgelaufenes Ablaufdatum wirkt sofort.

## Ohne gültige Lizenz: Nur-Lesen-Modus

Nachrichten bleiben lesbar. Gesperrt sind Senden, Bearbeiten, Reaktionen, Weiterleiten, neue Chats/Gruppen, Uploads und neue Konten. **Immer verfügbar** bleiben Anmeldung, Passwortwechsel, die eigene Datenauskunft/-löschung (Datenschutzrechte), Meldungen, das Löschen eigener Nachrichten und die gesamte Administration (damit ein Schlüssel eingetragen werden kann), ebenso `bin/backup.php` und `bin/restore.php`.

## Funktionen (Scopes)

Im Lizenz-Panel trägst du pro Tarif oder Lizenz unter «Funktionen» diese Kennungen ein:

| Kennung | Schaltet frei |
|---|---|
| `messaging` | Nachrichten senden/bearbeiten/reagieren/weiterleiten, Direktchats |
| `groups` | Gruppen anlegen und verwalten |
| `files` | Datei-Uploads |
| `export` | Administrative Exporte und Prüfpaket (die eigene Datenauskunft der Nutzer ist nie gesperrt) |
| `2fa` | Neue Einrichtung der Zwei-Faktor-Authentifizierung (bestehende 2FA wird weiter geprüft; die Admin-Pflicht gilt nur mit diesem Scope) |
| `sso` | OIDC-Einmalanmeldung |
| `push` | Web-Push |
| `realtime` | Echtzeit (SSE), sonst Polling |
| `backup` | Backup-Verwaltung und automatische Backups in der Weboberfläche |

Eine Lizenz **ohne** `messaging` kann nichts senden. Neue Scopes werden zentral in `App\Services\License::SCOPES` und `License::requirement()` definiert.

## Datenschutz

Übermittelt werden: Lizenzschlüssel, pseudonyme Platzkennungen, Produktkennung, IP-Adresse des Servers. Die Datenschutzerklärung nennt den Lizenzserver als Empfänger.
