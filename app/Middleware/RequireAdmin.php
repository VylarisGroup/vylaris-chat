<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Auth;

/** Zugriff nur für Administratoren (rollenbasiert, serverseitig). */
final class RequireAdmin
{
    public static function handle(Request $request): ?Response
    {
        $user = Auth::user();
        if (!$user || $user['role'] !== 'admin') {
            Audit::log('admin.denied', $user ? (int) $user['id'] : null, null, null, $request->method . ' ' . $request->path);
            throw new HttpException(403, 'Dafür fehlt dir die Berechtigung.');
        }
        return null;
    }
}
