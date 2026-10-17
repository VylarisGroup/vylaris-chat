<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\User;

/** Zentrale Suche. Nachrichten und Dateien werden ausschliesslich in Unterhaltungen durchsucht, in denen der Benutzer Mitglied ist. */
final class Search
{
    /**
     * @param array{type?:string,from_user?:int,from?:string,to?:string,conversation_id?:int} $filters
     */
    public static function run(int $userId, string $q, array $filters = []): array
    {
        $q = trim($q);
        $type = $filters['type'] ?? 'all';
        $fromUser = (int) ($filters['from_user'] ?? 0);
        $from = self::parseDate($filters['from'] ?? '');
        $to = self::parseDate($filters['to'] ?? '');
        $convFilter = (int) ($filters['conversation_id'] ?? 0);
        $hasFilter = $fromUser > 0 || $from !== null || $to !== null;

        $result = ['users' => [], 'conversations' => [], 'messages' => [], 'files' => []];
        if (mb_strlen($q) < 2 && !$hasFilter) {
            return $result;
        }

        if ($q !== '' && in_array($type, ['all', 'users'], true) && !$convFilter) {
            $result['users'] = User::directory($q, 8, $userId);
        }

        if ($q !== '' && in_array($type, ['all', 'conversations'], true) && !$convFilter) {
            foreach (Chat::listFor($userId) as $c) {
                if (mb_stripos((string) $c['name'], $q) !== false) {
                    $result['conversations'][] = $c;
                    if (count($result['conversations']) >= 10) {
                        break;
                    }
                }
            }
        }

        if (in_array($type, ['all', 'messages'], true)) {
            $where = "m.deleted_at IS NULL AND m.kind IN ('text','poll','event')";
            $params = [$userId];
            if ($q !== '') {
                $where .= ' AND m.body LIKE ?';
                $params[] = '%' . User::escapeLike($q) . '%';
            }
            if ($fromUser > 0) {
                $where .= ' AND m.sender_id = ?';
                $params[] = $fromUser;
            }
            if ($from !== null) {
                $where .= ' AND m.created_at >= ?';
                $params[] = $from;
            }
            if ($to !== null) {
                $where .= ' AND m.created_at < ?';
                $params[] = $to;
            }
            if ($convFilter > 0) {
                $where .= ' AND m.conversation_id = ?';
                $params[] = $convFilter;
            }
            $rows = Database::all(
                "SELECT m.id, m.conversation_id, m.sender_id, m.body, m.kind, m.thread_root_id, m.created_at, u.display_name
                 FROM messages m
                 JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL AND m.id > cm.visible_from_message_id
                 LEFT JOIN users u ON u.id = m.sender_id
                 WHERE $where ORDER BY m.id DESC LIMIT 30",
                $params
            );
            $convMap = self::conversationNames($userId, array_map(fn ($r) => (int) $r['conversation_id'], $rows));
            foreach ($rows as $r) {
                $cid = (int) $r['conversation_id'];
                $result['messages'][] = [
                    'id' => (int) $r['id'],
                    'conversation_id' => $cid,
                    'conversation_name' => $convMap[$cid]['name'] ?? 'Unterhaltung',
                    'conversation_type' => $convMap[$cid]['type'] ?? 'group',
                    'sender_id' => $r['sender_id'] ? (int) $r['sender_id'] : null,
                    'sender_name' => $r['display_name'] ?? 'Unbekannt',
                    'kind' => $r['kind'],
                    'thread_root_id' => $r['thread_root_id'] !== null ? (int) $r['thread_root_id'] : null,
                    'snippet' => self::snippet((string) $r['body'], $q),
                    'created_at' => iso($r['created_at']),
                ];
            }
        }

        if ($q !== '' && in_array($type, ['all', 'files'], true)) {
            $where = 'm.deleted_at IS NULL AND a.original_name LIKE ?';
            $params = [$userId, '%' . User::escapeLike($q) . '%'];
            if ($fromUser > 0) {
                $where .= ' AND m.sender_id = ?';
                $params[] = $fromUser;
            }
            if ($from !== null) {
                $where .= ' AND m.created_at >= ?';
                $params[] = $from;
            }
            if ($to !== null) {
                $where .= ' AND m.created_at < ?';
                $params[] = $to;
            }
            if ($convFilter > 0) {
                $where .= ' AND a.conversation_id = ?';
                $params[] = $convFilter;
            }
            $rows = Database::all(
                "SELECT a.*, m.id AS msg_id, m.created_at AS message_at, u.display_name
                 FROM message_attachments a
                 JOIN messages m ON m.id = a.message_id
                 JOIN conversation_members cm ON cm.conversation_id = a.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL AND m.id > cm.visible_from_message_id
                 LEFT JOIN users u ON u.id = m.sender_id
                 WHERE $where ORDER BY a.id DESC LIMIT 20",
                $params
            );
            $convMap = self::conversationNames($userId, array_map(fn ($r) => (int) $r['conversation_id'], $rows));
            foreach ($rows as $r) {
                $cid = (int) $r['conversation_id'];
                $result['files'][] = Messages::attachmentView($r) + [
                    'message_id' => (int) $r['msg_id'],
                    'conversation_id' => $cid,
                    'conversation_name' => $convMap[$cid]['name'] ?? 'Unterhaltung',
                    'sender_name' => $r['display_name'] ?? 'Unbekannt',
                    'message_at' => iso($r['message_at']),
                ];
            }
        }
        return $result;
    }

    private static function conversationNames(int $userId, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if (!$ids) {
            return [];
        }
        $map = [];
        foreach (Chat::listFor($userId, $ids) as $c) {
            $map[$c['id']] = ['name' => $c['name'], 'type' => $c['type']];
        }
        return $map;
    }

    private static function parseDate(string $s): ?string
    {
        $s = trim($s);
        if ($s === '') {
            return null;
        }
        $t = strtotime($s);
        return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    }

    /** Textausschnitt um die erste Fundstelle. */
    private static function snippet(string $body, string $q): string
    {
        $flat = trim(preg_replace('/\s+/u', ' ', $body) ?? '');
        if ($q === '' || ($pos = mb_stripos($flat, $q)) === false) {
            return mb_substr($flat, 0, 140);
        }
        $start = max(0, $pos - 50);
        $out = mb_substr($flat, $start, 160);
        return ($start > 0 ? '…' : '') . $out . (mb_strlen($flat) > $start + 160 ? '…' : '');
    }
}
