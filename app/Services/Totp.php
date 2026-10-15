<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Zeitbasierte Einmalcodes (TOTP, RFC 6238, HMAC-SHA1, 6 Stellen, 30 s) und Wiederherstellungscodes. */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const RECOVERY_ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';
    public const PERIOD = 30;

    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function base32Encode(string $bin): string
    {
        $bits = '';
        foreach (str_split($bin) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $s = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s) ?? '');
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin((int) strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }

    /** Code für einen Zeitschritt. */
    public static function code(string $base32Secret, int $step, int $digits = 6): string
    {
        $key = self::base32Decode($base32Secret);
        $hash = hash_hmac('sha1', pack('J', $step), $key, true);
        $off = ord($hash[19]) & 0x0F;
        $bin = ((ord($hash[$off]) & 0x7F) << 24) | (ord($hash[$off + 1]) << 16) | (ord($hash[$off + 2]) << 8) | ord($hash[$off + 3]);
        return str_pad((string) ($bin % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /** Prüft einen Code im Fenster ±1 Schritt. Gibt den passenden Schritt zurück oder null. */
    public static function matchStep(string $base32Secret, string $input, ?int $now = null): ?int
    {
        $input = preg_replace('/\s+/', '', $input) ?? '';
        if (!preg_match('/^\d{6}$/', $input)) {
            return null;
        }
        $cur = intdiv($now ?? time(), self::PERIOD);
        foreach ([0, -1, 1] as $d) {
            if (hash_equals(self::code($base32Secret, $cur + $d), $input)) {
                return $cur + $d;
            }
        }
        return null;
    }

    public static function uri(string $secret, string $account, string $issuer): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer . ':' . $account) . '?secret=' . $secret . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=' . self::PERIOD;
    }

    // ---------------------------------------------------------- Konto-Funktionen

    public static function isEnabled(array $user): bool
    {
        return !empty($user['totp_enabled_at']) && !empty($user['totp_secret_enc']);
    }

    /** Neues (noch nicht aktives) Geheimnis anlegen und zurückgeben. */
    public static function beginSetup(int $userId): string
    {
        $secret = self::generateSecret();
        Database::exec('UPDATE users SET totp_secret_enc = ?, totp_enabled_at = NULL, totp_last_step = 0 WHERE id = ?', [Crypto::encrypt($secret), $userId]);
        return $secret;
    }

    public static function pendingSecret(array $user): ?string
    {
        if (empty($user['totp_secret_enc']) || !empty($user['totp_enabled_at'])) {
            return null;
        }
        $s = Crypto::decrypt((string) $user['totp_secret_enc']);
        return $s !== '' ? $s : null;
    }

    /** Aktiviert nach erfolgreicher Prüfung des ersten Codes. @return string[]|null Wiederherstellungscodes oder null bei falschem Code */
    public static function enable(array $user, string $code): ?array
    {
        $secret = self::pendingSecret($user);
        if ($secret === null) {
            return null;
        }
        $step = self::matchStep($secret, $code);
        if ($step === null) {
            return null;
        }
        Database::exec('UPDATE users SET totp_enabled_at = ?, totp_last_step = ? WHERE id = ?', [now(), $step, $user['id']]);
        Audit::log('2fa.enabled', (int) $user['id']);
        return self::newRecoveryCodes((int) $user['id']);
    }

    public static function disable(int $userId, string $reason = 'self'): void
    {
        Database::exec('UPDATE users SET totp_secret_enc = NULL, totp_enabled_at = NULL, totp_last_step = 0 WHERE id = ?', [$userId]);
        Database::exec('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
        Audit::log('2fa.disabled', $reason === 'self' ? $userId : null, 'user', $userId, $reason);
    }

    /** @return string[] */
    public static function newRecoveryCodes(int $userId): array
    {
        Database::exec('DELETE FROM user_recovery_codes WHERE user_id = ?', [$userId]);
        $codes = [];
        for ($i = 0; $i < 10; $i++) {
            $raw = '';
            for ($j = 0; $j < 8; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4);
            Database::exec('INSERT INTO user_recovery_codes (user_id, code_hash, created_at) VALUES (?,?,?)', [$userId, hash('sha256', $raw), now()]);
        }
        return $codes;
    }

    public static function recoveryLeft(int $userId): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = ? AND used_at IS NULL', [$userId]);
    }

    /**
     * Prüft Authenticator- oder Wiederherstellungscode (jeweils nur einmal verwendbar, Wiederverwendung eines Zeitschritts wird abgelehnt).
     */
    public static function verifyLogin(array $user, string $input): bool
    {
        $uid = (int) $user['id'];
        if (!RateLimiter::hit('totp', (string) $uid, 8, 900)) {
            return false;
        }
        $input = trim($input);
        $secret = Crypto::decrypt((string) ($user['totp_secret_enc'] ?? ''));
        if ($secret !== '' && preg_match('/^\d{3}\s?\d{3}$/', $input)) {
            $step = self::matchStep($secret, $input);
            if ($step !== null) {
                // atomar: Schritt muss neu sein (Replay-Schutz)
                $ok = Database::exec('UPDATE users SET totp_last_step = ? WHERE id = ? AND totp_last_step < ?', [$step, $uid, $step]);
                if ($ok > 0) {
                    RateLimiter::clear('totp', (string) $uid);
                    return true;
                }
            }
            return false;
        }
        $raw = strtolower(str_replace(['-', ' '], '', $input));
        if (preg_match('/^[a-z0-9]{8}$/', $raw)) {
            $used = Database::exec('UPDATE user_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL', [now(), $uid, hash('sha256', $raw)]);
            if ($used > 0) {
                Audit::log('2fa.recovery_used', $uid);
                RateLimiter::clear('totp', (string) $uid);
                return true;
            }
        }
        return false;
    }
}
