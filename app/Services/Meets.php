<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\HttpException;

/**
 * Videokonferenzen über die Meets-API von TheRedStonee (https://api.theredstonee.de/api/meets/v1).
 * Der API-Schlüssel bleibt auf dem Server (verschlüsselt gespeichert). Der Browser erhält nur kurzlebige, signierte
 * Einladungslinks. Medien laufen über den Server des Anbieters; der Messenger speichert nur Raum-Code und Status.
 */
final class Meets
{
    public const API_BASE = 'https://api.theredstonee.de/api/meets/v1';
    public const EMBED_ORIGIN = 'https://meets.theredstonee.de';
    public const ROOM_MINUTES = 480;   // Ein Meeting endet spätestens nach 8 Stunden
    public const LINK_MINUTES = 10;    // Einladungslinks werden sofort benutzt

    // ------------------------------------------------------------ Einstellungen

    public static function key(): string
    {
        $set = Crypto::decrypt(Settings::get('meets_key_enc'));
        return $set !== '' ? $set : trim((string) Config::get('meets.api_key', ''));
    }

    public static function webhookSecret(): string
    {
        $set = Crypto::decrypt(Settings::get('meets_webhook_secret_enc'));
        return $set !== '' ? $set : trim((string) Config::get('meets.webhook_secret', ''));
    }

    public static function enabled(): bool
    {
        return Settings::bool('meets_enabled') && self::key() !== '';
    }

    /** Für Tests und eigene Installationen überschreibbar (config.php: 'meets' => ['api_base' => …]). Nur HTTPS, lokal auch HTTP. */
    public static function apiBase(): string
    {
        $base = rtrim((string) Config::get('meets.api_base', self::API_BASE), '/');
        $p = parse_url($base);
        $local = in_array($p['host'] ?? '', ['127.0.0.1', 'localhost'], true);
        if (($p['scheme'] ?? '') !== 'https' && !(($p['scheme'] ?? '') === 'http' && $local)) {
            return self::API_BASE;
        }
        return $base;
    }

    public static function embedOrigin(): string
    {
        $o = rtrim((string) Config::get('meets.embed_origin', self::EMBED_ORIGIN), '/');
        $p = parse_url($o);
        $local = in_array($p['host'] ?? '', ['127.0.0.1', 'localhost'], true);
        if (($p['scheme'] ?? '') !== 'https' && !(($p['scheme'] ?? '') === 'http' && $local)) {
            return self::EMBED_ORIGIN;
        }
        return $o;
    }

    /** Teil der Sicherheits-Header: erlaubt dem Messenger die Einbettung und die Freigabe von Kamera und Mikrofon. Ohne Funktion leer. */
    public static function embedOriginIfEnabled(): ?string
    {
        try {
            return self::enabled() ? self::embedOrigin() : null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ----------------------------------------------------------------- HTTP

    /**
     * @return array{0:int,1:array<string,mixed>} HTTP-Status und JSON-Antwort
     * @throws HttpException bei Netzwerkfehlern
     */
    public static function request(string $method, string $path, ?array $body = null, ?string $key = null): array
    {
        $key ??= self::key();
        if ($key === '') {
            throw new HttpException(503, 'Videokonferenzen sind nicht eingerichtet.');
        }
        if (!function_exists('curl_init') || !function_exists('curl_exec')) {
            throw new HttpException(503, 'Auf diesem Server fehlt die PHP-Erweiterung curl.');
        }
        $ch = curl_init(self::apiBase() . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $key, 'Accept: application/json', 'Content-Type: application/json', 'User-Agent: Messenger/' . APP_VERSION],
            CURLOPT_POSTFIELDS => $body !== null ? json_encode($body, JSON_UNESCAPED_UNICODE) : null,
        ]);
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $status === 0) {
            log_error('Meets: Verbindung fehlgeschlagen: ' . $err);
            throw new HttpException(502, 'Der Meeting-Dienst ist gerade nicht erreichbar. Bitte versuche es später erneut.');
        }
        $json = json_decode((string) $raw, true);
        return [$status, is_array($json) ? $json : []];
    }

    /** Wie request(), übersetzt Fehler der API in verständliche Meldungen. */
    private static function call(string $method, string $path, ?array $body = null): array
    {
        [$status, $json] = self::request($method, $path, $body);
        if ($status >= 200 && $status < 300) {
            return $json;
        }
        $code = (string) ($json['data']['code'] ?? '');
        log_error("Meets: $method $path -> $status $code");
        throw match (true) {
            $status === 401 => new HttpException(502, 'Der API-Schlüssel für Videokonferenzen wurde abgelehnt. Bitte die Administration informieren.'),
            $code === 'room_limit' => new HttpException(409, 'Es laufen bereits zu viele Meetings. Bitte beende zuerst eines.'),
            $code === 'room_not_found' => new HttpException(410, 'Das Meeting ist beendet.'),
            $status === 429 => new HttpException(429, 'Zu viele Anfragen an den Meeting-Dienst. Bitte warte einen Moment.'),
            default => new HttpException(502, 'Der Meeting-Dienst hat die Anfrage abgelehnt. Bitte versuche es später erneut.'),
        };
    }

    /** Verbindungstest für die Administration: prüft den Schlüssel mit GET /me. */
    public static function test(?string $key = null): array
    {
        try {
            [$status, $json] = self::request('GET', '/me', null, $key);
        } catch (HttpException $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        if ($status === 401) {
            return ['ok' => false, 'error' => 'Der Schlüssel wurde abgelehnt.'];
        }
        if ($status !== 200 || empty($json['key'])) {
            return ['ok' => false, 'error' => "Unerwartete Antwort (HTTP $status)."];
        }
        return ['ok' => true, 'key' => $json['key']];
    }

    // ------------------------------------------------------------ Regeln

    /** Starten und Moderieren: Direktchats alle Mitglieder, Gruppen nur Moderation. Gäste nie. */
    public static function canHost(array $user, array $member, array $conv): bool
    {
        if (($user['role'] ?? '') === 'guest') {
            return false;
        }
        return $conv['type'] === 'direct' || Chat::canModerate($member);
    }

    public static function isActive(array $m): bool
    {
        return $m['ended_at'] === null && strtotime($m['expires_at'] . ' UTC') > time();
    }

    private static function forUser(int $meetingId, int $userId): array
    {
        $m = Database::one('SELECT mt.*, msg.deleted_at FROM meetings mt JOIN messages msg ON msg.id = mt.message_id WHERE mt.id = ?', [$meetingId]);
        if (!$m || $m['deleted_at'] !== null) {
            throw new HttpException(404, 'Meeting nicht gefunden.');
        }
        $member = Chat::membership((int) $m['conversation_id'], $userId);
        if (!$member || (int) $m['message_id'] <= (int) $member['visible_from_message_id']) {
            throw new HttpException(404, 'Meeting nicht gefunden.');
        }
        return ['meeting' => $m, 'member' => $member, 'conv' => Chat::conversation((int) $m['conversation_id'])];
    }

    private static function assertEnabled(): void
    {
        if (!self::enabled()) {
            throw new HttpException(404, 'Videokonferenzen sind nicht aktiviert.');
        }
    }

    private static function title(array $conv, array $user): string
    {
        if ($conv['type'] === 'group') {
            return mb_substr('Meeting: ' . (string) $conv['name'], 0, 120);
        }
        return mb_substr('Meeting mit ' . (string) $user['display_name'], 0, 120);
    }

    // ----------------------------------------------------------- Aktionen

    /**
     * Meeting in einer Unterhaltung starten. Läuft dort schon eines, wird dieses zurückgegeben (existing = true).
     * @return array{message:array,meeting:array,link:array,existing:bool}
     */
    public static function start(array $user, int $conversationId): array
    {
        self::assertEnabled();
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        $member = Chat::requireMember($conversationId, $uid);
        $conv = Chat::conversation($conversationId) ?? throw new HttpException(404, 'Unterhaltung nicht gefunden.');
        if (!self::canHost($user, $member, $conv)) {
            throw new HttpException(403, $user['role'] === 'guest' ? 'Gäste können keine Meetings starten.' : 'In Gruppen können nur Moderatorinnen und Moderatoren ein Meeting starten.');
        }
        if (!RateLimiter::hit('meet_start', (string) $uid, 10, 600)) {
            throw new HttpException(429, 'Du hast zu viele Meetings gestartet. Bitte warte einen Moment.');
        }
        $lock = 'meets_start_' . $conversationId;
        Database::value('SELECT GET_LOCK(?, 8)', [$lock]);
        try {
            $active = Database::one('SELECT * FROM meetings WHERE conversation_id = ? AND ended_at IS NULL AND expires_at > ? ORDER BY id DESC LIMIT 1', [$conversationId, now()]);
            if ($active) {
                $res = self::join($user, (int) $active['id']);
                return $res + ['existing' => true];
            }
            $room = self::call('POST', '/rooms', [
                'title' => self::title($conv, $user),
                'waitingRoom' => false,
                'maxParticipants' => 0,
                'expiresInMinutes' => self::ROOM_MINUTES,
                'metadata' => ['app' => 'messenger', 'conversation' => (string) $conversationId],
            ])['room'] ?? null;
            $code = (string) ($room['code'] ?? '');
            if (!preg_match('/^[a-z0-9][a-z0-9\-]{3,59}$/', $code)) {
                throw new HttpException(502, 'Der Meeting-Dienst hat eine unerwartete Antwort geliefert.');
            }
            $expires = isset($room['expiresAt']) && strtotime((string) $room['expiresAt']) ? gmdate('Y-m-d H:i:s', strtotime((string) $room['expiresAt'])) : gmdate('Y-m-d H:i:s', time() + self::ROOM_MINUTES * 60);
            try {
                $row = Messages::create($user, $conversationId, 'Videomeeting gestartet', null, null, false, static function (int $messageId) use ($conversationId, $code, $uid, $expires): void {
                    Database::insert(
                        'INSERT INTO meetings (conversation_id, message_id, room_code, started_by, started_at, expires_at) VALUES (?,?,?,?,?,?)',
                        [$conversationId, $messageId, $code, $uid, now(), $expires]
                    );
                }, 'meeting');
            } catch (\Throwable $e) {
                try { self::call('DELETE', '/rooms/' . rawurlencode($code)); } catch (\Throwable) { /* Raum läuft von selbst ab */ }
                throw $e;
            }
        } finally {
            Database::value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
        $link = self::link($user, $code, true);
        self::ring($user, $conv, (int) $row['id'], $conversationId);
        return [
            'message' => Messages::hydrate([$row], $uid)[0],
            'meeting' => self::views([$row], $uid)[(int) $row['id']],
            'link' => $link,
            'existing' => false,
        ] ;
    }

    /**
     * Geräte der anderen Mitglieder «klingeln» lassen: Push mit Typ INCOMING_CALL (Annehmen / Ablehnen).
     * Kein Push an Personen, die die App gerade sichtbar offen haben (dort erscheint die Karte sofort), an stummgeschaltete
     * Gruppen und an Konten mit eingeschränkter Verarbeitung. Der Text nennt nur den Namen, nie Inhalte.
     */
    private static function ring(array $user, array $conv, int $messageId, int $conversationId): void
    {
        if (!Settings::bool('meets_ring') || !Push::enabled()) {
            return;
        }
        $meetingId = (int) Database::value('SELECT id FROM meetings WHERE message_id = ?', [$messageId]);
        $members = Database::all(
            'SELECT cm.user_id, cm.muted, u.last_visible_at, u.processing_restricted_at FROM conversation_members cm
             JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = ? AND cm.left_at IS NULL AND cm.user_id <> ? AND u.is_active = 1',
            [$conversationId, (int) $user['id']]
        );
        $caller = (string) $user['display_name'];
        foreach ($members as $m) {
            if ($m['processing_restricted_at'] !== null || ((int) $m['muted'] && $conv['type'] === 'group')) {
                continue;
            }
            if (!empty($m['last_visible_at']) && (time() - strtotime($m['last_visible_at'] . ' UTC')) < Push::VISIBLE_WINDOW) {
                continue;
            }
            $uid = (int) $m['user_id'];
            $p = Push::payload($uid, $conv['type'] === 'group' ? $caller . ' startet ein Videomeeting in „' . $conv['name'] . '“.' : $caller . ' ruft dich per Video an.', $conversationId, $messageId);
            $p['type'] = 'INCOMING_CALL';
            $p['callerName'] = $caller;
            $p['callId'] = $meetingId;
            $p['convId'] = $conversationId;
            $p['tag'] = 'meet-' . $meetingId;
            $p['url'] = '?c=' . $conversationId . '&meeting=' . $meetingId;
            Push::notify($uid, $p);
        }
    }

    /** @return array{link:array,meeting:array,message:array} */
    public static function join(array $user, int $meetingId): array
    {
        self::assertEnabled();
        Privacy::assertNotRestricted($user);
        $uid = (int) $user['id'];
        ['meeting' => $m, 'member' => $member, 'conv' => $conv] = self::forUser($meetingId, $uid);
        if (!self::isActive($m)) {
            throw new HttpException(410, 'Das Meeting ist beendet.');
        }
        if (!RateLimiter::hit('meet_join', (string) $uid, 30, 600)) {
            throw new HttpException(429, 'Zu viele Beitritte. Bitte warte einen Moment.');
        }
        try {
            $link = self::link($user, (string) $m['room_code'], self::canHost($user, $member, $conv));
        } catch (HttpException $e) {
            if ($e->getCode() === 410) {
                self::markEnded((int) $m['id'], 'expired');
            }
            throw $e;
        }
        $row = Messages::find((int) $m['message_id']);
        return ['link' => $link, 'meeting' => self::views([$row], $uid)[(int) $row['id']], 'message' => Messages::hydrate([$row], $uid)[0]];
    }

    /** Persönlicher, kurzlebiger Einladungslink mit festem Namen. */
    private static function link(array $user, string $code, bool $host): array
    {
        $res = self::call('POST', '/rooms/' . rawurlencode($code) . '/links', [
            'role' => $host ? 'host' : 'guest',
            'name' => mb_substr(trim((string) $user['display_name']), 0, 60),
            'expiresInMinutes' => self::LINK_MINUTES,
        ]);
        $link = $res['link'] ?? [];
        $origin = self::embedOrigin();
        foreach (['url', 'embedUrl'] as $k) {
            // Nur Adressen des Meeting-Dienstes weitergeben (kein offener Verweis durch eine manipulierte Antwort)
            if (empty($link[$k]) || !str_starts_with((string) $link[$k], $origin . '/')) {
                throw new HttpException(502, 'Der Meeting-Dienst hat eine unerwartete Antwort geliefert.');
            }
        }
        return ['url' => (string) $link['url'], 'embed_url' => (string) $link['embedUrl'], 'role' => $host ? 'host' : 'guest'];
    }

    /** Meeting beenden: Starter sowie, wer Meetings dieser Unterhaltung starten darf. */
    public static function end(array $user, int $meetingId): array
    {
        self::assertEnabled();
        $uid = (int) $user['id'];
        ['meeting' => $m, 'member' => $member, 'conv' => $conv] = self::forUser($meetingId, $uid);
        if ((int) $m['started_by'] !== $uid && !self::canHost($user, $member, $conv)) {
            throw new HttpException(403, 'Nur die Person, die das Meeting gestartet hat, oder die Moderation kann es beenden.');
        }
        if (self::isActive($m)) {
            try {
                self::call('DELETE', '/rooms/' . rawurlencode((string) $m['room_code']));
            } catch (HttpException $e) {
                if ($e->getCode() !== 410) {
                    throw $e;
                }
            }
            self::markEnded((int) $m['id'], 'host');
        }
        $row = Messages::find((int) $m['message_id']);
        return ['message' => Messages::hydrate([$row], $uid)[0]];
    }

    /** Aktueller Stand eines Meetings (mit Abgleich beim Dienst, höchstens alle 10 Sekunden). */
    public static function status(array $user, int $meetingId): array
    {
        $uid = (int) $user['id'];
        ['meeting' => $m] = self::forUser($meetingId, $uid);
        if (self::isActive($m) && self::enabled() && (!$m['last_sync_at'] || strtotime($m['last_sync_at'] . ' UTC') < time() - 10)) {
            self::sync($m);
        } elseif ($m['ended_at'] === null && !self::isActive($m)) {
            self::markEnded((int) $m['id'], 'expired');
        }
        $row = Messages::find((int) $m['message_id']);
        return ['message' => Messages::hydrate([$row], $uid)[0]];
    }

    // -------------------------------------------------- Zustand und Webhook

    public static function markEnded(int $meetingId, string $reason): void
    {
        $reason = in_array($reason, ['api', 'host', 'admin', 'expired'], true) ? $reason : 'expired';
        $n = Database::exec('UPDATE meetings SET ended_at = ?, ended_reason = ?, participants = 0 WHERE id = ? AND ended_at IS NULL', [now(), $reason, $meetingId]);
        if ($n > 0) {
            $m = Database::one('SELECT conversation_id, message_id FROM meetings WHERE id = ?', [$meetingId]);
            if ($m) {
                Events::push('message.meeting', (int) $m['conversation_id'], (int) $m['message_id']);
            }
        }
    }

    /** Teilnehmerzahl beim Dienst abfragen; ist der Raum weg, gilt das Meeting als beendet. */
    public static function sync(array $m): void
    {
        Database::exec('UPDATE meetings SET last_sync_at = ? WHERE id = ?', [now(), $m['id']]);
        try {
            $res = self::call('GET', '/rooms/' . rawurlencode((string) $m['room_code']));
        } catch (HttpException $e) {
            if ($e->getCode() === 410) {
                self::markEnded((int) $m['id'], 'expired');
            }
            return;
        }
        $count = max(0, min(65535, (int) ($res['room']['participantCount'] ?? count($res['participants'] ?? []))));
        self::setParticipants($m, $count);
    }

    private static function setParticipants(array $m, int $count): void
    {
        if ((int) $m['participants'] === $count) {
            return;
        }
        Database::exec('UPDATE meetings SET participants = ?, peak = GREATEST(peak, ?) WHERE id = ? AND ended_at IS NULL', [$count, $count, $m['id']]);
        Events::push('message.meeting', (int) $m['conversation_id'], (int) $m['message_id']);
    }

    /**
     * Webhook des Dienstes. Prüft Signatur (HMAC-SHA256 über "<Zeitstempel>.<Rumpf>") und Alter (höchstens 5 Minuten).
     * Alle Ereignisse sind idempotent, doppelte Zustellungen schaden nicht.
     * @return int HTTP-Status
     */
    public static function handleWebhook(string $raw, string $timestamp, string $signature): int
    {
        $secret = self::webhookSecret();
        if ($secret === '' || !ctype_digit($timestamp) || abs(time() - (int) $timestamp) > 300) {
            return 401;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $raw, $secret);
        if (!hash_equals($expected, $signature)) {
            return 401;
        }
        $payload = json_decode($raw, true);
        if (!is_array($payload) || !is_string($payload['event'] ?? null)) {
            return 400;
        }
        $code = (string) ($payload['data']['room']['code'] ?? '');
        $m = $code !== '' ? Database::one('SELECT * FROM meetings WHERE room_code = ?', [$code]) : null;
        if (!$m) {
            return 204; // fremder oder bereits gelöschter Raum: nichts zu tun
        }
        switch ($payload['event']) {
            case 'room.ended':
                self::markEnded((int) $m['id'], (string) ($payload['data']['reason'] ?? 'expired'));
                break;
            case 'participant.joined':
            case 'participant.left':
                if ($m['ended_at'] === null) {
                    self::sync($m);
                }
                break;
        }
        return 204;
    }

    /** Hintergrundlauf: abgelaufene Meetings schliessen. */
    public static function expireOld(): int
    {
        $rows = Database::all('SELECT id FROM meetings WHERE ended_at IS NULL AND expires_at <= ? LIMIT 200', [now()]);
        foreach ($rows as $r) {
            self::markEnded((int) $r['id'], 'expired');
        }
        return count($rows);
    }

    // ------------------------------------------------------------- Ansicht

    /**
     * @param array<int,array<string,mixed>> $messageRows Nachrichten der Art "meeting"
     * @return array<int,array<string,mixed>> je Nachrichten-ID
     */
    public static function views(array $messageRows, int $viewerId): array
    {
        if (!$messageRows) {
            return [];
        }
        $mids = array_map(fn ($r) => (int) $r['id'], $messageRows);
        $rows = Database::all(
            'SELECT mt.*, u.display_name AS starter_name, c.type AS conv_type FROM meetings mt
             JOIN conversations c ON c.id = mt.conversation_id LEFT JOIN users u ON u.id = mt.started_by
             WHERE mt.message_id IN (' . Database::in($mids) . ')',
            $mids
        );
        if (!$rows) {
            return [];
        }
        $cids = array_values(array_unique(array_map(fn ($r) => (int) $r['conversation_id'], $rows)));
        $roles = array_column(Database::all('SELECT conversation_id, role FROM conversation_members WHERE user_id = ? AND left_at IS NULL AND conversation_id IN (' . Database::in($cids) . ')', array_merge([$viewerId], $cids)), 'role', 'conversation_id');
        $viewerRole = (string) Database::value('SELECT role FROM users WHERE id = ?', [$viewerId]);
        $out = [];
        foreach ($rows as $r) {
            $active = self::isActive($r);
            $role = $roles[(int) $r['conversation_id']] ?? null;
            $canHost = $viewerRole !== 'guest' && $role !== null && ($r['conv_type'] === 'direct' || in_array($role, ['admin', 'moderator'], true));
            $out[(int) $r['message_id']] = [
                'id' => (int) $r['id'],
                'active' => $active,
                'started_at' => iso($r['started_at']),
                'ended_at' => $active ? null : iso($r['ended_at'] ?? $r['expires_at']),
                'participants' => $active ? (int) $r['participants'] : 0,
                'peak' => (int) $r['peak'],
                'starter_id' => $r['started_by'] ? (int) $r['started_by'] : null,
                'starter_name' => $r['starter_name'],
                'can_end' => $active && ($canHost || (int) $r['started_by'] === $viewerId),
            ];
        }
        return $out;
    }
}
