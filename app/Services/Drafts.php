<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/**
 * Entwürfe, die zwischen den Geräten einer Person synchron bleiben.
 * Je Unterhaltung und Thread ein Entwurf. Leerer Text löscht den Entwurf. Entwürfe verfallen nach 30 Tagen.
 */
final class Drafts
{
    public const MAX_AGE_DAYS = 30;

    public static function save(int $userId, int $conversationId, int $threadRoot, string $body, ?int $replyTo, string $device): void
    {
        $member = Chat::requireMember($conversationId, $userId);
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        if (mb_strlen($body) > Messages::MAX_BODY) {
            throw new HttpException(422, 'Der Entwurf ist zu lang.');
        }
        $device = preg_match('/^[A-Za-z0-9]{6,12}$/', $device) ? $device : '';
        if (trim($body) === '') {
            self::clear($userId, $conversationId, $threadRoot, $device);
            return;
        }
        if ($threadRoot > 0) {
            $ok = Database::value('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ? AND thread_root_id IS NULL AND id > ?', [$threadRoot, $conversationId, (int) $member['visible_from_message_id']]);
            if (!$ok) {
                throw new HttpException(422, 'Der Thread existiert nicht.');
            }
        }
        if ($replyTo !== null && !Database::value('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ?', [$replyTo, $conversationId])) {
            $replyTo = null;
        }
        $old = Database::one('SELECT body, reply_to_id FROM drafts WHERE user_id = ? AND conversation_id = ? AND thread_root_id = ?', [$userId, $conversationId, $threadRoot]);
        if ($old && $old['body'] === $body && (int) ($old['reply_to_id'] ?? 0) === (int) ($replyTo ?? 0)) {
            return; // unverändert: kein Ereignis, damit das Journal nicht wächst
        }
        Database::exec(
            'INSERT INTO drafts (user_id, conversation_id, thread_root_id, body, reply_to_id, device, updated_at) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE body = VALUES(body), reply_to_id = VALUES(reply_to_id), device = VALUES(device), updated_at = VALUES(updated_at)',
            [$userId, $conversationId, $threadRoot, $body, $replyTo, $device, now()]
        );
        Events::push('draft', $conversationId, null, $userId);
    }

    /** Entwurf entfernen (nach dem Senden bzw. bei leerem Text). Meldet es den anderen Geräten. */
    public static function clear(int $userId, int $conversationId, int $threadRoot, string $device = ''): void
    {
        $n = Database::exec('DELETE FROM drafts WHERE user_id = ? AND conversation_id = ? AND thread_root_id = ?', [$userId, $conversationId, $threadRoot]);
        if ($n > 0) {
            Events::push('draft', $conversationId, null, $userId);
        }
    }

    /**
     * @param int[]|null $conversationIds
     * @return list<array{conversation_id:int,thread_root_id:int,body:string,reply_to_id:?int,device:string,updated_at:?string}>
     */
    public static function listFor(int $userId, ?array $conversationIds = null): array
    {
        $sql = 'SELECT d.* FROM drafts d JOIN conversation_members cm ON cm.conversation_id = d.conversation_id AND cm.user_id = d.user_id AND cm.left_at IS NULL WHERE d.user_id = ?';
        $params = [$userId];
        if ($conversationIds !== null) {
            if (!$conversationIds) {
                return [];
            }
            $sql .= ' AND d.conversation_id IN (' . Database::in($conversationIds) . ')';
            array_push($params, ...$conversationIds);
        }
        return array_map(static fn (array $d): array => [
            'conversation_id' => (int) $d['conversation_id'],
            'thread_root_id' => (int) $d['thread_root_id'],
            'body' => (string) $d['body'],
            'reply_to_id' => $d['reply_to_id'] !== null ? (int) $d['reply_to_id'] : null,
            'device' => (string) $d['device'],
            'updated_at' => iso($d['updated_at']),
        ], Database::all($sql . ' ORDER BY d.updated_at DESC LIMIT 200', $params));
    }

    public static function purgeOld(): int
    {
        return Database::exec('DELETE FROM drafts WHERE updated_at < ?', [gmdate('Y-m-d H:i:s', time() - self::MAX_AGE_DAYS * 86400)]);
    }
}
