<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Ereignis-Journal für Near-Realtime-Updates per Polling.
 * Clients fragen "alle Ereignisse seit Cursor" ab; Sichtbarkeit wird über die Mitgliedschaft bestimmt.
 */
final class Events
{
    public static function push(string $type, ?int $conversationId, ?int $messageId = null, ?int $targetUserId = null): int
    {
        return Database::insert(
            'INSERT INTO events (type, conversation_id, message_id, target_user_id, created_at) VALUES (?,?,?,?,?)',
            [$type, $conversationId, $messageId, $targetUserId, now()]
        );
    }

    public static function latestId(): int
    {
        return (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM events');
    }

    /** @return array<int,array<string,mixed>> */
    public static function since(int $userId, int $cursor, int $limit = 200): array
    {
        return Database::all(
            'SELECT e.id, e.type, e.conversation_id, e.message_id, e.target_user_id
             FROM events e
             WHERE e.id > ? AND (
                 e.target_user_id = ?
                 OR (e.target_user_id IS NULL AND e.conversation_id IN (
                     SELECT cm.conversation_id FROM conversation_members cm WHERE cm.user_id = ? AND cm.left_at IS NULL))
             )
             ORDER BY e.id ASC LIMIT ?',
            [$cursor, $userId, $userId, $limit]
        );
    }
}
