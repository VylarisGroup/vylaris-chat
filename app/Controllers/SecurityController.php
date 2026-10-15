<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use App\Services\Settings;
use App\Services\Totp;

/** Serverseitig gerenderte Pflichtschritte: Passwortwechsel und 2FA-Einrichtung. */
final class SecurityController extends Controller
{
    private function page(string $template, array $vars, int $status = 200): Response
    {
        return $this->view($template, $vars, 'layouts/auth', $status);
    }

    private function pwReason(): string
    {
        return 'Aus Sicherheitsgründen musst du jetzt ein neues Passwort festlegen (abgelaufen oder von einer Administratorin bzw. einem Administrator verlangt).';
    }

    public function showPasswordChange(Request $r): Response
    {
        $this->user();
        return $this->page('auth/password_change', ['title' => 'Neues Passwort', 'reason' => $this->pwReason(), 'error' => null]);
    }

    public function passwordChange(Request $r): Response
    {
        $u = $this->user();
        $new = $r->str('new', '', 500);
        $err = $new !== $r->str('repeat', '', 500)
            ? 'Die neuen Passwörter stimmen nicht überein.'
            : ($new === $r->str('current', '', 500) ? 'Das neue Passwort muss sich vom alten unterscheiden.' : Auth::changePassword($u, $r->str('current', '', 500), $new));
        if ($err !== null) {
            return $this->page('auth/password_change', ['title' => 'Neues Passwort', 'reason' => $this->pwReason(), 'error' => $err], 422);
        }
        return $this->redirect('');
    }

    private function setupVars(array $u, ?string $error = null): array
    {
        $fresh = \App\Models\User::find((int) $u['id']);
        $secret = Totp::pendingSecret($fresh) ?? Totp::beginSetup((int) $u['id']);
        return [
            'title' => 'Zwei-Faktor-Authentifizierung',
            'secret' => $secret,
            'uri' => Totp::uri($secret, $u['email'], Settings::get('company_name')),
            'error' => $error,
            'forced' => Auth::mustSetupTwoFactor($u),
        ];
    }

    public function showTwoFactorSetup(Request $r): Response
    {
        $u = $this->user();
        if (Totp::isEnabled($u)) {
            return $this->redirect('');
        }
        return $this->page('auth/two_factor_setup', $this->setupVars($u));
    }

    public function twoFactorSetup(Request $r): Response
    {
        $u = $this->user();
        if (Totp::isEnabled($u)) {
            return $this->redirect('');
        }
        $codes = Totp::enable(\App\Models\User::find((int) $u['id']), $r->str('code', '', 20));
        if ($codes === null) {
            return $this->page('auth/two_factor_setup', $this->setupVars($u, 'Der Code ist falsch. Prüfe die Uhrzeit deines Geräts und versuche es erneut.'), 422);
        }
        return $this->page('auth/two_factor_setup', ['title' => 'Zwei-Faktor-Authentifizierung', 'codes' => $codes]);
    }
}
