<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\User;

/**
 * Einmalanmeldung per OpenID Connect (Authorization-Code-Flow mit PKCE, state und nonce).
 * Funktioniert mit Entra ID (Azure AD), Keycloak, Google Workspace, Okta u. a.
 * SAML wird nicht unterstützt.
 *
 * Der id_token wird direkt vom Token-Endpunkt über TLS bezogen; gemäss OIDC Core §3.1.3.7 ist dann keine
 * zusätzliche Signaturprüfung nötig. Geprüft werden Aussteller (iss), Zielgruppe (aud), Ablauf (exp) und nonce.
 */
final class Oidc
{
    public static function enabled(): bool
    {
        return License::has('sso') && Settings::bool('oidc_enabled') && Settings::get('oidc_issuer') !== '' && Settings::get('oidc_client_id') !== '' && Settings::get('oidc_client_secret_enc') !== '';
    }

    /** Akzeptiert Issuer- oder vollständige Discovery-URL und liefert den Issuer ohne Suffix und Schrägstrich. */
    public static function normalizeIssuer(string $url): string
    {
        $url = trim($url);
        $url = preg_replace('#/\.well-known/openid-configuration/?$#i', '', $url) ?? $url;
        return rtrim($url, '/');
    }

    /**
     * Fasst die automatisch gelesene Konfiguration des Anbieters zusammen und prüft die Kompatibilität.
     *
     * @return array{ok:bool,info:array<string,string>,warnings:string[],error?:string}
     */
    public static function describe(): array
    {
        try {
            $d = self::discovery();
        } catch (\Throwable $e) {
            return ['ok' => false, 'info' => [], 'warnings' => [], 'error' => $e->getMessage()];
        }
        $w = [];
        if (!in_array('S256', (array) ($d['code_challenge_methods_supported'] ?? ['S256']), true)) {
            $w[] = 'Der Anbieter meldet kein PKCE (S256).';
        }
        if (isset($d['response_types_supported']) && !in_array('code', (array) $d['response_types_supported'], true)) {
            $w[] = 'Der Anbieter unterstützt den Authorization-Code-Flow nicht.';
        }
        $m = (array) ($d['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
        if (!in_array('client_secret_post', $m, true) && !in_array('client_secret_basic', $m, true)) {
            $w[] = 'Keine unterstützte Client-Authentifizierung (client_secret_basic/post).';
        }
        $scopes = (array) ($d['scopes_supported'] ?? []);
        foreach (['openid', 'email'] as $sc) {
            if ($scopes && !in_array($sc, $scopes, true)) {
                $w[] = "Scope „$sc“ wird nicht angeboten.";
            }
        }
        $info = [
            'Aussteller' => (string) $d['issuer'],
            'Anmeldung' => (string) $d['authorization_endpoint'],
            'Token' => (string) $d['token_endpoint'],
            'Benutzerinfo' => (string) ($d['userinfo_endpoint'] ?? '–'),
            'Client-Authentifizierung' => implode(', ', $m),
            'PKCE' => implode(', ', (array) ($d['code_challenge_methods_supported'] ?? ['nicht angegeben'])),
            'ID-Token-Signatur' => implode(', ', (array) ($d['id_token_signing_alg_values_supported'] ?? ['–'])),
        ];
        return ['ok' => true, 'info' => $info, 'warnings' => $w];
    }

    private static function http(string $url, ?array $post = null, array $headers = []): array
    {
        if (!str_starts_with($url, 'https://') && !(str_starts_with($url, 'http://localhost') || str_starts_with($url, 'http://127.0.0.1'))) {
            throw new \RuntimeException('Nur HTTPS-Endpunkte sind erlaubt.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        ]);
        if ($post !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException('Verbindung zum Identitätsanbieter fehlgeschlagen: ' . $err);
        }
        $json = json_decode((string) $body, true);
        return ['code' => $code, 'json' => is_array($json) ? $json : [], 'raw' => mb_substr(trim((string) $body), 0, 300)];
    }

    /** @return array<string,mixed> */
    public static function discovery(): array
    {
        $issuer = self::normalizeIssuer(Settings::get('oidc_issuer'));
        $cache = storage_path('cache/oidc-' . substr(hash('sha256', $issuer), 0, 12) . '.json');
        if (is_file($cache) && filemtime($cache) > time() - 3600) {
            $c = json_decode((string) file_get_contents($cache), true);
            if (is_array($c) && !empty($c['authorization_endpoint'])) {
                return $c;
            }
        }
        $res = self::http($issuer . '/.well-known/openid-configuration');
        $d = $res['json'];
        if ($res['code'] !== 200 || empty($d['authorization_endpoint']) || empty($d['token_endpoint']) || empty($d['issuer'])) {
            throw new \RuntimeException('OIDC-Konfiguration des Anbieters nicht lesbar.');
        }
        if (rtrim((string) $d['issuer'], '/') !== $issuer) {
            throw new \RuntimeException('Der Aussteller in der Konfiguration stimmt nicht mit der Einstellung überein.');
        }
        @file_put_contents($cache, json_encode($d));
        return $d;
    }

    public static function redirectUri(): string
    {
        return base_url() . '/login/sso/callback';
    }

    private static function b64(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** URL, zu der der Browser umgeleitet wird. Speichert state/nonce/verifier in der Session. */
    public static function startUrl(string $next = ''): string
    {
        $d = self::discovery();
        $state = self::b64(random_bytes(24));
        $nonce = self::b64(random_bytes(24));
        $verifier = self::b64(random_bytes(48));
        $_SESSION['oidc'] = ['state' => $state, 'nonce' => $nonce, 'verifier' => $verifier, 'next' => $next, 'exp' => time() + 600];
        return $d['authorization_endpoint'] . (str_contains($d['authorization_endpoint'], '?') ? '&' : '?') . http_build_query([
            'response_type' => 'code',
            'client_id' => Settings::get('oidc_client_id'),
            'redirect_uri' => self::redirectUri(),
            'scope' => 'openid email profile',
            'state' => $state,
            'nonce' => $nonce,
            'code_challenge' => self::b64(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Verarbeitet die Rückkehr vom Anbieter.
     *
     * @return array{ok:bool,error?:string,user?:array,next?:string}
     */
    public static function handleCallback(string $code, string $state): array
    {
        $s = $_SESSION['oidc'] ?? null;
        unset($_SESSION['oidc']);
        if (!is_array($s) || ($s['exp'] ?? 0) < time() || !hash_equals((string) $s['state'], $state)) {
            return ['ok' => false, 'error' => 'Die Anmeldung ist abgelaufen oder ungültig. Bitte versuche es erneut.'];
        }
        if (!RateLimiter::hit('sso_cb', client_ip(), 30, 900)) {
            return ['ok' => false, 'error' => 'Zu viele Versuche. Bitte warte einige Minuten.'];
        }
        try {
            $d = self::discovery();
            $secret = Crypto::decrypt(Settings::get('oidc_client_secret_enc'));
            $methods = (array) ($d['token_endpoint_auth_methods_supported'] ?? ['client_secret_basic']);
            $form = [
                'grant_type' => 'authorization_code',
                'code' => $code,
                'redirect_uri' => self::redirectUri(),
                'client_id' => Settings::get('oidc_client_id'),
                'code_verifier' => $s['verifier'],
            ];
            $headers = [];
            $pref = Settings::get('oidc_auth_method');
            $canBasic = in_array('client_secret_basic', $methods, true);
            $canPost = in_array('client_secret_post', $methods, true);
            $useBasic = $pref === 'basic' || ($pref !== 'post' && ($canBasic || !$canPost));
            if ($useBasic) {
                $headers[] = 'Authorization: Basic ' . base64_encode(rawurlencode(Settings::get('oidc_client_id')) . ':' . rawurlencode($secret));
            } else {
                $form['client_secret'] = $secret;
            }
            $res = self::http($d['token_endpoint'], $form, $headers);
            $idToken = (string) ($res['json']['id_token'] ?? '');
            if ($res['code'] !== 200 || substr_count($idToken, '.') !== 2) {
                $why = (string) ($res['json']['error'] ?? ($idToken === '' ? 'kein id_token' : 'id_token ungültig'));
                $desc = mb_substr((string) ($res['json']['error_description'] ?? ''), 0, 160);
                log_error('OIDC Token-Endpunkt: HTTP ' . $res['code'] . ' ' . $why . ' ' . $desc . ' | Antwort: ' . $res['raw']);
                return ['ok' => false, 'error' => 'Der Identitätsanbieter hat die Anmeldung abgelehnt (HTTP ' . $res['code'] . ', ' . $why . ($desc !== '' ? ': ' . $desc : '') . ')' . ($res['code'] >= 500 ? ' – der Fehler liegt beim Identitätsanbieter; bitte dessen Protokoll prüfen.' : '.')];
            }
            $claims = json_decode((string) base64_decode(strtr(explode('.', $idToken)[1], '-_', '+/')), true);
            if (!is_array($claims)) {
                return ['ok' => false, 'error' => 'Ungültige Antwort des Identitätsanbieters.'];
            }
            // Fehlt die E-Mail im ID-Token, aus dem Userinfo-Endpunkt ergänzen (Subject muss übereinstimmen)
            if (empty($claims['email']) && !empty($d['userinfo_endpoint']) && !empty($res['json']['access_token'])) {
                $ui = self::http($d['userinfo_endpoint'], null, ['Authorization: Bearer ' . $res['json']['access_token']]);
                if ($ui['code'] === 200 && ($ui['json']['sub'] ?? null) === ($claims['sub'] ?? '')) {
                    $claims += array_intersect_key($ui['json'], array_flip(['email', 'email_verified', 'name']));
                }
            }
        } catch (\Throwable $e) {
            log_error('OIDC: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Der Identitätsanbieter ist nicht erreichbar.'];
        }
        $v = self::validateClaims($claims, self::normalizeIssuer((string) $d['issuer']), Settings::get('oidc_client_id'), (string) $s['nonce']);
        if ($v !== null) {
            log_error('OIDC Prüfung: ' . $v);
            Audit::log('sso.failed', null, null, null, $v);
            return ['ok' => false, 'error' => 'Die Anmeldung konnte nicht bestätigt werden (' . $v . ').'];
        }
        $user = self::resolveUser($claims);
        if (is_string($user)) {
            Audit::log('sso.denied', null, null, null, $user);
            return ['ok' => false, 'error' => $user];
        }
        return ['ok' => true, 'user' => $user, 'next' => (string) ($s['next'] ?? '')];
    }

    /** @return string|null Fehlergrund oder null, wenn alles passt */
    public static function validateClaims(array $c, string $issuer, string $clientId, string $nonce, ?int $now = null): ?string
    {
        $now ??= time();
        if (rtrim((string) ($c['iss'] ?? ''), '/') !== $issuer) {
            return 'Aussteller falsch';
        }
        $aud = $c['aud'] ?? [];
        $aud = is_array($aud) ? $aud : [$aud];
        if (!in_array($clientId, $aud, true)) {
            return 'Zielgruppe falsch';
        }
        if (count($aud) > 1 && ($c['azp'] ?? '') !== $clientId) {
            return 'azp falsch';
        }
        if ((int) ($c['exp'] ?? 0) < $now - 60) {
            return 'Token abgelaufen';
        }
        if (!hash_equals($nonce, (string) ($c['nonce'] ?? ''))) {
            return 'nonce falsch';
        }
        if (empty($c['sub'])) {
            return 'sub fehlt';
        }
        return null;
    }

    /** @return array|string Benutzer oder Fehlermeldung */
    private static function resolveUser(array $c): array|string
    {
        $subject = hash('sha256', rtrim((string) $c['iss'], '/') . '|' . $c['sub']);
        $user = Database::one('SELECT * FROM users WHERE sso_subject = ? AND deleted_at IS NULL', [$subject]);
        if (!$user) {
            $email = mb_strtolower(trim((string) ($c['email'] ?? '')));
            if ($email === '' || !Auth::validEmail($email)) {
                return 'Der Identitätsanbieter hat keine E-Mail-Adresse geliefert.';
            }
            if (($c['email_verified'] ?? true) === false) {
                return 'Die E-Mail-Adresse ist beim Identitätsanbieter nicht bestätigt.';
            }
            $user = User::findByEmail($email);
            if ($user) {
                if (!empty($user['sso_subject']) && $user['sso_subject'] !== $subject) {
                    return 'Dieses Konto ist bereits mit einer anderen Identität verknüpft.';
                }
                Database::exec('UPDATE users SET sso_subject = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [$subject, now(), $user['id']]);
            } else {
                if (!Settings::bool('oidc_autocreate') || !self::domainAllowed($email)) {
                    return 'Für diese Adresse existiert kein Konto. Bitte wende dich an die Administration.';
                }
                $name = trim((string) ($c['name'] ?? '')) ?: ucfirst((string) strstr($email, '@', true));
                $r = Auth::register($email, mb_substr($name, 0, 80), bin2hex(random_bytes(16)) . 'aA1!', 'member', true);
                if (!$r['ok']) {
                    return $r['error'] ?? 'Konto konnte nicht angelegt werden.';
                }
                Database::exec('UPDATE users SET sso_subject = ? WHERE id = ?', [$subject, $r['user_id']]);
                Audit::log('sso.autocreated', (int) $r['user_id']);
            }
            $user = User::findByEmail($email);
        }
        if (!$user || !(int) $user['is_active']) {
            return 'Dieses Konto ist deaktiviert.';
        }
        return $user;
    }

    private static function domainAllowed(string $email): bool
    {
        $domains = preg_split('/[\s,;]+/', mb_strtolower(Settings::get('oidc_domains')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $d = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));
        return $domains !== [] && in_array($d, $domains, true);
    }
}
