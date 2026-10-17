<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/** Umfragen in Unterhaltungen: Einzel- oder Mehrfachauswahl, optional anonym, optional mit Frist. */
final class Polls
{
    public const MAX_OPTIONS = 10;
    public const MAX_VOTER_NAMES = 12;

    /**
     * @param string[] $options
     * @return array{message:array}
     */
    public static function create(array $user, int $conversationId, string $question, array $options, bool $multiple, bool $anonymous, ?string $closesAt): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        Chat::requireMember($conversationId, $uid);
        if (($user['role'] ?? '') === 'guest') {
            throw new HttpException(403, 'Gäste können keine Umfragen erstellen.');
        }
        $question = trim(preg_replace('/\s+/u', ' ', $question) ?? '');
        if (mb_strlen($question) < 3 || mb_strlen($question) > 300) {
            throw new HttpException(422, 'Die Frage muss zwischen 3 und 300 Zeichen lang sein.');
        }
        $clean = [];
        foreach ($options as $o) {
            $o = trim(preg_replace('/\s+/u', ' ', (string) $o) ?? '');
            if ($o === '') {
                continue;
            }
            if (mb_strlen($o) > 100) {
                throw new HttpException(422, 'Eine Antwortmöglichkeit darf höchstens 100 Zeichen lang sein.');
            }
            $key = mb_strtolower($o);
            if (isset($clean[$key])) {
                throw new HttpException(422, 'Die Antwortmöglichkeiten müssen sich unterscheiden.');
            }
            $clean[$key] = $o;
        }
        $clean = array_values($clean);
        if (count($clean) < 2) {
            throw new HttpException(422, 'Bitte gib mindestens zwei Antwortmöglichkeiten an.');
        }
        if (count($clean) > self::MAX_OPTIONS) {
            throw new HttpException(422, 'Es sind höchstens ' . self::MAX_OPTIONS . ' Antwortmöglichkeiten erlaubt.');
        }
        $close = null;
        if ($closesAt !== null && trim($closesAt) !== '') {
            $ts = strtotime($closesAt);
            if ($ts === false || $ts < time() + 60 || $ts > time() + 90 * 86400) {
                throw new HttpException(422, 'Das Ende der Umfrage muss in der Zukunft liegen (höchstens 90 Tage).');
            }
            $close = gmdate('Y-m-d H:i:s', $ts);
        }
        if (!RateLimiter::hit('msg_send', (string) $uid, 60, 60)) {
            throw new HttpException(429, 'Du sendest zu schnell. Bitte warte einen Moment.');
        }
        $row = Messages::create($user, $conversationId, $question, null, null, false, function (int $messageId) use ($uid, $question, $clean, $multiple, $anonymous, $close): void {
            $pollId = Database::insert(
                'INSERT INTO polls (message_id, question, multiple, anonymous, closes_at, created_by, created_at) VALUES (?,?,?,?,?,?,?)',
                [$messageId, $question, $multiple ? 1 : 0, $anonymous ? 1 : 0, $close, $uid, now()]
            );
            foreach ($clean as $i => $label) {
                Database::exec('INSERT INTO poll_options (poll_id, label, position) VALUES (?,?,?)', [$pollId, $label, $i]);
            }
        }, 'poll');
        return ['message' => Messages::hydrate([$row], $uid)[0]];
    }

    public static function isClosed(array $poll): bool
    {
        return $poll['closed_at'] !== null || ($poll['closes_at'] !== null && strtotime($poll['closes_at'] . ' UTC') <= time());
    }

    /** Lädt Umfrage samt Nachricht und prüft die Sichtbarkeit für die Person. */
    private static function forUser(int $pollId, int $userId): array
    {
        $poll = Database::one('SELECT p.*, m.conversation_id, m.deleted_at FROM polls p JOIN messages m ON m.id = p.message_id WHERE p.id = ?', [$pollId]);
        if (!$poll || $poll['deleted_at'] !== null) {
            throw new HttpException(404, 'Umfrage nicht gefunden.');
        }
        $member = Chat::membership((int) $poll['conversation_id'], $userId);
        if (!$member || (int) $poll['message_id'] <= (int) $member['visible_from_message_id']) {
            throw new HttpException(404, 'Umfrage nicht gefunden.');
        }
        return ['poll' => $poll, 'member' => $member];
    }

    /**
     * Stimme abgeben. Eine leere Liste nimmt die Stimme zurück.
     * @param int[] $optionIds
     */
    public static function vote(array $user, int $pollId, array $optionIds): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        ['poll' => $poll] = self::forUser($pollId, $uid);
        if (self::isClosed($poll)) {
            throw new HttpException(422, 'Diese Umfrage ist beendet.');
        }
        $optionIds = array_values(array_unique(array_map('intval', $optionIds)));
        if (!(int) $poll['multiple'] && count($optionIds) > 1) {
            throw new HttpException(422, 'Bei dieser Umfrage ist nur eine Antwort möglich.');
        }
        if ($optionIds) {
            $valid = (int) Database::value('SELECT COUNT(*) FROM poll_options WHERE poll_id = ? AND id IN (' . Database::in($optionIds) . ')', array_merge([$pollId], $optionIds));
            if ($valid !== count($optionIds)) {
                throw new HttpException(422, 'Ungültige Antwortmöglichkeit.');
            }
        }
        if (!RateLimiter::hit('poll_vote', (string) $uid, 60, 60)) {
            throw new HttpException(429, 'Zu viele Abstimmungen. Bitte warte einen Moment.');
        }
        Database::transaction(function () use ($pollId, $uid, $optionIds): void {
            Database::exec('DELETE FROM poll_votes WHERE poll_id = ? AND user_id = ?', [$pollId, $uid]);
            foreach ($optionIds as $oid) {
                Database::exec('INSERT INTO poll_votes (poll_id, option_id, user_id, created_at) VALUES (?,?,?,?)', [$pollId, $oid, $uid, now()]);
            }
        });
        Events::push('message.poll', (int) $poll['conversation_id'], (int) $poll['message_id']);
        return Messages::hydrate([Messages::find((int) $poll['message_id'])], $uid)[0];
    }

    /** Umfrage vorzeitig beenden (Ersteller oder Moderation). */
    public static function close(array $user, int $pollId): array
    {
        $uid = (int) $user['id'];
        ['poll' => $poll, 'member' => $member] = self::forUser($pollId, $uid);
        if ((int) $poll['created_by'] !== $uid && !Chat::canModerate($member)) {
            throw new HttpException(403, 'Nur die Person, die die Umfrage erstellt hat, oder die Moderation kann sie beenden.');
        }
        if (!self::isClosed($poll)) {
            Database::exec('UPDATE polls SET closed_at = ? WHERE id = ?', [now(), $pollId]);
            Events::push('message.poll', (int) $poll['conversation_id'], (int) $poll['message_id']);
        }
        return Messages::hydrate([Messages::find((int) $poll['message_id'])], $uid)[0];
    }

    /**
     * Darstellung für den Client, je Nachrichten-ID.
     * @param array<int,array<string,mixed>> $messageRows Nachrichten der Art "poll"
     * @return array<int,array<string,mixed>>
     */
    public static function views(array $messageRows, int $viewerId): array
    {
        if (!$messageRows) {
            return [];
        }
        $mids = array_map(fn ($r) => (int) $r['id'], $messageRows);
        $polls = Database::all('SELECT * FROM polls WHERE message_id IN (' . Database::in($mids) . ')', $mids);
        if (!$polls) {
            return [];
        }
        $pids = array_map(fn ($p) => (int) $p['id'], $polls);
        $in = Database::in($pids);
        $options = [];
        foreach (Database::all("SELECT id, poll_id, label FROM poll_options WHERE poll_id IN ($in) ORDER BY position ASC, id ASC", $pids) as $o) {
            $options[(int) $o['poll_id']][] = ['id' => (int) $o['id'], 'label' => $o['label'], 'votes' => 0, 'mine' => false, 'voters' => []];
        }
        $voters = [];
        $totals = [];
        foreach (Database::all(
            "SELECT v.poll_id, v.option_id, v.user_id, u.display_name FROM poll_votes v JOIN users u ON u.id = v.user_id WHERE v.poll_id IN ($in) ORDER BY v.created_at ASC",
            $pids
        ) as $v) {
            $voters[(int) $v['poll_id']][(int) $v['option_id']][] = ['id' => (int) $v['user_id'], 'name' => $v['display_name']];
            $totals[(int) $v['poll_id']][(int) $v['user_id']] = true;
        }
        $creators = [];
        $cids = array_values(array_unique(array_filter(array_map(fn ($p) => $p['created_by'] ? (int) $p['created_by'] : null, $polls))));
        if ($cids) {
            $creators = array_column(Database::all('SELECT id, display_name FROM users WHERE id IN (' . Database::in($cids) . ')', $cids), 'display_name', 'id');
        }
        $out = [];
        foreach ($polls as $p) {
            $pid = (int) $p['id'];
            $anon = (bool) $p['anonymous'];
            $opts = $options[$pid] ?? [];
            foreach ($opts as &$o) {
                $list = $voters[$pid][$o['id']] ?? [];
                $o['votes'] = count($list);
                $o['mine'] = in_array($viewerId, array_column($list, 'id'), true);
                $o['voters'] = $anon ? [] : array_slice(array_column($list, 'name'), 0, self::MAX_VOTER_NAMES);
            }
            unset($o);
            $closed = self::isClosed($p);
            $out[(int) $p['message_id']] = [
                'id' => $pid,
                'question' => $p['question'],
                'multiple' => (bool) $p['multiple'],
                'anonymous' => $anon,
                'closed' => $closed,
                'closes_at' => iso($p['closes_at']),
                'closed_at' => iso($p['closed_at']),
                'creator_id' => $p['created_by'] ? (int) $p['created_by'] : null,
                'creator_name' => $creators[(int) $p['created_by']] ?? null,
                'total_voters' => count($totals[$pid] ?? []),
                'options' => $opts,
            ];
        }
        return $out;
    }
}
