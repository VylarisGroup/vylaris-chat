<?php
declare(strict_types=1);

namespace App\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf'];
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function valid(Request $request): bool
    {
        $expected = $_SESSION['csrf'] ?? '';
        if ($expected === '') {
            return false;
        }
        $given = $request->header('X-CSRF-Token') ?: (string) $request->input('_csrf', '');
        return $given !== '' && hash_equals($expected, $given);
    }

    /** Zusätzliche Origin-Prüfung gegen Cross-Site-Anfragen. */
    public static function originOk(Request $request): bool
    {
        $origin = $request->header('Origin');
        if ($origin === '') {
            return true; // Same-Origin-GET-Navigation oder Nicht-Browser-Client; Token wird separat geprüft
        }
        $originHost = parse_url($origin, PHP_URL_HOST);
        $originPort = parse_url($origin, PHP_URL_PORT);
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $originCmp = $originHost . ($originPort ? ':' . $originPort : '');
        return $originHost !== null && strcasecmp($originCmp, $host) === 0;
    }
}
