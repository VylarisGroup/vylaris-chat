# Änderungen

## 1.8.2 – Push ohne curl_multi (KeyHelp)

* Push funktioniert jetzt auch, wenn der Hoster `curl_multi_*` über `disable_functions` sperrt (z. B. KeyHelp): Die Geräte werden dann nacheinander statt parallel bedient.
* Zwingend nötig sind nur noch `curl_init`, `curl_exec` und die OpenSSL-Funktionen.

## 1.8.1 – Push-Test auf eingeschränkten Hostings (KeyHelp)

* Der Push-Test liefert bei Problemen statt eines Fehlers 500 eine Meldung im Klartext (inkl. Datei/Zeile).
* Gesperrte oder fehlende PHP-Funktionen (`disable_functions`, curl/openssl) werden erkannt und benannt.
* Migrationen sind nach einem teilweise angewendeten Lauf wiederholbar (vorhandene Spalten sind kein Fehler mehr).

## 1.8.0 – Push-Benachrichtigungen repariert

* **Verschlüsselte Push-Nachrichten (RFC 8291) mit korrekter VAPID-Signatur:** iOS (Home-Bildschirm-App ab 16.4) und Android erhalten Hinweise zuverlässig, auch wenn die App geschlossen ist. Der Service Worker zeigt bei jedem Push immer einen Hinweis an.
* **Unterdrückung nur bei sichtbarer App:** Ein Push wird nur ausgelassen, wenn die App in den letzten 30 Sekunden sichtbar war (vorher blockierte der Online-Status fälschlich).
* Hohe Dringlichkeit (Android Doze), Zusammenfassen je Unterhaltung, Abo-Erneuerung (`pushsubscriptionchange`) und regelmässiger Abgleich.
* Administration → Betrieb: Geräteliste mit letztem Status und Fehlertext, Test-Push zeigt Fehler je Gerät.
* **Wichtig:** Bestehende Geräte einmal in Einstellungen → Benachrichtigungen Push aus- und wieder einschalten (neue Schlüssel). iOS: Zuerst «Zum Home-Bildschirm».

## 1.7.0 – Assistenten und neue Seitenleiste

* **Seitenleiste überarbeitet:** Die Administration ist in ausklappbare Gruppen gegliedert (Verwaltung, Sicherheit & Datenschutz, System, Software, Assistenten). Die Gruppe der aktuellen Seite ist offen, der Zustand der übrigen wird im Browser gemerkt. Mobil lässt sich das ganze Menü einklappen.
* **Assistenten:** Schritt für Schritt einrichten – Person hinzufügen (Einladung oder direkt anlegen), E-Mail-Versand, Einmalanmeldung (SSO), Backup und Lizenz. Jeder Assistent zeigt am Ende eine Übersicht und speichert dasselbe wie das klassische Formular.
* Die klassischen Formulare bleiben unverändert; auf den Seiten Benutzer, Einstellungen, Sicherheit, Backup und Lizenz führt der Knopf «Mit Assistent» direkt zum passenden Assistenten.

## 1.6.0 – Änderungsprotokoll

* **Neu in dieser Version:** Nach einem Update zeigt die Administration beim ersten Aufruf die Änderungen an, bis du sie mit «Verstanden» bestätigst (je Administrationskonto).
* Neue Seite «Administration → Änderungen» mit dem vollständigen Verlauf.
* Der Text stammt aus `docs/CHANGELOG.md` und wird mit dem Code ausgeliefert.

## 1.5.0

* Update-Quelle fest: GitHub-Releases von `VylarisGroup/vylaris-chat`; nicht mehr in der Oberfläche einstellbar.
* Lizenzserver und öffentlicher Signaturschlüssel fest eingebaut; nicht mehr in der Oberfläche einstellbar.
* Lizenzsystem mit Funktions-Scopes, Platzbegrenzung pro Konto und Nur-Lesen-Modus.
* Selbstaktualisierung aus GitHub-Releases mit Backup, Wartungsmodus und Rückbau.
