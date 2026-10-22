<?php
declare(strict_types=1);

namespace App\Middleware;

/** Sicherheits-Header. Skripte und Styles stammen ausschliesslich vom eigenen Ursprung (kein CDN, kein Inline-JS). */
final class SecurityHeaders
{
    private static ?string $nonce = null;

    /** Nonce für die wenigen Inline-<style>-Blöcke (Akzentfarbe). */
    public static function nonce(): string
    {
        return self::$nonce ??= rtrim(base64_encode(random_bytes(16)), '=');
    }

    public static function send(): void
    {
        $nonce = self::nonce();
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        // Videokonferenzen: Kamera, Mikrofon und Bildschirmfreigabe nur für den Meeting-Dienst und nur, wenn die Funktion aktiv ist
        $meets = \App\Services\Meets::embedOriginIfEnabled();
        $media = $meets !== null ? '(self "' . $meets . '")' : '()';
        // Das Mikrofon braucht zusätzlich die eigene Seite (Sprachnachrichten)
        $mic = $meets !== null ? $media : '(self)';
        header("Permissions-Policy: camera=$media, microphone=$mic, display-capture=$media, fullscreen=(self), geolocation=(), payment=()");
        header('Cross-Origin-Opener-Policy: same-origin');
        header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'nonce-$nonce'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; connect-src 'self'; " . ($meets !== null ? "frame-src $meets; " : "frame-src 'none'; ") . " form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'");
        if (is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }
}
