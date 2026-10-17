# Änderungen

## 1.10.0 – Videokonferenzen (TheRedStonee Meets)

* **Videomeetings im Chat:** Über das Kamera-Symbol in der Kopfzeile startest du ein Meeting. Es erscheint als Karte im Verlauf («Videomeeting läuft») mit Live-Anzeige, wie viele Personen gerade teilnehmen, und einem Knopf zum Beitreten.
* **Eingebettet im Messenger:** Das Meeting öffnet sich als Overlay direkt im Messenger (auf dem Handy als Vollbild). Es lässt sich minimieren, während du weiter chattest, und über «Verlassen» schliessen.
* **Berechtigungen:** In Direktchats darf jede Person ein Meeting starten, in Gruppen nur die Moderation. Gäste können beitreten, aber nicht starten. Beenden darf, wer das Meeting gestartet hat, sowie die Moderation. Pro Unterhaltung läuft höchstens ein Meeting; ein Meeting endet spätestens nach 8 Stunden.
* **Administration:** Neue Seite «Videokonferenzen» (API-Schlüssel und Webhook-Geheimnis verschlüsselt gespeichert, Verbindungstest, Hinweise zur Einrichtung beim Anbieter).
* Die Funktion ist standardmässig aus. Voraussetzung ist ein API-Schlüssel von TheRedStonee, der den Messenger als erlaubte Einbettungs-Domain eingetragen hat. Das Meeting-Chat des Anbieters wird nicht in den Messenger gespiegelt.
* Datenbank-Migration 009 (automatisch beim Update).

## 1.9.0 – Zusammenarbeit: Threads, Umfragen, Termine, Entwürfe, Gäste

* **Threads:** Auf jede Nachricht lässt sich in einem eigenen Verlauf antworten. Die Hauptansicht bleibt aufgeräumt, unter der Nachricht steht «N Antworten» mit den Beteiligten. Beteiligte werden über neue Antworten benachrichtigt.
* **Reaktionen:** Die Auswahl wurde auf 24 Emojis erweitert; Reaktionen funktionieren auch bei Umfragen und Terminen.
* **Umfragen:** 2–10 Antworten, Einfach- oder Mehrfachauswahl, optional anonym, optional mit Frist (max. 90 Tage). Die Stimme lässt sich ändern oder zurücknehmen; Ersteller und Moderation können vorzeitig beenden.
* **Termine:** Titel, Ort, Beschreibung, Datum/Zeit oder ganztägig. Zu-/Absagen (Ja, Vielleicht, Nein) mit Zählern. Änderungen und Absagen erscheinen als Hinweis im Verlauf.
* **Kalender-Export (ICS):** Jeder Termin lässt sich als `.ics` herunterladen (mit Erinnerung 15 Minuten vorher). Zusätzlich gibt es in den Einstellungen einen persönlichen, geheimen Kalender-Abo-Link für alle Termine deiner Unterhaltungen (Outlook, Apple Kalender, Google Kalender u. a.). Der Link lässt sich jederzeit erneuern oder abschalten.
* **Synchrone Entwürfe:** Nicht gesendeter Text wird serverseitig gespeichert und auf deinen anderen Geräten angezeigt. Entwürfe werden nach 30 Tagen gelöscht.
* **Gastzugänge:** Neue Rolle «Gast» (Einladung, direkt anlegen oder Rolle ändern; auch im Assistenten). Gäste sind zeitlich befristet, sehen nur die gewählten Gruppen, haben keine Direktchats, sehen keine E-Mail-Adressen und können weder Gruppen, Umfragen noch Termine erstellen. Nach Ablauf wird das Konto automatisch gesperrt.
* Datenbank-Migration 008 (automatisch beim Update).

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
