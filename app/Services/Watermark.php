<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Response;

/**
 * Herkunftshinweis «powered by VylarisChat» am Fuss jeder Seitenleiste.
 *
 * Der Hinweis ist fest im Code verankert und nicht über Einstellungen, Datenbank oder Konfiguration veränderbar.
 * Jede ausgelieferte Seite mit Seitenleiste läuft durch guard(): Eine geänderte Vorlage wird auf den Originaltext
 * zurückgesetzt, eine fehlende ergänzt. Im Messenger zeichnet der Browser den Hinweis aus der vom Server gelieferten
 * Vorlage und prüft ihn laufend (public/assets/js/mark.js). Der Systemtest prüft denselben Aufbau.
 */
final class Watermark
{
    public const URL = 'https://vylaris.ch';
    public const PREFIX = 'powered by ';
    public const NAME = 'VylarisChat';

    /** @param string $extra Zusätzliche CSS-Klasse (nur für den Ersatz-Hinweis) */
    public static function html(string $extra = ''): string
    {
        return '<a class="vl-mark' . ($extra !== '' ? ' ' . $extra : '') . '" href="' . self::URL . '" target="_blank" rel="noopener" data-vl-mark title="VylarisChat – vylaris.ch">'
            . self::PREFIX . '<strong>' . self::NAME . '</strong></a>';
    }

    /** Seiten mit Seitenleiste erkennen (Messenger und Administration). */
    private static function hasSidebar(string $html): bool
    {
        return str_contains($html, 'id="app"') || str_contains($html, 'data-admin-nav');
    }

    /** Stellt sicher, dass der Hinweis in ausgelieferten Seiten vorhanden und unverändert ist. */
    public static function guard(Response $response): Response
    {
        if ($response->file !== null || $response->body === '' || !str_contains((string) ($response->headers['Content-Type'] ?? ''), 'text/html')) {
            return $response;
        }
        $html = $response->body;
        if (!self::hasSidebar($html)) {
            return $response;
        }
        // Veränderte Hinweise auf den Originaltext zurücksetzen
        $html = preg_replace_callback('~<a\b[^>]*\bdata-vl-mark\b[^>]*>.*?</a>~is', static function (array $m): string {
            return str_contains($m[0], 'vl-mark--fixed') ? self::html('vl-mark--fixed') : self::html();
        }, $html) ?? $html;
        $count = substr_count($html, 'data-vl-mark');
        if ($count === 0) {
            $isApp = str_contains($html, 'id="app"');
            $inject = $isApp ? '<template id="vl-mark-tpl">' . self::html() . '</template>' : self::html('vl-mark--fixed');
            $pos = strripos($html, '</body>');
            $html = $pos !== false ? substr($html, 0, $pos) . $inject . substr($html, $pos) : $html . $inject;
        } elseif (str_contains($html, 'id="app"') && !str_contains($html, 'id="vl-mark-tpl"')) {
            // Messenger: Vorlage für die Seitenleiste fehlt
            $pos = strripos($html, '</body>');
            $tpl = '<template id="vl-mark-tpl">' . self::html() . '</template>';
            $html = $pos !== false ? substr($html, 0, $pos) . $tpl . substr($html, $pos) : $html . $tpl;
        }
        $response->body = $html;
        return $response;
    }
}
