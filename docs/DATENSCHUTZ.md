# Datenschutz (DSG / DSGVO)

Dieses Dokument beschreibt, wie der Messenger die Rechte betroffener Personen nach dem Schweizer Datenschutzgesetz (DSG, in Kraft seit 1. September 2023) und der EU-DSGVO unterstützt, und enthält Vorlagen für die Dokumentation des Betreibers.

> Hinweis: Die mitgelieferte Datenschutzerklärung und diese Vorlagen sind eine fachlich sorgfältige Grundlage, ersetzen aber keine Rechtsberatung. Lass sie vor dem produktiven Einsatz für deine Organisation prüfen (insbesondere bei Beschäftigtendaten, Hosting im Ausland und internen Reglementen).

## 1. Was die Software bereitstellt

| Recht | DSG | DSGVO | Wo im Messenger |
|---|---|---|---|
| Information | Art. 19 | Art. 13/14 | Öffentliche Seite `/privacy`, Links auf Login, Registrierung, Einladung, Profilmenü |
| Auskunft / Datenherausgabe | Art. 25, 28 | Art. 15, 20 | Einstellungen > Meine Daten > "Meine Daten herunterladen" (ZIP mit `daten.json` und eigenen Anhängen, Passwortbestätigung); Administration > Benutzer > "Datenauszug herunterladen" (nur Metadaten) |
| Berichtigung | Art. 32 | Art. 16 | Profil (selbst); Administration > Benutzer (Name, E-Mail, Rolle), "Status und Profilbild entfernen"; Anfrage an Administration |
| Löschung | Art. 32 | Art. 17 | Einstellungen > Sicherheit > "Konto löschen" (wahlweise inkl. aller Nachrichten und Dateien); Administration > Benutzer > "Konto löschen"; einzelne Nachrichten jederzeit löschbar |
| Einschränkung / Sperrung | Art. 32 | Art. 18 | Administration > Benutzer > "Bearbeitung einschränken" |
| Widerspruch | Art. 30, 32 | Art. 21 | Anfrage unter Meine Daten; Bearbeitung in Administration > Datenschutz |
| Fristenkontrolle | Art. 25 Abs. 7 (30 Tage) | Art. 12 Abs. 3 (1 Monat) | Administration > Datenschutz: Anfragenliste mit Frist, Überfälligkeit, Statusverlauf |

### Wichtige Entwurfsentscheidung

Administratoren sehen **keine privaten Nachrichten**. Deshalb:

* Der **Datenauszug der Administration** enthält nur Konto-, Sitzungs-, Mitgliedschafts- und Metadaten (z. B. Zeitpunkt und Länge von Nachrichten, aber nicht deren Text, keine Dateinamen).
* Den **vollständigen Auszug inklusive eigener Nachrichtentexte und Dateien** kann nur die betroffene Person selbst erzeugen (Passwortbestätigung, max. 5 pro Stunde).
* **Nachrichten anderer Personen** sind nie Teil eines Auszugs (Rechte Dritter).
* Verlangt eine Person ohne Zugang (z. B. ehemalige Mitarbeitende) Auskunft, erfasst die Administration die Anfrage manuell und liefert den Metadaten-Auszug. Für Inhalte kann das Konto vorübergehend reaktiviert und die Person angeleitet werden, den Selbst-Export auszuführen.

### Löschung und Anonymisierung

Beim Löschen eines Kontos werden entfernt: Name, E-Mail, Benutzername (ersetzt), Profilbild, Statusnachricht, Einstellungen, Sitzungen (inkl. IP/Browser), Reset- und Bestätigungs-Tokens, Lesebestätigungen, Reaktionen, Benachrichtigungen; IP-Adresse und Browser-Kennung in Protokolleinträgen werden geleert; Namen in Systemmeldungen ("X hat die Gruppe verlassen") werden durch "Gelöschter Benutzer" ersetzt.

Mit der Option **"Auch alle Nachrichten und Dateien löschen"** wird zusätzlich der Inhalt aller gesendeten Nachrichten endgültig entfernt (Text, Anhänge, Dateien auf der Festplatte). Die Nachrichten bleiben als Platzhalter "Nachricht gelöscht" bestehen. Ohne diese Option bleiben sie ohne Namensbezug erhalten.

Nicht automatisch gelöscht: Datensicherungen (Backups) des Servers (nach regulärem Zyklus überschreiben), Datenschutzanfragen als Nachweis (automatische Löschung 3 Jahre nach Abschluss), Protokolleinträge ohne Personenbezug (nach der eingestellten Aufbewahrungsfrist).

### Einschränkung

Eine eingeschränkte Person kann sich anmelden, lesen, ihre Daten exportieren und Anfragen stellen, aber nichts senden, weiterleiten, bearbeiten, hochladen oder reagieren. Sie erscheint nicht mehr im Benutzerverzeichnis und erhält keine Benachrichtigungs-E-Mails.

## 2. Pflichten des Betreibers (nicht automatisierbar)

1. **Angaben ausfüllen**: Administration > Datenschutz: Verantwortlicher, Kontakt, Hosting, Dienstleister, ggf. Datenschutzberater und Vertretung.
2. **Verzeichnis der Bearbeitungstätigkeiten** führen (Art. 12 DSG; Art. 30 DSGVO), siehe Vorlage unten. Für Unternehmen mit weniger als 250 Mitarbeitenden bestehen Ausnahmen, sofern die Bearbeitung nur ein geringes Risiko mit sich bringt; prüfe das im Einzelfall.
3. **Auftragsbearbeitungsverträge** mit Hosting- und SMTP-Anbieter (Art. 9 DSG; Art. 28 DSGVO).
4. **Datenschutz-Folgenabschätzung** prüfen (Art. 22 DSG; Art. 35 DSGVO), insbesondere bei Verdacht auf Leistungs- oder Verhaltenskontrolle. Der Messenger wertet Inhalte nicht aus.
5. **Verletzungen der Datensicherheit** melden (Art. 24 DSG: so rasch als möglich an den EDÖB; Art. 33 DSGVO: binnen 72 Stunden). Siehe Ablauf unten.
6. **Interne Regeln**: Nutzungsreglement, Umgang mit privaten Nachrichten, Zugriff auf Serverebene (Datenbank) einschränken, da keine Ende-zu-Ende-Verschlüsselung besteht.
7. **Auslandsbezug**: Standort der Server und Anbieter in der Erklärung angeben, bei Drittländern Garantien (Standardvertragsklauseln) sicherstellen.

## 3. Vorlage: Verzeichnis der Bearbeitungstätigkeiten

| Feld | Eintrag |
|---|---|
| Verantwortlicher | (Firma, Adresse, Kontakt) |
| Bearbeitungstätigkeit | Interner Messenger |
| Zweck | Interne Kommunikation und Zusammenarbeit; Sicherheit und Missbrauchsabwehr |
| Kategorien betroffener Personen | Mitarbeitende, ggf. externe Mitglieder |
| Kategorien von Personendaten | Konto- und Profildaten, Nachrichten und Dateien, Nutzungs- und Verbindungsdaten (IP, Browser, Zeiten), Sicherheitsprotokoll, Meldungen, Datenschutzanfragen |
| Empfänger | Gesprächsteilnehmende; Administratoren (nur Konto/Metadaten); Hosting-Anbieter; SMTP-Anbieter |
| Bekanntgabe ins Ausland | (Land, Garantien) |
| Aufbewahrung | gemäss Administration > Einstellungen (Nachrichten, Protokoll); Sitzungen 30 Tage nach Ablauf; Anfragen 3 Jahre nach Abschluss |
| Massnahmen der Datensicherheit | HTTPS, Passwort-Hashing, CSRF/XSS/SQL-Injection-Schutz, Rate-Limits, widerrufbare Sitzungen, Zugriffskontrolle, Audit-Log, Backups (Verschlüsselung und Zugriff: Betreiber ergänzen) |
| Rechtsgrundlage (DSGVO) | Art. 6 Abs. 1 lit. b, c, f |

## 4. Ablauf: Verletzung der Datensicherheit

1. Vorfall erfassen (Zeitpunkt, betroffene Daten und Personen, Ursache), betroffene Sitzungen beenden (Administration > Benutzer > "Alle Sitzungen beenden"), Passwörter zurücksetzen.
2. Risiko beurteilen. Bei voraussichtlich hohem Risiko für die Persönlichkeit bzw. Rechte: Meldung an den EDÖB (Meldeportal des EDÖB) und/oder die EU-Aufsichtsbehörde (72 Stunden).
3. Betroffene informieren, wenn es zu ihrem Schutz erforderlich ist.
4. Vorfall und Massnahmen dokumentieren.

## 5. Ablauf: Auskunftsbegehren

1. Eingang prüfen, Identität sicherstellen (Anfrage über das Konto = bereits identifiziert; bei E-Mail/Brief Rückfrage).
2. Anfrage unter Administration > Datenschutz erfassen (Frist 30 Tage wird gesetzt).
3. Person auf den Selbstexport (Meine Daten) hinweisen oder Metadaten-Auszug herunterladen und über einen sicheren Kanal übermitteln.
4. Status auf "Erledigt" setzen und Antwort vermerken; bei Ablehnung "Abgelehnt" mit Begründung (Art. 26 und 29 DSG; Art. 12 Abs. 4 DSGVO).


## Neue Verarbeitungen (Version mit 2FA, Backup, Push, SSO)

Die Datenschutzerklärung (`/privacy`) passt sich den Einstellungen an und erwähnt: 2FA-Daten (Geheimnis verschlüsselt, Wiederherstellungscodes als Hash), optional SSO-Daten und Push-Registrierungen, Betriebsdaten (Fehlerprotokoll, Hintergrundaufträge, Backups mit Aufbewahrungsdauer), Export-Dateien (7 Tage).

* **Selbst-Export** läuft als Hintergrundauftrag; die Datei liegt 7 Tage unter `storage/exports/` und wird bei Kontolöschung sofort entfernt.
* **Backups** enthalten alle Daten, auch solche, die inzwischen gelöscht wurden, bis zum Ablauf der Aufbewahrung (Einstellung „Backups behalten“). Das ist in der Erklärung vermerkt; passe die Zahl an deine Löschkonzept-Vorgaben an.
* **Prüfpaket:** Administration → Datenschutz → Prüfpaket. Es ersetzt keine Rechtsberatung. Lass Erklärung und Verzeichnis von einer Fachperson prüfen und halte das Ergebnis im Prüfvermerk fest.

## Register der Datenschutzverletzungen

Administration → Datenschutz → Datenschutzverletzungen: Vorfälle mit Kenntnisnahme-Zeitpunkt, Datenkategorien, Betroffenenzahl, Risiko, Massnahmen sowie Meldung an die Behörde und Information der Betroffenen. Bei Risiko zeigt das Register die 72-Stunden-Frist (Art. 33 DSGVO) und markiert überschrittene Fristen; ein offener Vorfall ohne Meldung oder Begründung färbt die Prüfpaket-Checkliste rot. Auch nicht meldepflichtige Vorfälle werden hier dokumentiert (Art. 33 Abs. 5 DSGVO, Art. 24 DSG). Die Meldung selbst erfolgt weiterhin durch dich bei der zuständigen Behörde (EDÖB bzw. EU-Aufsichtsbehörde).
