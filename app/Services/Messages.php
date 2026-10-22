<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\User;

/** Nachrichten: Senden, Bearbeiten, Löschen, Reaktionen, Weiterleiten, Lesestatus. */
final class Messages
{
    public const MAX_BODY = 8000;
    /** Erlaubte Reaktionen (feste Liste verhindert missbräuchliche oder defekte Zeichenfolgen). */
    public const REACTIONS = ['👍', '👎', '❤️', '😂', '😮', '😢', '🎉', '🙏', '✅', '👀', '🔥', '💯', '👏', '🙌', '🤝', '😍', '🤔', '😅', '🚀', '💡', '⭐', '❌', '⚠️', '👋'];
    /** Nachrichtenarten, die Inhalt von Personen enthalten (alles ausser Systemmeldungen). */
    public const USER_KINDS = ['text', 'poll', 'event'];

    // ---------------------------------------------------------------- Hilfen

    public static function attachmentView(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'name' => $a['original_name'],
            'mime' => $a['mime_type'],
            'size' => (int) $a['size_bytes'],
            'is_image' => (bool) $a['is_image'],
            'voice' => !empty($a['is_voice']),
            'duration_ms' => isset($a['duration_ms']) ? (int) $a['duration_ms'] : null,
            'width' => $a['width'] !== null ? (int) $a['width'] : null,
            'height' => $a['height'] !== null ? (int) $a['height'] : null,
            'created_at' => iso($a['created_at'] ?? null),
        ];
    }

    /** Systemnachricht (Beitritt, Austritt, Umbenennung ...). */
    public static function system(int $conversationId, string $text): int
    {
        $id = Database::insert(
            "INSERT INTO messages (conversation_id, sender_id, kind, body, created_at) VALUES (?, NULL, 'system', ?, ?)",
            [$conversationId, mb_substr($text, 0, 500), now()]
        );
        Database::exec('UPDATE conversations SET last_message_id = ?, last_message_at = ?, updated_at = ? WHERE id = ?', [$id, now(), now(), $conversationId]);
        Events::push('message.new', $conversationId, $id);
        return $id;
    }

    /** @return array<string,array{id:int,username:string}> */
    private static function resolveMentions(string $body, int $conversationId, int $senderId): array
    {
        if (!preg_match_all('/(?<![\w@])@([A-Za-z0-9][A-Za-z0-9._-]{1,39})/u', $body, $m)) {
            return [];
        }
        $names = array_values(array_unique(array_map('mb_strtolower', $m[1])));
        $names = array_slice($names, 0, 25);
        $rows = Database::all(
            'SELECT u.id, u.username FROM conversation_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.conversation_id = ? AND cm.left_at IS NULL AND u.is_active = 1 AND u.id <> ? AND u.username IN (' . Database::in($names) . ')',
            array_merge([$conversationId, $senderId], $names)
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['username']] = ['id' => (int) $r['id'], 'username' => $r['username']];
        }
        return $out;
    }

    private static function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = trim($body);
        // Mehr als zwei Leerzeilen hintereinander zusammenfassen
        return preg_replace("/\n{4,}/", "\n\n\n", $body) ?? $body;
    }

    // -------------------------------------------------------------- Hydrierung

    /**
     * Wandelt Nachrichtenzeilen in API-Objekte um (Absender, Reaktionen, Anhänge, Erwähnungen, Antwort, Lesestatus).
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    public static function hydrate(array $rows, int $viewerId): array
    {
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);
        $in = Database::in($ids);

        $senderIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['sender_id'] ? (int) $r['sender_id'] : null, $rows))));
        $senders = [];
        if ($senderIds) {
            foreach (Database::all(
                'SELECT ' . User::PUBLIC_COLUMNS . ' FROM users u LEFT JOIN user_settings s ON s.user_id = u.id WHERE u.id IN (' . Database::in($senderIds) . ')',
                $senderIds
            ) as $u) {
                $v = User::publicView($u);
                $senders[$v['id']] = ['id' => $v['id'], 'display_name' => $v['display_name'], 'username' => $v['username'], 'has_avatar' => $v['has_avatar'], 'avatar_v' => $v['avatar_v'], 'bot' => $v['bot']];
            }
        }

        $reactions = [];
        foreach (Database::all(
            "SELECT r.message_id, r.emoji, r.user_id, u.display_name FROM message_reactions r JOIN users u ON u.id = r.user_id
             WHERE r.message_id IN ($in) ORDER BY r.created_at ASC",
            $ids
        ) as $r) {
            $reactions[(int) $r['message_id']][$r['emoji']][] = ['id' => (int) $r['user_id'], 'name' => $r['display_name']];
        }

        $attachments = [];
        foreach (Database::all("SELECT * FROM message_attachments WHERE message_id IN ($in) ORDER BY id ASC", $ids) as $a) {
            $attachments[(int) $a['message_id']][] = self::attachmentView($a);
        }

        $mentions = [];
        foreach (Database::all(
            "SELECT mm.message_id, u.id, u.username, u.display_name FROM message_mentions mm JOIN users u ON u.id = mm.user_id WHERE mm.message_id IN ($in)",
            $ids
        ) as $m) {
            $mentions[(int) $m['message_id']][] = ['id' => (int) $m['id'], 'username' => $m['username'], 'display_name' => $m['display_name']];
        }

        $replyIds = array_values(array_unique(array_filter(array_map(fn ($r) => $r['reply_to_id'] ? (int) $r['reply_to_id'] : null, $rows))));
        $replies = [];
        if ($replyIds) {
            foreach (Database::all(
                'SELECT m.id, m.body, m.deleted_at, u.display_name,
                        (SELECT COUNT(*) FROM message_attachments a WHERE a.message_id = m.id) AS att,
                        (SELECT COUNT(*) FROM message_attachments a WHERE a.message_id = m.id AND a.is_voice = 1) AS voice
                 FROM messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.id IN (' . Database::in($replyIds) . ')',
                $replyIds
            ) as $r) {
                $text = $r['deleted_at'] !== null ? '' : mb_substr(preg_replace('/\s+/u', ' ', (string) $r['body']) ?? '', 0, 140);
                if ($text === '' && $r['deleted_at'] === null && (int) $r['att'] > 0) {
                    $text = (int) $r['voice'] > 0 ? 'Sprachnachricht' : 'Anhang';
                }
                $replies[(int) $r['id']] = ['id' => (int) $r['id'], 'sender_name' => $r['display_name'] ?? 'Unbekannt', 'text' => $text, 'deleted' => $r['deleted_at'] !== null];
            }
        }

        // Threads: Zusammenfassung für Nachrichten, die Antworten haben
        $threads = [];
        $rootIds = array_values(array_map(fn ($r) => (int) $r['id'], array_filter($rows, fn ($r) => empty($r['thread_root_id']) && $r['deleted_at'] === null && $r['kind'] !== 'system')));
        if ($rootIds) {
            foreach (Database::all(
                'SELECT thread_root_id, COUNT(*) AS c, MAX(id) AS last_id, MAX(created_at) AS last_at FROM messages
                 WHERE thread_root_id IN (' . Database::in($rootIds) . ') AND deleted_at IS NULL GROUP BY thread_root_id',
                $rootIds
            ) as $t) {
                $threads[(int) $t['thread_root_id']] = ['count' => (int) $t['c'], 'last_id' => (int) $t['last_id'], 'last_at' => iso($t['last_at']), 'participants' => []];
            }
            if ($threads) {
                $tids = array_keys($threads);
                $seen = [];
                foreach (Database::all(
                    'SELECT m.thread_root_id, u.id, u.display_name, u.username, u.avatar_file FROM messages m JOIN users u ON u.id = m.sender_id
                     WHERE m.thread_root_id IN (' . Database::in($tids) . ') AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 400',
                    $tids
                ) as $p) {
                    $rid = (int) $p['thread_root_id'];
                    if (isset($seen[$rid][(int) $p['id']]) || count($threads[$rid]['participants']) >= 4) {
                        continue;
                    }
                    $seen[$rid][(int) $p['id']] = true;
                    $threads[$rid]['participants'][] = ['id' => (int) $p['id'], 'display_name' => $p['display_name'], 'username' => $p['username'], 'has_avatar' => !empty($p['avatar_file']), 'avatar_v' => !empty($p['avatar_file']) ? substr(md5($p['avatar_file']), 0, 8) : null];
                }
            }
        }

        // Umfragen und Termine
        $pollViews = Polls::views(array_values(array_filter($rows, fn ($r) => $r['kind'] === 'poll' && $r['deleted_at'] === null)), $viewerId);
        $eventViews = Calendar::views(array_values(array_filter($rows, fn ($r) => $r['kind'] === 'event' && $r['deleted_at'] === null)), $viewerId);
        $meetingViews = Meets::views(array_values(array_filter($rows, fn ($r) => $r['kind'] === 'meeting' && $r['deleted_at'] === null)), $viewerId);

        // Lesestatus nur für eigene Nachrichten
        $ownIds = array_values(array_map(fn ($r) => (int) $r['id'], array_filter($rows, fn ($r) => (int) $r['sender_id'] === $viewerId && $r['kind'] !== 'system' && $r['deleted_at'] === null)));
        $receipts = [];
        $recipientCounts = [];
        if ($ownIds) {
            foreach (Database::all(
                'SELECT r.message_id, SUM(r.delivered_at IS NOT NULL) AS d, SUM(r.read_at IS NOT NULL) AS rd
                 FROM message_receipts r
                 JOIN messages m ON m.id = r.message_id
                 JOIN conversation_members cm ON cm.conversation_id = m.conversation_id AND cm.user_id = r.user_id AND cm.left_at IS NULL
                 WHERE r.message_id IN (' . Database::in($ownIds) . ') GROUP BY r.message_id',
                $ownIds
            ) as $r) {
                $receipts[(int) $r['message_id']] = ['d' => (int) $r['d'], 'rd' => (int) $r['rd']];
            }
            $convIds = array_values(array_unique(array_map(fn ($r) => (int) $r['conversation_id'], $rows)));
            foreach (Database::all(
                'SELECT conversation_id, COUNT(*) AS c FROM conversation_members WHERE left_at IS NULL AND user_id <> ? AND conversation_id IN (' . Database::in($convIds) . ') GROUP BY conversation_id',
                array_merge([$viewerId], $convIds)
            ) as $c) {
                $recipientCounts[(int) $c['conversation_id']] = (int) $c['c'];
            }
        }

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $deleted = $r['deleted_at'] !== null;
            $own = (int) $r['sender_id'] === $viewerId;
            $reactionList = [];
            if (!$deleted) {
                foreach ($reactions[$id] ?? [] as $emoji => $users) {
                    $reactionList[] = [
                        'emoji' => $emoji,
                        'count' => count($users),
                        'mine' => in_array($viewerId, array_column($users, 'id'), true),
                        'names' => array_slice(array_column($users, 'name'), 0, 8),
                    ];
                }
            }
            $status = null;
            $readCount = 0;
            $total = 0;
            if ($own && $r['kind'] !== 'system' && !$deleted) {
                $total = $recipientCounts[(int) $r['conversation_id']] ?? 0;
                $rc = $receipts[$id] ?? ['d' => 0, 'rd' => 0];
                $readCount = $rc['rd'];
                if ($total > 0 && $rc['rd'] >= $total) {
                    $status = 'read';
                } elseif ($total > 0 && $rc['d'] >= $total) {
                    $status = 'delivered';
                } else {
                    $status = 'sent';
                }
            }
            $out[] = [
                'id' => $id,
                'conversation_id' => (int) $r['conversation_id'],
                'kind' => $r['kind'],
                'sender' => $r['sender_id'] ? ($senders[(int) $r['sender_id']] ?? null) : null,
                'body' => $deleted ? '' : (string) $r['body'],
                'deleted' => $deleted,
                'edited_at' => iso($r['edited_at']),
                'created_at' => iso($r['created_at']),
                'forwarded' => (bool) $r['forwarded'],
                'thread_root_id' => !empty($r['thread_root_id']) ? (int) $r['thread_root_id'] : null,
                'thread' => $threads[$id] ?? null,
                'poll' => $deleted ? null : ($pollViews[$id] ?? null),
                'event' => $deleted ? null : ($eventViews[$id] ?? null),
                'meeting' => $deleted ? null : ($meetingViews[$id] ?? null),
                'reply_to' => $r['reply_to_id'] ? ($replies[(int) $r['reply_to_id']] ?? ['id' => (int) $r['reply_to_id'], 'sender_name' => 'Unbekannt', 'text' => '', 'deleted' => true]) : null,
                'reactions' => $reactionList,
                'attachments' => $deleted ? [] : ($attachments[$id] ?? []),
                'mentions' => $deleted ? [] : ($mentions[$id] ?? []),
                'status' => $status,
                'read_count' => $readCount,
                'recipient_count' => $total,
                'client_uid' => $own ? $r['client_uid'] : null,
            ];
        }
        return $out;
    }

    public static function find(int $messageId): ?array
    {
        return Database::one('SELECT * FROM messages WHERE id = ?', [$messageId]);
    }

    /** Lädt eine Nachricht und prüft die Mitgliedschaft des Benutzers (sonst 404). */
    public static function findForUser(int $messageId, int $userId): array
    {
        $msg = self::find($messageId);
        if (!$msg) {
            throw new HttpException(404, 'Nachricht nicht gefunden.');
        }
        $member = Chat::membership((int) $msg['conversation_id'], $userId);
        if (!$member || $messageId <= (int) $member['visible_from_message_id']) {
            throw new HttpException(404, 'Nachricht nicht gefunden.');
        }
        return ['message' => $msg, 'member' => $member];
    }

    // ---------------------------------------------------------------- Abrufen

    /**
     * Nachrichten einer Unterhaltung, cursorbasiert.
     * @return array{messages:array,has_more_before:bool,has_more_after:bool}
     */
    public static function fetch(int $conversationId, int $userId, ?int $before, ?int $after, ?int $around, int $limit = 40): array
    {
        $member = Chat::requireMember($conversationId, $userId);
        $min = (int) $member['visible_from_message_id'];
        $limit = max(5, min(100, $limit));
        $hasBefore = false;
        $hasAfter = false;

        if ($around !== null) {
            $older = Database::all('SELECT * FROM messages WHERE conversation_id = ? AND thread_root_id IS NULL AND id > ? AND id <= ? ORDER BY id DESC LIMIT 26', [$conversationId, $min, $around]);
            $newer = Database::all('SELECT * FROM messages WHERE conversation_id = ? AND thread_root_id IS NULL AND id > ? ORDER BY id ASC LIMIT 26', [$conversationId, max($around, $min)]);
            $hasBefore = count($older) > 25;
            $hasAfter = count($newer) > 25;
            $rows = array_merge(array_reverse(array_slice($older, 0, 25)), array_slice($newer, 0, 25));
        } elseif ($after !== null) {
            $rows = Database::all('SELECT * FROM messages WHERE conversation_id = ? AND thread_root_id IS NULL AND id > ? ORDER BY id ASC LIMIT ' . ($limit + 1), [$conversationId, max($after, $min)]);
            $hasAfter = count($rows) > $limit;
            $rows = array_slice($rows, 0, $limit);
        } elseif ($before !== null) {
            $rows = Database::all('SELECT * FROM messages WHERE conversation_id = ? AND thread_root_id IS NULL AND id > ? AND id < ? ORDER BY id DESC LIMIT ' . ($limit + 1), [$conversationId, $min, $before]);
            $hasBefore = count($rows) > $limit;
            $rows = array_reverse(array_slice($rows, 0, $limit));
            $hasAfter = true;
        } else {
            $rows = Database::all('SELECT * FROM messages WHERE conversation_id = ? AND thread_root_id IS NULL AND id > ? ORDER BY id DESC LIMIT ' . ($limit + 1), [$conversationId, $min]);
            $hasBefore = count($rows) > $limit;
            $rows = array_reverse(array_slice($rows, 0, $limit));
        }

        if ($rows) {
            $maxId = (int) end($rows)['id'];
            self::markDelivered($conversationId, $userId, $maxId, $member);
        }
        Database::exec('UPDATE conversation_members SET last_viewed_at = ? WHERE id = ?', [now(), $member['id']]);

        return ['messages' => self::hydrate($rows, $userId), 'has_more_before' => $hasBefore, 'has_more_after' => $hasAfter];
    }

    // --------------------------------------------------------------- Senden

    /**
     * @param int[] $attachmentIds
     * @return array{message:array,duplicate:bool}
     */
    public static function send(array $user, int $conversationId, string $body, ?string $clientUid, ?int $replyTo, array $attachmentIds, ?int $threadRoot = null): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        $member = Chat::requireMember($conversationId, $uid);
        $body = self::normalizeBody($body);
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new HttpException(422, 'Die Nachricht ist zu lang (maximal ' . self::MAX_BODY . ' Zeichen).');
        }
        if ($body === '' && !$attachmentIds) {
            throw new HttpException(422, 'Die Nachricht ist leer.');
        }
        if ($clientUid !== null && !preg_match('/^[A-Za-z0-9-]{8,36}$/', $clientUid)) {
            $clientUid = null;
        }
        if ($clientUid !== null) {
            $existing = Database::one('SELECT * FROM messages WHERE sender_id = ? AND client_uid = ?', [$uid, $clientUid]);
            if ($existing) {
                return ['message' => self::hydrate([$existing], $uid)[0], 'duplicate' => true];
            }
        }
        if (!RateLimiter::hit('msg_send', (string) $uid, 60, 60)) {
            throw new HttpException(429, 'Du sendest zu schnell. Bitte warte einen Moment.', ['retry_after' => RateLimiter::retryAfter('msg_send', (string) $uid, 60)]);
        }
        if ($replyTo !== null) {
            $ok = Database::value('SELECT 1 FROM messages WHERE id = ? AND conversation_id = ? AND id > ?', [$replyTo, $conversationId, (int) $member['visible_from_message_id']]);
            if (!$ok) {
                throw new HttpException(422, 'Die Nachricht, auf die du antwortest, existiert nicht.');
            }
        }
        if ($threadRoot !== null) {
            $root = Database::one('SELECT id, kind, deleted_at, thread_root_id FROM messages WHERE id = ? AND conversation_id = ? AND id > ?', [$threadRoot, $conversationId, (int) $member['visible_from_message_id']]);
            if (!$root || $root['kind'] === 'system' || $root['deleted_at'] !== null) {
                throw new HttpException(422, 'Der Thread existiert nicht mehr.');
            }
            if ($root['thread_root_id'] !== null) {
                $threadRoot = (int) $root['thread_root_id']; // Antwort auf eine Thread-Antwort gehört zum selben Thread
            }
        }
        $attRows = [];
        if ($attachmentIds) {
            if (count($attachmentIds) > 10) {
                throw new HttpException(422, 'Maximal 10 Anhänge pro Nachricht.');
            }
            $attRows = Database::all(
                'SELECT id FROM message_attachments WHERE id IN (' . Database::in($attachmentIds) . ') AND uploader_id = ? AND conversation_id = ? AND message_id IS NULL',
                array_merge($attachmentIds, [$uid, $conversationId])
            );
            if (count($attRows) !== count($attachmentIds)) {
                throw new HttpException(422, 'Ein Anhang ist ungültig oder wurde bereits verwendet.');
            }
        }

        try {
            $row = self::create($user, $conversationId, $body, $clientUid, $replyTo, false, function (int $messageId) use ($attachmentIds) {
                if ($attachmentIds) {
                    Database::exec(
                        'UPDATE message_attachments SET message_id = ? WHERE id IN (' . Database::in($attachmentIds) . ') AND message_id IS NULL',
                        array_merge([$messageId], $attachmentIds)
                    );
                }
            }, 'text', $threadRoot);
        } catch (\PDOException $e) {
            // Gleichzeitiger Doppel-Request mit derselben client_uid
            if ($clientUid !== null && $e->getCode() === '23000') {
                $existing = Database::one('SELECT * FROM messages WHERE sender_id = ? AND client_uid = ?', [$uid, $clientUid]);
                if ($existing) {
                    return ['message' => self::hydrate([$existing], $uid)[0], 'duplicate' => true];
                }
            }
            throw $e;
        }
        Ai::afterSend($row, $user);
        return ['message' => self::hydrate([$row], $uid)[0], 'duplicate' => false];
    }

    /**
     * Gemeinsame Einfüge-Logik für Senden, Weiterleiten sowie Umfragen und Termine.
     * Thread-Antworten erscheinen nicht im Hauptverlauf: Sie ändern weder die Vorschau noch die Lesemarke der Unterhaltung.
     */
    public static function create(array $user, int $conversationId, string $body, ?string $clientUid, ?int $replyTo, bool $forwarded, ?callable $inTx = null, string $kind = 'text', ?int $threadRoot = null): array
    {
        $uid = (int) $user['id'];
        $mentions = self::resolveMentions($body, $conversationId, $uid);
        $id = (int) Database::transaction(function () use ($uid, $conversationId, $body, $clientUid, $replyTo, $forwarded, $mentions, $inTx, $kind, $threadRoot) {
            $id = Database::insert(
                'INSERT INTO messages (conversation_id, sender_id, client_uid, kind, body, reply_to_id, thread_root_id, forwarded, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
                [$conversationId, $uid, $clientUid, $kind, $body, $replyTo, $threadRoot, $forwarded ? 1 : 0, now()]
            );
            foreach ($mentions as $mn) {
                Database::exec('INSERT IGNORE INTO message_mentions (message_id, user_id) VALUES (?, ?)', [$id, $mn['id']]);
            }
            if ($inTx) {
                $inTx($id);
            }
            if ($threadRoot === null) {
                Database::exec('UPDATE conversations SET last_message_id = ?, last_message_at = ?, updated_at = ? WHERE id = ?', [$id, now(), now(), $conversationId]);
                Database::exec('UPDATE conversation_members SET last_read_message_id = ?, archived = 0 WHERE conversation_id = ? AND user_id = ?', [$id, $conversationId, $uid]);
                // Archivierte Unterhaltungen anderer Mitglieder tauchen bei neuen Nachrichten wieder auf, ausser sie sind stummgeschaltet
                Database::exec('UPDATE conversation_members SET archived = 0 WHERE conversation_id = ? AND muted = 0 AND left_at IS NULL', [$conversationId]);
            }
            Database::exec('DELETE FROM typing_state WHERE conversation_id = ? AND user_id = ?', [$conversationId, $uid]);
            return $id;
        });
        Events::push('message.new', $conversationId, $id);
        if ($threadRoot !== null) {
            Events::push('message.thread', $conversationId, $threadRoot);
        }
        Drafts::clear($uid, $conversationId, $threadRoot ?? 0);
        $row = self::find($id);
        if ($threadRoot !== null) {
            Notifications::forThreadReply($row, $user, array_column($mentions, 'id'), $threadRoot);
        } else {
            Notifications::forNewMessage($row, $user, array_column($mentions, 'id'));
        }
        return $row;
    }

    /**
     * Thread einer Nachricht: Ursprungsnachricht und alle Antworten.
     * @return array{root:array,replies:array}
     */
    public static function thread(int $userId, int $messageId): array
    {
        ['message' => $msg, 'member' => $member] = self::findForUser($messageId, $userId);
        if ($msg['kind'] === 'system') {
            throw new HttpException(404, 'Nachricht nicht gefunden.');
        }
        $rootId = !empty($msg['thread_root_id']) ? (int) $msg['thread_root_id'] : (int) $msg['id'];
        $root = $rootId === (int) $msg['id'] ? $msg : self::find($rootId);
        if (!$root || $rootId <= (int) $member['visible_from_message_id']) {
            throw new HttpException(404, 'Nachricht nicht gefunden.');
        }
        $replies = Database::all('SELECT * FROM messages WHERE thread_root_id = ? AND id > ? ORDER BY id ASC LIMIT 300', [$rootId, (int) $member['visible_from_message_id']]);
        if ($replies) {
            $showReceipts = (bool) (Database::value('SELECT show_read_receipts FROM user_settings WHERE user_id = ?', [$userId]) ?? 1);
            $n = Database::exec(
                "INSERT INTO message_receipts (message_id, user_id, delivered_at, read_at)
                 SELECT id, ?, ?, ? FROM messages WHERE thread_root_id = ? AND id > ? AND sender_id IS NOT NULL AND sender_id <> ? AND deleted_at IS NULL
                 ON DUPLICATE KEY UPDATE delivered_at = COALESCE(delivered_at, VALUES(delivered_at)), read_at = COALESCE(read_at, VALUES(read_at))",
                [$userId, now(), $showReceipts ? now() : null, $rootId, (int) $member['visible_from_message_id'], $userId]
            );
            if ($n > 0) {
                Events::push('receipt', (int) $root['conversation_id'], (int) end($replies)['id']);
            }
            Database::exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND conversation_id = ? AND read_at IS NULL AND message_id IN (SELECT id FROM messages WHERE thread_root_id = ?)', [now(), $userId, (int) $root['conversation_id'], $rootId]);
        }
        return ['root' => self::hydrate([$root], $userId)[0], 'replies' => self::hydrate($replies, $userId)];
    }

    // ------------------------------------------------------------- Bearbeiten

    public static function edit(array $user, int $messageId, string $body): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        ['message' => $msg] = self::findForUser($messageId, $uid);
        if ((int) $msg['sender_id'] !== $uid || $msg['kind'] !== 'text') {
            throw new HttpException(403, 'Du kannst nur deine eigenen Nachrichten bearbeiten.');
        }
        if ($msg['deleted_at'] !== null) {
            throw new HttpException(422, 'Gelöschte Nachrichten können nicht bearbeitet werden.');
        }
        $minutes = Settings::int('message_edit_minutes');
        if ($minutes > 0 && time() - strtotime($msg['created_at'] . ' UTC') > $minutes * 60) {
            throw new HttpException(403, "Nachrichten können nur $minutes Minuten nach dem Senden bearbeitet werden.");
        }
        $body = self::normalizeBody($body);
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new HttpException(422, 'Die Nachricht ist zu lang.');
        }
        $hasAtt = (bool) Database::value('SELECT 1 FROM message_attachments WHERE message_id = ? LIMIT 1', [$messageId]);
        if ($body === '' && !$hasAtt) {
            throw new HttpException(422, 'Die Nachricht darf nicht leer sein. Lösche sie stattdessen.');
        }
        if ($body === (string) $msg['body']) {
            return self::hydrate([$msg], $uid)[0];
        }
        $convId = (int) $msg['conversation_id'];
        $mentions = self::resolveMentions($body, $convId, $uid);
        $old = array_map('intval', array_column(Database::all('SELECT user_id FROM message_mentions WHERE message_id = ?', [$messageId]), 'user_id'));
        Database::transaction(function () use ($messageId, $body, $mentions) {
            Database::exec('UPDATE messages SET body = ?, edited_at = ? WHERE id = ?', [$body, now(), $messageId]);
            Database::exec('DELETE FROM message_mentions WHERE message_id = ?', [$messageId]);
            foreach ($mentions as $mn) {
                Database::exec('INSERT IGNORE INTO message_mentions (message_id, user_id) VALUES (?, ?)', [$messageId, $mn['id']]);
            }
        });
        $newlyMentioned = array_diff(array_column($mentions, 'id'), $old);
        foreach ($newlyMentioned as $mid) {
            Notifications::create((int) $mid, 'mention', $uid, $convId, $messageId, $user['display_name'] . ' hat dich erwähnt.');
        }
        Events::push('message.edit', $convId, $messageId);
        return self::hydrate([self::find($messageId)], $uid)[0];
    }

    // ---------------------------------------------------------------- Löschen

    public static function delete(array $user, int $messageId): void
    {
        $uid = (int) $user['id'];
        ['message' => $msg, 'member' => $member] = self::findForUser($messageId, $uid);
        if ($msg['kind'] === 'system') {
            throw new HttpException(403, 'Systemnachrichten können nicht gelöscht werden.');
        }
        $own = (int) $msg['sender_id'] === $uid;
        $conv = Chat::conversation((int) $msg['conversation_id']);
        $moderating = !$own && $conv['type'] === 'group' && Chat::canModerate($member);
        if (!$own && !$moderating) {
            throw new HttpException(403, 'Du darfst diese Nachricht nicht löschen.');
        }
        if ($msg['deleted_at'] !== null) {
            return;
        }
        self::erase($messageId, (int) $msg['conversation_id']);
        if ($moderating) {
            Audit::log('message.moderated_delete', $uid, 'message', $messageId, 'conversation ' . $msg['conversation_id']);
        }
    }

    /** Inhalt endgültig entfernen (Text, Anhänge, Reaktionen) und Clients benachrichtigen. */
    private static function erase(int $messageId, int $conversationId): void
    {
        $files = [];
        Database::transaction(function () use ($messageId, &$files) {
            $files = array_column(Database::all('SELECT storage_name FROM message_attachments WHERE message_id = ?', [$messageId]), 'storage_name');
            Database::exec('DELETE FROM message_attachments WHERE message_id = ?', [$messageId]);
            Database::exec('DELETE FROM message_reactions WHERE message_id = ?', [$messageId]);
            Database::exec('DELETE FROM message_mentions WHERE message_id = ?', [$messageId]);
            Database::exec('DELETE FROM polls WHERE message_id = ?', [$messageId]);
            Database::exec('DELETE FROM calendar_events WHERE message_id = ?', [$messageId]);
            Database::exec('DELETE FROM notifications WHERE message_id = ?', [$messageId]);
            Database::exec("UPDATE messages SET body = '', deleted_at = ?, edited_at = NULL WHERE id = ?", [now(), $messageId]);
        });
        foreach ($files as $name) {
            Files::unlinkStored((string) $name);
        }
        Events::push('message.delete', $conversationId, $messageId);
    }

    /** Inhalt im Rahmen einer Datenschutz-Löschung entfernen (Aufruf nur aus Privacy). */
    public static function eraseForPrivacy(int $messageId, int $conversationId): void
    {
        self::erase($messageId, $conversationId);
    }

    /** Entfernen einer gemeldeten Nachricht durch einen Administrator (wird protokolliert). */
    public static function removeReported(int $adminId, int $messageId): void
    {
        $msg = Database::one('SELECT id, conversation_id, kind, deleted_at FROM messages WHERE id = ?', [$messageId]);
        if (!$msg || $msg['kind'] === 'system' || $msg['deleted_at'] !== null) {
            return;
        }
        self::erase($messageId, (int) $msg['conversation_id']);
        Audit::log('message.admin_removed', $adminId, 'message', $messageId, 'conversation ' . $msg['conversation_id']);
    }

    // -------------------------------------------------------------- Reaktionen

    public static function react(array $user, int $messageId, string $emoji): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        if (!in_array($emoji, self::REACTIONS, true)) {
            throw new HttpException(422, 'Diese Reaktion ist nicht erlaubt.');
        }
        ['message' => $msg] = self::findForUser($messageId, $uid);
        if ($msg['deleted_at'] !== null || $msg['kind'] === 'system') {
            throw new HttpException(422, 'Auf diese Nachricht kann nicht reagiert werden.');
        }
        if (!RateLimiter::hit('react', (string) $uid, 120, 60)) {
            throw new HttpException(429, 'Zu viele Reaktionen. Bitte warte einen Moment.');
        }
        $deleted = Database::exec('DELETE FROM message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?', [$messageId, $uid, $emoji]);
        if ($deleted === 0) {
            Database::exec('INSERT IGNORE INTO message_reactions (message_id, user_id, emoji, created_at) VALUES (?,?,?,?)', [$messageId, $uid, $emoji, now()]);
        }
        Events::push('message.reaction', (int) $msg['conversation_id'], $messageId);
        return self::hydrate([self::find($messageId)], $uid)[0];
    }

    // ------------------------------------------------------------ Weiterleiten

    /**
     * @param int[] $targetConversationIds
     * @return int[] IDs der neuen Nachrichten
     */
    public static function forward(array $user, int $messageId, array $targetConversationIds): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        ['message' => $msg] = self::findForUser($messageId, $uid);
        if ($msg['deleted_at'] !== null || $msg['kind'] !== 'text') {
            throw new HttpException(422, 'Diese Nachricht kann nicht weitergeleitet werden.');
        }
        $targets = array_slice(array_values(array_unique($targetConversationIds)), 0, 5);
        if (!$targets) {
            throw new HttpException(422, 'Bitte wähle mindestens eine Unterhaltung aus.');
        }
        foreach ($targets as $t) {
            Chat::requireMember((int) $t, $uid);
        }
        if (!RateLimiter::hit('msg_send', (string) $uid, 60, 60)) {
            throw new HttpException(429, 'Du sendest zu schnell. Bitte warte einen Moment.');
        }
        $atts = Database::all('SELECT * FROM message_attachments WHERE message_id = ?', [$messageId]);
        $created = [];
        foreach ($targets as $t) {
            $row = self::create($user, (int) $t, (string) $msg['body'], null, null, true, function (int $newId) use ($atts, $t, $uid) {
                foreach ($atts as $a) {
                    Files::duplicate($a, $newId, (int) $t, $uid);
                }
            });
            $created[] = (int) $row['id'];
        }
        return $created;
    }

    // ----------------------------------------------------------- Lesestatus

    public static function markDelivered(int $conversationId, int $userId, int $upToId, ?array $member = null): void
    {
        $member ??= Chat::membership($conversationId, $userId);
        if (!$member) {
            return;
        }
        $from = max((int) $member['last_read_message_id'], (int) $member['visible_from_message_id']);
        if ($upToId <= $from) {
            return;
        }
        $n = Database::exec(
            "INSERT IGNORE INTO message_receipts (message_id, user_id, delivered_at)
             SELECT id, ?, ? FROM messages
             WHERE conversation_id = ? AND id > ? AND id <= ? AND sender_id IS NOT NULL AND sender_id <> ? AND kind <> 'system' AND deleted_at IS NULL",
            [$userId, now(), $conversationId, $from, $upToId, $userId]
        );
        if ($n > 0) {
            Events::push('receipt', $conversationId, $upToId);
        }
    }

    public static function markRead(int $conversationId, int $userId, ?int $upTo): array
    {
        $member = Chat::requireMember($conversationId, $userId);
        $last = (int) Database::value('SELECT COALESCE(MAX(id), 0) FROM messages WHERE conversation_id = ?', [$conversationId]);
        $upTo = $upTo === null ? $last : min($upTo, $last);
        $current = (int) $member['last_read_message_id'];
        if ($upTo > $current) {
            $showReceipts = (bool) (Database::value('SELECT show_read_receipts FROM user_settings WHERE user_id = ?', [$userId]) ?? 1);
            Database::transaction(function () use ($conversationId, $userId, $upTo, $current, $showReceipts, $member) {
                Database::exec('UPDATE conversation_members SET last_read_message_id = ?, last_viewed_at = ? WHERE id = ?', [$upTo, now(), $member['id']]);
                $from = max($current, (int) $member['visible_from_message_id']);
                Database::exec(
                    "INSERT INTO message_receipts (message_id, user_id, delivered_at, read_at)
                     SELECT id, ?, ?, ? FROM messages
                     WHERE conversation_id = ? AND id > ? AND id <= ? AND sender_id IS NOT NULL AND sender_id <> ? AND kind <> 'system' AND deleted_at IS NULL
                     ON DUPLICATE KEY UPDATE delivered_at = COALESCE(delivered_at, VALUES(delivered_at)), read_at = COALESCE(read_at, VALUES(read_at))",
                    [$userId, now(), $showReceipts ? now() : null, $conversationId, $from, $upTo, $userId]
                );
                Database::exec('UPDATE notifications SET read_at = ? WHERE user_id = ? AND conversation_id = ? AND read_at IS NULL AND (message_id IS NULL OR message_id <= ?)', [now(), $userId, $conversationId, $upTo]);
            });
            Events::push('receipt', $conversationId, $upTo);
            Events::push('conversation.prefs', $conversationId, null, $userId);
        }
        return ['last_read_message_id' => max($upTo, $current)];
    }

    public static function typing(int $conversationId, int $userId): void
    {
        Chat::requireMember($conversationId, $userId);
        Database::exec(
            'INSERT INTO typing_state (conversation_id, user_id, typed_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE typed_at = VALUES(typed_at)',
            [$conversationId, $userId, now()]
        );
    }

    /** @return string[] Namen der Personen, die gerade tippen */
    public static function typingNames(int $conversationId, int $viewerId): array
    {
        return array_column(Database::all(
            'SELECT u.display_name FROM typing_state t JOIN users u ON u.id = t.user_id
             WHERE t.conversation_id = ? AND t.user_id <> ? AND t.typed_at > ? ORDER BY t.typed_at ASC LIMIT 4',
            [$conversationId, $viewerId, gmdate('Y-m-d H:i:s', time() - 6)]
        ), 'display_name');
    }

    // ---------------------------------------------------------------- Melden

    public static function report(array $user, int $messageId, string $reason): void
    {
        $uid = (int) $user['id'];
        ['message' => $msg] = self::findForUser($messageId, $uid);
        if ((int) $msg['sender_id'] === $uid || $msg['kind'] === 'system') {
            throw new HttpException(422, 'Diese Nachricht kann nicht gemeldet werden.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new HttpException(422, 'Bitte beschreibe kurz den Grund der Meldung.');
        }
        if (!RateLimiter::hit('report', (string) $uid, 10, 3600)) {
            throw new HttpException(429, 'Zu viele Meldungen. Bitte versuche es später erneut.');
        }
        Database::exec(
            "INSERT INTO message_reports (message_id, reporter_id, reason, created_at) VALUES (?,?,?,?)
             ON DUPLICATE KEY UPDATE reason = VALUES(reason), status = 'open'",
            [$messageId, $uid, mb_substr($reason, 0, 500), now()]
        );
        Audit::log('message.reported', $uid, 'message', $messageId);
    }
}
