<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Request;
use App\Core\Response;
use App\Core\View;
use App\Services\Auth;
use App\Services\Settings;

final class PageController extends Controller
{
    /** Anwendungs-Shell: die gesamte Oberfläche wird von assets/js/app.js aufgebaut. */
    public function app(Request $r): Response
    {
        $user = $this->user();
        return Response::html(View::render('app', [
            'title' => Settings::get('company_name'),
            'csrf' => Csrf::token(),
            'isAdmin' => $user['role'] === 'admin',
        ], null));
    }

    /** Web-App-Manifest (installierbare PWA). Name und Farbe stammen aus der Branding-Einstellung. */
    public function manifest(Request $r): Response
    {
        $base = app_base_path();
        $name = \App\Services\Settings::get('company_name');
        $accent = \App\Services\Settings::get('accent_color');
        $manifest = [
            'id' => ($base ?: '') . '/',
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => 'Unternehmens-Messenger',
            'lang' => 'de',
            'start_url' => ($base ?: '') . '/',
            'scope' => ($base ?: '') . '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'categories' => ['business', 'productivity', 'social'],
            'background_color' => '#ffffff',
            'theme_color' => preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? $accent : '#2f6fed',
            'icons' => [
                ['src' => url('assets/icons/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => url('assets/icons/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => url('assets/icons/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ];
        return new Response(200, (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), [
            'Content-Type' => 'application/manifest+json; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
