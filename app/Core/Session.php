<?php
declare(strict_types=1);

namespace App\Core;

/** Sichere Session-Initialisierung (HttpOnly, SameSite, Secure bei HTTPS, Strict Mode). */
final class Session
{
    public const LIFETIME = 1209600; // 14 Tage, gleitend

    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $dir = storage_path('sessions');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            session_save_path($dir);
        }
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) self::LIFETIME);
        session_name('msgr_sid');
        session_set_cookie_params([
            'lifetime' => self::LIFETIME,
            'path' => app_base_path() === '' ? '/' : app_base_path() . '/',
            'secure' => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    /** Schreibsperre früh lösen, damit parallele Requests (Polling) nicht blockieren. */
    public static function close(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    public static function flash(string $key, mixed $value = null): mixed
    {
        if ($value !== null) {
            $_SESSION['_flash'][$key] = $value;
            return null;
        }
        $v = $_SESSION['_flash'][$key] ?? null;
        unset($_SESSION['_flash'][$key]);
        return $v;
    }
}
