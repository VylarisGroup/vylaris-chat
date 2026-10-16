<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Deferred;
use App\Core\Request;
use App\Core\Response;
use App\Services\Chat;
use App\Services\Events;
use App\Services\Messages;
use App\Services\Notifications;
use App\Services\Retention;
use App\Services\Settings;

/**
 * Near-Realtime per kurzem Polling: liefert alle Ereignisse seit dem Cursor samt den betroffenen Nachrichten und Unterhaltungen.
 * Läuft auf jedem klassischen Webhosting (kein WebSocket nötig, keine dauerhaft belegten PHP-Prozesse).
 */
final class PollController extends Controller
{
    private const LIMIT = 200;

    /**
     * Optionaler Echtzeitmodus (Server-Sent Events): hält die Verbindung höchstens ~25 s offen und sendet ein "wake",
     * sobald für diese Person neue Ereignisse vorliegen. Der Client holt die Daten dann über /api/poll ab. So bleibt die
     * gesamte Berechtigungslogik an einer Stelle. Achtung: Jede offene Verbindung belegt einen PHP-Prozess.
     */
    public function stream(Request $r): Response
    {
        if (Settings::get('realtime_mode') !== 'sse' || !\App\Services\License::has('realtime')) {
            throw new \App\Core\HttpException(404, 'Echtzeitmodus ist nicht aktiviert.');
        }
        $user = $this->user();
        $uid = (int) $user['id'];
        $cursor = max(0, (int) $r->query('cursor', 0));
        $view = (int) $r->query('view', 0);

        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        @ini_set('zlib.output_compression', '0');
        @set_time_limit(40);
        ignore_user_abort(false);
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');
        echo "retry: 3000\n\n";
        @flush();

        $typingSig = $view > 0 && Chat::membership($view, $uid) ? implode(',', Messages::typingNames($view, $uid)) : '';
        $deadline = time() + 25;
        $lastPing = time();
        $reason = 'bye';
        while (time() < $deadline) {
            if (connection_aborted()) {
                exit;
            }
            if (Events::since($uid, $cursor, 1)) {
                $reason = 'wake';
                break;
            }
            if ($view > 0) {
                $sig = Chat::membership($view, $uid) ? implode(',', Messages::typingNames($view, $uid)) : '';
                if ($sig !== $typingSig) {
                    $reason = 'wake';
                    break;
                }
            }
            if (time() - $lastPing >= 10) {
                echo ": ping\n\n";
                @flush();
                $lastPing = time();
            }
            usleep(1000000);
        }
        echo "event: $reason\ndata: {}\n\n";
        @flush();
        exit;
    }

    public function poll(Request $r): Response
    {
        $user = $this->user();
        $uid = (int) $user['id'];
        $cursor = max(0, (int) $r->query('cursor', 0));
        $view = (int) $r->query('view', 0);
        $active = (bool) $r->query('active', 1);
        $visible = (bool) $r->query('visible', 0);

        // Anwesenheit (höchstens alle 20 Sekunden schreiben)
        $seen = $user['last_seen_at'] ? strtotime($user['last_seen_at'] . ' UTC') : 0;
        $act = $user['last_active_at'] ? strtotime($user['last_active_at'] . ' UTC') : 0;
        if (time() - $seen >= 20 || ($active && time() - $act >= 20)) {
            Database::exec(
                $active ? 'UPDATE users SET last_seen_at = ?, last_active_at = ? WHERE id = ?' : 'UPDATE users SET last_seen_at = ? WHERE id = ?',
                $active ? [now(), now(), $uid] : [now(), $uid]
            );
        }

        // Sichtbarkeit der App (für Push): nur wenn der Tab/die PWA im Vordergrund ist, höchstens alle 10 Sekunden schreiben
        if ($visible) {
            $vis = !empty($user['last_visible_at']) ? strtotime($user['last_visible_at'] . ' UTC') : 0;
            if (time() - $vis >= 10) {
                Database::exec('UPDATE users SET last_visible_at = ? WHERE id = ?', [now(), $uid]);
            }
        }

        $events = Events::since($uid, $cursor, self::LIMIT + 1);
        $reset = false;
        if (count($events) > self::LIMIT) {
            $reset = true;
            $events = [];
            $cursor = Events::latestId();
        }

        $messageIds = [];
        $convIds = [];
        $removed = [];
        $receiptUpTo = [];
        $newByConv = [];
        $newCursor = $cursor;
        foreach ($events as $e) {
            $newCursor = max($newCursor, (int) $e['id']);
            $cid = $e['conversation_id'] ? (int) $e['conversation_id'] : null;
            $mid = $e['message_id'] ? (int) $e['message_id'] : null;
            switch ($e['type']) {
                case 'message.new':
                    $messageIds[$mid] = true;
                    $convIds[$cid] = true;
                    $newByConv[$cid] = max($newByConv[$cid] ?? 0, $mid);
                    break;
                case 'message.edit':
                case 'message.delete':
                case 'message.reaction':
                    $messageIds[$mid] = true;
                    $convIds[$cid] = true;
                    break;
                case 'receipt':
                    $receiptUpTo[$cid] = max($receiptUpTo[$cid] ?? 0, $mid);
                    break;
                case 'conversation.removed':
                    if ((int) $e['target_user_id'] === $uid) {
                        $removed[$cid] = true;
                        unset($convIds[$cid]);
                    }
                    break;
                default: // conversation.added, conversation.updated, conversation.prefs
                    $convIds[$cid] = true;
            }
        }
        foreach (array_keys($removed) as $cid) {
            unset($convIds[$cid]);
        }

        // Als zugestellt markieren, was neu eintrifft
        foreach ($newByConv as $cid => $maxId) {
            if (!isset($removed[$cid]) && Chat::membership($cid, $uid)) {
                Messages::markDelivered($cid, $uid, $maxId);
            }
        }

        $messages = [];
        if ($messageIds) {
            $ids = array_keys($messageIds);
            $rows = Database::all(
                'SELECT m.* FROM messages m
                 JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL AND m.id > cm.visible_from_message_id
                 WHERE m.id IN (' . Database::in($ids) . ')',
                array_merge([$uid], $ids)
            );
            foreach (Messages::hydrate($rows, $uid) as $m) {
                $messages[$m['id']] = $m;
            }
        }
        // Eigene Nachrichten, deren Lesestatus sich geändert hat
        foreach ($receiptUpTo as $cid => $upTo) {
            if (!Chat::membership($cid, $uid)) {
                continue;
            }
            $rows = Database::all(
                "SELECT * FROM messages WHERE conversation_id = ? AND sender_id = ? AND id <= ? AND kind = 'text' AND deleted_at IS NULL ORDER BY id DESC LIMIT 30",
                [$cid, $uid, $upTo]
            );
            foreach (Messages::hydrate($rows, $uid) as $m) {
                $messages[$m['id']] ??= $m;
            }
        }

        $conversations = $convIds ? Chat::listFor($uid, array_keys($convIds)) : [];
        $typing = [];
        if ($view > 0 && Chat::membership($view, $uid)) {
            $typing = Messages::typingNames($view, $uid);
        }

        if (random_int(1, 40) === 1) {
            Deferred::add(static fn () => Retention::maybeRun());
        }

        return $this->json([
            'cursor' => $reset ? $cursor : $newCursor,
            'reset' => $reset,
            'events' => array_map(fn ($e) => ['type' => $e['type'], 'conversation_id' => $e['conversation_id'] ? (int) $e['conversation_id'] : null, 'message_id' => $e['message_id'] ? (int) $e['message_id'] : null], $events),
            'messages' => array_values($messages),
            'conversations' => $conversations,
            'removed' => array_map('intval', array_keys($removed)),
            'typing' => ['conversation_id' => $view, 'names' => $typing],
            'notifications_unread' => Notifications::unreadCount($uid),
            'server_time' => iso(now()),
        ]);
    }
}
