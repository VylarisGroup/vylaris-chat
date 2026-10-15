<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Deferred;

/**
 * Einfache Job-Warteschlange in der Datenbank (Tabelle jobs).
 * Ausgeführt nach dem Senden der Antwort (Deferred), bei der Wartung (Retention) oder per bin/worker.php.
 * Jobs sind idempotent angelegt: Ein Job wird atomar "geclaimt", bevor er läuft.
 */
final class Jobs
{
    public const TYPES = ['export' => 'Datenexport', 'backup' => 'Backup'];
    private const STALE_MINUTES = 20;

    public static function enqueue(string $type, ?int $userId, array $payload = [], int $expiresDays = 7): int
    {
        Database::exec(
            'INSERT INTO jobs (type, user_id, payload, status, created_at, expires_at) VALUES (?,?,?,?,?,?)',
            [$type, $userId, json_encode($payload), 'queued', now(), gmdate('Y-m-d H:i:s', time() + $expiresDays * 86400)]
        );
        $id = (int) Database::pdo()->lastInsertId();
        Deferred::add(static fn () => self::runPending(2, 45));
        return $id;
    }

    /** Führt wartende Jobs aus. @return int Anzahl ausgeführter Jobs */
    public static function runPending(int $max = 3, int $budgetSeconds = 50): int
    {
        self::recoverStale();
        $deadline = time() + $budgetSeconds;
        $ran = 0;
        while ($ran < $max && time() < $deadline) {
            $job = Database::one("SELECT * FROM jobs WHERE status = 'queued' ORDER BY id ASC LIMIT 1");
            if (!$job) {
                break;
            }
            $claimed = Database::exec("UPDATE jobs SET status = 'running', started_at = ? WHERE id = ? AND status = 'queued'", [now(), $job['id']]);
            if ($claimed < 1) {
                continue; // ein paralleler Prozess war schneller
            }
            self::execute($job);
            $ran++;
        }
        return $ran;
    }

    public static function execute(array $job): void
    {
        try {
            $payload = json_decode((string) $job['payload'], true) ?: [];
            switch ($job['type']) {
                case 'export':
                    if (!$job['user_id']) {
                        throw new \RuntimeException('Kein Benutzer');
                    }
                    $res = Privacy::buildArchive((int) $job['user_id']);
                    Database::exec("UPDATE jobs SET status = 'done', file_name = ?, file_size = ?, payload = ?, finished_at = ? WHERE id = ?", [$res['file'], $res['size'], json_encode(['download_name' => $res['download_name']]), now(), $job['id']]);
                    Audit::log('privacy.self_export_ready', (int) $job['user_id'], 'job', (int) $job['id']);
                    break;
                case 'backup':
                    $res = Backup::create((bool) ($payload['config'] ?? false), (string) ($payload['passphrase_enc'] ?? '') !== '' ? Crypto::decrypt((string) $payload['passphrase_enc']) : '');
                    Database::exec("UPDATE jobs SET status = 'done', file_name = ?, file_size = ?, payload = ?, finished_at = ? WHERE id = ?", [$res['file'], $res['size'], json_encode(['download_name' => $res['file']]), now(), $job['id']]);
                    break;
                default:
                    throw new \RuntimeException('Unbekannter Auftragstyp');
            }
        } catch (\Throwable $e) {
            log_error('Job #' . $job['id'] . ' fehlgeschlagen: ' . $e->getMessage());
            Database::exec("UPDATE jobs SET status = 'failed', error = ?, finished_at = ? WHERE id = ?", [mb_substr($e->getMessage(), 0, 250), now(), $job['id']]);
        }
    }

    /** Hängende Jobs (Prozess abgebrochen) als fehlgeschlagen markieren. */
    private static function recoverStale(): void
    {
        Database::exec(
            "UPDATE jobs SET status = 'failed', error = 'Zeitüberschreitung', finished_at = ? WHERE status = 'running' AND started_at < ?",
            [now(), gmdate('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60)]
        );
    }

    /** Dateipfad eines Jobergebnisses (nur wenn vorhanden und nicht abgelaufen). */
    public static function filePath(array $job): ?string
    {
        if ($job['status'] !== 'done' || empty($job['file_name']) || strtotime($job['expires_at'] . ' UTC') < time()) {
            return null;
        }
        $dir = $job['type'] === 'backup' ? Backup::dir() : storage_path('exports');
        $path = $dir . '/' . basename((string) $job['file_name']);
        return is_file($path) ? $path : null;
    }

    public static function downloadName(array $job): string
    {
        $p = json_decode((string) $job['payload'], true);
        return is_array($p) && !empty($p['download_name']) ? (string) $p['download_name'] : (string) $job['file_name'];
    }

    /** Abgelaufene Ergebnisse entfernen und alte Jobeinträge löschen. */
    public static function purge(): int
    {
        $n = 0;
        $rows = Database::all("SELECT id, type, file_name FROM jobs WHERE type = 'export' AND file_name IS NOT NULL AND expires_at < ?", [now()]);
        foreach ($rows as $j) {
            @unlink(storage_path('exports') . '/' . basename((string) $j['file_name']));
            Database::exec('UPDATE jobs SET file_name = NULL, status = ? WHERE id = ?', ['failed', $j['id']]);
            Database::exec("UPDATE jobs SET error = 'abgelaufen' WHERE id = ?", [$j['id']]);
            $n++;
        }
        $n += Database::exec("DELETE FROM jobs WHERE type = 'export' AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        return $n;
    }

    /** Alle Exportdateien einer Person entfernen (bei Kontolöschung). */
    public static function eraseForUser(int $userId): void
    {
        foreach (Database::all("SELECT file_name FROM jobs WHERE user_id = ? AND type = 'export' AND file_name IS NOT NULL", [$userId]) as $j) {
            @unlink(storage_path('exports') . '/' . basename((string) $j['file_name']));
        }
        Database::exec("DELETE FROM jobs WHERE user_id = ? AND type = 'export'", [$userId]);
    }
}
