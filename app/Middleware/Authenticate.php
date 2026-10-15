<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Auth;

/** Erzwingt eine gültige Anmeldung. API-Aufrufe erhalten 401, Seiten werden zum Login umgeleitet. */
final class Authenticate
{
    public static function handle(Request $request): ?Response
    {
        $user = Auth::user();
        if (!$user) {
            if ($request->wantsJson()) {
                throw new HttpException(401, 'Bitte melde dich an.');
            }
            return Response::redirect(url('login'));
        }
        // Pflichtschritte: Passwortwechsel (Ablauf/Admin-Vorgabe) bzw. 2FA-Einrichtung für Administratoren
        $gate = null;
        if (!empty($user['must_change_password'])) {
            $gate = 'password-change';
        } elseif (Auth::mustSetupTwoFactor($user)) {
            $gate = 'security/2fa';
        }
        if ($gate !== null) {
            $path = '/' . trim($request->path, '/');
            if ($path === '/' . $gate || $path === '/logout') {
                return null;
            }
            if ($request->wantsJson() || str_starts_with($path, '/api/')) {
                throw new HttpException(403, $gate === 'password-change' ? 'Bitte lege zuerst ein neues Passwort fest.' : 'Bitte richte zuerst die Zwei-Faktor-Authentifizierung ein.');
            }
            return Response::redirect(url($gate));
        }
        return null;
    }

    /** Schreibsperre auf der Session lösen (nach Auth und CSRF), damit Polling nicht blockiert. */
    public static function releaseSession(): void
    {
        Session::close();
    }
}
