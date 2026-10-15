<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Deferred;

/**
 * Web-Push ohne Nutzlast (VAPID, RFC 8292): Die Push-Dienste von Google, Mozilla oder Apple sehen nur "es gibt etwas",
 * nie Absender oder Inhalt. Der Service Worker holt den Hinweistext anschliessend selbst von diesem Server
 * (GET /api/push/latest, mit der Sitzung der Person). Der Text enthält nie den Nachrichteninhalt.
 */
final class Push
{
    /** Erlaubte Push-Dienste (SSRF-Schutz: der Server ruft nur diese Hosts auf). */
    private const HOST_SUFFIXES = [
        'fcm.googleapis.com', 'android.googleapis.com', 'push.services.mozilla.com', 'push.apple.com',
        'notify.windows.com', 'wns.windows.com',
    ];

    public static function enabled(): bool
    {
        return License::has('push') && Settings::bool('push_enabled') && Settings::get('vapid_public') !== '' && Settings::get('vapid_private_enc') !== '' && function_exists('curl_init');
    }

    public static function allowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (!$p || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || strlen($endpoint) > 1000) {
            return false;
        }
        $host = strtolower($p['host']);
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

    public static function subscribe(int $userId, string $endpoint, string $userAgent): bool
    {
        if (!self::allowedEndpoint($endpoint)) {
            return false;
        }
        $hash = hash('sha256', $endpoint);
        Database::exec(
            'INSERT INTO push_subscriptions (user_id, endpoint_hash, endpoint, user_agent, created_at) VALUES (?,?,?,?,?)
             ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), fail_count = 0',
            [$userId, $hash, $endpoint, mb_substr($userAgent, 0, 255), now()]
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

    /** Stösst für die Person einen Push an allen Geräten an (nach der Antwort, im Hintergrund). */
    public static function notify(int $userId): void
    {
        if (!self::enabled() || self::countFor($userId) === 0) {
            return;
        }
        Deferred::add(static fn () => self::sendNow($userId));
    }

    public static function sendNow(int $userId): int
    {
        $sent = 0;
        foreach (Database::all('SELECT id, endpoint, fail_count FROM push_subscriptions WHERE user_id = ?', [$userId]) as $s) {
            $p = parse_url($s['endpoint']);
            $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
            try {
                $jwt = self::jwt($aud);
            } catch (\Throwable $e) {
                log_error('Push: ' . $e->getMessage());
                return $sent;
            }
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => '',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Authorization: vapid t=' . $jwt . ', k=' . Settings::get('vapid_public'), 'TTL: 3600', 'Urgency: normal', 'Content-Length: 0'],
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($code >= 200 && $code < 300) {
                $sent++;
                Database::exec('UPDATE push_subscriptions SET last_sent_at = ?, fail_count = 0 WHERE id = ?', [now(), $s['id']]);
            } elseif ($code === 404 || $code === 410 || $code === 401 || $code === 403 || ($s['fail_count'] + 1) >= 5) {
                Database::exec('DELETE FROM push_subscriptions WHERE id = ?', [$s['id']]);
            } else {
                Database::exec('UPDATE push_subscriptions SET fail_count = fail_count + 1 WHERE id = ?', [$s['id']]);
            }
        }
        return $sent;
    }
}
