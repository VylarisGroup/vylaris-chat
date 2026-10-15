<?php
declare(strict_types=1);

namespace App\Models;

use App\Core\Database;

final class User
{
    public const ONLINE_WINDOW = 90;   // Sekunden seit letztem Poll
    public const AWAY_AFTER = 300;     // Sekunden ohne Aktivität

    public static function find(int $id): ?array
    {
        return Database::one('SELECT * FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
    }

    public static function findByEmail(string $email): ?array
    {
        return Database::one('SELECT * FROM users WHERE email = ? AND deleted_at IS NULL', [mb_strtolower(trim($email))]);
    }

    public static function findByUsername(string $username): ?array
    {
        return Database::one('SELECT * FROM users WHERE username = ? AND deleted_at IS NULL', [mb_strtolower($username)]);
    }

    public static function settings(int $userId): array
    {
        $row = Database::one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]);
        if ($row === null) {
            Database::exec('INSERT IGNORE INTO user_settings (user_id, updated_at) VALUES (?, ?)', [$userId, now()]);
            $row = Database::one('SELECT * FROM user_settings WHERE user_id = ?', [$userId]) ?? [];
        }
        unset($row['user_id'], $row['updated_at']);
        foreach ($row as $k => $v) {
            if (str_starts_with($k, 'notify_') || in_array($k, ['show_last_seen', 'show_read_receipts', 'enter_sends'], true)) {
                $row[$k] = (bool) $v;
            }
        }
        return $row;
    }

    /** Eindeutigen Benutzernamen (für @Erwähnungen) aus einer Vorlage ableiten. */
    public static function uniqueUsername(string $seed): string
    {
        $base = mb_strtolower($seed);
        $base = strtr($base, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $base = preg_replace('/[^a-z0-9._-]+/', '.', $base) ?? '';
        $base = trim($base, '._-');
        $base = substr($base, 0, 30);
        if (strlen($base) < 2) {
            $base = 'user';
        }
        $name = $base;
        $i = 1;
        while (Database::value('SELECT 1 FROM users WHERE username = ?', [$name])) {
            $i++;
            $name = substr($base, 0, 34) . $i;
        }
        return $name;
    }

    public static function create(string $email, string $displayName, string $passwordHash, string $role, bool $verified, ?string $username = null): int
    {
        $email = mb_strtolower(trim($email));
        $username = $username ?? self::uniqueUsername(explode('@', $email)[0]);
        $id = Database::insert(
            'INSERT INTO users (email, username, password_hash, display_name, role, email_verified_at, password_changed_at, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$email, $username, $passwordHash, $displayName, $role, $verified ? now() : null, now(), now(), now()]
        );
        Database::exec('INSERT INTO user_settings (user_id, updated_at) VALUES (?, ?)', [$id, now()]);
        return $id;
    }

    /** Anwesenheitsstatus aus Zeitstempeln ableiten: online, away, offline. */
    public static function presence(array $u): string
    {
        if (($u['presence_pref'] ?? 'auto') === 'invisible' || empty($u['last_seen_at'])) {
            return 'offline';
        }
        $seen = strtotime($u['last_seen_at'] . ' UTC');
        if (time() - $seen > self::ONLINE_WINDOW) {
            return 'offline';
        }
        if (($u['presence_pref'] ?? 'auto') === 'away') {
            return 'away';
        }
        $active = !empty($u['last_active_at']) ? strtotime($u['last_active_at'] . ' UTC') : 0;
        return (time() - $active > self::AWAY_AFTER) ? 'away' : 'online';
    }

    /**
     * Öffentliche Darstellung eines Benutzers für andere Benutzer.
     * Die Spalte show_last_seen aus user_settings muss per JOIN als "show_last_seen" vorliegen.
     */
    public static function publicView(array $u): array
    {
        $showSeen = !isset($u['show_last_seen']) || (int) $u['show_last_seen'] === 1;
        return [
            'id' => (int) $u['id'],
            'username' => $u['username'],
            'display_name' => $u['display_name'],
            'email' => $u['email'],
            'status_message' => $u['status_message'],
            'has_avatar' => !empty($u['avatar_file']),
            'avatar_v' => !empty($u['avatar_file']) ? substr(md5($u['avatar_file']), 0, 8) : null,
            'presence' => self::presence($u),
            'last_seen_at' => ($showSeen && ($u['presence_pref'] ?? 'auto') !== 'invisible') ? iso($u['last_seen_at'] ?? null) : null,
            'is_active' => (bool) $u['is_active'],
            'role' => $u['role'],
        ];
    }

    /** Selects für publicView. */
    public const PUBLIC_COLUMNS = 'u.id, u.username, u.display_name, u.email, u.status_message, u.avatar_file, u.presence_pref, u.last_seen_at, u.last_active_at, u.is_active, u.role, COALESCE(s.show_last_seen, 1) AS show_last_seen';

    public static function directory(string $q, int $limit = 30, ?int $excludeId = null): array
    {
        $params = [];
        $where = 'u.deleted_at IS NULL AND u.is_active = 1 AND u.processing_restricted_at IS NULL';
        if ($excludeId !== null) {
            $where .= ' AND u.id <> ?';
            $params[] = $excludeId;
        }
        if ($q !== '') {
            $like = '%' . self::escapeLike($q) . '%';
            $where .= ' AND (u.display_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $params[] = $limit;
        $rows = Database::all(
            'SELECT ' . self::PUBLIC_COLUMNS . ' FROM users u LEFT JOIN user_settings s ON s.user_id = u.id WHERE ' . $where . ' ORDER BY u.display_name ASC LIMIT ?',
            $params
        );
        return array_map([self::class, 'publicView'], $rows);
    }

    public static function escapeLike(string $s): string
    {
        return addcslashes($s, '\\%_');
    }
}
