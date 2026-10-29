<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Chat;
use App\Services\Crypto;
use App\Services\Events;
use App\Services\Files;
use App\Services\Mail;
use App\Services\Messages;
use App\Services\Privacy;
use App\Services\Retention;
use App\Services\SelfTest;
use App\Services\RateLimiter;
use App\Services\Settings;

/**
 * Administration. Administratoren verwalten Konten, Gruppen und Einstellungen,
 * haben aber KEINEN Zugriff auf Inhalte privater Unterhaltungen. Einsehbar ist ausschliesslich eine
 * von Mitgliedern gemeldete Einzelnachricht; jede Einsicht wird im Audit-Log protokolliert.
 */
final class AdminController extends Controller
{
    private const PER_PAGE = 25;

    private function page(string $template, array $vars, string $section, string $title): Response
    {
        $vars += [
            'section' => $section,
            'title' => $title,
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
        ];
        return $this->view($template, $vars, 'layouts/main');
    }

    private function back(string $path, ?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect($path);
    }

    private function adminCount(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL");
    }

    // ------------------------------------------------------------ Übersicht

    public function dashboard(Request $r): Response
    {
        $day = gmdate('Y-m-d H:i:s', time() - 86400);
        $stats = [
            'users_active' => (int) Database::value('SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NULL AND is_bot = 0'),
            'users_inactive' => (int) Database::value('SELECT COUNT(*) FROM users WHERE is_active = 0 AND deleted_at IS NULL AND is_bot = 0'),
            'users_unverified' => (int) Database::value('SELECT COUNT(*) FROM users WHERE email_verified_at IS NULL AND deleted_at IS NULL AND is_bot = 0'),
            'admins' => $this->adminCount(),
            'online' => (int) Database::value("SELECT COUNT(*) FROM users WHERE last_seen_at > ? AND presence_pref <> 'invisible' AND deleted_at IS NULL", [gmdate('Y-m-d H:i:s', time() - User::ONLINE_WINDOW)]),
            'groups' => (int) Database::value("SELECT COUNT(*) FROM conversations WHERE type = 'group'"),
            'directs' => (int) Database::value("SELECT COUNT(*) FROM conversations WHERE type = 'direct'"),
            'messages_total' => (int) Database::value("SELECT COUNT(*) FROM messages WHERE kind = 'text' AND deleted_at IS NULL"),
            'messages_day' => (int) Database::value("SELECT COUNT(*) FROM messages WHERE kind = 'text' AND deleted_at IS NULL AND created_at > ?", [$day]),
            'files' => (int) Database::value('SELECT COUNT(*) FROM message_attachments WHERE message_id IS NOT NULL'),
            'file_bytes' => (int) Database::value('SELECT COALESCE(SUM(size_bytes),0) FROM message_attachments WHERE message_id IS NOT NULL'),
            'reports_open' => (int) Database::value("SELECT COUNT(*) FROM message_reports WHERE status = 'open'"),
            'invitations' => (int) Database::value('SELECT COUNT(*) FROM invitations WHERE accepted_at IS NULL AND revoked_at IS NULL AND expires_at > ?', [now()]),
            'failed_logins' => (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE action = 'login.failed' AND created_at > ?", [$day]),
        ];
        $storage = storage_path();
        $free = @disk_free_space($storage);
        $health = [
            ['PHP-Version', PHP_VERSION, version_compare(PHP_VERSION, '8.3.0', '>=')],
            ['HTTPS', is_https() ? 'aktiv' : 'nicht aktiv', is_https()],
            ['E-Mail-Versand (SMTP)', Mail::configured() ? 'konfiguriert' : 'nicht konfiguriert', Mail::configured()],
            ['Speicherverzeichnis beschreibbar', is_writable($storage) ? 'ja' : 'nein', is_writable($storage)],
            ['Freier Speicherplatz', $free === false ? 'unbekannt' : human_size((int) $free), $free === false || $free > 200 * 1048576],
            ['GD (Bildverarbeitung)', extension_loaded('gd') ? 'verfügbar' : 'fehlt', extension_loaded('gd')],
            ['Letzte Bereinigung', ((int) Settings::get('last_cleanup_at')) > 0 ? local_dt(gmdate('Y-m-d H:i:s', (int) Settings::get('last_cleanup_at'))) : 'noch nie', ((int) Settings::get('last_cleanup_at')) > time() - 3 * 86400],
            ['Maximale Uploadgrösse (PHP)', human_size(Files::iniBytes('upload_max_filesize')) . ' / Formular ' . human_size(Files::iniBytes('post_max_size')), true],
        ];
        foreach (\App\Services\Monitor::checks() as $c) {
            if (!$c['ok'] && !in_array($c['name'], ['HTTPS', 'Speicherordner beschreibbar', 'Speicherplatz', 'Wartung (Cron / automatisch)'], true)) {
                $health[] = [$c['name'], $c['detail'], false];
            }
        }
        $adminsNo2fa = (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL AND totp_enabled_at IS NULL AND sso_subject IS NULL");
        $health[] = ['2FA der Administratoren', $adminsNo2fa === 0 ? 'alle geschützt' : $adminsNo2fa . ' ohne 2FA', $adminsNo2fa === 0];
        $recent = Database::all(
            'SELECT a.*, u.display_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 8'
        );
        return $this->page('admin/dashboard', compact('stats', 'health', 'recent') + ['selftest' => self::lastSelfTest()], 'dashboard', 'Übersicht');
    }

    // ------------------------------------------------------------ Systemtest

    /** @return array<string,mixed>|null Ergebnis des letzten Funktionstests */
    public static function lastSelfTest(): ?array
    {
        $raw = Settings::get('selftest_last');
        $data = $raw !== '' ? json_decode($raw, true) : null;
        return is_array($data) && isset($data['groups']) ? $data : null;
    }

    public function tests(Request $r): Response
    {
        return $this->page('admin/tests', ['selftest' => self::lastSelfTest(), 'filter' => $r->str('show', 'issues', 10)], 'tests', 'Systemtest');
    }

    public function runTests(Request $r): Response
    {
        $admin = $this->user();
        @set_time_limit(120);
        $res = SelfTest::run();
        Settings::set('selftest_last', json_encode($res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        $s = $res['summary'];
        Audit::log('admin.selftest', (int) $admin['id'], null, null, $s['pass'] . ' bestanden, ' . $s['fail'] . ' fehlgeschlagen, ' . $s['warn'] . ' Warnungen');
        $back = $r->str('return') === 'dashboard' ? 'admin' : 'admin/tests';
        return $s['fail'] > 0
            ? $this->back($back, null, $s['fail'] . ' Test(s) fehlgeschlagen. Details im Systemtest.')
            : $this->back($back, 'Alle ' . $s['pass'] . ' Tests bestanden' . ($s['warn'] > 0 ? ' (' . $s['warn'] . ' Hinweise).' : '.'));
    }

    // ------------------------------------------------------------- Benutzer

    public function users(Request $r): Response
    {
        $q = trim($r->str('q', '', 80));
        $status = $r->str('status', 'all', 20);
        $page = max(1, (int) $r->query('page', 1));
        $where = 'u.deleted_at IS NULL AND u.is_bot = 0';
        $params = [];
        if ($q !== '') {
            $like = '%' . User::escapeLike($q) . '%';
            $where .= ' AND (u.display_name LIKE ? OR u.email LIKE ? OR u.username LIKE ?)';
            array_push($params, $like, $like, $like);
        }
        $where .= match ($status) {
            'active' => ' AND u.is_active = 1',
            'inactive' => ' AND u.is_active = 0',
            'unverified' => ' AND u.email_verified_at IS NULL',
            'admins' => " AND u.role = 'admin'",
            'guests' => " AND u.role = 'guest'",
            default => '',
        };
        $total = (int) Database::value("SELECT COUNT(*) FROM users u WHERE $where", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $users = Database::all(
            "SELECT u.* FROM users u WHERE $where ORDER BY u.display_name ASC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        $edit = null;
        if ($r->query('edit')) {
            $edit = User::find((int) $r->query('edit'));
            if ($edit && $edit['deleted_at'] !== null) {
                $edit = null;
            }
        }
        $invitations = Database::all(
            'SELECT i.*, u.display_name AS inviter FROM invitations i LEFT JOIN users u ON u.id = i.invited_by
             WHERE i.accepted_at IS NULL AND i.revoked_at IS NULL AND i.expires_at > ? ORDER BY i.id DESC LIMIT 50',
            [now()]
        );
        $editRequests = $edit ? Database::all('SELECT id, type, status, due_at, created_at FROM privacy_requests WHERE user_id = ? ORDER BY id DESC LIMIT 10', [(int) $edit['id']]) : [];
        $groups = Database::all("SELECT id, name FROM conversations WHERE type = 'group' ORDER BY name ASC LIMIT 300");
        $guestGroups = $edit && $edit['role'] === 'guest'
            ? array_map('intval', array_column(Database::all("SELECT cm.conversation_id FROM conversation_members cm WHERE cm.user_id = ? AND cm.left_at IS NULL", [(int) $edit['id']]), 'conversation_id'))
            : [];
        return $this->page('admin/users', compact('users', 'q', 'status', 'page', 'pages', 'total', 'edit', 'invitations', 'editRequests', 'groups', 'guestGroups') + [
            'mailOk' => Mail::configured(),
            'registrationOpen' => Settings::bool('registration_enabled'),
        ], 'users', 'Benutzer');
    }

    /** Rolle aus dem Formular: admin, guest oder member. */
    private function roleInput(Request $r): string
    {
        return match ($r->str('role', 'member', 10)) {
            'admin' => 'admin',
            'guest' => 'guest',
            default => 'member',
        };
    }

    /** Ende des gewählten Tages (Anzeige-Zeitzone) als UTC-Zeitstempel für den Ablauf eines Gastzugangs. */
    private function guestExpiry(string $date): ?string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return null;
        }
        try {
            $tz = new \DateTimeZone((string) \App\Core\Config::get('app.timezone', 'Europe/Zurich'));
            $d = new \DateTimeImmutable($date . ' 23:59:59', $tz);
        } catch (\Throwable) {
            return null;
        }
        return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Macht ein Konto zum Gast: Direktchats verlassen, Moderationsrechte in Gruppen entziehen, Ablaufdatum setzen.
     * @param int[] $groupIds Gruppen, in die der Gast aufgenommen wird
     */
    private function makeGuest(int $id, string $expiresUtc, array $groupIds): void
    {
        Database::exec("UPDATE users SET role = 'guest', guest_expires_at = ?, updated_at = ? WHERE id = ?", [$expiresUtc, now(), $id]);
        $directs = Database::all("SELECT cm.conversation_id FROM conversation_members cm JOIN conversations c ON c.id = cm.conversation_id WHERE cm.user_id = ? AND cm.left_at IS NULL AND c.type = 'direct'", [$id]);
        foreach ($directs as $d) {
            Database::exec('UPDATE conversation_members SET left_at = ? WHERE conversation_id = ? AND user_id = ?', [now(), $d['conversation_id'], $id]);
            Events::push('conversation.removed', (int) $d['conversation_id'], null, $id);
        }
        Database::exec("UPDATE conversation_members SET role = 'member' WHERE user_id = ? AND left_at IS NULL", [$id]);
        $this->syncGuestGroups($id, $groupIds, false);
    }

    /** Gruppenzugang eines Gasts auf die gewählten Gruppen abgleichen. */
    private function syncGuestGroups(int $id, array $groupIds, bool $remove = true): void
    {
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds))));
        $valid = $groupIds ? array_map('intval', array_column(Database::all("SELECT id FROM conversations WHERE type = 'group' AND id IN (" . Database::in($groupIds) . ')', $groupIds), 'id')) : [];
        $current = array_map('intval', array_column(Database::all(
            "SELECT cm.conversation_id FROM conversation_members cm JOIN conversations c ON c.id = cm.conversation_id WHERE cm.user_id = ? AND cm.left_at IS NULL AND c.type = 'group'",
            [$id]
        ), 'conversation_id'));
        $guest = User::find($id);
        foreach (array_diff($valid, $current) as $cid) {
            $conv = Chat::conversation($cid);
            Chat::addMembersSilently($cid, [$id]);
            Messages::system($cid, ($guest['display_name'] ?? 'Ein Gast') . ' wurde als Gast hinzugefügt.');
            Events::push('conversation.updated', $cid);
        }
        if ($remove) {
            foreach (array_diff($current, $valid) as $cid) {
                Database::exec('UPDATE conversation_members SET left_at = ? WHERE conversation_id = ? AND user_id = ?', [now(), $cid, $id]);
                Events::push('conversation.removed', $cid, null, $id);
                Events::push('conversation.updated', $cid);
                Chat::repairAdmins($cid);
            }
        }
    }

    public function guestAccess(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        $target = User::find($id);
        if (!$target || $target['role'] !== 'guest') {
            throw new HttpException(404, 'Gast nicht gefunden.');
        }
        $back = 'admin/users?edit=' . $id;
        $groups = $r->intList('conversation_ids');
        if (!$groups) {
            return $this->back($back, null, 'Ein Gast braucht mindestens eine Gruppe. Zum Sperren das Konto deaktivieren.');
        }
        $this->syncGuestGroups($id, $groups);
        $exp = $this->guestExpiry($r->str('guest_expires', '', 10));
        if ($exp !== null && $exp !== $target['guest_expires_at']) {
            Database::exec('UPDATE users SET guest_expires_at = ?, is_active = IF(? > ?, 1, is_active), updated_at = ? WHERE id = ?', [$exp, $exp, now(), now(), $id]);
        }
        Audit::log('admin.guest_access', (int) $admin['id'], 'user', $id, implode(',', $groups));
        return $this->back($back, 'Zugang des Gasts gespeichert.');
    }

    public function invite(Request $r): Response
    {
        $admin = $this->user();
        $role = $this->roleInput($r);
        $res = Auth::invite($r->str('email', '', 190), $role, (int) $admin['id'], null, $r->intList('conversation_ids'), $r->int('guest_days', 30));
        if (!$res['ok']) {
            return $this->back('admin/users', null, $res['error']);
        }
        if ($res['mailed']) {
            return $this->back('admin/users', 'Einladung wurde per E-Mail gesendet.');
        }
        return $this->back('admin/users', 'Die Einladung wurde angelegt, konnte aber nicht per E-Mail versendet werden (SMTP prüfen). Gib diesen Link manuell weiter (7 Tage gültig): ' . $res['link']);
    }

    public function createUser(Request $r): Response
    {
        $admin = $this->user();
        $role = $this->roleInput($r);
        $email = $r->str('email', '', 190);
        $guestGroups = [];
        if ($role === 'guest') {
            $guestGroups = $r->intList('conversation_ids');
            if (!$guestGroups) {
                return $this->back('admin/users', null, 'Für einen Gast muss mindestens eine Gruppe gewählt werden.');
            }
        }
        $password = $r->str('password', '', 500);
        $generated = $password === '';
        if ($generated) {
            $password = bin2hex(random_bytes(16)) . 'Aa1!';
        }
        $res = Auth::register($email, $r->str('display_name', '', 80), $password, $role, true);
        if (!$res['ok']) {
            return $this->back('admin/users', null, $res['error']);
        }
        $uid = (int) $res['user_id'];
        if ($role === 'guest') {
            $days = max(1, min(365, $r->int('guest_days', 30)));
            $this->makeGuest($uid, gmdate('Y-m-d H:i:s', time() + $days * 86400), $guestGroups);
        }
        Audit::log('admin.user_created', (int) $admin['id'], 'user', $uid, $role);
        if ($generated) {
            $user = User::find($uid);
            $sent = $user ? Auth::sendResetMail($user) : false;
            return $this->back('admin/users', $sent
                ? 'Konto angelegt. Die Person erhält eine E-Mail, um ein eigenes Passwort festzulegen.'
                : 'Konto angelegt, aber die E-Mail konnte nicht versendet werden. Nutze „Passwort-Link senden“ in der Benutzerverwaltung, sobald SMTP funktioniert.');
        }
        return $this->back('admin/users', 'Konto angelegt. Teile das Anfangspasswort über einen sicheren Kanal mit und bitte die Person, es zu ändern.');
    }

    public function updateUser(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        $target = User::find($id);
        if (!$target || $target['deleted_at'] !== null) {
            throw new HttpException(404, 'Benutzer nicht gefunden.');
        }
        $name = trim($r->str('display_name', '', 80));
        $email = mb_strtolower(trim($r->str('email', '', 190)));
        $role = $this->roleInput($r);
        $back = 'admin/users?edit=' . $id;
        if (mb_strlen($name) < 2) {
            return $this->back($back, null, 'Der Anzeigename muss mindestens 2 Zeichen lang sein.');
        }
        if (!Auth::validEmail($email)) {
            return $this->back($back, null, 'Bitte gib eine gültige E-Mail-Adresse an.');
        }
        if ($email !== $target['email'] && Database::value('SELECT 1 FROM users WHERE email = ? AND id <> ?', [$email, $id])) {
            return $this->back($back, null, 'Diese E-Mail-Adresse wird bereits verwendet.');
        }
        if ($role !== $target['role']) {
            if ($id === (int) $admin['id']) {
                return $this->back($back, null, 'Du kannst deine eigene Rolle nicht ändern.');
            }
            if ($target['role'] === 'admin' && $role !== 'admin' && $this->adminCount() <= 1) {
                return $this->back($back, null, 'Es muss mindestens ein Administrator bestehen bleiben.');
            }
            if ($role === 'guest') {
                $exp = $this->guestExpiry($r->str('guest_expires', '', 10)) ?? gmdate('Y-m-d H:i:s', time() + 30 * 86400);
                if (!Database::value("SELECT 1 FROM conversation_members cm JOIN conversations c ON c.id = cm.conversation_id WHERE cm.user_id = ? AND cm.left_at IS NULL AND c.type = 'group' LIMIT 1", [$id]) && !$r->intList('conversation_ids')) {
                    return $this->back($back, null, 'Für einen Gast muss mindestens eine Gruppe gewählt werden.');
                }
            }
        }
        $emailChanged = $email !== $target['email'];
        Database::exec(
            'UPDATE users SET display_name = ?, email = ?, role = ?, email_verified_at = ?, updated_at = ? WHERE id = ?',
            [$name, $email, $role === 'guest' ? 'guest' : $role, $emailChanged ? null : $target['email_verified_at'], now(), $id]
        );
        if ($role !== $target['role']) {
            if ($role === 'guest') {
                $this->makeGuest($id, $exp, $r->intList('conversation_ids'));
            } elseif ($target['role'] === 'guest') {
                Database::exec('UPDATE users SET guest_expires_at = NULL WHERE id = ?', [$id]);
            }
        }
        $changes = [];
        foreach (['display_name' => $name, 'email' => $email, 'role' => $role] as $k => $v) {
            if ($target[$k] !== $v) {
                $changes[] = $k;
            }
        }
        Audit::log('admin.user_updated', (int) $admin['id'], 'user', $id, implode(',', $changes));
        if ($emailChanged) {
            Auth::sendVerification($id);
        }
        return $this->back($back, 'Änderungen gespeichert.' . ($emailChanged ? ' Die neue E-Mail-Adresse muss erneut bestätigt werden.' : ''));
    }

    public function userAction(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        $action = $r->str('action', '', 30);
        $target = User::find($id);
        if (!$target || $target['deleted_at'] !== null) {
            throw new HttpException(404, 'Benutzer nicht gefunden.');
        }
        $self = $id === (int) $admin['id'];
        $back = 'admin/users?edit=' . $id;
        $isLastAdmin = $target['role'] === 'admin' && (int) $target['is_active'] === 1 && $this->adminCount() <= 1;

        switch ($action) {
            case 'deactivate':
                if ($self || $isLastAdmin) {
                    return $this->back($back, null, $self ? 'Du kannst dein eigenes Konto nicht deaktivieren.' : 'Der letzte Administrator kann nicht deaktiviert werden.');
                }
                Database::exec('UPDATE users SET is_active = 0, updated_at = ? WHERE id = ?', [now(), $id]);
                Database::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [now(), $id]);
                $msg = 'Konto deaktiviert. Aktive Sitzungen wurden beendet.';
                break;
            case 'activate':
                Database::exec('UPDATE users SET is_active = 1, updated_at = ? WHERE id = ?', [now(), $id]);
                $msg = 'Konto aktiviert.';
                break;
            case 'reset_password':
                if (!Mail::configured()) {
                    return $this->back($back, null, 'E-Mail-Versand ist nicht konfiguriert.');
                }
                $msg = Auth::sendResetMail($target) ? 'Link zum Zurücksetzen des Passworts wurde gesendet.' : null;
                if ($msg === null) {
                    return $this->back($back, null, 'Die E-Mail konnte nicht versendet werden.');
                }
                break;
            case 'resend_verification':
                if (!Mail::configured() || !Auth::sendVerification($id)) {
                    return $this->back($back, null, 'Die Bestätigungs-E-Mail konnte nicht gesendet werden (bereits bestätigt oder SMTP nicht verfügbar).');
                }
                $msg = 'Bestätigungs-E-Mail gesendet.';
                break;
            case 'verify':
                Database::exec('UPDATE users SET email_verified_at = COALESCE(email_verified_at, ?), updated_at = ? WHERE id = ?', [now(), now(), $id]);
                $msg = 'E-Mail-Adresse als bestätigt markiert.';
                break;
            case 'reset_2fa':
                \App\Services\Totp::disable($id, 'admin:' . $admin['id']);
                Database::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL AND id <> ?', [now(), $id, $self ? (int) ($admin['_session_id'] ?? 0) : 0]);
                $msg = '2FA zurückgesetzt. Die Person kann sie nach der nächsten Anmeldung neu einrichten.';
                break;
            case 'unlock':
                RateLimiter::clear('login_acct', $target['email']);
                RateLimiter::clear('totp', (string) $id);
                Audit::log('user.unlocked', (int) $admin['id'], 'user', $id);
                $msg = 'Sperre nach Fehlversuchen aufgehoben.';
                break;
            case 'force_pw_change':
                Database::exec('UPDATE users SET must_change_password = 1, updated_at = ? WHERE id = ?', [now(), $id]);
                Audit::log('user.force_pw_change', (int) $admin['id'], 'user', $id);
                $msg = 'Die Person muss bei der nächsten Aktion ein neues Passwort festlegen.';
                break;
            case 'revoke_sessions':
                Database::exec('UPDATE user_sessions SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL', [now(), $id]);
                $msg = 'Alle Sitzungen wurden beendet.';
                break;
            case 'restrict':
                if ($self) {
                    return $this->back($back, null, 'Du kannst die Bearbeitung deines eigenen Kontos nicht einschränken.');
                }
                if ($isLastAdmin) {
                    return $this->back($back, null, 'Der letzte Administrator kann nicht eingeschränkt werden.');
                }
                Privacy::restrict($id, (int) $admin['id'], trim($r->str('note', '', 255)));
                return $this->back($back, 'Bearbeitung eingeschränkt: Die Person kann nichts mehr senden oder hochladen und erscheint nicht mehr im Verzeichnis. Lesen, Datenexport und Anfragen bleiben möglich.');
            case 'unrestrict':
                Privacy::unrestrict($id, (int) $admin['id']);
                return $this->back($back, 'Einschränkung aufgehoben.');
            case 'clear_profile':
                if ($target['avatar_file']) {
                    Files::deleteProfileImage('avatars', $target['avatar_file']);
                }
                Database::exec("UPDATE users SET status_message = '', avatar_file = NULL, updated_at = ? WHERE id = ?", [now(), $id]);
                foreach (Database::all('SELECT conversation_id FROM conversation_members WHERE user_id = ? AND left_at IS NULL', [$id]) as $c) {
                    Events::push('conversation.updated', (int) $c['conversation_id']);
                }
                $msg = 'Statusnachricht und Profilbild wurden entfernt.';
                break;
            case 'delete':
                if ($self || $isLastAdmin) {
                    return $this->back($back, null, $self ? 'Verwende zum Löschen deines eigenen Kontos die Einstellungen im Messenger.' : 'Der letzte Administrator kann nicht gelöscht werden.');
                }
                if ($r->str('confirm') !== '1') {
                    return $this->back($back, null, 'Bitte bestätige das Löschen.');
                }
                $erase = $r->str('erase_content') === '1';
                Auth::anonymize($id, 'admin', $erase);
                Audit::log('admin.user_deleted', (int) $admin['id'], 'user', $id, $erase ? 'mit Löschung aller Inhalte' : 'Inhalte anonymisiert');
                return $this->back('admin/users', $erase ? 'Konto anonymisiert. Alle Nachrichten und Dateien der Person wurden endgültig gelöscht.' : 'Konto wurde anonymisiert. Nachrichten bleiben ohne Personenbezug erhalten.');
            default:
                throw new HttpException(422, 'Unbekannte Aktion.');
        }
        Audit::log('admin.user_' . $action, (int) $admin['id'], 'user', $id);
        return $this->back($back, $msg);
    }

    public function revokeInvitation(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        Database::exec('UPDATE invitations SET revoked_at = ? WHERE id = ? AND accepted_at IS NULL AND revoked_at IS NULL', [now(), $id]);
        Audit::log('invitation.revoked', (int) $admin['id'], 'invitation', $id);
        return $this->back('admin/users', 'Einladung zurückgezogen.');
    }

    // --------------------------------------------------------------- Gruppen

    public function groups(Request $r): Response
    {
        $q = trim($r->str('q', '', 80));
        $page = max(1, (int) $r->query('page', 1));
        $where = "c.type = 'group'";
        $params = [];
        if ($q !== '') {
            $where .= ' AND c.name LIKE ?';
            $params[] = '%' . User::escapeLike($q) . '%';
        }
        $total = (int) Database::value("SELECT COUNT(*) FROM conversations c WHERE $where", $params);
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);
        $groups = Database::all(
            "SELECT c.id, c.name, c.created_at, c.last_message_at,
                (SELECT COUNT(*) FROM conversation_members m WHERE m.conversation_id = c.id AND m.left_at IS NULL) AS members,
                (SELECT COUNT(*) FROM conversation_members m WHERE m.conversation_id = c.id AND m.left_at IS NULL AND m.role = 'admin') AS admins,
                (SELECT COUNT(*) FROM messages x WHERE x.conversation_id = c.id AND x.kind = 'text' AND x.deleted_at IS NULL) AS messages
             FROM conversations c WHERE $where ORDER BY c.name ASC LIMIT " . self::PER_PAGE . ' OFFSET ' . (($page - 1) * self::PER_PAGE),
            $params
        );
        return $this->page('admin/groups', compact('groups', 'q', 'page', 'pages', 'total'), 'groups', 'Gruppen');
    }

    public function group(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $group = Database::one("SELECT * FROM conversations WHERE id = ? AND type = 'group'", [$id]);
        if (!$group) {
            throw new HttpException(404, 'Gruppe nicht gefunden.');
        }
        $members = Database::all(
            "SELECT u.id, u.display_name, u.email, u.is_active, cm.role, cm.joined_at FROM conversation_members cm JOIN users u ON u.id = cm.user_id
             WHERE cm.conversation_id = ? AND cm.left_at IS NULL ORDER BY FIELD(cm.role,'admin','moderator','member'), u.display_name",
            [$id]
        );
        $counts = [
            'messages' => (int) Database::value("SELECT COUNT(*) FROM messages WHERE conversation_id = ? AND kind = 'text' AND deleted_at IS NULL", [$id]),
            'files' => (int) Database::value('SELECT COUNT(*) FROM message_attachments WHERE conversation_id = ? AND message_id IS NOT NULL', [$id]),
        ];
        return $this->page('admin/group', compact('group', 'members', 'counts'), 'groups', 'Gruppe: ' . $group['name']);
    }

    /** Notfall-Eingriff: ein bestehendes Mitglied zum Gruppenadministrator ernennen (für verwaiste Gruppen). Wird in der Gruppe sichtbar gemacht. */
    public function groupAdmin(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        $uid = $r->int('user_id');
        $group = Database::one("SELECT * FROM conversations WHERE id = ? AND type = 'group'", [$id]);
        $member = Database::one('SELECT cm.*, u.display_name FROM conversation_members cm JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = ? AND cm.user_id = ? AND cm.left_at IS NULL', [$id, $uid]);
        if (!$group || !$member) {
            return $this->back('admin/groups/' . $id, null, 'Mitglied nicht gefunden.');
        }
        if ($member['role'] === 'admin') {
            return $this->back('admin/groups/' . $id, null, 'Diese Person ist bereits Gruppenadministrator.');
        }
        Database::exec("UPDATE conversation_members SET role = 'admin' WHERE conversation_id = ? AND user_id = ?", [$id, $uid]);
        Messages::system($id, $member['display_name'] . ' wurde von der Systemadministration zum Gruppenadministrator ernannt.');
        Events::push('conversation.updated', $id);
        Audit::log('admin.group_admin_assigned', (int) $admin['id'], 'conversation', $id, 'user ' . $uid);
        return $this->back('admin/groups/' . $id, 'Gruppenadministrator ernannt. Die Gruppe sieht einen entsprechenden Hinweis.');
    }

    // -------------------------------------------------------------- Meldungen

    public function reports(Request $r): Response
    {
        $admin = $this->user();
        $status = $r->str('status', 'open', 10) === 'all' ? 'all' : 'open';
        $rows = Database::all(
            "SELECT rp.id, rp.reason, rp.status, rp.created_at, rp.handled_at, m.id AS message_id, m.body, m.deleted_at, m.created_at AS message_at,
                    c.type AS conv_type, c.name AS conv_name, s.display_name AS sender, rep.display_name AS reporter, hb.display_name AS handler
             FROM message_reports rp
             JOIN messages m ON m.id = rp.message_id
             JOIN conversations c ON c.id = m.conversation_id
             LEFT JOIN users s ON s.id = m.sender_id
             LEFT JOIN users rep ON rep.id = rp.reporter_id
             LEFT JOIN users hb ON hb.id = rp.handled_by
             " . ($status === 'open' ? "WHERE rp.status = 'open'" : '') . ' ORDER BY rp.id DESC LIMIT 100'
        );
        $viewed = array_map(fn ($x) => (int) $x['id'], array_filter($rows, fn ($x) => $x['deleted_at'] === null && $x['status'] === 'open'));
        if ($viewed) {
            Audit::log('admin.reports_viewed', (int) $admin['id'], 'report', null, 'Meldungen ' . implode(',', $viewed));
        }
        return $this->page('admin/reports', compact('rows', 'status'), 'reports', 'Gemeldete Nachrichten');
    }

    public function handleReport(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        $report = Database::one('SELECT * FROM message_reports WHERE id = ?', [$id]);
        if (!$report) {
            throw new HttpException(404, 'Meldung nicht gefunden.');
        }
        $action = $r->str('action');
        if ($action === 'remove') {
            Messages::removeReported((int) $admin['id'], (int) $report['message_id']);
            $new = 'actioned';
        } elseif ($action === 'dismiss') {
            $new = 'dismissed';
        } else {
            throw new HttpException(422, 'Unbekannte Aktion.');
        }
        Database::exec('UPDATE message_reports SET status = ?, handled_by = ?, handled_at = ? WHERE id = ?', [$new, $admin['id'], now(), $id]);
        Audit::log('admin.report_' . $new, (int) $admin['id'], 'report', $id);
        return $this->back('admin/reports', $new === 'actioned' ? 'Nachricht entfernt.' : 'Meldung abgelehnt.');
    }

    // ----------------------------------------------------------- Einstellungen

    public function settings(Request $r): Response
    {
        $s = Settings::all();
        return $this->page('admin/settings', [
            's' => $s,
            'smtpPassSet' => ($s['smtp_pass_enc'] ?? '') !== '',
            'appUrl' => base_url(),
            'phpUpload' => min(Files::iniBytes('upload_max_filesize'), Files::iniBytes('post_max_size')),
        ], 'settings', 'Einstellungen');
    }

    private function intIn(Request $r, string $key, int $min, int $max): int
    {
        return max($min, min($max, $r->int($key)));
    }

    /** Kontrastverhältnis zu Weiss (WCAG), damit weisse Schrift auf der Akzentfarbe lesbar bleibt. */
    private function contrastWithWhite(string $hex): float
    {
        $lin = static function (int $c): float {
            $c /= 255;
            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $l = 0.2126 * $lin(hexdec(substr($hex, 1, 2))) + 0.7152 * $lin(hexdec(substr($hex, 3, 2))) + 0.0722 * $lin(hexdec(substr($hex, 5, 2)));
        return 1.05 / ($l + 0.05);
    }

    public function saveOrganization(Request $r): Response
    {
        $admin = $this->user();
        $name = trim($r->str('company_name', '', 60));
        $accent = strtolower(trim($r->str('accent_color', '', 7)));
        if (mb_strlen($name) < 2) {
            return $this->back('admin/settings', null, 'Der Firmenname muss mindestens 2 Zeichen lang sein.');
        }
        if (!preg_match('/^#[0-9a-f]{6}$/', $accent)) {
            return $this->back('admin/settings', null, 'Bitte wähle eine gültige Akzentfarbe.');
        }
        if ($this->contrastWithWhite($accent) < 3.0) {
            return $this->back('admin/settings', null, 'Die Akzentfarbe ist zu hell: Weisse Schrift darauf wäre schlecht lesbar. Wähle eine dunklere Farbe.');
        }
        $domains = [];
        foreach (preg_split('/[\s,;]+/', mb_strtolower($r->str('registration_domains', '', 500)), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $d) {
            $d = ltrim($d, '@');
            if (!preg_match('/^(?=.{3,190}$)([a-z0-9-]+\.)+[a-z]{2,}$/', $d)) {
                return $this->back('admin/settings', null, 'Ungültige Domain: ' . $d);
            }
            $domains[] = $d;
        }
        // Logo
        $logo = $r->file('logo');
        if ($logo && (int) ($logo['error'] ?? 4) !== UPLOAD_ERR_NO_FILE) {
            try {
                $new = Files::saveProfileImage($logo, 'branding', 'fit', 320);
            } catch (HttpException $e) {
                return $this->back('admin/settings', null, $e->getMessage());
            }
            Files::deleteProfileImage('branding', Settings::get('logo_file') ?: null);
            Settings::set('logo_file', $new);
        } elseif ($r->str('remove_logo') === '1') {
            Files::deleteProfileImage('branding', Settings::get('logo_file') ?: null);
            Settings::set('logo_file', '');
        }
        Settings::set('company_name', $name);
        Settings::set('accent_color', $accent);
        Settings::set('registration_enabled', $r->bool('registration_enabled') ? '1' : '0');
        Settings::set('registration_domains', implode(' ', array_unique($domains)));
        Settings::set('require_email_verification', $r->bool('require_email_verification') ? '1' : '0');
        Audit::log('admin.settings_organization', (int) $admin['id']);
        return $this->back('admin/settings', 'Organisation gespeichert.');
    }

    public function saveSystem(Request $r): Response
    {
        $admin = $this->user();
        $phpMb = max(1, intdiv(min(Files::iniBytes('upload_max_filesize'), Files::iniBytes('post_max_size')), 1048576));
        $maxMb = $this->intIn($r, 'max_upload_mb', 1, 2048);
        Settings::set('max_upload_mb', (string) $maxMb);
        Settings::set('voice_enabled', $r->bool('voice_enabled') ? '1' : '0');
        Settings::set('voice_max_seconds', (string) $this->intIn($r, 'voice_max_seconds', 5, 1800));
        Settings::set('message_edit_minutes', (string) $this->intIn($r, 'message_edit_minutes', 0, 10080));
        Settings::set('retention_days_messages', (string) $this->intIn($r, 'retention_days_messages', 0, 3650));
        Settings::set('retention_days_audit', (string) $this->intIn($r, 'retention_days_audit', 0, 3650));
        Settings::set('retention_days_inactive_orphan_uploads', (string) $this->intIn($r, 'retention_days_inactive_orphan_uploads', 1, 30));
        Settings::set('email_notifications_enabled', $r->bool('email_notifications_enabled') ? '1' : '0');
        Audit::log('admin.settings_system', (int) $admin['id']);
        $note = 'Systemeinstellungen gespeichert.';
        if ($maxMb > $phpMb) {
            $note .= " Hinweis: PHP erlaubt auf diesem Server nur $phpMb MB pro Upload (upload_max_filesize / post_max_size). Erhöhe diese Werte beim Hosting, sonst gilt das kleinere Limit.";
        }
        return $this->back('admin/settings', $note);
    }

    /** SMTP-Konfiguration aus dem Formular; leeres Passwort = gespeichertes beibehalten. */
    private function smtpFromRequest(Request $r): array
    {
        $security = $r->str('smtp_security');
        $pass = $r->str('smtp_pass', '', 500);
        return [
            'host' => trim($r->str('smtp_host', '', 190)),
            'port' => $this->intIn($r, 'smtp_port', 1, 65535) ?: 587,
            'security' => in_array($security, ['none', 'tls', 'ssl'], true) ? $security : 'tls',
            'user' => trim($r->str('smtp_user', '', 190)),
            'pass' => $pass !== '' ? $pass : Crypto::decrypt(Settings::get('smtp_pass_enc')),
            'from_email' => trim($r->str('smtp_from_email', '', 190)),
            'from_name' => trim($r->str('smtp_from_name', '', 100)),
        ];
    }

    public function saveSmtp(Request $r): Response
    {
        $admin = $this->user();
        $cfg = $this->smtpFromRequest($r);
        if ($cfg['host'] !== '' && !Auth::validEmail($cfg['from_email'])) {
            return $this->back('admin/settings', null, 'Bitte gib eine gültige Absenderadresse an.');
        }
        Settings::set('smtp_host', $cfg['host']);
        Settings::set('smtp_port', (string) $cfg['port']);
        Settings::set('smtp_security', $cfg['security']);
        Settings::set('smtp_user', $cfg['user']);
        if ($r->str('smtp_pass_clear') === '1') {
            Settings::set('smtp_pass_enc', '');
        } elseif ($r->str('smtp_pass', '', 500) !== '') {
            Settings::set('smtp_pass_enc', Crypto::encrypt($cfg['pass']));
        }
        Settings::set('smtp_from_email', $cfg['from_email']);
        Settings::set('smtp_from_name', $cfg['from_name']);
        Audit::log('admin.settings_smtp', (int) $admin['id'], null, null, $cfg['host']);
        return $this->back('admin/settings', 'E-Mail-Einstellungen gespeichert.');
    }

    public function testSmtp(Request $r): Response
    {
        $admin = $this->user();
        $cfg = $this->smtpFromRequest($r);
        if ($cfg['host'] === '' || !Auth::validEmail($cfg['from_email'])) {
            return $this->back('admin/settings', null, 'Für den Test werden Server und Absenderadresse benötigt.');
        }
        $to = trim($r->str('test_to', '', 190));
        $res = $to !== '' ? (Auth::validEmail($to) ? Mail::sendTest($cfg, $to) : ['ok' => false, 'error' => 'Ungültige Empfängeradresse.']) : Mail::testConnection($cfg);
        Audit::log('admin.smtp_test', (int) $admin['id'], null, null, $res['ok'] ? 'ok' : 'fehler');
        if ($res['ok']) {
            return $this->back('admin/settings', $to !== '' ? "Testnachricht an $to wurde versendet." : 'Verbindung zum SMTP-Server erfolgreich. Die Einstellungen sind noch nicht gespeichert.');
        }
        return $this->back('admin/settings', null, 'SMTP-Test fehlgeschlagen: ' . $res['error']);
    }

    public function runCleanup(Request $r): Response
    {
        $admin = $this->user();
        $stats = Retention::run();
        Settings::set('last_cleanup_at', (string) time());
        Audit::log('admin.cleanup', (int) $admin['id']);
        $parts = [];
        foreach ($stats as $k => $v) {
            if ($v > 0) {
                $parts[] = "$k: $v";
            }
        }
        return $this->back('admin/settings', 'Bereinigung abgeschlossen' . ($parts ? ' (' . implode(', ', $parts) . ').' : ': nichts zu bereinigen.'));
    }

    // ---------------------------------------------------------------- Protokoll

    public function logs(Request $r): Response
    {
        $page = max(1, (int) $r->query('page', 1));
        $action = trim($r->str('action', '', 60));
        $who = trim($r->str('user', '', 80));
        $from = trim($r->str('from', '', 10));
        $to = trim($r->str('to', '', 10));
        $where = '1=1';
        $params = [];
        if ($action !== '') {
            $where .= ' AND a.action LIKE ?';
            $params[] = User::escapeLike($action) . '%';
        }
        if ($who !== '') {
            $where .= ' AND (u.display_name LIKE ? OR u.email LIKE ?)';
            $like = '%' . User::escapeLike($who) . '%';
            array_push($params, $like, $like);
        }
        $tz = new \DateTimeZone((string) Config::get('app.timezone', 'Europe/Zurich'));
        foreach ([['from', $from, '>='], ['to', $to, '<']] as [$k, $v, $op]) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
                $d = new \DateTimeImmutable($v . ' 00:00:00', $tz);
                if ($k === 'to') {
                    $d = $d->modify('+1 day');
                }
                $where .= " AND a.created_at $op ?";
                $params[] = $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
            }
        }
        $perPage = 50;
        $total = (int) Database::value("SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where", $params);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $rows = Database::all(
            "SELECT a.*, u.display_name, u.email FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE $where ORDER BY a.id DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage),
            $params
        );
        return $this->page('admin/logs', compact('rows', 'page', 'pages', 'total', 'action', 'who', 'from', 'to'), 'logs', 'Protokoll');
    }
}
