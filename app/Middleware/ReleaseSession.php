<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

/** Gibt die Session-Sperre frei, sobald Anmeldung und CSRF geprüft sind (paralleles Polling blockiert sich sonst gegenseitig). */
final class ReleaseSession
{
    public static function handle(Request $request): ?Response
    {
        Session::close();
        return null;
    }
}
