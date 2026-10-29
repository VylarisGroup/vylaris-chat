# Änderungen

## 1.19.0 – Aufräumen

* Interne Bereinigung; keine sichtbaren Änderungen im Alltag.

## 1.18.0 – Herkunftshinweis

* **«powered by VylarisChat»:** Am Fuss jeder Seitenleiste (Messenger und Administration) steht dezent der Hinweis «powered by VylarisChat» mit Link auf vylaris.ch. Er ist fest im Programm verankert, lässt sich nicht über Einstellungen ändern oder abschalten und wird bei jeder ausgelieferten Seite vom Server geprüft (geänderter Text oder Link wird zurückgesetzt, ein fehlender Hinweis ergänzt). Im Messenger prüft der Browser ihn zusätzlich laufend und stellt ihn bei Änderung oder Entfernung wieder her.

## 1.17.0 – Sprachnachrichten

* **Aufnehmen:** Im Eingabefeld gibt es einen Mikrofon-Knopf. Ein Tipp startet die Aufnahme mit Timer und Pegelanzeige, mit dem Papierkorb verwirfst du sie, mit «Senden» geht sie sofort raus. Beim Wechsel in einen anderen Chat wird eine laufende Aufnahme verworfen. Die Länge ist begrenzt (Standard 5 Minuten, einstellbar) und läuft das Limit ab, wird die Nachricht automatisch gesendet.
* **Abspielen:** In der Nachricht erscheint ein Player mit Wellenform, Abspielen/Pause, Spulen per Tipp auf die Wellenform (oder mit den Pfeiltasten), Dauer und Tempo 1×/1,5×/2×. Es spielt immer nur eine Sprachnachricht gleichzeitig.
* **Überall nutzbar:** In Einzelchats, Gruppen und in den Notizen, auch beim Weiterleiten und «In Notizen speichern» (Dauer bleibt erhalten). In der Chat-Liste und in Zitaten steht «Sprachnachricht».
* **Formate:** Der Browser nimmt auf, was er kann (WebM/Opus in Chrome, Edge, Firefox, MP4/AAC in Safari). Der Server prüft den Inhalt, nicht nur die Endung. Alte Safari-Versionen können WebM möglicherweise nicht abspielen, dann bietet der Player den Download an.
* **Administration:** «Einstellungen → System»: Sprachnachrichten erlauben (Standard an) und maximale Dauer. Aufnahmen werden wie Dateien gespeichert, zählen zur Dateigrösse und werden mit den Nachrichten aufbewahrt und gelöscht.
* **Datenschutz und Sicherheit:** Das Mikrofon wird nur nach Tipp auf den Knopf und nach deiner Freigabe im Browser verwendet und danach sofort wieder freigegeben. Aufnahme erfordert HTTPS (oder localhost). Die KI sieht von Sprachnachrichten nur, dass eine existiert (keine Transkription).
* Datenbank-Migration 011 (automatisch beim Update).

## 1.16.0 – Notizen-Chat und KI-Assistent

### Notizen (Chat mit dir selbst)

* **Eigener Chat «Notizen»:** Jede Person (ausser Gäste) hat oben in der Liste einen privaten Chat, den nur sie sieht. Er dient als Ablage für Texte, Links (mit Vorschau) und Dateien und ist auf allen Geräten gleich. Er lässt sich nicht stummschalten, anpinnen oder archivieren und kennt keine Umfragen, Termine oder Meetings.
* **In Notizen speichern:** Im Menü jeder Nachricht («Mehr») speichert «In Notizen speichern» eine Kopie samt Anhängen in die Notizen.

### KI-Assistent (OpenAI-kompatibel)

* **Anbindung:** Neue Administrationsseite «System → KI-Assistent»: Endpunkt (Basis-Adresse, der Messenger ruft `…/chat/completions` auf), API-Schlüssel (verschlüsselt gespeichert), Modell, Anzeigename, Systemprompt, Temperatur, Antwortlänge, Zeitlimit, Kontext-Limit (höchstens 90 000 Tokens) und Verbindungstest. Funktioniert mit OpenAI und kompatiblen Diensten oder lokalen Servern. Modelle, die `max_completion_tokens` verlangen oder keine eigene Temperatur erlauben, werden automatisch erkannt.
* **Genau eine KI pro Installation.** Sie erscheint wie eine Person (@ki) im Verzeichnis und ist ein Systemkonto ohne Anmeldung.
* **Einzelchat und Gruppen:** Jeder (ausser Gästen) kann einen Chat mit der KI starten. Zu Gruppen fügt die Moderation sie hinzu; alle Mitglieder sehen dazu eine Systemmeldung. In Gruppen antwortet die KI nur auf @Erwähnung oder wenn auf eine ihrer Nachrichten geantwortet wird, im Einzelchat auf jede Nachricht.
* **Kontext pro Chat:** In den Details wählt man, was mitgeschickt wird: nur die Anfrage, die letzten ca. 20 Nachrichten oder der ganze Verlauf seit dem Beitritt der KI, immer begrenzt durch das Token-Limit. In Gruppen ändert das die Moderation. Nachrichten vor dem Beitritt der KI sieht sie nie.
* **Antwort:** Die Antwort kommt fertig als eine Nachricht, solange läuft «schreibt …». Jede Nachricht der KI trägt das Label «KI-Antwort». Fehler erscheinen als Systemmeldung.
* **Zugriff:** Die Administration kann die Nutzung auf Administratoren oder eine Liste von Benutzernamen beschränken und ein Stundenlimit pro Person setzen.
* **Datenschutz:** Pro Anfrage gehen der Nachrichtentext und je nach Stufe früherer Verlauf (mit Anzeigenamen, ohne E-Mail-Adressen, ohne Dateiinhalte) an den eingetragenen Anbieter. Die Datenschutzerklärung nennt ihn automatisch, solange die KI aktiv ist.
* Standardmässig aus. Datenbank-Migration 010 (automatisch beim Update).

### Behoben

* Administration: «Videokonferenzen» und der neue «KI-Assistent» standen in der eingeklappten Gruppe «System» und waren schwer zu finden. Sie haben jetzt die eigene Gruppe «Funktionen», und Menügruppen sind beim ersten Besuch offen (danach merkt sich der Browser, was du geschlossen hast).
* In der Chat-Kopfzeile erschien das Wort «null», wenn kein Meeting-Knopf angezeigt wird.
* Umfrage- und Termin-Knöpfe liessen sich in manchen Fällen nicht ausblenden.

## 1.15.0 – Videokonferenzen und PWA-Feinschliff

Diese Version fasst die übersprungenen Versionen 1.10.0 bis 1.14.0 zusammen.

### Videokonferenzen (TheRedStonee Meets)

* **Videomeetings im Chat:** Über das Kamera-Symbol in der Kopfzeile startest du ein Meeting. Es erscheint als Karte im Verlauf («Videomeeting läuft») mit Live-Anzeige, wie viele Personen gerade teilnehmen, und einem Knopf zum Beitreten.
* **Eingebettet im Messenger:** Das Meeting öffnet sich als Overlay direkt im Messenger (auf dem Handy als Vollbild). Es lässt sich minimieren, während du weiter chattest, und über «Verlassen» schliessen.
* **Schnell zurück zum Chat:** Oben links im Meeting steht «Chat» (auch Esc, Zurück-Geste/-Taste am Handy): das Meeting läuft klein weiter, der Chat ist sofort bedienbar. Eine grüne Statusleiste «Meeting läuft 0:42 · Zurück» mit Verlassen-Knopf bleibt oben erreichbar; ein Tipp auf das Mini-Bild oder die Leiste öffnet das Meeting wieder gross. Bildschirm bleibt während des Meetings an (wo unterstützt), Notch und Home-Leiste werden berücksichtigt.
* **Berechtigungen:** In Direktchats darf jede Person ein Meeting starten, in Gruppen nur die Moderation. Gäste können beitreten, aber nicht starten. Beenden darf, wer das Meeting gestartet hat, sowie die Moderation. Pro Unterhaltung läuft höchstens ein Meeting; ein Meeting endet spätestens nach 8 Stunden.
* **Klingeln per Push:** Beim Start eines Meetings erhalten die anderen Mitglieder eine Push-Benachrichtigung mit dem Namen der anrufenden Person und den Aktionen «Annehmen» und «Ablehnen» (bleibt stehen, bis man reagiert). «Annehmen» öffnet die App und tritt dem Meeting direkt bei, «Ablehnen» schliesst nur den Hinweis. Wer die App gerade sichtbar offen hat, bekommt keinen Push, sondern sieht die Karte sofort. Pro Meeting gibt es nur einen Hinweis. Abschaltbar in der Administration. Android/Desktop zeigen die Aktionsknöpfe; iOS/iPadOS zeigen keine Aktionsknöpfe und kein Dauer-Klingeln, ein Tipp öffnet den Chat mit der Karte.
* **Symbole nur bei aktivem System:** Kamera-Symbol und Meeting-Karten erscheinen nur, wenn Videokonferenzen aktiviert und eingerichtet sind.
* **Administration:** Neue Seite «Videokonferenzen» (API-Schlüssel und Webhook-Geheimnis verschlüsselt gespeichert, Verbindungstest, Hinweise zur Einrichtung beim Anbieter).
* Die Funktion ist standardmässig aus. Voraussetzung ist ein API-Schlüssel von TheRedStonee, der den Messenger als erlaubte Einbettungs-Domain (`embedOrigins`) und die Webhook-Adresse eingetragen hat. Der Chat innerhalb des Meetings wird nicht in den Messenger gespiegelt.

### PWA und Touch-Geräte

* **Kein Doppeltippen mehr:** Hover-Effekte gelten nur noch auf Geräten mit Maus. Bisher löste auf iPhone, iPad und Android der erste Tipp nur den «Hover» aus (z. B. die Optionen einer Unterhaltung) und erst der zweite die Aktion. Tippen löst jetzt sofort aus; zusätzlich entfallen die Wartezeit und der Doppeltipp-Zoom (`touch-action: manipulation`).
* **Kein Auto-Zoom beim Tippen:** Eingabefelder haben auf Touch-Geräten 16 px. Darunter zoomt iOS beim Fokussieren die Seite heran und zoomt nicht zurück. Bleibt die Seite doch herangezoomt, wird sie nach dem Tippen zurückgesetzt. Der Zoom per Fingergeste bleibt erlaubt (Barrierefreiheit).
* **Tastatur:** Die Seitenhöhe folgt der sichtbaren Fläche (`visualViewport`, `interactive-widget=resizes-content`). Kopfzeile und Eingabefeld bleiben sichtbar, der Verlauf bleibt am Ende, wenn er dort stand.
* **Zurück-Geste:** Aus einem Chat führt Zurück (Android-Taste, Wischgeste) zur Unterhaltungsliste statt aus der App; die Meeting-Ansicht minimiert sich zuerst.
* **Langes Drücken:** Auf einer Unterhaltung öffnet langes Drücken das Menü (iOS löst kein Kontextmenü aus; die Optionen-Taste erscheint nur mit Maus).
* **Ruhigeres Verhalten:** Kein Pull-to-Refresh und kein Gummiband über die ganze Seite, Bedienelemente sind nicht markierbar und lösen kein Lupen-/Kontextmenü aus.
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
* **Assistenten:** Schritt für Schritt einrichten – Person hinzufügen (Einladung oder direkt anlegen), E-Mail-Versand, Einmalanmeldung (SSO) und Backup. Jeder Assistent zeigt am Ende eine Übersicht und speichert dasselbe wie das klassische Formular.
* Die klassischen Formulare bleiben unverändert; auf den Seiten Benutzer, Einstellungen, Sicherheit und Backup führt der Knopf «Mit Assistent» direkt zum passenden Assistenten.

## 1.6.0 – Änderungsprotokoll

* **Neu in dieser Version:** Nach einem Update zeigt die Administration beim ersten Aufruf die Änderungen an, bis du sie mit «Verstanden» bestätigst (je Administrationskonto).
* Neue Seite «Administration → Änderungen» mit dem vollständigen Verlauf.
* Der Text stammt aus `docs/CHANGELOG.md` und wird mit dem Code ausgeliefert.

## 1.5.0

* Update-Quelle fest: GitHub-Releases von `VylarisGroup/vylaris-chat`; nicht mehr in der Oberfläche einstellbar.
* Selbstaktualisierung aus GitHub-Releases mit Backup, Wartungsmodus und Rückbau.
