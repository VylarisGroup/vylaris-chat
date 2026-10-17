<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;

/** Aufbewahrungsregeln und Bereinigung. Läuft per Cron (bin/cleanup.php) oder opportunistisch höchstens stündlich. */
final class Retention
{
    public static function maybeRun(): void
    {
        $now = time();
        // Atomar "reservieren", damit parallele Requests nicht gleichzeitig aufräumen
        Database::exec("INSERT IGNORE INTO system_settings (k, v, updated_at) VALUES ('last_cleanup_at', '0', ?)", [now()]);
        $claimed = Database::exec(
            "UPDATE system_settings SET v = ?, updated_at = ? WHERE k = 'last_cleanup_at' AND CAST(v AS UNSIGNED) < ?",
            [(string) $now, now(), $now - 3600]
        );
        if ($claimed > 0) {
            self::run();
        }
    }

    /** @return array<string,int> Anzahl bereinigter Datensätze je Bereich */
    public static function run(): array
    {
        $stats = [];
        $stats['events'] = Database::exec('DELETE FROM events WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 2 * 86400)]);
        $stats['typing'] = Database::exec('DELETE FROM typing_state WHERE typed_at < ?', [gmdate('Y-m-d H:i:s', time() - 120)]);
        RateLimiter::purgeOld();
        $stats['drafts'] = Drafts::purgeOld();
        $stats['guests_expired'] = Auth::expireGuests();
        $stats['notifications'] = Database::exec('DELETE FROM notifications WHERE read_at IS NOT NULL AND read_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $stats['tokens'] = Database::exec('DELETE FROM password_resets WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)])
            + Database::exec('DELETE FROM email_verifications WHERE expires_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)])
            + Database::exec('DELETE FROM invitations WHERE expires_at < ? AND accepted_at IS NULL', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        $stats['sessions'] = Database::exec('DELETE FROM user_sessions WHERE (revoked_at IS NOT NULL AND revoked_at < ?) OR last_activity_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400), gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);

        // Nicht verwendete Uploads
        $orphanDays = max(1, Settings::int('retention_days_inactive_orphan_uploads'));
        $orphans = Database::all('SELECT id, storage_name FROM message_attachments WHERE message_id IS NULL AND created_at < ? LIMIT 500', [gmdate('Y-m-d H:i:s', time() - $orphanDays * 86400)]);
        foreach ($orphans as $o) {
            Files::unlinkStored((string) $o['storage_name']);
            Database::exec('DELETE FROM message_attachments WHERE id = ?', [$o['id']]);
        }
        $stats['orphan_uploads'] = count($orphans);

        $auditDays = Settings::int('retention_days_audit');
        // Temporäre Exportdateien und abgeschlossene Datenschutzanfragen (Nachweisfrist 3 Jahre)
        foreach (glob(storage_path('tmp') . '/exp*') ?: [] as $tmpFile) {
            if (is_file($tmpFile) && filemtime($tmpFile) < time() - 3600) {
                @unlink($tmpFile);
            }
        }
        $stats['privacy_requests'] = Database::exec("DELETE FROM privacy_requests WHERE status IN ('completed','rejected') AND handled_at < ?", [gmdate('Y-m-d H:i:s', time() - 3 * 365 * 86400)]);
        $stats['audit'] = $auditDays > 0 ? Database::exec('DELETE FROM audit_logs WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - $auditDays * 86400)]) : 0;

        try {
            Updater::maybeCheck();
        } catch (\Throwable $e) {
            log_error('Update-Prüfung: ' . $e->getMessage());
        }
        try {
            License::maintain();
        } catch (\Throwable $e) {
            log_error('Lizenzwartung: ' . $e->getMessage());
        }
        Monitor::rotateLog();
        Backup::maybeScheduled();
        $stats['jobs'] = Jobs::purge();
        Jobs::runPending(2, 30);
        $stats['messages'] = self::purgeMessages();
        $stats['session_files'] = self::purgeSessionFiles();
        return $stats;
    }

    /** Nachrichten nach Ablauf der Aufbewahrungsfrist endgültig löschen (inklusive Dateien). */
    private static function purgeMessages(): int
    {
        $days = Settings::int('retention_days_messages');
        if ($days <= 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $total = 0;
        for ($round = 0; $round < 20; $round++) {
            $ids = array_column(Database::all('SELECT id FROM messages WHERE created_at < ? ORDER BY id ASC LIMIT 500', [$cutoff]), 'id');
            if (!$ids) {
                break;
            }
            $in = Database::in($ids);
            $files = Database::all("SELECT storage_name FROM message_attachments WHERE message_id IN ($in)", $ids);
            $convIds = array_column(Database::all("SELECT DISTINCT conversation_id FROM messages WHERE id IN ($in)", $ids), 'conversation_id');
            Database::transaction(function () use ($ids, $in) {
                Database::exec("DELETE FROM notifications WHERE message_id IN ($in)", $ids);
                Database::exec("DELETE FROM messages WHERE id IN ($in)", $ids);
            });
            foreach ($files as $f) {
                Files::unlinkStored((string) $f['storage_name']);
            }
            foreach ($convIds as $cid) {
                Database::exec(
                    'UPDATE conversations c SET
                        c.last_message_id = (SELECT MAX(m.id) FROM messages m WHERE m.conversation_id = c.id),
                        c.last_message_at = (SELECT m2.created_at FROM messages m2 WHERE m2.conversation_id = c.id ORDER BY m2.id DESC LIMIT 1)
                     WHERE c.id = ?',
                    [$cid]
                );
            }
            $total += count($ids);
        }
        if ($total > 0) {
            Audit::log('retention.messages_purged', null, null, null, "$total Nachrichten älter als $days Tage gelöscht");
        }
        return $total;
    }

    private static function purgeSessionFiles(): int
    {
        $dir = storage_path('sessions');
        $n = 0;
        foreach (glob($dir . '/sess_*') ?: [] as $f) {
            if (is_file($f) && filemtime($f) < time() - Session::LIFETIME - 86400) {
                @unlink($f);
                $n++;
            }
        }
        return $n;
    }
}
