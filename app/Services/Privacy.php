<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Response;
use App\Models\User;

/**
 * Betroffenenrechte nach revDSG (Art. 25, 28, 32 DSG) und DSGVO (Art. 15 bis 21).
 *
 * Leitprinzip: Administratoren erhalten über diese Funktionen KEINEN Zugriff auf Nachrichteninhalte.
 * Der Export durch Administratoren enthält nur Konto- und Metadaten ("metadata"); den vollständigen
 * Export mit eigenen Inhalten ("full") kann nur die betroffene Person selbst auslösen.
 * Inhalte anderer Personen (deren Nachrichten) sind nie Teil eines Exports (Rechte Dritter).
 */
final class Privacy
{
    public const TYPES = [
        'access' => 'Auskunft über meine Daten',
        'portability' => 'Herausgabe / Übertragung meiner Daten',
        'rectification' => 'Berichtigung unrichtiger Daten',
        'erasure' => 'Löschung meiner Daten',
        'restriction' => 'Einschränkung (Sperrung) der Bearbeitung',
        'objection' => 'Widerspruch gegen eine Bearbeitung',
        'other' => 'Anderes Anliegen zum Datenschutz',
    ];

    public const STATUSES = [
        'open' => 'Offen',
        'in_progress' => 'In Bearbeitung',
        'completed' => 'Erledigt',
        'rejected' => 'Abgelehnt',
    ];

    /** Antwortfrist in Tagen (Art. 25 Abs. 7 DSG: 30 Tage, Art. 12 Abs. 3 DSGVO: ein Monat). */
    public const DEADLINE_DAYS = 30;

    /** Anhänge werden nur bis zu dieser Gesamtgrösse in die ZIP-Datei gepackt. */
    private const MAX_ZIP_FILES_BYTES = 300 * 1048576;

    // ------------------------------------------------------------ Einschränkung

    public static function isRestricted(array $user): bool
    {
        return !empty($user['processing_restricted_at']);
    }

    /** Verhindert aktive Nutzung (Senden, Hochladen, Reagieren) bei eingeschränkter Bearbeitung. */
    public static function assertNotRestricted(array $user): void
    {
        if (self::isRestricted($user)) {
            throw new HttpException(403, 'Die Bearbeitung deiner Daten ist eingeschränkt (Datenschutz). Du kannst nichts mehr senden oder hochladen. Du kannst weiterhin lesen, deine Daten herunterladen und Anfragen stellen.');
        }
    }

    public static function restrict(int $userId, int $adminId, string $note): void
    {
        Database::exec(
            'UPDATE users SET processing_restricted_at = COALESCE(processing_restricted_at, ?), processing_restricted_note = ?, updated_at = ? WHERE id = ?',
            [now(), mb_substr($note, 0, 255), now(), $userId]
        );
        Audit::log('privacy.restricted', $adminId, 'user', $userId, mb_substr($note, 0, 200));
    }

    public static function unrestrict(int $userId, int $adminId): void
    {
        Database::exec("UPDATE users SET processing_restricted_at = NULL, processing_restricted_note = '', updated_at = ? WHERE id = ?", [now(), $userId]);
        Audit::log('privacy.unrestricted', $adminId, 'user', $userId);
    }

    /** Hält fest, dass der Datenschutzhinweis bei der Kontoerstellung angezeigt wurde (Informationspflicht, keine Einwilligung). */
    public static function acknowledge(int $userId): void
    {
        Database::exec('UPDATE users SET privacy_ack_at = ? WHERE id = ?', [now(), $userId]);
    }

    // ------------------------------------------------------------------ Anfragen

    public static function createRequest(?int $userId, string $label, string $type, string $message, string $source, ?int $createdBy): int
    {
        if (!isset(self::TYPES[$type])) {
            throw new HttpException(422, 'Unbekannte Art der Anfrage.');
        }
        $id = Database::insert(
            'INSERT INTO privacy_requests (user_id, subject_label, type, message, source, status, due_at, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$userId, mb_substr($label, 0, 190), $type, mb_substr($message, 0, 2000), $source, 'open', gmdate('Y-m-d H:i:s', time() + self::DEADLINE_DAYS * 86400), $createdBy, now(), now()]
        );
        Audit::log('privacy.request_created', $createdBy ?? $userId, 'privacy_request', $id, $type);
        if ($source === 'user') {
            $who = $label !== '' ? $label : 'Ein Mitglied';
            foreach (Database::all("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL") as $a) {
                Notifications::create((int) $a['id'], 'system', $userId, null, null, 'Neue Datenschutzanfrage: ' . (self::TYPES[$type]) . ' (' . $who . ')');
            }
        }
        return $id;
    }

    public static function updateRequest(int $id, int $adminId, string $status, string $response): void
    {
        if (!isset(self::STATUSES[$status])) {
            throw new HttpException(422, 'Unbekannter Status.');
        }
        $closed = in_array($status, ['completed', 'rejected'], true);
        Database::exec(
            'UPDATE privacy_requests SET status = ?, response = ?, handled_by = ?, handled_at = ?, updated_at = ? WHERE id = ?',
            [$status, mb_substr($response, 0, 2000), $adminId, $closed ? now() : null, now(), $id]
        );
        Audit::log('privacy.request_' . $status, $adminId, 'privacy_request', $id);
        $req = Database::one('SELECT user_id, type FROM privacy_requests WHERE id = ?', [$id]);
        if ($closed && $req && $req['user_id']) {
            Notifications::create((int) $req['user_id'], 'system', null, null, null, 'Deine Datenschutzanfrage (' . self::TYPES[$req['type']] . ') wurde bearbeitet: ' . self::STATUSES[$status] . '.');
        }
    }

    public static function openCount(): int
    {
        try {
            return (int) Database::value("SELECT COUNT(*) FROM privacy_requests WHERE status IN ('open','in_progress')");
        } catch (\Throwable) {
            return 0;
        }
    }

    // -------------------------------------------------------------------- Export

    /**
     * Sammelt die zu einer Person gespeicherten Daten.
     *
     * @param string $scope "full" (nur durch die Person selbst: inkl. eigener Nachrichtentexte und Dateinamen)
     *                      oder "metadata" (Administratoren: ohne Nachrichteninhalte)
     */
    public static function collect(int $userId, string $scope): array
    {
        $full = $scope === 'full';
        $u = User::find($userId);
        if (!$u) {
            throw new HttpException(404, 'Benutzer nicht gefunden.');
        }

        $profile = [
            'id' => (int) $u['id'],
            'e_mail' => $u['email'],
            'benutzername' => $u['username'],
            'anzeigename' => $u['display_name'],
            'statusnachricht' => $u['status_message'],
            'rolle' => $u['role'],
            'profilbild_vorhanden' => !empty($u['avatar_file']),
            'konto_aktiv' => (bool) $u['is_active'],
            'praesenz_einstellung' => $u['presence_pref'],
            'e_mail_bestaetigt_am' => $u['email_verified_at'],
            'zuletzt_gesehen' => $u['last_seen_at'],
            'letzte_aktivitaet' => $u['last_active_at'],
            'letzte_anmeldung' => $u['last_login_at'],
            'passwort_geaendert_am' => $u['password_changed_at'],
            'datenschutzhinweis_zur_kenntnis_genommen_am' => $u['privacy_ack_at'],
            'bearbeitung_eingeschraenkt_seit' => $u['processing_restricted_at'],
            'erstellt_am' => $u['created_at'],
            'aktualisiert_am' => $u['updated_at'],
            'passwort' => 'Nur als nicht umkehrbarer Hash gespeichert (nicht Teil des Exports).',
        ];

        $settings = Database::one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]) ?: [];
        unset($settings['user_id']);

        $sessions = Database::all('SELECT ip, user_agent, created_at, last_activity_at, revoked_at FROM user_sessions WHERE user_id = ? ORDER BY id DESC LIMIT 500', [$userId]);

        $memberships = Database::all(
            "SELECT c.id AS conversation_id, c.type, c.name, m.role, m.muted, m.archived, m.pinned, m.joined_at, m.left_at,
                    (SELECT u2.display_name FROM conversation_members m2 JOIN users u2 ON u2.id = m2.user_id
                      WHERE m2.conversation_id = c.id AND m2.user_id <> m.user_id LIMIT 1) AS partner
             FROM conversation_members m JOIN conversations c ON c.id = m.conversation_id
             WHERE m.user_id = ? ORDER BY m.joined_at DESC LIMIT 5000",
            [$userId]
        );
        foreach ($memberships as &$m) {
            $m['unterhaltung'] = $m['type'] === 'direct' ? 'Direktnachricht mit ' . ($m['partner'] ?? 'unbekannt') : (string) $m['name'];
            unset($m['partner'], $m['name']);
        }
        unset($m);

        $msgCols = $full ? 'id, conversation_id, kind, body, reply_to_id, forwarded, edited_at, deleted_at, created_at'
                         : 'id, conversation_id, kind, CHAR_LENGTH(body) AS zeichen, forwarded, edited_at, deleted_at, created_at';
        $messages = Database::all("SELECT $msgCols FROM messages WHERE sender_id = ? AND kind IN ('text','poll','event','meeting') ORDER BY id ASC LIMIT 200000", [$userId]);

        $attCols = $full ? 'id, message_id, conversation_id, original_name, mime_type, size_bytes, created_at'
                         : 'id, message_id, conversation_id, mime_type, size_bytes, created_at';
        $attachments = Database::all("SELECT $attCols FROM message_attachments WHERE uploader_id = ? AND message_id IS NOT NULL ORDER BY id ASC LIMIT 50000", [$userId]);

        $reactions = Database::all('SELECT message_id, emoji, created_at FROM message_reactions WHERE user_id = ? ORDER BY created_at ASC LIMIT 50000', [$userId]);
        $receipts = Database::value('SELECT COUNT(*) FROM message_receipts WHERE user_id = ?', [$userId]);
        $mentions = Database::value('SELECT COUNT(*) FROM message_mentions WHERE user_id = ?', [$userId]);
        $reports = Database::all('SELECT message_id, ' . ($full ? 'reason, ' : '') . 'status, created_at FROM message_reports WHERE reporter_id = ? ORDER BY id ASC LIMIT 5000', [$userId]);
        $notifications = Database::all('SELECT type, text, read_at, created_at FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1000', [$userId]);
        $audit = Database::all(
            "SELECT action, target_type, target_id, details, ip, user_agent, created_at FROM audit_logs
             WHERE user_id = ? OR (target_type = 'user' AND target_id = ?) ORDER BY id DESC LIMIT 5000",
            [$userId, $userId]
        );
        $requests = Database::all('SELECT id, type, message, status, response, due_at, handled_at, created_at FROM privacy_requests WHERE user_id = ? ORDER BY id ASC', [$userId]);
        $invites = Database::value('SELECT COUNT(*) FROM invitations WHERE invited_by = ?', [$userId]);
        $drafts = Database::all('SELECT conversation_id, thread_root_id, ' . ($full ? 'body, ' : 'CHAR_LENGTH(body) AS zeichen, ') . 'updated_at FROM drafts WHERE user_id = ? ORDER BY updated_at DESC LIMIT 500', [$userId]);
        $votes = Database::all('SELECT poll_id, option_id, created_at FROM poll_votes WHERE user_id = ? ORDER BY created_at ASC LIMIT 20000', [$userId]);
        $rsvps = Database::all('SELECT event_id, status, updated_at FROM event_rsvps WHERE user_id = ? ORDER BY updated_at ASC LIMIT 20000', [$userId]);

        return [
            '_hinweis' => [
                'beschreibung' => 'Auszug der zu Ihrer Person gespeicherten Daten (Auskunft nach Art. 25 DSG und Art. 15 DSGVO, Datenherausgabe nach Art. 28 DSG und Art. 20 DSGVO).',
                'erstellt_am_utc' => gmdate('c'),
                'umfang' => $full
                    ? 'Vollständig: enthält Ihre eigenen Nachrichtentexte, Dateinamen und Ihre Meldungsgründe.'
                    : 'Nur Konto- und Metadaten: Nachrichtentexte, Dateinamen und Dateiinhalte sind bewusst nicht enthalten (Administratoren haben keinen Zugriff auf private Nachrichten). Ihre Inhalte erhalten Sie über Einstellungen > Meine Daten.',
                'nicht_enthalten' => 'Nachrichten und Dateien anderer Personen (Rechte Dritter), Passwort-Hash, Sicherheits-Tokens.',
                'zeitangaben' => 'UTC',
            ],
            'konto' => $profile,
            'einstellungen' => $settings,
            'sitzungen' => $sessions,
            'unterhaltungen' => $memberships,
            'nachrichten' => $messages,
            'dateien' => $attachments,
            'reaktionen' => $reactions,
            'entwuerfe_synchronisiert' => $drafts,
            'umfrage_stimmen' => $votes,
            'terminantworten' => $rsvps,
            'meldungen_von_ihnen' => $reports,
            'benachrichtigungen' => $notifications,
            'protokolleintraege' => $audit,
            'datenschutzanfragen' => $requests,
            'zaehler' => [
                'lesebestaetigungen' => (int) $receipts,
                'erwaehnungen_von_ihnen_in_nachrichten_anderer' => (int) $mentions,
                'versendete_einladungen' => (int) $invites,
            ],
        ];
    }

    /**
     * Erzeugt die Download-Antwort. Selbst-Export (full) enthält zusätzlich eine ZIP-Datei mit den eigenen Anhängen,
     * sofern die PHP-Erweiterung zip verfügbar ist.
     */
    public static function download(int $userId, string $scope): Response
    {
        $data = self::collect($userId, $scope);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $stamp = gmdate('Ymd-His');
        $base = $scope === 'full' ? "meine-daten-$stamp" : "datenauszug-nutzer-$userId-$stamp";

        if ($scope !== 'full' || !class_exists(\ZipArchive::class)) {
            return new Response(200, (string) $json, [
                'Content-Type' => 'application/json; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="' . $base . '.json"',
                'Cache-Control' => 'no-store',
                'X-Content-Type-Options' => 'nosniff',
            ]);
        }

        $dir = storage_path('tmp');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $tmp = tempnam($dir, 'exp');
        if ($tmp === false) {
            throw new HttpException(500, 'Der Export konnte nicht erstellt werden.');
        }
        register_shutdown_function(static function () use ($tmp) {
            @unlink($tmp);
        });
        self::writeFullZip($userId, $tmp, (string) $json);
        return Response::file($tmp, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $base . '.zip"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Baut das vollständige Selbst-Export-Archiv (Hintergrundjob). Schreibt nach storage/exports.
     *
     * @return array{file:string,size:int,download_name:string}
     */
    public static function buildArchive(int $userId): array
    {
        $dir = storage_path('exports');
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $json = (string) json_encode(self::collect($userId, 'full'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        $stamp = gmdate('Ymd-His');
        $stored = 'u' . $userId . '-' . bin2hex(random_bytes(12));
        if (!class_exists(\ZipArchive::class)) {
            $path = $dir . '/' . $stored . '.json';
            file_put_contents($path, $json);
            return ['file' => basename($path), 'size' => (int) filesize($path), 'download_name' => "meine-daten-$stamp.json"];
        }
        $path = $dir . '/' . $stored . '.zip';
        self::writeFullZip($userId, $path, $json);
        return ['file' => basename($path), 'size' => (int) filesize($path), 'download_name' => "meine-daten-$stamp.zip"];
    }

    private static function writeFullZip(int $userId, string $target, string $json): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($target, \ZipArchive::OVERWRITE | \ZipArchive::CREATE) !== true) {
            throw new HttpException(500, 'Der Export konnte nicht erstellt werden.');
        }
        $zip->addFromString('daten.json', (string) $json);
        $skipped = 0;
        $total = 0;
        $seen = [];
        $rows = Database::all("SELECT id, storage_name, original_name, size_bytes FROM message_attachments WHERE uploader_id = ? AND message_id IS NOT NULL ORDER BY id ASC LIMIT 5000", [$userId]);
        foreach ($rows as $a) {
            $path = Files::path((string) $a['storage_name']);
            if (!is_file($path) || $total + (int) $a['size_bytes'] > self::MAX_ZIP_FILES_BYTES) {
                $skipped++;
                continue;
            }
            $name = 'dateien/' . $a['id'] . '_' . Files::sanitizeName((string) $a['original_name']);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $zip->addFile($path, $name);
            $total += (int) $a['size_bytes'];
        }
        $readme = "Export Ihrer Daten (" . gmdate('c') . ")\n\n"
            . "daten.json   Ihre gespeicherten Daten in maschinenlesbarer Form (UTF-8, Zeitangaben in UTC)\n"
            . "dateien/     Ihre hochgeladenen Anhänge" . ($skipped ? " (" . $skipped . " Datei(en) wegen der Grössenbeschränkung nicht enthalten, bitte beim Verantwortlichen anfragen)" : '') . "\n\n"
            . "Nachrichten und Dateien anderer Personen sind nicht enthalten (Rechte Dritter).\n";
        $zip->addFromString('LIESMICH.txt', $readme);
        $zip->close();
    }

    // ------------------------------------------------------------------ Löschung

    /**
     * Löscht den Inhalt aller von der Person gesendeten Nachrichten samt Anhängen endgültig.
     * Die Nachrichten bleiben als Platzhalter "Nachricht gelöscht" bestehen, damit Unterhaltungen konsistent bleiben.
     *
     * @return int Anzahl gelöschter Nachrichten
     */
    public static function eraseContent(int $userId): int
    {
        $count = 0;
        while (true) {
            $rows = Database::all(
                "SELECT id, conversation_id FROM messages WHERE sender_id = ? AND kind IN ('text','poll','event','meeting') AND deleted_at IS NULL ORDER BY id ASC LIMIT 500",
                [$userId]
            );
            if (!$rows) {
                break;
            }
            foreach ($rows as $row) {
                Messages::eraseForPrivacy((int) $row['id'], (int) $row['conversation_id']);
                $count++;
            }
        }
        // Von der Person hochgeladene, noch nicht gesendete Dateien
        foreach (Database::all('SELECT id, storage_name FROM message_attachments WHERE uploader_id = ? AND message_id IS NULL', [$userId]) as $a) {
            Database::exec('DELETE FROM message_attachments WHERE id = ?', [$a['id']]);
            Files::unlinkStored((string) $a['storage_name']);
        }
        Database::exec('DELETE FROM message_reports WHERE reporter_id = ? AND status = ?', [$userId, 'open']);
        return $count;
    }

    /** Ersetzt den Namen in Systemmeldungen ("X hat die Gruppe verlassen") durch einen Platzhalter. */
    public static function scrubSystemMessages(int $userId, string $displayName): void
    {
        $name = trim($displayName);
        if (mb_strlen($name) < 2) {
            return;
        }
        Database::exec(
            "UPDATE messages SET body = REPLACE(body, ?, 'Gelöschter Benutzer')
             WHERE kind = 'system' AND body LIKE ? AND conversation_id IN (SELECT conversation_id FROM conversation_members WHERE user_id = ?)",
            [$name, '%' . User::escapeLike($name) . '%', $userId]
        );
    }
}
