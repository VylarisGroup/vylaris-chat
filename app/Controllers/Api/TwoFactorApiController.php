<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use App\Services\RateLimiter;
use App\Services\Settings;
use App\Services\Totp;

final class TwoFactorApiController extends Controller
{
    private function confirm(array $u, Request $r): void
    {
        if (!RateLimiter::hit('2fa_manage', (string) $u['id'], 10, 900)) {
            throw new HttpException(429, 'Zu viele Versuche. Bitte warte kurz.');
        }
        if (!password_verify($r->str('password', '', 500), $u['password_hash'])) {
            throw new HttpException(422, 'Das Passwort ist falsch.');
        }
    }

    public function status(Request $r): Response
    {
        $u = $this->user();
        return $this->json([
            'enabled' => Totp::isEnabled($u),
            'recovery_left' => Totp::isEnabled($u) ? Totp::recoveryLeft((int) $u['id']) : 0,
            'required' => $u['role'] === 'admin' && Settings::bool('require_2fa_admins'),
        ]);
    }

    public function recovery(Request $r): Response
    {
        $u = $this->user();
        if (!Totp::isEnabled($u)) {
            throw new HttpException(422, 'Die Zwei-Faktor-Authentifizierung ist nicht aktiv.');
        }
        $this->confirm($u, $r);
        \App\Services\Audit::log('2fa.recovery_regenerated', (int) $u['id']);
        return $this->json(['codes' => Totp::newRecoveryCodes((int) $u['id'])]);
    }

    public function disable(Request $r): Response
    {
        $u = $this->user();
        if (Auth::mustSetupTwoFactor(array_merge($u, ['totp_enabled_at' => null, 'totp_secret_enc' => null]))) {
            throw new HttpException(403, 'Für Administratorkonten ist die Zwei-Faktor-Authentifizierung vorgeschrieben.');
        }
        $this->confirm($u, $r);
        Totp::disable((int) $u['id']);
        return $this->json(['ok' => true]);
    }
}
