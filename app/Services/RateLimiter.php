<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Festes Zeitfenster pro (bucket, identifier). Identifier wird gehasht gespeichert. */
final class RateLimiter
{
    private static function id(string $identifier): string
    {
        return hash('sha256', mb_strtolower($identifier));
    }

    /** Zählt einen Treffer und liefert true, wenn das Limit noch nicht überschritten ist. */
    public static function hit(string $bucket, string $identifier, int $max, int $windowSeconds): bool
    {
        $id = self::id($identifier);
        $now = now();
        return (bool) Database::transaction(function () use ($bucket, $id, $max, $windowSeconds, $now) {
            $row = Database::one('SELECT hits, window_start FROM rate_limits WHERE bucket = ? AND identifier = ? FOR UPDATE', [$bucket, $id]);
            if ($row === null) {
                Database::exec('INSERT INTO rate_limits (bucket, identifier, hits, window_start) VALUES (?, ?, 1, ?) ON DUPLICATE KEY UPDATE hits = hits + 1', [$bucket, $id, $now]);
                return 1 <= $max;
            }
            if (strtotime($row['window_start'] . ' UTC') + $windowSeconds <= time()) {
                Database::exec('UPDATE rate_limits SET hits = 1, window_start = ? WHERE bucket = ? AND identifier = ?', [$now, $bucket, $id]);
                return true;
            }
            Database::exec('UPDATE rate_limits SET hits = hits + 1 WHERE bucket = ? AND identifier = ?', [$bucket, $id]);
            return ((int) $row['hits'] + 1) <= $max;
        });
    }

    /** Prüft ohne zu zählen, ob das Limit bereits erreicht ist. */
    public static function blocked(string $bucket, string $identifier, int $max, int $windowSeconds): bool
    {
        $row = Database::one('SELECT hits, window_start FROM rate_limits WHERE bucket = ? AND identifier = ?', [$bucket, self::id($identifier)]);
        if ($row === null) {
            return false;
        }
        if (strtotime($row['window_start'] . ' UTC') + $windowSeconds <= time()) {
            return false;
        }
        return (int) $row['hits'] >= $max;
    }

    /** Sekunden bis das Fenster endet. */
    public static function retryAfter(string $bucket, string $identifier, int $windowSeconds): int
    {
        $row = Database::one('SELECT window_start FROM rate_limits WHERE bucket = ? AND identifier = ?', [$bucket, self::id($identifier)]);
        if ($row === null) {
            return 0;
        }
        return max(0, strtotime($row['window_start'] . ' UTC') + $windowSeconds - time());
    }

    public static function clear(string $bucket, string $identifier): void
    {
        Database::exec('DELETE FROM rate_limits WHERE bucket = ? AND identifier = ?', [$bucket, self::id($identifier)]);
    }

    public static function purgeOld(): void
    {
        Database::exec('DELETE FROM rate_limits WHERE window_start < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    }
}
