<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;

/** Termine in Unterhaltungen: Zu- und Absagen, Änderungen, iCalendar-Export (einzeln und als persönlicher Kalender-Feed). */
final class Calendar
{
    public const RSVP = ['yes', 'maybe', 'no'];
    public const FEED_PAST_DAYS = 30;
    public const FEED_FUTURE_DAYS = 365;

    // ------------------------------------------------------------ Eingaben

    /** @return array{title:string,description:string,location:string,starts_at:string,ends_at:?string,all_day:bool} */
    private static function parse(array $in, ?array $current = null): array
    {
        $title = trim(preg_replace('/\s+/u', ' ', (string) ($in['title'] ?? $current['title'] ?? '')) ?? '');
        if (mb_strlen($title) < 2 || mb_strlen($title) > 150) {
            throw new HttpException(422, 'Der Titel muss zwischen 2 und 150 Zeichen lang sein.');
        }
        $description = trim(str_replace(["\r\n", "\r"], "\n", (string) ($in['description'] ?? $current['description'] ?? '')));
        if (mb_strlen($description) > 2000) {
            throw new HttpException(422, 'Die Beschreibung darf höchstens 2000 Zeichen lang sein.');
        }
        $location = trim(preg_replace('/\s+/u', ' ', (string) ($in['location'] ?? $current['location'] ?? '')) ?? '');
        if (mb_strlen($location) > 200) {
            throw new HttpException(422, 'Der Ort darf höchstens 200 Zeichen lang sein.');
        }
        $allDay = array_key_exists('all_day', $in) ? filter_var($in['all_day'], FILTER_VALIDATE_BOOLEAN) : (bool) ($current['all_day'] ?? false);

        if ($allDay) {
            $startDate = (string) ($in['start_date'] ?? ($current && $current['all_day'] ? substr((string) $current['starts_at'], 0, 10) : ''));
            $endDate = (string) ($in['end_date'] ?? '');
            if ($endDate === '' && $current && $current['all_day'] && $current['ends_at'] !== null && !isset($in['start_date'])) {
                $endDate = gmdate('Y-m-d', strtotime((string) $current['ends_at'] . ' UTC') - 86400);
            }
            $s = \DateTimeImmutable::createFromFormat('!Y-m-d', $startDate, new \DateTimeZone('UTC'));
            if (!$s || $s->format('Y-m-d') !== $startDate) {
                throw new HttpException(422, 'Bitte gib ein gültiges Datum an.');
            }
            $e = $s;
            if ($endDate !== '') {
                $e = \DateTimeImmutable::createFromFormat('!Y-m-d', $endDate, new \DateTimeZone('UTC'));
                if (!$e || $e->format('Y-m-d') !== $endDate) {
                    throw new HttpException(422, 'Das Enddatum ist ungültig.');
                }
            }
            if ($e < $s) {
                throw new HttpException(422, 'Das Ende liegt vor dem Beginn.');
            }
            if ($s->diff($e)->days > 60) {
                throw new HttpException(422, 'Ein Termin darf höchstens 60 Tage dauern.');
            }
            $starts = $s;
            $ends = $e->modify('+1 day'); // ICS: Enddatum exklusiv
        } else {
            $startRaw = (string) ($in['starts_at'] ?? ($current && !$current['all_day'] ? $current['starts_at'] . ' UTC' : ''));
            $endRaw = array_key_exists('ends_at', $in) ? (string) $in['ends_at'] : ($current && !$current['all_day'] && $current['ends_at'] !== null && !isset($in['starts_at']) ? $current['ends_at'] . ' UTC' : '');
            $starts = self::time($startRaw, 'Beginn');
            $ends = $endRaw !== '' ? self::time($endRaw, 'Ende') : null;
            if ($ends !== null && $ends <= $starts) {
                throw new HttpException(422, 'Das Ende muss nach dem Beginn liegen.');
            }
            if ($ends !== null && $ends->getTimestamp() - $starts->getTimestamp() > 60 * 86400) {
                throw new HttpException(422, 'Ein Termin darf höchstens 60 Tage dauern.');
            }
        }
        $ts = $starts->getTimestamp();
        if ($ts < time() - 3 * 365 * 86400 || $ts > time() + 5 * 365 * 86400) {
            throw new HttpException(422, 'Der Zeitpunkt liegt ausserhalb des erlaubten Bereichs.');
        }
        return [
            'title' => $title,
            'description' => $description,
            'location' => $location,
            'starts_at' => $starts->format('Y-m-d H:i:s'),
            'ends_at' => $ends?->format('Y-m-d H:i:s'),
            'all_day' => $allDay,
        ];
    }

    private static function time(string $raw, string $what): \DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new HttpException(422, "Bitte gib $what an.");
        }
        try {
            return (new \DateTimeImmutable($raw, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'));
        } catch (\Throwable) {
            throw new HttpException(422, "$what ist ungültig.");
        }
    }

    // ------------------------------------------------------------ Anlegen

    public static function create(array $user, int $conversationId, array $in): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        Chat::requireMember($conversationId, $uid);
        if (($user['role'] ?? '') === 'guest') {
            throw new HttpException(403, 'Gäste können keine Termine erstellen.');
        }
        $d = self::parse($in);
        if (!RateLimiter::hit('msg_send', (string) $uid, 60, 60)) {
            throw new HttpException(429, 'Du sendest zu schnell. Bitte warte einen Moment.');
        }
        $uuid = self::uuid();
        $row = Messages::create($user, $conversationId, $d['title'], null, null, false, function (int $messageId) use ($d, $conversationId, $uid, $uuid): void {
            Database::exec(
                'INSERT INTO calendar_events (message_id, conversation_id, title, description, location, starts_at, ends_at, all_day, uid, created_by, created_at, updated_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [$messageId, $conversationId, $d['title'], $d['description'], $d['location'], $d['starts_at'], $d['ends_at'], $d['all_day'] ? 1 : 0, $uuid, $uid, now(), now()]
            );
        }, 'event');
        // Die erstellende Person nimmt standardmässig teil
        $eid = (int) Database::value('SELECT id FROM calendar_events WHERE message_id = ?', [(int) $row['id']]);
        Database::exec("INSERT IGNORE INTO event_rsvps (event_id, user_id, status, updated_at) VALUES (?,?,'yes',?)", [$eid, $uid, now()]);
        return ['message' => Messages::hydrate([Messages::find((int) $row['id'])], $uid)[0]];
    }

    /** Lädt Termin samt Nachricht und prüft die Sichtbarkeit für die Person. */
    private static function forUser(int $eventId, int $userId): array
    {
        $ev = Database::one('SELECT c.*, m.deleted_at FROM calendar_events c JOIN messages m ON m.id = c.message_id WHERE c.id = ?', [$eventId]);
        if (!$ev || $ev['deleted_at'] !== null) {
            throw new HttpException(404, 'Termin nicht gefunden.');
        }
        $member = Chat::membership((int) $ev['conversation_id'], $userId);
        if (!$member || (int) $ev['message_id'] <= (int) $member['visible_from_message_id']) {
            throw new HttpException(404, 'Termin nicht gefunden.');
        }
        return ['event' => $ev, 'member' => $member];
    }

    private static function requireOwnerOrModerator(array $ev, array $member, int $userId): void
    {
        if ((int) $ev['created_by'] !== $userId && !Chat::canModerate($member)) {
            throw new HttpException(403, 'Nur die Person, die den Termin erstellt hat, oder die Moderation darf ihn ändern.');
        }
    }

    public static function update(array $user, int $eventId, array $in): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        ['event' => $ev, 'member' => $member] = self::forUser($eventId, $uid);
        self::requireOwnerOrModerator($ev, $member, $uid);
        if ($ev['cancelled_at'] !== null) {
            throw new HttpException(422, 'Ein abgesagter Termin kann nicht mehr geändert werden.');
        }
        $d = self::parse($in, $ev);
        $changed = $d['title'] !== $ev['title'] || $d['description'] !== $ev['description'] || $d['location'] !== $ev['location']
            || $d['starts_at'] !== $ev['starts_at'] || $d['ends_at'] !== $ev['ends_at'] || (int) $d['all_day'] !== (int) $ev['all_day'];
        if ($changed) {
            Database::transaction(function () use ($d, $eventId, $ev): void {
                Database::exec(
                    'UPDATE calendar_events SET title = ?, description = ?, location = ?, starts_at = ?, ends_at = ?, all_day = ?, sequence = sequence + 1, updated_at = ? WHERE id = ?',
                    [$d['title'], $d['description'], $d['location'], $d['starts_at'], $d['ends_at'], $d['all_day'] ? 1 : 0, now(), $eventId]
                );
                Database::exec('UPDATE messages SET body = ? WHERE id = ?', [$d['title'], $ev['message_id']]);
            });
            Events::push('message.event', (int) $ev['conversation_id'], (int) $ev['message_id']);
            Messages::system((int) $ev['conversation_id'], ($user['display_name'] ?? 'Jemand') . ' hat den Termin «' . $d['title'] . '» geändert.');
        }
        return Messages::hydrate([Messages::find((int) $ev['message_id'])], $uid)[0];
    }

    public static function cancel(array $user, int $eventId): array
    {
        $uid = (int) $user['id'];
        ['event' => $ev, 'member' => $member] = self::forUser($eventId, $uid);
        self::requireOwnerOrModerator($ev, $member, $uid);
        if ($ev['cancelled_at'] === null) {
            Database::exec('UPDATE calendar_events SET cancelled_at = ?, sequence = sequence + 1, updated_at = ? WHERE id = ?', [now(), now(), $eventId]);
            Events::push('message.event', (int) $ev['conversation_id'], (int) $ev['message_id']);
            Messages::system((int) $ev['conversation_id'], ($user['display_name'] ?? 'Jemand') . ' hat den Termin «' . $ev['title'] . '» abgesagt.');
        }
        return Messages::hydrate([Messages::find((int) $ev['message_id'])], $uid)[0];
    }

    public static function rsvp(array $user, int $eventId, string $status): array
    {
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        ['event' => $ev] = self::forUser($eventId, $uid);
        if ($ev['cancelled_at'] !== null) {
            throw new HttpException(422, 'Dieser Termin wurde abgesagt.');
        }
        if ($status !== '' && !in_array($status, self::RSVP, true)) {
            throw new HttpException(422, 'Ungültige Antwort.');
        }
        if (!RateLimiter::hit('rsvp', (string) $uid, 60, 60)) {
            throw new HttpException(429, 'Zu viele Antworten. Bitte warte einen Moment.');
        }
        if ($status === '') {
            Database::exec('DELETE FROM event_rsvps WHERE event_id = ? AND user_id = ?', [$eventId, $uid]);
        } else {
            Database::exec(
                'INSERT INTO event_rsvps (event_id, user_id, status, updated_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)',
                [$eventId, $uid, $status, now()]
            );
        }
        Events::push('message.event', (int) $ev['conversation_id'], (int) $ev['message_id']);
        return Messages::hydrate([Messages::find((int) $ev['message_id'])], $uid)[0];
    }

    // ------------------------------------------------------------ Darstellung

    /**
     * @param array<int,array<string,mixed>> $messageRows Nachrichten der Art "event"
     * @return array<int,array<string,mixed>> je Nachrichten-ID
     */
    public static function views(array $messageRows, int $viewerId): array
    {
        if (!$messageRows) {
            return [];
        }
        $mids = array_map(fn ($r) => (int) $r['id'], $messageRows);
        $events = Database::all(
            'SELECT c.*, u.display_name AS creator_name FROM calendar_events c LEFT JOIN users u ON u.id = c.created_by WHERE c.message_id IN (' . Database::in($mids) . ')',
            $mids
        );
        if (!$events) {
            return [];
        }
        $eids = array_map(fn ($e) => (int) $e['id'], $events);
        $rsvps = [];
        foreach (Database::all(
            'SELECT r.event_id, r.user_id, r.status, u.display_name FROM event_rsvps r JOIN users u ON u.id = r.user_id WHERE r.event_id IN (' . Database::in($eids) . ') ORDER BY r.updated_at ASC',
            $eids
        ) as $r) {
            $rsvps[(int) $r['event_id']][$r['status']][] = ['id' => (int) $r['user_id'], 'name' => $r['display_name']];
        }
        $out = [];
        foreach ($events as $e) {
            $id = (int) $e['id'];
            $allDay = (bool) $e['all_day'];
            $counts = [];
            $names = [];
            $mine = null;
            foreach (self::RSVP as $st) {
                $list = $rsvps[$id][$st] ?? [];
                $counts[$st] = count($list);
                $names[$st] = array_slice(array_column($list, 'name'), 0, 10);
                if (in_array($viewerId, array_column($list, 'id'), true)) {
                    $mine = $st;
                }
            }
            $out[(int) $e['message_id']] = [
                'id' => $id,
                'title' => $e['title'],
                'description' => $e['description'],
                'location' => $e['location'],
                'all_day' => $allDay,
                'starts_at' => $allDay ? null : iso($e['starts_at']),
                'ends_at' => $allDay ? null : iso($e['ends_at']),
                'start_date' => $allDay ? substr((string) $e['starts_at'], 0, 10) : null,
                'end_date' => $allDay ? gmdate('Y-m-d', strtotime(($e['ends_at'] ?? $e['starts_at']) . ' UTC') - ($e['ends_at'] !== null ? 86400 : 0)) : null,
                'cancelled' => $e['cancelled_at'] !== null,
                'sequence' => (int) $e['sequence'],
                'creator_id' => $e['created_by'] ? (int) $e['created_by'] : null,
                'creator_name' => $e['creator_name'],
                'counts' => $counts,
                'names' => $names,
                'mine' => $mine,
            ];
        }
        return $out;
    }

    // ------------------------------------------------------------ Export

    /** @return array{filename:string,body:string} */
    public static function icsFor(int $eventId, int $userId): array
    {
        ['event' => $ev] = self::forUser($eventId, $userId);
        $rows = self::withNames([$ev]);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(strtr($ev['title'], ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'Ä' => 'ae', 'Ö' => 'oe', 'Ü' => 'ue', 'ß' => 'ss']))), '-');
        return [
            'filename' => ($slug !== '' ? substr($slug, 0, 60) : 'termin') . '.ics',
            'body' => Ics::calendar($rows, (string) $ev['title'], true),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $events
     * @return array<int,array<string,mixed>>
     */
    private static function withNames(array $events): array
    {
        $uids = array_values(array_unique(array_filter(array_map(fn ($e) => $e['created_by'] ?? null, $events))));
        $cids = array_values(array_unique(array_map(fn ($e) => (int) $e['conversation_id'], $events)));
        $users = $uids ? array_column(Database::all('SELECT id, display_name FROM users WHERE id IN (' . Database::in($uids) . ')', $uids), 'display_name', 'id') : [];
        $convs = [];
        foreach ($cids as $cid) {
            $c = Chat::conversation($cid);
            $convs[$cid] = $c && $c['type'] === 'group' ? (string) $c['name'] : '';
        }
        foreach ($events as &$e) {
            $e['creator_name'] = $users[(int) $e['created_by']] ?? '';
            $e['conversation_name'] = $convs[(int) $e['conversation_id']] ?? '';
        }
        unset($e);
        return $events;
    }

    // ------------------------------------------------------------ Persönlicher Kalender-Feed

    /** Erzeugt einen neuen geheimen Feed-Link (ein bestehender wird ungültig). Der Klartext wird nur hier zurückgegeben. */
    public static function enableFeed(int $userId): string
    {
        $token = bin2hex(random_bytes(24));
        Database::exec('UPDATE users SET ics_token_hash = ? WHERE id = ?', [hash('sha256', $token), $userId]);
        return $token;
    }

    public static function disableFeed(int $userId): void
    {
        Database::exec('UPDATE users SET ics_token_hash = NULL WHERE id = ?', [$userId]);
    }

    public static function feedEnabled(int $userId): bool
    {
        return (bool) Database::value('SELECT ics_token_hash IS NOT NULL FROM users WHERE id = ?', [$userId]);
    }

    public static function feedUrl(string $token): string
    {
        return base_url() . '/calendar/' . $token . '.ics';
    }

    /** Gesamter Kalender der Person (Termine aus ihren Unterhaltungen, ohne eigene Absagen). */
    public static function feedFor(string $token): ?string
    {
        $token = preg_replace('/\.ics$/i', '', $token) ?? '';
        if (!preg_match('/^[a-f0-9]{48}$/', $token)) {
            return null;
        }
        $user = Database::one('SELECT id, display_name, is_active FROM users WHERE ics_token_hash = ? AND deleted_at IS NULL', [hash('sha256', $token)]);
        if (!$user || !(int) $user['is_active']) {
            return null;
        }
        $uid = (int) $user['id'];
        $events = Database::all(
            "SELECT c.* FROM calendar_events c
             JOIN conversation_members cm ON cm.conversation_id = c.conversation_id AND cm.user_id = ? AND cm.left_at IS NULL
             JOIN messages m ON m.id = c.message_id AND m.deleted_at IS NULL AND m.id > cm.visible_from_message_id
             LEFT JOIN event_rsvps r ON r.event_id = c.id AND r.user_id = ?
             WHERE c.starts_at >= ? AND c.starts_at <= ? AND (r.status IS NULL OR r.status <> 'no')
             ORDER BY c.starts_at ASC LIMIT 1000",
            [$uid, $uid, gmdate('Y-m-d H:i:s', time() - self::FEED_PAST_DAYS * 86400), gmdate('Y-m-d H:i:s', time() + self::FEED_FUTURE_DAYS * 86400)]
        );
        return Ics::calendar(self::withNames($events), Settings::get('company_name') . ' – Termine');
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
