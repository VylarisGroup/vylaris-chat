# Änderungen

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
