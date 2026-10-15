<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Csrf;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** CSRF-Token und Origin-Prüfung für alle zustandsändernden Anfragen. */
final class VerifyCsrf
{
    public static function handle(Request $request): ?Response
    {
        if (in_array($request->method, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }
        if (!Csrf::originOk($request) || !Csrf::valid($request)) {
            throw new HttpException(419, 'Die Sitzung ist abgelaufen oder die Anfrage ist ungültig. Bitte lade die Seite neu.');
        }
        return null;
    }
}
