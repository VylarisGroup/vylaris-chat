<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Crypto;
use App\Services\Oidc;
use App\Services\RateLimiter;
use App\Services\Settings;

/** Sicherheitsrichtlinien: 2FA-Pflicht, Passwort-Ablauf, Sperre nach Fehlversuchen, Einmalanmeldung (OIDC). */
final class AdminSecurityController extends Controller
{
    private function back(?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect('admin/security');
    }

    public function index(Request $r): Response
    {
        $users = Database::all('SELECT id, email, display_name, role, is_active, totp_enabled_at, must_change_password, password_changed_at, created_at, sso_subject FROM users WHERE deleted_at IS NULL ORDER BY display_name ASC LIMIT 1000');
        [$max, $win] = Auth::lockoutPolicy();
        $locked = [];
        $maxAge = Settings::int('password_max_age_days');
        $expired = [];
        $stats = ['total' => 0, 'tfa' => 0, 'admins' => 0, 'admins_tfa' => 0, 'sso' => 0];
        foreach ($users as $u) {
            if (!(int) $u['is_active']) {
                continue;
            }
            $stats['total']++;
            $stats['tfa'] += $u['totp_enabled_at'] ? 1 : 0;
            $stats['sso'] += $u['sso_subject'] ? 1 : 0;
            if ($u['role'] === 'admin') {
                $stats['admins']++;
                $stats['admins_tfa'] += ($u['totp_enabled_at'] || $u['sso_subject']) ? 1 : 0;
            }
            if (RateLimiter::blocked('login_acct', $u['email'], $max, $win) || RateLimiter::blocked('totp', (string) $u['id'], 8, 900)) {
                $locked[] = $u;
            }
            if ($maxAge > 0 && !$u['sso_subject']) {
                $changed = strtotime(((string) ($u['password_changed_at'] ?: $u['created_at'])) . ' UTC');
                if ($changed + $maxAge * 86400 < time()) {
                    $expired[] = $u;
                }
            }
        }
        $recentFails = (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'login.failed' AND created_at > ?", [gmdate('Y-m-d H:i:s', time() - 86400)]);
        return $this->view('admin/security', [
            'section' => 'security',
            'title' => 'Sicherheit',
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
            's' => Settings::all(),
            'stats' => $stats,
            'locked' => $locked,
            'expired' => $expired,
            'recentFails' => $recentFails,
            'redirectUri' => Oidc::redirectUri(),
            'hasSecret' => Settings::get('oidc_client_secret_enc') !== '',
            'curl' => function_exists('curl_init'),
            'oidcInfo' => (Oidc::enabled() || Settings::get('oidc_issuer') !== '') ? Oidc::describe() : null,
        ], 'layouts/main');
    }

    public function savePolicy(Request $r): Response
    {
        $admin = $this->user();
        Settings::set('require_2fa_admins', $r->str('require_2fa_admins') === '1' ? '1' : '0');
        Settings::set('password_max_age_days', (string) max(0, min(1095, (int) $r->str('password_max_age_days', '0', 5))));
        Settings::set('lockout_attempts', (string) max(3, min(20, (int) $r->str('lockout_attempts', '8', 3))));
        Settings::set('lockout_minutes', (string) max(1, min(1440, (int) $r->str('lockout_minutes', '15', 5))));
        Audit::log('admin.settings_security', (int) $admin['id']);
        return $this->back('Sicherheitsrichtlinien gespeichert.');
    }

    public function saveOidc(Request $r): Response
    {
        $admin = $this->user();
        $issuer = Oidc::normalizeIssuer($r->str('oidc_issuer', '', 300));
        if ($issuer !== '' && !preg_match('#^https://[^\s]+$#', $issuer)) {
            return $this->back(null, 'Der Aussteller (Issuer) muss eine https-Adresse sein.');
        }
        Settings::set('oidc_issuer', $issuer);
        Settings::set('oidc_client_id', $r->str('oidc_client_id', '', 300));
        $secret = $r->str('oidc_client_secret', '', 500);
        if ($secret !== '') {
            Settings::set('oidc_client_secret_enc', Crypto::encrypt($secret));
        }
        Settings::set('oidc_button', $r->str('oidc_button', '', 60) ?: 'Mit Firmenkonto anmelden');
        Settings::set('oidc_autocreate', $r->str('oidc_autocreate') === '1' ? '1' : '0');
        Settings::set('oidc_domains', $r->str('oidc_domains', '', 500));
        Settings::set('oidc_auth_method', in_array($r->str('oidc_auth_method'), ['basic', 'post'], true) ? $r->str('oidc_auth_method') : 'auto');
        Settings::set('oidc_enabled', $r->str('oidc_enabled') === '1' ? '1' : '0');
        Audit::log('admin.settings_oidc', (int) $admin['id']);
        @array_map('unlink', glob(storage_path('cache') . '/oidc-*.json') ?: []);
        if (Settings::bool('oidc_enabled') && !Oidc::enabled()) {
            return $this->back(null, 'SSO ist nicht vollständig konfiguriert (Aussteller, Client-ID und Secret nötig).');
        }
        if (Oidc::enabled()) {
            try {
                Oidc::discovery();
            } catch (\Throwable $e) {
                return $this->back('Gespeichert.', 'Warnung: ' . $e->getMessage());
            }
        }
        return $this->back('Einmalanmeldung gespeichert.');
    }

    public function unlock(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        $u = Database::one('SELECT id, email FROM users WHERE id = ? AND deleted_at IS NULL', [$id]);
        if ($u) {
            RateLimiter::clear('login_acct', $u['email']);
            RateLimiter::clear('totp', (string) $id);
            Audit::log('user.unlocked', (int) $admin['id'], 'user', $id);
        }
        return $this->back('Sperre aufgehoben.');
    }
}
