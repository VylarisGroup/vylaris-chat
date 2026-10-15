<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Prüfpaket für die rechtliche Beurteilung: automatisch befülltes Verzeichnis der Bearbeitungstätigkeiten
 * (Art. 12 DSG / Art. 30 DSGVO), Checkliste und Prüfvermerk. Ersetzt keine Rechtsberatung.
 */
final class PrivacyReview
{
    /** Manuell zu bestätigende Punkte (id => [Titel, Hinweis]). */
    public const MANUAL = [
        'legal_review' => ['Rechtliche Prüfung der Datenschutzerklärung durch eine Fachperson', 'Anwältin/Anwalt oder Datenschutzberatung hat die veröffentlichte Erklärung und dieses Verzeichnis geprüft (Prüfvermerk unten eintragen).'],
        'dpa_hosting' => ['Auftragsbearbeitungs-Vertrag mit dem Hosting-Anbieter', 'Art. 9 DSG / Art. 28 DSGVO. Inkl. Regelung zu Zugriff auf Server und Datenbank.'],
        'dpa_smtp' => ['Auftragsbearbeitungs-Vertrag mit dem E-Mail-Versanddienst', 'Falls ein externer SMTP-Dienst genutzt wird.'],
        'employee_info' => ['Mitarbeitende informiert / Nutzungsreglement', 'Regeln zu privater Nutzung, Einsicht (nur bei Meldungen) und Leistungs-/Verhaltenskontrolle; für CH: Art. 328b OR, Art. 26 ArGV 3; Mitwirkung der Arbeitnehmenden beachten.'],
        'dpia' => ['Datenschutz-Folgenabschätzung geprüft', 'Art. 22 DSG / Art. 35 DSGVO: Ist wegen Art und Umfang der Inhalte (z. B. besonders schützenswerte Daten) eine DSFA nötig? Ergebnis dokumentieren.'],
        'breach_process' => ['Prozess für Datenschutzverletzungen festgelegt', 'Wer meldet wann an wen (Art. 24 DSG: so rasch als möglich an den EDÖB; Art. 33 DSGVO: 72 Stunden).'],
        'foreign' => ['Auslandbekanntgabe geprüft', 'Standorte des Hostings und der Dienstleister, ggf. Standardvertragsklauseln (Art. 16 f. DSG, Art. 44 ff. DSGVO).'],
        'rep' => ['Vertretung in der Schweiz/EU geprüft', 'Nur nötig, wenn der Verantwortliche seinen Sitz ausserhalb des jeweiligen Gebiets hat (Art. 14 DSG, Art. 27 DSGVO).'],
        'access_concept' => ['Zugriffskonzept auf Server und Datenbank dokumentiert', 'Wer darf technisch Nachrichteninhalte lesen (der Messenger ist nicht Ende-zu-Ende-verschlüsselt)?'],
        'backup_test' => ['Wiederherstellung eines Backups getestet', 'Mindestens jährlich; Ergebnis notieren.'],
        'annual' => ['Jährliche Überprüfung von Erklärung und Verzeichnis geplant', 'Termin im Kalender / Verantwortliche Person.'],
    ];

    /** Felder, die den Stand der Erklärung bestimmen (für den Änderungs-Fingerabdruck). */
    private const FINGERPRINT_KEYS = [
        'privacy_controller', 'privacy_contact_email', 'privacy_dpo', 'privacy_representative', 'privacy_hosting', 'privacy_recipients',
        'privacy_scope', 'privacy_authority_eu', 'privacy_extra', 'retention_days_messages', 'retention_days_audit', 'push_enabled',
        'oidc_enabled', 'backup_keep', 'email_notifications_enabled',
    ];

    public static function fingerprint(array $s): string
    {
        $parts = [];
        foreach (self::FINGERPRINT_KEYS as $k) {
            $parts[$k] = (string) ($s[$k] ?? '');
        }
        $parts['tpl'] = '2'; // Version der Vorlage
        return substr(hash('sha256', json_encode($parts)), 0, 16);
    }

    /** @return array{items:array<int,array<string,mixed>>,done:int,total:int} */
    public static function checklist(array $s): array
    {
        $ticked = json_decode((string) ($s['review_checklist'] ?? ''), true);
        $ticked = is_array($ticked) ? $ticked : [];
        $filled = static fn (string $k): bool => trim((string) ($s[$k] ?? '')) !== '';
        $auto = [
            ['controller', 'Verantwortlicher angegeben', $filled('privacy_controller'), 'Administration → Datenschutz → Angaben'],
            ['contact', 'Kontaktadresse für Datenschutzanliegen angegeben', $filled('privacy_contact_email'), 'Administration → Datenschutz → Angaben'],
            ['hosting', 'Hosting/Serverstandort angegeben', $filled('privacy_hosting'), 'Administration → Datenschutz → Angaben'],
            ['recipients', 'Dienstleister/Empfänger angegeben', $filled('privacy_recipients'), 'Administration → Datenschutz → Angaben'],
            ['retention_audit', 'Aufbewahrungsfrist für das Sicherheitsprotokoll festgelegt', (int) ($s['retention_days_audit'] ?? 0) > 0, 'Einstellungen → System'],
            ['retention_msg', 'Aufbewahrungsfrist für Nachrichten bewusst festgelegt (0 = unbefristet ist zulässig, aber zu begründen)', (int) ($s['retention_days_messages'] ?? 0) > 0, 'Einstellungen → System'],
            ['https', 'HTTPS aktiv', is_https() || str_starts_with(base_url(), 'https://'), 'Webserver/Hosting'],
            ['tfa', 'Zwei-Faktor-Authentifizierung für Administratoren vorgeschrieben', ($s['require_2fa_admins'] ?? '0') === '1', 'Administration → Sicherheit'],
            ['backup', 'Backup eingerichtet und aktuell (innerhalb von 8 Tagen)', (int) ($s['backup_last_at'] ?? 0) > time() - 8 * 86400, 'Administration → Backup'],
            ['backup_dl', 'Backup-Download im Browser ist ausgeschaltet (Administratoren sollen keine Nachrichteninhalte erhalten)', ($s['backup_web_download'] ?? '0') !== '1', 'Administration → Backup'],
            ['smtp', 'SMTP konfiguriert (für Bestätigungs- und Passwort-Mails)', Mail::configured(), 'Einstellungen → E-Mail'],
            ['breach', 'Keine offene Datenschutzverletzung mit Risiko ohne Meldung bzw. Begründung', (int) Database::value("SELECT COUNT(*) FROM data_breaches WHERE status = 'open' AND risk <> 'low' AND authority_notified_at IS NULL AND (authority_reason IS NULL OR authority_reason = '')") === 0, 'Administration → Datenschutz → Datenschutzverletzungen'],
            ['open_requests', 'Keine überfällige Betroffenenanfrage', (int) Database::value("SELECT COUNT(*) FROM privacy_requests WHERE status IN ('open','in_progress') AND due_at < ?", [now()]) === 0, 'Administration → Datenschutz'],
        ];
        $items = [];
        $done = 0;
        foreach ($auto as [$id, $title, $ok, $where]) {
            $items[] = ['id' => $id, 'title' => $title, 'ok' => $ok, 'auto' => true, 'hint' => $ok ? '' : $where, 'by' => null, 'at' => null];
            $done += $ok ? 1 : 0;
        }
        foreach (self::MANUAL as $id => [$title, $hint]) {
            $t = $ticked[$id] ?? null;
            $items[] = ['id' => $id, 'title' => $title, 'ok' => $t !== null, 'auto' => false, 'hint' => $hint, 'by' => $t['by'] ?? null, 'at' => $t['at'] ?? null];
            $done += $t !== null ? 1 : 0;
        }
        return ['items' => $items, 'done' => $done, 'total' => count($items)];
    }

    /**
     * Verzeichnis der Bearbeitungstätigkeiten, abgeleitet aus dem Funktionsumfang und den Einstellungen.
     *
     * @return array{controller:string,activities:array<int,array<string,string>>}
     */
    public static function register(array $s): array
    {
        $msgDays = (int) ($s['retention_days_messages'] ?? 0);
        $auditDays = (int) ($s['retention_days_audit'] ?? 0);
        $rec = trim((string) ($s['privacy_recipients'] ?? '')) ?: 'Hosting-Anbieter, E-Mail-Versanddienst (noch nicht hinterlegt)';
        $host = trim((string) ($s['privacy_hosting'] ?? '')) ?: 'Serverstandort nicht hinterlegt';
        $toms = 'HTTPS, Passwort-Hashing (Argon2id/bcrypt), Zugriffskontrolle je Unterhaltung, Rollenmodell (Administratoren ohne Zugriff auf Nachrichteninhalte), CSRF-/XSS-Schutz, Rate-Limits, widerrufbare Sitzungen, Audit-Protokoll';
        $a = [];
        $a[] = [
            'name' => 'Betrieb des internen Messengers',
            'purpose' => 'Interne Kommunikation und Zusammenarbeit (Einzel- und Gruppenchats, Dateien)',
            'subjects' => 'Mitarbeitende, ggf. eingeladene externe Personen',
            'data' => 'Kontodaten, Profil, Nachrichten und Dateien, Reaktionen, Lese-/Zustellstatus, Online-Status',
            'recipients' => 'Gesprächsteilnehmende; ' . $rec,
            'abroad' => $host,
            'retention' => ($msgDays > 0 ? "Nachrichten nach $msgDays Tagen gelöscht" : 'Nachrichten unbefristet bis zur Löschung') . '; Konto bis zur Löschung',
            'toms' => $toms,
        ];
        $a[] = [
            'name' => 'Authentifizierung und Sicherheit',
            'purpose' => 'Zugangsschutz, Missbrauchsabwehr, Nachvollziehbarkeit',
            'subjects' => 'Alle Nutzenden',
            'data' => 'Passwort-Hash, Sitzungen (IP, Browser), Anmeldeprotokoll, Rate-Limits' . (($s['oidc_enabled'] ?? '0') === '1' ? ', SSO-Kennung (Hash)' : '') . ', optional 2FA-Geheimnis (verschlüsselt) und Wiederherstellungscode-Hashes',
            'recipients' => 'Administratoren (Protokoll, ohne Nachrichteninhalte)' . (($s['oidc_enabled'] ?? '0') === '1' ? '; Identitätsanbieter (SSO)' : ''),
            'abroad' => 'wie Hosting; ' . ((($s['oidc_enabled'] ?? '0') === '1') ? 'Standort des Identitätsanbieters prüfen' : 'keine weitere'),
            'retention' => 'Sitzungen max. 30 Tage nach Ablauf; Protokoll ' . ($auditDays > 0 ? "$auditDays Tage" : 'unbefristet (Frist empfohlen)'),
            'toms' => $toms . ', Kontosperre nach Fehlversuchen, optionale/obligatorische 2FA',
        ];
        $a[] = [
            'name' => 'Benachrichtigungen',
            'purpose' => 'Hinweise auf neue Nachrichten und Erwähnungen (In-App, E-Mail' . (($s['push_enabled'] ?? '0') === '1' ? ', Push' : '') . ')',
            'subjects' => 'Alle Nutzenden',
            'data' => 'E-Mail-Adresse, Name, Benachrichtigungseinstellungen' . (($s['push_enabled'] ?? '0') === '1' ? ', Push-Geräteadresse' : '') . ' (nie Nachrichteninhalte)',
            'recipients' => 'E-Mail-Versanddienst (SMTP)' . (($s['push_enabled'] ?? '0') === '1' ? '; Push-Dienste der Browser-Hersteller (nur leeres Signal)' : ''),
            'abroad' => 'Standort SMTP-Anbieter prüfen' . (($s['push_enabled'] ?? '0') === '1' ? '; Push-Dienste (Google/Mozilla/Apple) ggf. USA' : ''),
            'retention' => 'Gelesene Hinweise 30 Tage; Push bis zur Abmeldung des Geräts',
            'toms' => 'Mails ohne Inhalt, Push ohne Nutzlast, Opt-in für Push',
        ];
        $a[] = [
            'name' => 'Meldungen von Nachrichten',
            'purpose' => 'Moderation bei Missbrauch',
            'subjects' => 'Meldende und gemeldete Personen',
            'data' => 'Gemeldete Nachricht (Einsicht nur dieser einen), Grund, Bearbeitung',
            'recipients' => 'Administratoren',
            'abroad' => 'wie Hosting',
            'retention' => 'bis zur Erledigung bzw. Löschung der Nachricht',
            'toms' => 'Einsicht wird protokolliert',
        ];
        $a[] = [
            'name' => 'Betroffenenrechte',
            'purpose' => 'Beantwortung von Auskunfts-, Berichtigungs-, Löschungs- und weiteren Anfragen',
            'subjects' => 'Anfragende Personen',
            'data' => 'Anfrage, Antwort, Fristen; Datenexport (Archiv, 7 Tage abrufbar)',
            'recipients' => 'Administratoren (Anfragen; Exporte der Administration nur mit Metadaten)',
            'abroad' => 'wie Hosting',
            'retention' => '3 Jahre nach Abschluss; Exportdateien 7 Tage',
            'toms' => 'Passwortbestätigung für Selbst-Export, Rechte Dritter gewahrt (keine fremden Nachrichten)',
        ];
        $a[] = [
            'name' => 'Datensicherung und Betrieb',
            'purpose' => 'Verfügbarkeit, Wiederherstellung, Fehlerbehebung',
            'subjects' => 'Alle Nutzenden',
            'data' => 'Gesamter Datenbestand (Backups), technische Fehlerprotokolle',
            'recipients' => 'Betrieb/IT (Server-Zugriff)',
            'abroad' => $host,
            'retention' => 'Letzte ' . max(1, (int) ($s['backup_keep'] ?? 7)) . ' Backups; Fehlerlog rotiert',
            'toms' => 'Backup-Download im Browser standardmässig aus, optional AES-256-Verschlüsselung, Zugriff nur auf Serverebene',
        ];
        return ['controller' => trim((string) ($s['privacy_controller'] ?? '')) ?: 'Verantwortlicher nicht hinterlegt', 'activities' => $a];
    }

    public static function registerMarkdown(array $s): string
    {
        $r = self::register($s);
        $md = "# Verzeichnis der Bearbeitungstätigkeiten\n\nStand: " . gmdate('d.m.Y') . " (automatisch aus den Einstellungen erzeugt; vor Verwendung fachlich prüfen)\n\n"
            . "**Verantwortlicher:** " . str_replace("\n", ', ', $r['controller']) . "\n"
            . (trim((string) ($s['privacy_dpo'] ?? '')) !== '' ? '**Datenschutzberatung:** ' . str_replace("\n", ', ', trim((string) $s['privacy_dpo'])) . "\n" : '')
            . "\n";
        $labels = ['purpose' => 'Zweck', 'subjects' => 'Betroffene Personen', 'data' => 'Datenkategorien', 'recipients' => 'Empfänger', 'abroad' => 'Bekanntgabe ins Ausland / Standort', 'retention' => 'Aufbewahrung', 'toms' => 'Technische und organisatorische Massnahmen'];
        foreach ($r['activities'] as $i => $act) {
            $md .= '## ' . ($i + 1) . '. ' . $act['name'] . "\n\n";
            foreach ($labels as $k => $l) {
                $md .= "- **$l:** " . $act[$k] . "\n";
            }
            $md .= "\n";
        }
        return $md;
    }

    /** @return array<string,mixed>|null */
    public static function record(array $s): ?array
    {
        $r = json_decode((string) ($s['review_record'] ?? ''), true);
        return is_array($r) && !empty($r['date']) ? $r : null;
    }
}
