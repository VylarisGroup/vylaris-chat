<?php
declare(strict_types=1);

namespace App\Core;

use App\Services\Auth;

abstract class Controller
{
    /** Angemeldeter Benutzer (Middleware garantiert, dass er existiert). */
    protected function user(): array
    {
        $u = Auth::user();
        if (!$u) {
            throw new HttpException(401, 'Bitte melde dich an.');
        }
        return $u;
    }

    protected function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function view(string $template, array $vars = [], ?string $layout = 'layouts/main', int $status = 200): Response
    {
        return Response::html(View::render($template, $vars, $layout), $status);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect(url($path));
    }

    /** Nur relative Pfade innerhalb der Anwendung zulassen (Open-Redirect-Schutz). */
    protected function safeNext(string $next): string
    {
        if ($next !== '' && $next[0] === '/' && !str_starts_with($next, '//') && !str_contains($next, '\\') && !preg_match('/[\x00-\x1F]/', $next)) {
            return $next;
        }
        return '';
    }
}
