<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Models\User;

/** Authentifizierung, Sitzungen, Einladungen, Passwort-Reset und E-Mail-Verifizierung. */
final class Auth
{
    public const PASSWORD_MIN = 10;
    public const PASSWORD_MAX_BYTES = 72;

    private static ?array $user = null;
    private static bool $resolved = false;

    // ---------------------------------------------------------------- Passwort

    public static function hashPassword(string $password): string
    {
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
        return password_hash($password, $algo);
    }

    /** @return string|null Fehlermeldung oder null, wenn gültig. */
    public static function passwordError(string $password, string $email = ''): ?string
    {
        if (mb_strlen($password) < self::PASSWORD_MIN) {
            return 'Das Passwort muss mindestens ' . self::PASSWORD_MIN . ' Zeichen lang sein.';
        }
        if (strlen($password) > self::PASSWORD_MAX_BYTES) {
            return 'Das Passwort darf höchstens ' . self::PASSWORD_MAX_BYTES . ' Bytes lang sein.';
        }
        if (!preg_match('/\p{L}/u', $password) || !preg_match('/[\p{N}\p{P}\p{S}]/u', $password)) {
            return 'Das Passwort muss Buchstaben sowie mindestens eine Ziffer oder ein Sonderzeichen enthalten.';
        }
        if ($email !== '' && mb_strtolower($password) === mb_strtolower($email)) {
            return 'Das Passwort darf nicht mit der E-Mail-Adresse übereinstimmen.';
        }
        return null;
    }

    // ------------------------------------------------------------------ Login

    /**
     * @return array{ok:bool,error?:string,user?:array,wait?:int}
     */
    public static function attempt(string $email, string $password): array
    {
        $email = mb_strtolower(trim($email));
        $ip = client_ip();
        [$lockMax, $lockWin] = self::lockoutPolicy();
        if (RateLimiter::blocked('login_ip', $ip, 30, 900) || RateLimiter::blocked('login_acct', $email, $lockMax, $lockWin)) {
            Audit::log('login.blocked', null, null, null, 'Rate Limit erreicht');
            return ['ok' => false, 'error' => 'Zu viele Anmeldeversuche. Bitte warte einige Minuten und versuche es erneut.'];
        }

        $user = $email !== '' ? User::findByEmail($email) : null;
        if ($user) {
            $valid = password_verify($password, $user['password_hash']);
        } else {
            self::hashPassword($password); // gleicher Zeitaufwand, verhindert Rückschlüsse auf existierende Konten
            $valid = false;
        }

        if (!$user || !$valid) {
            RateLimiter::hit('login_ip', $ip, 30, 900);
            RateLimiter::hit('login_acct', $email, $lockMax, $lockWin);
            Audit::log('login.failed', $user ? (int) $user['id'] : null, null, null, $user ? '' : 'Unbekannte Adresse');
            return ['ok' => false, 'error' => 'E-Mail-Adresse oder Passwort ist falsch.'];
        }
        if (self::guestExpired($user)) {
            self::expireGuest($user);
            return ['ok' => false, 'error' => 'Dein Gastzugang ist abgelaufen. Bitte wende dich an die Person, die dich eingeladen hat.'];
        }
        if (!(int) $user['is_active']) {
            Audit::log('login.inactive', (int) $user['id']);
            return ['ok' => false, 'error' => 'Dieses Konto ist deaktiviert. Bitte wende dich an eine Administratorin oder einen Administrator.'];
        }
        if ($user['email_verified_at'] === null && Settings::bool('require_email_verification')) {
            return ['ok' => false, 'error' => 'Bitte bestätige zuerst deine E-Mail-Adresse. Den Link findest du in der Bestätigungs-Mail.', 'unverified' => true];
        }

        RateLimiter::clear('login_acct', $email);
        if (password_needs_rehash($user['password_hash'], defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            Database::exec('UPDATE users SET password_hash = ? WHERE id = ?', [self::hashPassword($password), $user['id']]);
        }
        if (Totp::isEnabled($user)) {
            // Zweiter Faktor steht noch aus: noch keine angemeldete Sitzung, nur ein kurzlebiger Zwischenzustand.
            $_SESSION['pending_2fa'] = ['uid' => (int) $user['id'], 'exp' => time() + 300];
            return ['ok' => true, 'needs_2fa' => true, 'user' => $user];
        }
        self::finishLogin($user);
        return ['ok' => true, 'user' => $user];
    }

    /** Schließt die Anmeldung ab (nach Passwort bzw. zweitem Faktor): Sitzung starten, Passwortablauf prüfen. */
    public static function finishLogin(array $user, string $method = 'password'): void
    {
        $uid = (int) $user['id'];
        self::startSession($uid);
        $maxAge = Settings::int('password_max_age_days');
        if ($maxAge > 0 && empty($user['sso_subject'])) {
            $changed = strtotime((((string) ($user['password_changed_at'] ?? '')) ?: (string) $user['created_at']) . ' UTC');
            if ($changed !== false && $changed + $maxAge * 86400 < time()) {
                Database::exec('UPDATE users SET must_change_password = 1 WHERE id = ?', [$uid]);
            }
        }
        Audit::log('login.success', $uid, null, null, $method);
    }

    /** @return array{0:int,1:int} Fehlversuche und Fenster (Sekunden) bis zur Sperre */
    public static function lockoutPolicy(): array
    {
        return [max(3, Settings::int('lockout_attempts')), max(1, Settings::int('lockout_minutes')) * 60];
    }

    public static function pendingTwoFactorUser(): ?array
    {
        $p = $_SESSION['pending_2fa'] ?? null;
        if (!is_array($p) || ($p['exp'] ?? 0) < time()) {
            unset($_SESSION['pending_2fa']);
            return null;
        }
        $u = User::find((int) $p['uid']);
        return $u && (int) $u['is_active'] && Totp::isEnabled($u) ? $u : null;
    }

    /** @return string|null Fehlermeldung oder null (angemeldet) */
    public static function completeTwoFactor(string $code): ?string
    {
        $user = self::pendingTwoFactorUser();
        if (!$user) {
            return 'Die Anmeldung ist abgelaufen. Bitte melde dich erneut an.';
        }
        if (!Totp::verifyLogin($user, $code)) {
            Audit::log('login.2fa_failed', (int) $user['id']);
            return 'Der Code ist ungültig oder wurde bereits verwendet.';
        }
        unset($_SESSION['pending_2fa']);
        self::finishLogin($user, '2fa');
        return null;
    }

    /** Muss diese Person noch 2FA einrichten (Pflicht für Administratoren per Einstellung)? */
    public static function mustSetupTwoFactor(array $user): bool
    {
        return $user['role'] === 'admin' && License::has('2fa') && Settings::bool('require_2fa_admins') && empty($user['sso_subject']) && !Totp::isEnabled($user);
    }

    /** Neue Sitzung nach erfolgreicher Authentifizierung (ID-Regeneration gegen Session Fixation). */
    public static function startSession(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = $userId;
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        Database::exec(
            'INSERT INTO user_sessions (user_id, session_hash, ip, user_agent, created_at, last_activity_at) VALUES (?,?,?,?,?,?)',
            [$userId, hash('sha256', session_id()), client_ip(), mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255), now(), now()]
        );
        Database::exec('UPDATE users SET last_login_at = ?, last_seen_at = ?, last_active_at = ? WHERE id = ?', [now(), now(), now(), $userId]);
        self::$user = null;
        self::$resolved = false;
    }

    /** Aktuell angemeldeter Benutzer oder null. Validiert die Sitzung gegen user_sessions. */
    public static function user(): ?array
    {
        if (self::$resolved) {
            return self::$user;
        }
        self::$resolved = true;
        $uid = (int) ($_SESSION['uid'] ?? 0);
        if ($uid <= 0 || session_status() === PHP_SESSION_NONE) {
            return self::$user = null;
        }
        $hash = hash('sha256', session_id());
        $sess = Database::one('SELECT id, last_activity_at FROM user_sessions WHERE session_hash = ? AND user_id = ? AND revoked_at IS NULL', [$hash, $uid]);
        $user = $sess ? User::find($uid) : null;
        if ($user && self::guestExpired($user)) {
            self::expireGuest($user);
            $user = null;
        }
        if (!$sess || !$user || !(int) $user['is_active']) {
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION = [];
            }
            return self::$user = null;
        }
        if (time() - strtotime($sess['last_activity_at'] . ' UTC') > 60) {
            Database::exec('UPDATE user_sessions SET last_activity_at = ? WHERE id = ?', [now(), $sess['id']]);
        }
        $user['_session_id'] = (int) $sess['id'];
        return self::$user = $user;
    }

    public static function guestExpired(array $user): bool
    {
        return ($user['role'] ?? '') === 'guest' && !empty($user['guest_expires_at']) && strtotime($user['guest_expires_at'] . ' UTC') <= time();
    }

    /** Läuft ein Gastzugang ab, wird das Konto deaktiviert (Sitzungen enden, der Platz wird frei). */
    public static function expireGuest(array $user): void
    {
        if ((int) $user['is_active'] === 1) {
            Database::exec('UPDATE users SET is_active = 0, updated_at = ? WHERE id = ?', [now(), $user['id']]);
            Database::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [now(), $user['id']]);
            Audit::log('guest.expired', (int) $user['id'], 'user', (int) $user['id']);
            License::reconcileLater();
        }
    }

    /** Deaktiviert alle abgelaufenen Gastzugänge (wird regelmässig aufgerufen). */
    public static function expireGuests(): int
    {
        $rows = Database::all("SELECT * FROM users WHERE role = 'guest' AND is_active = 1 AND deleted_at IS NULL AND guest_expires_at IS NOT NULL AND guest_expires_at <= ? LIMIT 200", [now()]);
        foreach ($rows as $u) {
            self::expireGuest($u);
        }
        return count($rows);
    }

    public static function logout(): void
    {
        $user = self::user();
        if ($user) {
            Database::exec('UPDATE user_sessions SET revoked_at = ? WHERE session_hash = ?', [now(), hash('sha256', session_id())]);
            Audit::log('logout', (int) $user['id']);
        }
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        self::$user = null;
        self::$resolved = true;
    }

    public static function revokeOtherSessions(int $userId, int $keepSessionId): void
    {
        Database::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND id <> ? AND revoked_at IS NULL', [now(), $userId, $keepSessionId]);
    }

    // ------------------------------------------------------------ Registrierung

    public static function validEmail(string $email): bool
    {
        return strlen($email) <= 190 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** @return array{ok:bool,error?:string,user_id?:int,needs_verification?:bool} */
    public static function register(string $email, string $displayName, string $password, string $role = 'member', bool $verified = false): array
    {
        $email = mb_strtolower(trim($email));
        $displayName = trim($displayName);
        if (!self::validEmail($email)) {
            return ['ok' => false, 'error' => 'Bitte gib eine gültige E-Mail-Adresse an.'];
        }
        if (mb_strlen($displayName) < 2 || mb_strlen($displayName) > 80) {
            return ['ok' => false, 'error' => 'Der Anzeigename muss zwischen 2 und 80 Zeichen lang sein.'];
        }
        if ($err = self::passwordError($password, $email)) {
            return ['ok' => false, 'error' => $err];
        }
        if (Database::value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            return ['ok' => false, 'error' => 'Für diese E-Mail-Adresse existiert bereits ein Konto.'];
        }
        if ($err = License::canAddUser()) {
            return ['ok' => false, 'error' => $err];
        }
        $needsVerify = !$verified && Settings::bool('require_email_verification');
        $id = User::create($email, $displayName, self::hashPassword($password), $role, !$needsVerify);
        if ($err = License::claimSeat($id)) {
            Database::exec('DELETE FROM users WHERE id = ?', [$id]);
            return ['ok' => false, 'error' => $err];
        }
        if ($needsVerify) {
            self::sendVerification($id);
        }
        Audit::log('user.registered', $id, 'user', $id);
        return ['ok' => true, 'user_id' => $id, 'needs_verification' => $needsVerify];
    }

    public static function registrationAllowedFor(string $email): bool
    {
        $domains = trim(Settings::get('registration_domains'));
        if ($domains === '') {
            return true;
        }
        $domain = mb_strtolower(substr(strrchr($email, '@') ?: '', 1));
        foreach (preg_split('/[\s,;]+/', mb_strtolower($domains), -1, PREG_SPLIT_NO_EMPTY) as $d) {
            if ($d === $domain) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------------- Verifizierung

    public static function sendVerification(int $userId): bool
    {
        $user = User::find($userId);
        if (!$user || $user['email_verified_at'] !== null) {
            return false;
        }
        $token = random_token();
        Database::exec('UPDATE email_verifications SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $userId]);
        Database::exec(
            'INSERT INTO email_verifications (user_id, token_hash, expires_at, created_at) VALUES (?,?,?,?)',
            [$userId, hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 172800), now()]
        );
        $link = base_url() . '/verify/' . $token;
        return Mail::send(
            $user['email'],
            $user['display_name'],
            'Bitte bestätige deine E-Mail-Adresse',
            'E-Mail-Adresse bestätigen',
            '<p>Hallo ' . e($user['display_name']) . ',</p><p>bitte bestätige deine E-Mail-Adresse, um dein Konto zu aktivieren. Der Link ist 48 Stunden gültig.</p>' . Mail::button($link, 'Adresse bestätigen'),
            "Hallo {$user['display_name']},\n\nbitte bestätige deine E-Mail-Adresse (48 Stunden gültig):\n$link\n"
        );
    }

    public static function verifyEmail(string $token): bool
    {
        $row = Database::one('SELECT id, user_id FROM email_verifications WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), now()]);
        if (!$row) {
            return false;
        }
        Database::transaction(function () use ($row) {
            Database::exec('UPDATE email_verifications SET used_at = ? WHERE id = ?', [now(), $row['id']]);
            Database::exec('UPDATE users SET email_verified_at = ?, updated_at = ? WHERE id = ? AND email_verified_at IS NULL', [now(), now(), $row['user_id']]);
        });
        Audit::log('email.verified', (int) $row['user_id']);
        return true;
    }

    // ------------------------------------------------------------- Passwort-Reset

    public static function requestReset(string $email): void
    {
        $email = mb_strtolower(trim($email));
        if (RateLimiter::blocked('reset_ip', client_ip(), 10, 3600) || !RateLimiter::hit('reset_acct', $email, 3, 3600)) {
            return; // stillschweigend, um keine Informationen preiszugeben
        }
        RateLimiter::hit('reset_ip', client_ip(), 10, 3600);
        $user = User::findByEmail($email);
        if (!$user || !(int) $user['is_active']) {
            return;
        }
        self::sendResetMail($user);
    }

    public static function sendResetMail(array $user): bool
    {
        $token = random_token();
        Database::exec('UPDATE password_resets SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [now(), $user['id']]);
        Database::exec(
            'INSERT INTO password_resets (user_id, token_hash, expires_at, ip, created_at) VALUES (?,?,?,?,?)',
            [$user['id'], hash('sha256', $token), gmdate('Y-m-d H:i:s', time() + 3600), client_ip(), now()]
        );
        Audit::log('password.reset_requested', (int) $user['id']);
        $link = base_url() . '/reset/' . $token;
        return Mail::send(
            $user['email'],
            $user['display_name'],
            'Passwort zurücksetzen',
            'Passwort zurücksetzen',
            '<p>Hallo ' . e($user['display_name']) . ',</p><p>für dein Konto wurde das Zurücksetzen des Passworts angefordert. Der Link ist eine Stunde gültig. Wenn du das nicht warst, kannst du diese Nachricht ignorieren.</p>' . Mail::button($link, 'Neues Passwort festlegen'),
            "Hallo {$user['display_name']},\n\nPasswort zurücksetzen (eine Stunde gültig):\n$link\n\nWenn du das nicht warst, ignoriere diese Nachricht.\n"
        );
    }

    public static function resetTokenValid(string $token): bool
    {
        return (bool) Database::value('SELECT 1 FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), now()]);
    }

    /** @return string|null Fehlermeldung oder null bei Erfolg */
    public static function resetPassword(string $token, string $password): ?string
    {
        $row = Database::one('SELECT id, user_id FROM password_resets WHERE token_hash = ? AND used_at IS NULL AND expires_at > ?', [hash('sha256', $token), now()]);
        if (!$row) {
            return 'Der Link ist ungültig oder abgelaufen. Bitte fordere einen neuen an.';
        }
        $user = User::find((int) $row['user_id']);
        if (!$user) {
            return 'Der Link ist ungültig oder abgelaufen.';
        }
        if ($err = self::passwordError($password, $user['email'])) {
            return $err;
        }
        Database::transaction(function () use ($row, $password) {
            Database::exec('UPDATE password_resets SET used_at = ? WHERE id = ?', [now(), $row['id']]);
            Database::exec('UPDATE users SET must_change_password = 0, password_hash = ?, password_changed_at = ?, updated_at = ?, email_verified_at = COALESCE(email_verified_at, ?) WHERE id = ?', [self::hashPassword($password), now(), now(), now(), $row['user_id']]);
            Database::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [now(), $row['user_id']]);
        });
        RateLimiter::clear('login_acct', $user['email']);
        Audit::log('password.reset_done', (int) $user['id']);
        return null;
    }

    public static function changePassword(array $user, string $current, string $new): ?string
    {
        if (!RateLimiter::hit('pwchange', (string) $user['id'], 10, 900)) {
            return 'Zu viele Versuche. Bitte warte kurz.';
        }
        if (!password_verify($current, $user['password_hash'])) {
            Audit::log('password.change_failed', (int) $user['id']);
            return 'Das aktuelle Passwort ist falsch.';
        }
        if ($err = self::passwordError($new, $user['email'])) {
            return $err;
        }
        Database::exec('UPDATE users SET must_change_password = 0, password_hash = ?, password_changed_at = ?, updated_at = ? WHERE id = ?', [self::hashPassword($new), now(), now(), $user['id']]);
        self::revokeOtherSessions((int) $user['id'], (int) ($user['_session_id'] ?? 0));
        Audit::log('password.changed', (int) $user['id']);
        return null;
    }

    // ----------------------------------------------------------------- Einladungen

    /** @return array{ok:bool,error?:string,link?:string,mailed?:bool} */
    /** @param int[] $conversationIds Gruppen, in die ein Gast aufgenommen wird */
    public static function invite(string $email, string $role, int $invitedBy, ?int $conversationId = null, array $conversationIds = [], ?int $guestDays = null): array
    {
        $email = mb_strtolower(trim($email));
        if (!self::validEmail($email)) {
            return ['ok' => false, 'error' => 'Ungültige E-Mail-Adresse.'];
        }
        if (Database::value('SELECT 1 FROM users WHERE email = ? AND deleted_at IS NULL', [$email])) {
            return ['ok' => false, 'error' => 'Für diese Adresse existiert bereits ein Konto.'];
        }
        $token = random_token();
        Database::exec('UPDATE invitations SET revoked_at = ? WHERE email = ? AND accepted_at IS NULL AND revoked_at IS NULL', [now(), $email]);
        $role = in_array($role, ['admin', 'guest'], true) ? $role : 'member';
        $conversationIds = array_values(array_unique(array_filter(array_map('intval', $conversationIds))));
        if ($role === 'guest') {
            if (!$conversationIds) {
                return ['ok' => false, 'error' => 'Für einen Gast muss mindestens eine Gruppe gewählt werden.'];
            }
            $valid = array_map('intval', array_column(Database::all("SELECT id FROM conversations WHERE type = 'group' AND id IN (" . Database::in($conversationIds) . ')', $conversationIds), 'id'));
            if (count($valid) !== count($conversationIds)) {
                return ['ok' => false, 'error' => 'Eine der gewählten Gruppen existiert nicht.'];
            }
            $guestDays = max(1, min(365, $guestDays ?? 30));
        } else {
            $conversationIds = [];
            $guestDays = null;
        }
        $id = Database::insert(
            'INSERT INTO invitations (email, token_hash, role, conversation_id, conversation_ids, guest_days, invited_by, expires_at, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$email, hash('sha256', $token), $role, $conversationId, $conversationIds ? implode(',', $conversationIds) : null, $guestDays, $invitedBy, gmdate('Y-m-d H:i:s', time() + 7 * 86400), now()]
        );
        $link = base_url() . '/invite/' . $token;
        $inviter = User::find($invitedBy);
        $mailed = Mail::send(
            $email,
            '',
            'Einladung zu ' . Settings::get('company_name'),
            'Du wurdest eingeladen',
            '<p>' . e($inviter['display_name'] ?? 'Ein Administrator') . ' hat dich zu <strong>' . e(Settings::get('company_name')) . '</strong> eingeladen. Lege jetzt dein Konto an. Der Link ist 7 Tage gültig.</p>' . Mail::button($link, 'Einladung annehmen'),
            "Du wurdest zu " . Settings::get('company_name') . " eingeladen (7 Tage gültig):\n$link\n"
        );
        Audit::log('invitation.created', $invitedBy, 'invitation', $id, $email);
        return ['ok' => true, 'link' => $link, 'mailed' => $mailed];
    }

    public static function invitationByToken(string $token): ?array
    {
        return Database::one(
            'SELECT * FROM invitations WHERE token_hash = ? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ?',
            [hash('sha256', $token), now()]
        );
    }

    /** @return array{ok:bool,error?:string,user_id?:int} */
    public static function acceptInvitation(string $token, string $displayName, string $password): array
    {
        $inv = self::invitationByToken($token);
        if (!$inv) {
            return ['ok' => false, 'error' => 'Die Einladung ist ungültig oder abgelaufen.'];
        }
        // E-Mail-Besitz gilt durch den Einladungslink als bestätigt
        $res = self::register($inv['email'], $displayName, $password, $inv['role'], true);
        if (!$res['ok']) {
            return $res;
        }
        Database::exec('UPDATE invitations SET accepted_at = ? WHERE id = ?', [now(), $inv['id']]);
        if ($inv['conversation_id']) {
            Chat::addMembersSilently((int) $inv['conversation_id'], [(int) $res['user_id']]);
        }
        if ($inv['role'] === 'guest') {
            Database::exec('UPDATE users SET guest_expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + max(1, (int) ($inv['guest_days'] ?? 30)) * 86400), $res['user_id']]);
            foreach (array_filter(array_map('intval', explode(',', (string) ($inv['conversation_ids'] ?? '')))) as $cid) {
                Chat::addMembersSilently($cid, [(int) $res['user_id']]);
            }
        }
        Audit::log('invitation.accepted', (int) $res['user_id'], 'invitation', (int) $inv['id']);
        return $res;
    }

    // ------------------------------------------------------------------ Kontolöschung

    /** Konto anonymisieren (DSG: Löschung/Anonymisierung personenbezogener Daten). Nachrichten bleiben ohne Personenbezug erhalten. */
    public static function anonymize(int $userId, string $reason = 'self', bool $eraseContent = false): void
    {
        $user = User::find($userId);
        if (!$user) {
            return;
        }
        Jobs::eraseForUser($userId);
        $erased = $eraseContent ? Privacy::eraseContent($userId) : 0;
        Privacy::scrubSystemMessages($userId, (string) $user['display_name']);
        $convIds = array_column(Database::all("SELECT conversation_id FROM conversation_members WHERE user_id = ? AND left_at IS NULL", [$userId]), 'conversation_id');
        Database::transaction(function () use ($user, $userId) {
            if ($user['avatar_file']) {
                Files::deleteProfileImage('avatars', $user['avatar_file']);
            }
            Database::exec(
                "UPDATE users SET email = ?, username = ?, display_name = 'Gelöschter Benutzer', status_message = '', avatar_file = NULL,
                 password_hash = ?, is_active = 0, deleted_at = ?, updated_at = ? WHERE id = ?",
                ['deleted-' . $userId . '-' . bin2hex(random_bytes(4)) . '@invalid.local', 'deleted-' . $userId, self::hashPassword(random_token()), now(), now(), $userId]
            );
            Database::exec('DELETE FROM user_sessions WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM password_resets WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM email_verifications WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM message_receipts WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM typing_state WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM user_settings WHERE user_id = ?', [$userId]);
            Database::exec("UPDATE audit_logs SET ip = '', user_agent = '' WHERE user_id = ?", [$userId]);
            Database::exec("UPDATE users SET processing_restricted_at = NULL, processing_restricted_note = '', privacy_ack_at = NULL WHERE id = ?", [$userId]);
            Database::exec('DELETE FROM notifications WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM message_reactions WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM drafts WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM poll_votes WHERE user_id = ?', [$userId]);
            Database::exec('DELETE FROM event_rsvps WHERE user_id = ?', [$userId]);
            Database::exec('UPDATE users SET ics_token_hash = NULL, guest_expires_at = NULL WHERE id = ?', [$userId]);
            Database::exec('UPDATE conversation_members SET left_at = ? WHERE user_id = ? AND left_at IS NULL', [now(), $userId]);
            Database::exec('UPDATE invitations SET revoked_at = ? WHERE invited_by = ? AND accepted_at IS NULL AND revoked_at IS NULL', [now(), $userId]);
        });
        foreach ($convIds as $cid) {
            Chat::repairAdmins((int) $cid);
            Events::push('conversation.removed', (int) $cid, null, $userId);
            Events::push('conversation.updated', (int) $cid);
        }
        Audit::log('user.anonymized', $reason === 'self' ? $userId : null, 'user', $userId, $reason . ($eraseContent ? ' (Inhalte gelöscht: ' . $erased . ' Nachrichten)' : ''));
        License::reconcileLater();
    }
}
