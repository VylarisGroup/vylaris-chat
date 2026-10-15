<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Settings;

/** Seiten für Anmeldung, Registrierung, Passwort-Reset, Verifizierung und Einladungen. */
final class AuthController extends Controller
{
    private function guest(string $template, array $vars = [], int $status = 200): Response
    {
        return $this->view($template, $vars, 'layouts/auth', $status);
    }

    public function showLogin(Request $r): Response
    {
        if (Auth::user()) {
            return $this->redirect('');
        }
        return $this->guest('auth/login', [
            'title' => 'Anmelden',
            'next' => $this->safeNext((string) $r->query('next', '')),
            'notice' => \App\Core\Session::flash('notice'),
            'error' => null,
            'email' => '',
            'registration' => Settings::bool('registration_enabled'),
        ]);
    }

    public function login(Request $r): Response
    {
        $email = $r->str('email', '', 190);
        $next = $this->safeNext($r->str('next', '', 300));
        $res = Auth::attempt($email, $r->str('password', '', 500));
        if (!$res['ok']) {
            return $this->guest('auth/login', [
                'title' => 'Anmelden',
                'next' => $next,
                'notice' => null,
                'error' => $res['error'],
                'email' => $email,
                'registration' => Settings::bool('registration_enabled'),
                'unverified' => $res['unverified'] ?? false,
            ], 422);
        }
        if (!empty($res['needs_2fa'])) {
            $_SESSION['pending_next'] = $next;
            return $this->redirect('login/2fa');
        }
        return Response::redirect(url($next !== '' ? ltrim($next, '/') : ''));
    }

    public function showTwoFactor(Request $r): Response
    {
        if (!Auth::pendingTwoFactorUser()) {
            return $this->redirect('login');
        }
        return $this->guest('auth/two_factor', ['title' => 'Bestätigungscode', 'error' => null]);
    }

    public function twoFactor(Request $r): Response
    {
        if (!Auth::pendingTwoFactorUser()) {
            return $this->redirect('login');
        }
        $err = Auth::completeTwoFactor($r->str('code', '', 40));
        if ($err !== null) {
            return $this->guest('auth/two_factor', ['title' => 'Bestätigungscode', 'error' => $err], 422);
        }
        $next = $this->safeNext((string) ($_SESSION['pending_next'] ?? ''));
        unset($_SESSION['pending_next']);
        return Response::redirect(url($next !== '' ? ltrim($next, '/') : ''));
    }

    public function ssoStart(Request $r): Response
    {
        if (!\App\Services\Oidc::enabled()) {
            return $this->redirect('login');
        }
        try {
            return Response::redirect(\App\Services\Oidc::startUrl($this->safeNext($r->str('next', '', 300))));
        } catch (\Throwable $e) {
            log_error('SSO-Start: ' . $e->getMessage());
            \App\Core\Session::flash('notice', 'Die Anmeldung mit dem Firmenkonto ist derzeit nicht verfügbar.');
            return $this->redirect('login');
        }
    }

    public function ssoCallback(Request $r): Response
    {
        if (!\App\Services\Oidc::enabled()) {
            return $this->redirect('login');
        }
        if ($r->str('error') !== '' || $r->str('code', '', 2000) === '') {
            return $this->ssoFail('Die Anmeldung wurde abgebrochen oder vom Anbieter abgelehnt.');
        }
        $res = \App\Services\Oidc::handleCallback($r->str('code', '', 2000), $r->str('state', '', 200));
        if (!$res['ok']) {
            return $this->ssoFail($res['error']);
        }
        Auth::finishLogin($res['user'], 'sso');
        $next = $this->safeNext($res['next']);
        return Response::redirect(url($next !== '' ? ltrim($next, '/') : ''));
    }

    private function ssoFail(string $msg): Response
    {
        return $this->guest('auth/login', ['title' => 'Anmelden', 'next' => '', 'notice' => null, 'error' => $msg, 'email' => '', 'registration' => Settings::bool('registration_enabled')], 200);
    }

    public function logout(Request $r): Response
    {
        Auth::logout();
        \App\Core\Session::start();
        return $this->redirect('login');
    }

    // ------------------------------------------------------------ Registrierung

    public function showRegister(Request $r): Response
    {
        if (!Settings::bool('registration_enabled')) {
            return $this->guest('auth/message', ['title' => 'Registrierung', 'heading' => 'Registrierung nicht verfügbar', 'text' => 'Die Selbstregistrierung ist deaktiviert. Bitte frage eine Administratorin oder einen Administrator nach einer Einladung.', 'tone' => 'info'], 403);
        }
        return $this->guest('auth/register', ['title' => 'Registrieren', 'error' => null, 'old' => ['email' => '', 'name' => '', 'license_key' => '']]);
    }

    public function register(Request $r): Response
    {
        if (!Settings::bool('registration_enabled')) {
            return $this->showRegister($r);
        }
        $email = mb_strtolower($r->str('email', '', 190));
        $name = $r->str('name', '', 80);
        $old = ['email' => $email, 'name' => $name, 'license_key' => $r->str('license_key', '', 80)];
        if (!\App\Services\RateLimiter::hit('register_ip', client_ip(), 10, 3600)) {
            return $this->guest('auth/register', ['title' => 'Registrieren', 'error' => 'Zu viele Registrierungen von dieser Adresse. Bitte versuche es später erneut.', 'old' => $old], 429);
        }
        if ($r->str('password', '', 500) !== $r->str('password2', '', 500)) {
            return $this->guest('auth/register', ['title' => 'Registrieren', 'error' => 'Die Passwörter stimmen nicht überein.', 'old' => $old], 422);
        }
        if (Auth::validEmail($email) && !Auth::registrationAllowedFor($email)) {
            return $this->guest('auth/register', ['title' => 'Registrieren', 'error' => 'Registrierungen sind nur mit einer freigegebenen Firmen-E-Mail-Adresse möglich.', 'old' => $old], 422);
        }
        if (!\App\Services\RateLimiter::hit('license_key_ip', client_ip(), 10, 3600)) {
            return $this->guest('auth/register', ['title' => 'Registrieren', 'error' => 'Zu viele Versuche. Bitte versuche es später erneut.', 'old' => $old], 429);
        }
        if (!\App\Services\License::keyMatches($old['license_key'])) {
            return $this->guest('auth/register', ['title' => 'Registrieren', 'error' => 'Der Lizenzschlüssel ist ungültig.', 'old' => $old], 422);
        }
        $res = Auth::register($email, $name, $r->str('password', '', 500));
        if (!$res['ok']) {
            return $this->guest('auth/register', ['title' => 'Registrieren', 'error' => $res['error'], 'old' => $old], 422);
        }
        \App\Services\Privacy::acknowledge((int) $res['user_id']);
        if ($res['needs_verification']) {
            return $this->guest('auth/message', ['title' => 'Bestätigung erforderlich', 'heading' => 'Fast geschafft', 'text' => 'Wir haben dir eine E-Mail mit einem Bestätigungslink geschickt. Bitte bestätige deine Adresse, bevor du dich anmeldest.', 'tone' => 'success', 'link' => ['login', 'Zur Anmeldung']]);
        }
        \App\Core\Session::flash('notice', 'Dein Konto wurde erstellt. Du kannst dich jetzt anmelden.');
        return $this->redirect('login');
    }

    public function verify(Request $r): Response
    {
        $ok = Auth::verifyEmail($r->params['token'] ?? '');
        return $this->guest('auth/message', [
            'title' => 'E-Mail bestätigen',
            'heading' => $ok ? 'E-Mail-Adresse bestätigt' : 'Link ungültig',
            'text' => $ok ? 'Danke! Du kannst dich jetzt anmelden.' : 'Der Bestätigungslink ist ungültig oder abgelaufen. Melde dich an, um einen neuen Link anzufordern.',
            'tone' => $ok ? 'success' : 'error',
            'link' => ['login', 'Zur Anmeldung'],
        ], $ok ? 200 : 410);
    }

    public function resendVerification(Request $r): Response
    {
        $email = $r->str('email', '', 190);
        if (\App\Services\RateLimiter::hit('verify_resend', mb_strtolower($email), 3, 3600) && \App\Services\RateLimiter::hit('verify_resend_ip', client_ip(), 10, 3600)) {
            $user = User::findByEmail($email);
            if ($user && $user['email_verified_at'] === null && (int) $user['is_active']) {
                Auth::sendVerification((int) $user['id']);
            }
        }
        return $this->guest('auth/message', ['title' => 'Bestätigung', 'heading' => 'Anfrage erhalten', 'text' => 'Falls ein unbestätigtes Konto mit dieser Adresse existiert, haben wir einen neuen Link gesendet.', 'tone' => 'info', 'link' => ['login', 'Zur Anmeldung']]);
    }

    // ------------------------------------------------------------ Passwort

    public function showForgot(Request $r): Response
    {
        return $this->guest('auth/forgot', ['title' => 'Passwort vergessen', 'sent' => false]);
    }

    public function forgot(Request $r): Response
    {
        Auth::requestReset($r->str('email', '', 190));
        return $this->guest('auth/forgot', ['title' => 'Passwort vergessen', 'sent' => true]);
    }

    public function showReset(Request $r): Response
    {
        $token = $r->params['token'] ?? '';
        if (!Auth::resetTokenValid($token)) {
            return $this->guest('auth/message', ['title' => 'Link ungültig', 'heading' => 'Link ungültig oder abgelaufen', 'text' => 'Bitte fordere einen neuen Link zum Zurücksetzen des Passworts an.', 'tone' => 'error', 'link' => ['forgot', 'Neuen Link anfordern']], 410);
        }
        return $this->guest('auth/reset', ['title' => 'Neues Passwort', 'token' => $token, 'error' => null]);
    }

    public function reset(Request $r): Response
    {
        $token = $r->params['token'] ?? '';
        if ($r->str('password', '', 500) !== $r->str('password2', '', 500)) {
            return $this->guest('auth/reset', ['title' => 'Neues Passwort', 'token' => $token, 'error' => 'Die Passwörter stimmen nicht überein.'], 422);
        }
        $err = Auth::resetPassword($token, $r->str('password', '', 500));
        if ($err !== null) {
            return $this->guest('auth/reset', ['title' => 'Neues Passwort', 'token' => $token, 'error' => $err], 422);
        }
        \App\Core\Session::flash('notice', 'Dein Passwort wurde geändert. Bitte melde dich neu an.');
        return $this->redirect('login');
    }

    // ----------------------------------------------------------- Einladung

    public function showInvite(Request $r): Response
    {
        $inv = Auth::invitationByToken($r->params['token'] ?? '');
        if (!$inv) {
            return $this->guest('auth/message', ['title' => 'Einladung', 'heading' => 'Einladung ungültig', 'text' => 'Diese Einladung ist abgelaufen, wurde widerrufen oder bereits verwendet.', 'tone' => 'error', 'link' => ['login', 'Zur Anmeldung']], 410);
        }
        return $this->guest('auth/invite', ['title' => 'Einladung annehmen', 'email' => $inv['email'], 'token' => $r->params['token'], 'error' => null, 'name' => '']);
    }

    public function acceptInvite(Request $r): Response
    {
        $token = $r->params['token'] ?? '';
        $inv = Auth::invitationByToken($token);
        if (!$inv) {
            return $this->showInvite($r);
        }
        $name = $r->str('name', '', 80);
        if ($r->str('password', '', 500) !== $r->str('password2', '', 500)) {
            return $this->guest('auth/invite', ['title' => 'Einladung annehmen', 'email' => $inv['email'], 'token' => $token, 'error' => 'Die Passwörter stimmen nicht überein.', 'name' => $name], 422);
        }
        $res = Auth::acceptInvitation($token, $name, $r->str('password', '', 500));
        if (!$res['ok']) {
            return $this->guest('auth/invite', ['title' => 'Einladung annehmen', 'email' => $inv['email'], 'token' => $token, 'error' => $res['error'], 'name' => $name], 422);
        }
        \App\Services\Privacy::acknowledge((int) $res['user_id']);
        Audit::log('invitation.completed', (int) $res['user_id']);
        \App\Core\Session::flash('notice', 'Dein Konto ist bereit. Melde dich jetzt an.');
        return $this->redirect('login');
    }
}
