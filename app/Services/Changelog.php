<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Änderungsprotokoll aus docs/CHANGELOG.md (wird mit dem Code ausgeliefert und passt daher immer zur installierten Version).
 * Format: «## 1.5.0» oder «## 1.5.0 – Titel», darunter Stichpunkte mit «* » oder «- ».
 * Jede Administration sieht neue Einträge einmal, bis sie sie bestätigt.
 */
final class Changelog
{
    private const MAX_UNSEEN = 5;

    /** @return list<array{version:string,title:string,items:list<string>}> neueste zuerst */
    public static function entries(?string $file = null): array
    {
        $file ??= dirname(__DIR__, 2) . '/docs/CHANGELOG.md';
        $text = is_file($file) ? (string) file_get_contents($file) : '';
        $out = [];
        $cur = null;
        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^##\s+v?(\d+(?:\.\d+)*(?:[-+][0-9A-Za-z.-]+)?)\s*(?:[–—-]\s*(.+))?$/u', trim($line), $m)) {
                if ($cur !== null) {
                    $out[] = $cur;
                }
                $cur = ['version' => $m[1], 'title' => trim($m[2] ?? ''), 'items' => []];
            } elseif ($cur !== null && preg_match('/^\s*[*-]\s+(.+)$/u', $line, $m)) {
                $cur['items'][] = trim($m[1]);
            }
        }
        if ($cur !== null) {
            $out[] = $cur;
        }
        usort($out, static fn (array $a, array $b): int => version_compare($b['version'], $a['version']));
        return $out;
    }

    /** Einfache Darstellung: HTML maskiert, `code` und **fett** erlaubt. */
    public static function render(string $item): string
    {
        $h = e($item);
        $h = preg_replace('/`([^`]+)`/', '<code>$1</code>', $h) ?? $h;
        return preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $h) ?? $h;
    }

    public static function seenVersion(int $userId): ?string
    {
        $v = Database::value('SELECT version FROM changelog_seen WHERE user_id = ?', [$userId]);
        return $v === null || $v === false ? null : (string) $v;
    }

    /** Einträge bis zur installierten Version, die dieses Konto noch nicht bestätigt hat. Ohne Vermerk: nur der aktuelle Eintrag. */
    public static function unseen(int $userId): array
    {
        return self::select(self::entries(), APP_VERSION, self::seenVersion($userId));
    }

    /** @param list<array{version:string,title:string,items:list<string>}> $entries */
    public static function select(array $entries, string $current, ?string $seen): array
    {
        $out = [];
        foreach ($entries as $e) {
            if (version_compare($e['version'], $current, '>')) {
                continue;
            }
            if ($seen === null ? version_compare($e['version'], $current, '!=') : version_compare($e['version'], $seen, '<=')) {
                continue;
            }
            $out[] = $e;
        }
        return array_slice($out, 0, self::MAX_UNSEEN);
    }

    public static function markSeen(int $userId, ?string $version = null): void
    {
        Database::exec(
            'INSERT INTO changelog_seen (user_id, version, seen_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE version = VALUES(version), seen_at = VALUES(seen_at)',
            [$userId, $version ?? APP_VERSION, gmdate('Y-m-d H:i:s')]
        );
    }

    /** Für das Layout: nur Administrationskonten, und nur wenn die Tabelle schon existiert (Migration läuft beim ersten Aufruf). */
    public static function pending(int $userId): array
    {
        try {
            return self::unseen($userId);
        } catch (\Throwable) {
            return [];
        }
    }
}
