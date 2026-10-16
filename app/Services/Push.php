<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Deferred;

/**
 * Web-Push mit VAPID (RFC 8292) und verschlüsselter Nutzlast (RFC 8291, aes128gcm). Die Push-Dienste von Google, Mozilla
 * oder Apple sehen nur verschlüsselte Bytes; lesen kann sie ausschliesslich das Gerät der Person. Die Nutzlast enthält
 * nur den Hinweistext («Neue Nachricht von …»), nie den Nachrichteninhalt. Geräte ohne gespeicherte Schlüssel
 * (ältere Abos) erhalten ein leeres Signal; der Service Worker holt dann den Text von GET /api/push/latest.
 */
final class Push
{
    /** So lange (Sekunden) gilt die App nach der letzten Sichtbarkeits-Meldung als geöffnet; deckt das längste Poll-Intervall ab. */
    public const VISIBLE_WINDOW = 30;

    /** Erlaubte Push-Dienste (SSRF-Schutz: der Server ruft nur diese Hosts auf). */
    private const HOST_SUFFIXES = [
        'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com',
        'notify.windows.com', 'wns.windows.com',
    ];

    /** Zusätzliche Hosts nur für Tests (config.php: 'push' => ['test_hosts' => ['127.0.0.1']]); dort ist auch http erlaubt. */
    private static function testHosts(): array
    {
        $h = \App\Core\Config::get('push.test_hosts', []);
        return is_array($h) ? array_map('strval', $h) : [];
    }

    public static function enabled(): bool
    {
        return License::has('push') && Settings::bool('push_enabled') && Settings::get('vapid_public') !== '' && Settings::get('vapid_private_enc') !== '' && function_exists('curl_init');
    }

    public static function allowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (!$p || empty($p['host']) || isset($p['user']) || strlen($endpoint) > 1000) {
            return false;
        }
        $host = strtolower($p['host']);
        if (in_array($host, self::testHosts(), true) && in_array($p['scheme'] ?? '', ['http', 'https'], true)) {
            return true;
        }
        if (($p['scheme'] ?? '') !== 'https') {
            return false;
        }
        foreach (self::HOST_SUFFIXES as $suf) {
            if ($host === $suf || str_ends_with($host, '.' . $suf)) {
                return true;
            }
        }
        return false;
    }

    public static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** Erzeugt bei Bedarf das VAPID-Schlüsselpaar (P-256). */
    public static function ensureKeys(): bool
    {
        if (Settings::get('vapid_public') !== '' && Settings::get('vapid_private_enc') !== '') {
            return true;
        }
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if (!$key) {
            return false;
        }
        openssl_pkey_export($key, $pem);
        $d = openssl_pkey_get_details($key);
        $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        Settings::set('vapid_public', self::b64($pub));
        Settings::set('vapid_private_enc', Crypto::encrypt($pem));
        return true;
    }

    /** DER-ECDSA-Signatur in die 64-Byte-Form (R||S) für ES256 umwandeln. */
    public static function derToRaw(string $der): string
    {
        $off = 2;
        if (ord($der[1]) & 0x80) {
            $off += ord($der[1]) & 0x7f;
        }
        $parts = [];
        for ($i = 0; $i < 2; $i++) {
            $len = ord($der[$off + 1]);
            $int = substr($der, $off + 2, $len);
            $parts[] = str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
            $off += 2 + $len;
        }
        return $parts[0] . $parts[1];
    }

    public static function jwt(string $audience, ?int $now = null): string
    {
        $contact = Settings::get('privacy_contact_email') ?: Settings::get('smtp_from_email');
        $sub = $contact !== '' && Auth::validEmail($contact) ? 'mailto:' . $contact : base_url();
        $h = self::b64((string) json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64((string) json_encode(['aud' => $audience, 'exp' => ($now ?? time()) + 12 * 3600, 'sub' => $sub]));
        $key = openssl_pkey_get_private(Crypto::decrypt(Settings::get('vapid_private_enc')));
        if (!$key || !openssl_sign("$h.$c", $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new \RuntimeException('VAPID-Signatur fehlgeschlagen.');
        }
        return "$h.$c." . self::b64(self::derToRaw($der));
    }

    public static function b64d(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }

    /** Gültiger öffentlicher Geräteschlüssel (65 Byte, unkomprimiert) und Auth-Geheimnis (16 Byte)? */
    public static function validKeys(?string $p256dh, ?string $auth): bool
    {
        if ($p256dh === null || $auth === null || $p256dh === '' || $auth === '') {
            return false;
        }
        $k = self::b64d($p256dh);
        return strlen($k) === 65 && $k[0] === "\x04" && strlen(self::b64d($auth)) === 16;
    }

    public static function subscribe(int $userId, string $endpoint, string $userAgent, ?string $p256dh = null, ?string $auth = null): bool
    {
        if (!self::allowedEndpoint($endpoint)) {
            return false;
        }
        $keys = self::validKeys($p256dh, $auth);
        Database::exec(
            'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, p256dh, auth_secret, user_agent, created_at) VALUES (?,?,?,?,?,?,?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), fail_count = 0, last_error = NULL, last_error_at = NULL,
               p256dh = COALESCE(VALUES(p256dh), p256dh), auth_secret = COALESCE(VALUES(auth_secret), auth_secret), user_agent = VALUES(user_agent)',
            [$userId, hash('sha256', $endpoint), $endpoint, $keys ? $p256dh : null, $keys ? $auth : null, mb_substr($userAgent, 0, 255), now()]
        );
        return true;
    }

    /**
     * Das Gerät hat seine Push-Adresse erneuert (pushsubscriptionchange). Autorisiert wird über die Kenntnis der alten Adresse,
     * da der Service Worker keine Sitzung und kein CSRF-Token hat.
     */
    public static function resubscribe(string $oldEndpoint, string $endpoint, ?string $p256dh, ?string $auth, string $userAgent): bool
    {
        if ($oldEndpoint === '' || !self::allowedEndpoint($endpoint)) {
            return false;
        }
        $row = Database::one('SELECT id, user_id FROM push_subscriptions WHERE endpoint_hash = ?', [hash('sha256', $oldEndpoint)]);
        if (!$row) {
            return false;
        }
        Database::exec('DELETE FROM push_subscriptions WHERE endpoint_hash = ? AND id <> ?', [hash('sha256', $endpoint), $row['id']]);
        $keys = self::validKeys($p256dh, $auth);
        Database::exec(
            'UPDATE push_subscriptions SET endpoint_hash = ?, endpoint = ?, p256dh = ?, auth_secret = ?, fail_count = 0, last_error = NULL, last_error_at = NULL, user_agent = ? WHERE id = ?',
            [hash('sha256', $endpoint), $endpoint, $keys ? $p256dh : null, $keys ? $auth : null, mb_substr($userAgent, 0, 255), $row['id']]
        );
        return true;
    }

    public static function unsubscribe(int $userId, string $endpoint): void
    {
        Database::exec('DELETE FROM push_subscriptions WHERE user_id = ? AND endpoint_hash = ?', [$userId, hash('sha256', $endpoint)]);
    }

    public static function countFor(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?', [$userId]);
    }

    // ------------------------------------------------------------ Verschlüsselung (RFC 8291 / RFC 8188, aes128gcm)

    private const SPKI_P256 = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    private static function pemFromPoint(string $point65): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(self::SPKI_P256) . $point65), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * @param string $p256dh Öffentlicher Schlüssel des Geräts (base64url, 65 Byte)
     * @param string $auth   Auth-Geheimnis des Geräts (base64url, 16 Byte)
     * @param mixed  $ephemeral Nur für Tests: vorgegebenes Schlüsselpaar (OpenSSL-Schlüssel)
     */
    public static function encrypt(string $plaintext, string $p256dh, string $auth, ?string $salt = null, $ephemeral = null): string
    {
        $ua = self::b64d($p256dh);
        $authSecret = self::b64d($auth);
        if (strlen($ua) !== 65 || strlen($authSecret) !== 16) {
            throw new \InvalidArgumentException('Ungültige Geräteschlüssel.');
        }
        if (strlen($plaintext) > 3800) {
            throw new \InvalidArgumentException('Nutzlast zu gross.');
        }
        $eph = $ephemeral ?? openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $d = openssl_pkey_get_details($eph);
        $asPublic = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $peer = openssl_pkey_get_public(self::pemFromPoint($ua));
        $shared = $peer ? openssl_pkey_derive($peer, $eph, 32) : false;
        if ($shared === false) {
            throw new \RuntimeException('Schlüsselvereinbarung fehlgeschlagen.');
        }
        $salt ??= random_bytes(16);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua . $asPublic, $authSecret);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $tag = '';
        $cipher = openssl_encrypt($plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Verschlüsselung fehlgeschlagen.');
        }
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** Gegenstück zu encrypt() (für Tests und Diagnose). @param mixed $uaPrivate OpenSSL-Schlüssel des Geräts */
    public static function decrypt(string $body, $uaPrivate, string $auth): string
    {
        $salt = substr($body, 0, 16);
        $idlen = ord($body[20]);
        $asPublic = substr($body, 21, $idlen);
        $cipher = substr($body, 21 + $idlen);
        $d = openssl_pkey_get_details($uaPrivate);
        $ua = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $peer = openssl_pkey_get_public(self::pemFromPoint($asPublic));
        $shared = openssl_pkey_derive($peer, $uaPrivate, 32);
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $ua . $asPublic, self::b64d($auth));
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $plain = openssl_decrypt(substr($cipher, 0, -16), 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, substr($cipher, -16));
        if ($plain === false) {
            throw new \RuntimeException('Entschlüsselung fehlgeschlagen.');
        }
        return substr(rtrim($plain, "\0"), 0, -1);
    }

    // ------------------------------------------------------------ Versand

    /**
     * Hinweistext für ein Gerät (nie Nachrichteninhalt).
     * @return array{title:string,body:string,url:string,tag:string,badge:int}
     */
    public static function payload(int $userId, string $body, int $convId = 0, int $msgId = 0): array
    {
        $unread = (int) Database::value('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$userId]);
        return [
            'title' => Settings::get('company_name'),
            'body' => mb_substr($body, 0, 200),
            'url' => $convId > 0 ? '?c=' . $convId . ($msgId > 0 ? '&m=' . $msgId : '') : '',
            'tag' => $convId > 0 ? 'conv-' . $convId : 'msgr-generic',
            'badge' => max(1, $unread),
        ];
    }

    /** Stösst für die Person einen Push an allen Geräten an (nach der Antwort, im Hintergrund). */
    public static function notify(int $userId, ?array $payload = null): void
    {
        if (!self::enabled() || self::countFor($userId) === 0) {
            return;
        }
        $payload ??= self::payload($userId, 'Neue Aktivität im Messenger');
        Deferred::add(static fn () => self::sendNow($userId, $payload));
    }

    /** @return list<string> Für Push benötigte, aber nicht nutzbare PHP-Funktionen. */
    public static function missingFunctions(): array
    {
        $out = [];
        foreach (['curl_init', 'curl_multi_init', 'curl_multi_exec', 'curl_multi_select', 'curl_multi_getcontent', 'openssl_pkey_derive', 'openssl_pkey_new', 'openssl_sign', 'openssl_encrypt', 'hash_hkdf', 'random_bytes'] as $f) {
            if (!function_exists($f)) {
                $out[] = $f;
            }
        }
        return $out;
    }

    /**
     * Sendet parallel an alle Geräte der Person.
     * @return list<array{id:int,ua:string,status:int,ok:bool,error:string,encrypted:bool}>
     */
    public static function sendDetailed(int $userId, ?array $payload = null): array
    {
        $payload ??= self::payload($userId, 'Neue Aktivität im Messenger');
        $missing = self::missingFunctions();
        if ($missing !== []) {
            // Manche Hoster (z. B. KeyHelp mit eingeschränkter PHP-Konfiguration) sperren Funktionen über disable_functions.
            $msg = 'PHP-Funktionen gesperrt oder fehlen: ' . implode(', ', $missing) . ' (disable_functions bzw. Erweiterung curl/openssl prüfen).';
            log_error('Push: ' . $msg);
            return array_map(static fn (array $x): array => ['id' => (int) $x['id'], 'ua' => (string) $x['user_agent'], 'status' => 0, 'ok' => false, 'error' => $msg, 'encrypted' => false], Database::all('SELECT id, user_agent FROM push_subscriptions WHERE user_id = ?', [$userId]));
        }
        $subs = Database::all('SELECT id, endpoint, p256dh, auth_secret, fail_count, user_agent FROM push_subscriptions WHERE user_id = ?', [$userId]);
        $mh = curl_multi_init();
        $jobs = [];
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        foreach ($subs as $s) {
            $p = parse_url($s['endpoint']);
            $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            $row = ['id' => (int) $s['id'], 'ua' => (string) $s['user_agent'], 'status' => 0, 'ok' => false, 'error' => '', 'encrypted' => false, 'fail' => (int) $s['fail_count']];
            try {
                $jwt = self::jwt($aud);
                $headers = ['Authorization: vapid t=' . $jwt . ', k=' . Settings::get('vapid_public'), 'TTL: 86400', 'Urgency: high'];
                if (preg_match('/^[A-Za-z0-9_-]{1,32}$/', (string) $payload['tag'])) {
                    $headers[] = 'Topic: ' . $payload['tag'];
                }
                if (self::validKeys($s['p256dh'], $s['auth_secret'])) {
                    $body = self::encrypt($json, (string) $s['p256dh'], (string) $s['auth_secret']);
                    $headers[] = 'Content-Encoding: aes128gcm';
                    $headers[] = 'Content-Type: application/octet-stream';
                    $row['encrypted'] = true;
                } else {
                    $body = '';
                    $headers[] = 'Content-Length: 0';
                }
            } catch (\Throwable $e) {
                log_error('Push: ' . $e->getMessage());
                $row['error'] = $e->getMessage();
                $jobs[] = [$row, null];
                continue;
            }
            $ch = curl_init($s['endpoint']);
            $http = ($p['scheme'] ?? '') === 'http';
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => $http ? (CURLPROTO_HTTP | CURLPROTO_HTTPS) : CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            curl_multi_add_handle($mh, $ch);
            $jobs[] = [$row, $ch];
        }
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $results = [];
        foreach ($jobs as [$row, $ch]) {
            if ($ch !== null) {
                $row['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $resp = (string) curl_multi_getcontent($ch);
                $err = curl_error($ch);
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                $row['ok'] = $row['status'] >= 200 && $row['status'] < 300;
                if (!$row['ok']) {
                    $row['error'] = $row['status'] === 0 ? ($err !== '' ? $err : 'keine Antwort') : 'HTTP ' . $row['status'] . ($resp !== '' ? ': ' . mb_substr(preg_replace('/\s+/', ' ', $resp) ?? '', 0, 160) : '');
                }
            }
            if ($row['ok']) {
                Database::exec('UPDATE push_subscriptions SET last_sent_at = ?, last_status = ?, fail_count = 0, last_error = NULL, last_error_at = NULL WHERE id = ?', [now(), $row['status'], $row['id']]);
            } elseif (in_array($row['status'], [404, 410], true)) {
                // Das Gerät hat sich abgemeldet oder das Abo ist abgelaufen.
                Database::exec('DELETE FROM push_subscriptions WHERE id = ?', [$row['id']]);
            } else {
                // Alles andere (auch 401/403 bei fehlerhafter Konfiguration) löscht das Abo nicht sofort, sondern wird protokolliert.
                log_error('Push: Gerät ' . $row['id'] . ' – ' . $row['error']);
                $dead = $row['fail'] + 1 >= 20;
                if ($dead) {
                    Database::exec('DELETE FROM push_subscriptions WHERE id = ?', [$row['id']]);
                } else {
                    Database::exec('UPDATE push_subscriptions SET fail_count = fail_count + 1, last_status = ?, last_error = ?, last_error_at = ? WHERE id = ?', [$row['status'], mb_substr($row['error'], 0, 255), now(), $row['id']]);
                }
            }
            $results[] = $row;
        }
        curl_multi_close($mh);
        return $results;
    }

    public static function sendNow(int $userId, ?array $payload = null): int
    {
        return count(array_filter(self::sendDetailed($userId, $payload), static fn (array $r): bool => $r['ok']));
    }
}
