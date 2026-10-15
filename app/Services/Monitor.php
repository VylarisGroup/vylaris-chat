<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Überwachung: zählt Fehlereinträge, warnt per E-Mail bei Häufung und liefert Zustandsprüfungen.
 * Der Zähler und die Alarm-Einstellungen liegen bewusst in einer Datei (storage/cache/monitor.json) und nicht in der
 * Datenbank, damit auch bei einem Datenbankausfall gezählt werden kann.
 */
final class Monitor
{
    private static bool $busy = false;

    private static function file(): string
    {
        return storage_path('cache/monitor.json');
    }

    /** Alarm-Einstellungen für den DB-losen Zugriff spiegeln. */
    public static function saveConfig(string $email, int $threshold, int $windowMin): void
    {
        $st = self::read();
        $st['email'] = $email;
        $st['threshold'] = max(1, $threshold);
        $st['window'] = max(1, $windowMin);
        self::write($st);
    }

    private static function read(): array
    {
        $j = is_file(self::file()) ? json_decode((string) @file_get_contents(self::file()), true) : null;
        return is_array($j) ? $j : [];
    }

    private static function write(array $st): void
    {
        @file_put_contents(self::file(), json_encode($st), LOCK_EX);
    }

    /** Wird von log_error() aufgerufen. Darf nie selbst einen Fehler auslösen. */
    public static function record(string $message): void
    {
        if (self::$busy) {
            return;
        }
        self::$busy = true;
        try {
            $st = self::read();
            $email = (string) ($st['email'] ?? '');
            $threshold = (int) ($st['threshold'] ?? 10);
            $window = (int) ($st['window'] ?? 10) * 60;
            $now = time();
            if (($st['start'] ?? 0) + $window < $now) {
                $st['start'] = $now;
                $st['count'] = 0;
                $st['samples'] = [];
            }
            $st['count'] = (int) ($st['count'] ?? 0) + 1;
            $st['samples'] = array_slice(array_merge($st['samples'] ?? [], [mb_substr($message, 0, 160)]), -5);
            $alert = $email !== '' && $st['count'] >= $threshold && ($st['last_alert'] ?? 0) < $now - 3600;
            if ($alert) {
                $st['last_alert'] = $now;
            }
            self::write($st);
            if ($alert) {
                $n = (int) $st['count'];
                $samples = implode("\n", $st['samples']);
                $host = parse_url(base_url(), PHP_URL_HOST) ?: 'Messenger';
                Mail::send(
                    $email,
                    '',
                    "[$host] $n Fehler in " . (int) ($window / 60) . ' Minuten',
                    'Fehlerhäufung',
                    '<p>Im Messenger sind ' . $n . ' Fehler innerhalb von ' . (int) ($window / 60) . ' Minuten aufgetreten.</p><pre style="white-space:pre-wrap">' . e($samples) . '</pre><p>Details: Administration &rarr; Betrieb.</p>',
                    "$n Fehler in " . (int) ($window / 60) . " Minuten.\n\n$samples\n"
                );
            }
        } catch (\Throwable) {
            // Überwachung darf die Anwendung nie stören
        } finally {
            self::$busy = false;
        }
    }

    /** @return array{last_hour:int,last_day:int,total_size:int,lines:string[]} */
    public static function errorStats(): array
    {
        $file = storage_path('logs/error.log');
        $out = ['last_hour' => 0, 'last_day' => 0, 'total_size' => is_file($file) ? (int) filesize($file) : 0, 'lines' => []];
        if (!is_file($file)) {
            return $out;
        }
        $fh = fopen($file, 'rb');
        $size = filesize($file);
        fseek($fh, max(0, $size - 262144));
        $chunk = (string) fread($fh, 262144);
        fclose($fh);
        $lines = array_filter(explode("\n", $chunk));
        foreach ($lines as $l) {
            if (preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d) UTC\]/', $l, $m)) {
                $t = strtotime($m[1] . ' UTC');
                $out['last_day'] += $t > time() - 86400 ? 1 : 0;
                $out['last_hour'] += $t > time() - 3600 ? 1 : 0;
            }
        }
        $out['lines'] = array_slice(array_values($lines), -15);
        return $out;
    }

    /**
     * Zustandsprüfungen. @return array<int,array{name:string,ok:bool,detail:string,level:string}>
     */
    public static function checks(): array
    {
        $c = [];
        $add = static function (string $name, bool $ok, string $detail, string $level = 'warn') use (&$c) {
            $c[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail, 'level' => $ok ? 'ok' : $level];
        };
        try {
            Database::value('SELECT 1');
            $add('Datenbank', true, 'erreichbar');
        } catch (\Throwable $e) {
            $add('Datenbank', false, 'nicht erreichbar', 'error');
            return $c;
        }
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free !== false && $total) {
            $pct = $free / $total * 100;
            $add('Speicherplatz', $pct > 10 && $free > 200 * 1048576, sprintf('%.0f %% frei (%s)', $pct, human_size((int) $free)), $pct < 3 ? 'error' : 'warn');
        }
        $add('Speicherordner beschreibbar', is_writable(storage_path()), is_writable(storage_path()) ? 'ja' : 'nein', 'error');
        $last = Settings::int('last_cleanup_at');
        $add('Wartung (Cron / automatisch)', $last > time() - 6 * 3600, $last > 0 ? 'zuletzt vor ' . (int) ((time() - $last) / 60) . ' Min.' : 'noch nie', 'warn');
        $failed = (int) Database::value("SELECT COUNT(*) FROM jobs WHERE status = 'failed' AND created_at > ?", [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        $add('Hintergrundaufträge', $failed === 0, $failed === 0 ? 'keine Fehler (7 Tage)' : "$failed fehlgeschlagen (7 Tage)", 'warn');
        $autoDays = Settings::int('backup_auto_days');
        $bk = Settings::int('backup_last_at');
        $add('Backup', $bk > time() - max(2, $autoDays ?: 7) * 86400, $bk > 0 ? 'zuletzt vor ' . (int) ((time() - $bk) / 3600) . ' Std.' : 'noch keins', 'warn');
        $st = self::errorStats();
        $add('Fehler-Log', $st['last_hour'] < 5, $st['last_hour'] . ' in der letzten Stunde, ' . $st['last_day'] . ' in 24 Stunden', $st['last_hour'] >= 20 ? 'error' : 'warn');
        $https = PHP_SAPI === 'cli' ? str_starts_with(base_url(), 'https://') : is_https();
        $add('HTTPS', $https, $https ? 'aktiv' : 'nicht aktiv – Push, Installation und sichere Cookies brauchen HTTPS', 'warn');
        return $c;
    }

    /** Log-Rotation: ab 2 MB wird error.log rotiert (3 Generationen). */
    public static function rotateLog(): void
    {
        $f = storage_path('logs/error.log');
        if (!is_file($f) || filesize($f) < 2 * 1048576) {
            return;
        }
        @unlink($f . '.3');
        foreach ([2, 1] as $i) {
            if (is_file($f . '.' . $i)) {
                @rename($f . '.' . $i, $f . '.' . ($i + 1));
            }
        }
        @rename($f, $f . '.1');
    }
}
