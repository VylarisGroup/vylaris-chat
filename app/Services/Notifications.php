<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Deferred;
use App\Models\User;

/** In-App-Benachrichtigungen und E-Mail-Benachrichtigungen. Mails enthalten bewusst keinen Nachrichteninhalt. */
final class Notifications
{
    private const EMAIL_COOLDOWN = 900; // höchstens eine Mail pro Unterhaltung und 15 Minuten

    public static function create(int $userId, string $type, ?int $actorId, ?int $conversationId, ?int $messageId, string $text): int
    {
        return Database::insert(
            'INSERT INTO notifications (user_id, type, actor_id, conversation_id, message_id, text, created_at) VALUES (?,?,?,?,?,?,?)',
            [$userId, $type, $actorId, $conversationId, $messageId, mb_substr($text, 0, 255), now()]
        );
    }

    /** @param int[] $mentionedIds */
    public static function forNewMessage(array $msg, array $sender, array $mentionedIds): void
    {
        $convId = (int) $msg['conversation_id'];
        $conv = Chat::conversation($convId);
        if (!$conv) {
            return;
        }
        $isDirect = $conv['type'] === 'direct';
        $members = Database::all(
            'SELECT cm.id AS member_row, cm.user_id, cm.muted, cm.last_viewed_at, cm.last_emailed_at,
                    u.email, u.display_name, u.processing_restricted_at, u.last_seen_at, u.last_active_at, u.last_visible_at, u.presence_pref,
                    COALESCE(s.notify_email_direct, 1) AS notify_email_direct, COALESCE(s.notify_email_mentions, 1) AS notify_email_mentions
             FROM conversation_members cm
             JOIN users u ON u.id = cm.user_id
             LEFT JOIN user_settings s ON s.user_id = u.id
             WHERE cm.conversation_id = ? AND cm.left_at IS NULL AND cm.user_id <> ? AND u.is_active = 1',
            [$convId, (int) $sender['id']]
        );
        $emailEnabled = Settings::bool('email_notifications_enabled') && Mail::configured();
        $senderName = (string) $sender['display_name'];
        $convName = $isDirect ? $senderName : (string) $conv['name'];

        foreach ($members as $m) {
            $uid = (int) $m['user_id'];
            $mentioned = in_array($uid, $mentionedIds, true);
            $kind = null;
            $pushText = '';
            if ($mentioned) {
                $kind = 'mention';
                $pushText = $senderName . ' hat dich in „' . $convName . '“ erwähnt.';
                self::create($uid, 'mention', (int) $sender['id'], $convId, (int) $msg['id'], $pushText);
            } elseif ($isDirect && !(int) $m['muted']) {
                $kind = 'message';
                $pushText = 'Neue Nachricht von ' . $senderName;
                // Ungelesene Direktnachrichten pro Unterhaltung zu einer Benachrichtigung zusammenfassen
                $upd = Database::exec(
                    "UPDATE notifications SET message_id = ?, created_at = ?, text = ? WHERE user_id = ? AND conversation_id = ? AND type = 'message' AND read_at IS NULL",
                    [(int) $msg['id'], now(), 'Neue Nachricht von ' . $senderName, $uid, $convId]
                );
                if ($upd === 0) {
                    self::create($uid, 'message', (int) $sender['id'], $convId, (int) $msg['id'], 'Neue Nachricht von ' . $senderName);
                }
            }
            if ($kind !== null && $m['processing_restricted_at'] === null) {
                // Kein Push nur, solange die App sichtbar geöffnet ist (das meldet der Client mit jedem Poll bzw. beim Verbergen).
                // Ein Tab im Hintergrund oder eine geschlossene PWA zählt nicht als «online»: sonst bliebe der Push bis zu 90 Sekunden aus.
                $visible = !empty($m['last_visible_at']) && (time() - strtotime($m['last_visible_at'] . ' UTC')) < Push::VISIBLE_WINDOW;
                if (!$visible) {
                    Push::notify($uid, Push::payload($uid, $pushText, $convId, (int) $msg['id']));
                }
            }
            if ($kind === null || !$emailEnabled || $m['processing_restricted_at'] !== null) {
                continue;
            }
            if ($kind === 'mention' ? !(int) $m['notify_email_mentions'] : !(int) $m['notify_email_direct']) {
                continue;
            }
            if ($kind === 'message' && (int) $m['muted']) {
                continue;
            }
            // Keine Mail, wenn die Person gerade aktiv ist oder die Unterhaltung geöffnet hat
            $presence = User::presence(['presence_pref' => $m['presence_pref'], 'last_seen_at' => $m['last_seen_at'], 'last_active_at' => $m['last_active_at']]);
            $viewing = $m['last_viewed_at'] && (time() - strtotime($m['last_viewed_at'] . ' UTC')) < 120;
            if ($presence === 'online' || $viewing) {
                continue;
            }
            if ($m['last_emailed_at'] && (time() - strtotime($m['last_emailed_at'] . ' UTC')) < self::EMAIL_COOLDOWN) {
                continue;
            }
            Database::exec('UPDATE conversation_members SET last_emailed_at = ? WHERE id = ?', [now(), $m['member_row']]);
            $email = (string) $m['email'];
            $name = (string) $m['display_name'];
            $link = base_url() . '/?c=' . $convId . '&m=' . (int) $msg['id'];
            $subject = $kind === 'mention' ? "$senderName hat dich erwähnt" : "Neue Nachricht von $senderName";
            $intro = $kind === 'mention'
                ? e($senderName) . ' hat dich in <strong>' . e($convName) . '</strong> erwähnt.'
                : e($senderName) . ' hat dir eine Nachricht geschickt.';
            Deferred::add(static function () use ($email, $name, $subject, $intro, $link, $senderName, $convName, $kind) {
                Mail::send(
                    $email,
                    $name,
                    $subject,
                    $subject,
                    '<p>' . $intro . '</p><p>Aus Datenschutzgründen enthält diese E-Mail keinen Nachrichteninhalt.</p>' . Mail::button($link, 'Nachricht öffnen'),
                    ($kind === 'mention' ? "$senderName hat dich in $convName erwähnt." : "$senderName hat dir eine Nachricht geschickt.") . "\n\n$link\n"
                );
            });
        }
    }

    public static function listFor(int $userId, int $limit = 30): array
    {
        $rows = Database::all(
            'SELECT n.id, n.type, n.actor_id, n.conversation_id, n.message_id, n.text, n.read_at, n.created_at
             FROM notifications n WHERE n.user_id = ? ORDER BY n.id DESC LIMIT ?',
            [$userId, $limit]
        );
        return array_map(fn ($r) => [
            'id' => (int) $r['id'],
            'type' => $r['type'],
            'conversation_id' => $r['conversation_id'] ? (int) $r['conversation_id'] : null,
            'message_id' => $r['message_id'] ? (int) $r['message_id'] : null,
            'text' => $r['text'],
            'read' => $r['read_at'] !== null,
            'created_at' => iso($r['created_at']),
        ], $rows);
    }

    public static function unreadCount(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
    }

    /** @param int[]|null $ids null = alle */
    public static function markRead(int $userId, ?array $ids): void
    {
        if ($ids === null) {
            Database::exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL', [now(), $userId]);
            return;
        }
        if (!$ids) {
            return;
        }
        Database::exec(
            'UPDATE notifications SET read_at = ? WHERE user_id = ? AND read_at IS NULL AND id IN (' . Database::in($ids) . ')',
            array_merge([now(), $userId], $ids)
        );
    }
}
