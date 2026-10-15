<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;

/**
 * Verschlüsselt Geheimnisse in der Datenbank (z. B. SMTP-Passwort) mit libsodium (secretbox).
 * Schlüssel: app.key aus config/config.php. Das ist KEINE Ende-zu-Ende-Verschlüsselung von Nachrichten.
 */
final class Crypto
{
    private static function key(): string
    {
        $raw = (string) Config::get('app.key', '');
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) < 32) {
            throw new \RuntimeException('app.key fehlt oder ist zu kurz.');
        }
        return sodium_crypto_generichash($decoded, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        if ($plain === '') {
            return '';
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(string $cipher): string
    {
        if ($cipher === '') {
            return '';
        }
        $bin = base64_decode($cipher, true);
        if ($bin === false || strlen($bin) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return '';
        }
        $nonce = substr($bin, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(substr($bin, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, self::key());
        return $plain === false ? '' : $plain;
    }
}
