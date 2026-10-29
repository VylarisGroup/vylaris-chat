<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Models\User;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Chat;
use App\Services\Events;
use App\Services\Files;
use App\Services\Messages;
use App\Services\Notifications;
use App\Services\Settings;

/** Eigenes Profil, Einstellungen, Sitzungen, Benutzerverzeichnis und Start-Daten der Oberfläche. */
final class MeController extends Controller
{
    private function meView(array $u): array
    {
        return [
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'username' => $u['username'],
            'display_name' => $u['display_name'],
            'status_message' => $u['status_message'],
            'role' => $u['role'],
            'presence_pref' => $u['presence_pref'],
            'restricted' => !empty($u['processing_restricted_at']),
            'has_avatar' => !empty($u['avatar_file']),
            'avatar_v' => !empty($u['avatar_file']) ? substr(md5($u['avatar_file']), 0, 8) : null,
            'settings' => User::settings((int) $u['id']),
        ];
    }

    public function bootstrap(Request $r): Response
    {
        $u = $this->user();
        $uid = (int) $u['id'];
        return $this->json([
            'me' => $this->meView($u),
            'branding' => Settings::branding(),
            'conversations' => Chat::listFor($uid),
            'cursor' => Events::latestId(),
            'drafts' => \App\Services\Drafts::listFor($uid),
            'notifications_unread' => Notifications::unreadCount($uid),
            'csrf' => Csrf::token(),
            'limits' => [
                'max_upload_bytes' => Files::maxBytes(),
                'max_body' => Messages::MAX_BODY,
                'voice_enabled' => Settings::bool('voice_enabled'),
                'voice_max_seconds' => max(5, min(1800, Settings::int('voice_max_seconds'))),
                'edit_minutes' => Settings::int('message_edit_minutes'),
            ],
            'reactions' => Messages::REACTIONS,
            'base' => app_base_path(),
            'e2ee' => false,
            'realtime' => Settings::get('realtime_mode') === 'sse' ? 'sse' : 'poll',
            'push' => Settings::bool('push_enabled') ? ['public_key' => Settings::get('vapid_public')] : null,
            'meets' => \App\Services\Meets::enabled() ? ['origin' => \App\Services\Meets::embedOrigin()] : null,
        ]);
    }

    public function update(Request $r): Response
    {
        $u = $this->user();
        $sets = [];
        $params = [];
        if ($r->input('display_name') !== null) {
            $name = trim($r->str('display_name', '', 80));
            if (mb_strlen($name) < 2) {
                throw new HttpException(422, 'Der Anzeigename muss mindestens 2 Zeichen lang sein.');
            }
            $sets[] = 'display_name = ?';
            $params[] = $name;
        }
        if ($r->input('status_message') !== null) {
            $sets[] = 'status_message = ?';
            $params[] = trim(str_replace("\n", ' ', $r->str('status_message', '', 140)));
        }
        if ($r->input('presence_pref') !== null) {
            $pref = $r->str('presence_pref');
            if (!in_array($pref, ['auto', 'away', 'invisible'], true)) {
                throw new HttpException(422, 'Ungültiger Status.');
            }
            $sets[] = 'presence_pref = ?';
            $params[] = $pref;
        }
        if ($sets) {
            $sets[] = 'updated_at = ?';
            $params[] = now();
            $params[] = $u['id'];
            Database::exec('UPDATE users SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
            $this->touchConversations((int) $u['id']);
        }
        return $this->json(['me' => $this->meView(User::find((int) $u['id']))]);
    }

    /** Andere Mitglieder sollen Profiländerungen sehen (Listen aktualisieren). */
    private function touchConversations(int $userId): void
    {
        foreach (Database::all('SELECT conversation_id FROM conversation_members WHERE user_id = ? AND left_at IS NULL', [$userId]) as $c) {
            Events::push('conversation.updated', (int) $c['conversation_id']);
        }
    }

    public function settings(Request $r): Response
    {
        $u = $this->user();
        $bools = ['notify_inapp', 'notify_browser', 'notify_email_mentions', 'notify_email_direct', 'notify_sound', 'show_last_seen', 'show_read_receipts', 'enter_sends'];
        $sets = [];
        $params = [];
        foreach ($bools as $k) {
            if ($r->input($k) !== null) {
                $sets[] = "$k = ?";
                $params[] = $r->bool($k) ? 1 : 0;
            }
        }
        if ($r->input('theme') !== null) {
            $theme = $r->str('theme');
            if (!in_array($theme, ['system', 'light', 'dark'], true)) {
                throw new HttpException(422, 'Ungültiges Design.');
            }
            $sets[] = 'theme = ?';
            $params[] = $theme;
        }
        if ($sets) {
            $sets[] = 'updated_at = ?';
            $params[] = now();
            $params[] = $u['id'];
            Database::exec('UPDATE user_settings SET ' . implode(', ', $sets) . ' WHERE user_id = ?', $params);
        }
        return $this->json(['settings' => User::settings((int) $u['id'])]);
    }

    public function avatar(Request $r): Response
    {
        $u = $this->user();
        $file = $r->file('file');
        if (!$file) {
            throw new HttpException(422, 'Bitte wähle ein Bild aus.');
        }
        $name = Files::saveProfileImage($file, 'avatars', 'square', 256);
        Files::deleteProfileImage('avatars', $u['avatar_file']);
        Database::exec('UPDATE users SET avatar_file = ?, updated_at = ? WHERE id = ?', [$name, now(), $u['id']]);
        $this->touchConversations((int) $u['id']);
        return $this->json(['me' => $this->meView(User::find((int) $u['id']))]);
    }

    public function removeAvatar(Request $r): Response
    {
        $u = $this->user();
        Files::deleteProfileImage('avatars', $u['avatar_file']);
        Database::exec('UPDATE users SET avatar_file = NULL, updated_at = ? WHERE id = ?', [now(), $u['id']]);
        $this->touchConversations((int) $u['id']);
        return $this->json(['me' => $this->meView(User::find((int) $u['id']))]);
    }

    public function password(Request $r): Response
    {
        $u = $this->user();
        $err = Auth::changePassword($u, $r->str('current', '', 500), $r->str('new', '', 500));
        if ($err !== null) {
            throw new HttpException(422, $err);
        }
        return $this->json(['ok' => true]);
    }

    public function sessions(Request $r): Response
    {
        $u = $this->user();
        $rows = Database::all(
            'SELECT id, ip, user_agent, created_at, last_activity_at FROM user_sessions WHERE user_id = ? AND revoked_at IS NULL ORDER BY last_activity_at DESC LIMIT 20',
            [$u['id']]
        );
        return $this->json(['sessions' => array_map(fn ($s) => [
            'id' => (int) $s['id'],
            'ip' => $s['ip'],
            'user_agent' => $s['user_agent'],
            'created_at' => iso($s['created_at']),
            'last_activity_at' => iso($s['last_activity_at']),
            'current' => (int) $s['id'] === (int) $u['_session_id'],
        ], $rows)]);
    }

    public function revokeSession(Request $r): Response
    {
        $u = $this->user();
        $id = (int) $r->params['id'];
        if ($id === (int) $u['_session_id']) {
            throw new HttpException(422, 'Die aktuelle Sitzung kannst du über „Abmelden“ beenden.');
        }
        Database::exec('UPDATE user_sessions SET revoked_at = ? WHERE id = ? AND user_id = ? AND revoked_at IS NULL', [now(), $id, $u['id']]);
        Audit::log('session.revoked', (int) $u['id'], 'session', $id);
        return $this->json(['ok' => true]);
    }

    public function revokeOtherSessions(Request $r): Response
    {
        $u = $this->user();
        Auth::revokeOtherSessions((int) $u['id'], (int) $u['_session_id']);
        Audit::log('session.revoked_all', (int) $u['id']);
        return $this->json(['ok' => true]);
    }

    public function deleteAccount(Request $r): Response
    {
        $u = $this->user();
        if (!password_verify($r->str('password', '', 500), $u['password_hash'])) {
            Audit::log('account.delete_denied', (int) $u['id']);
            throw new HttpException(422, 'Das Passwort ist falsch.');
        }
        if ($u['role'] === 'admin') {
            $admins = (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL");
            if ($admins <= 1) {
                throw new HttpException(422, 'Du bist der letzte Administrator. Ernenne zuerst jemand anderen zum Administrator.');
            }
        }
        $uid = (int) $u['id'];
        Auth::anonymize($uid, 'self', $r->bool('erase_content'));
        $_SESSION = [];
        return $this->json(['ok' => true, 'redirect' => url('login')]);
    }

    // ------------------------------------------------------------ Verzeichnis

    public function users(Request $r): Response
    {
        $u = $this->user();
        $q = trim($r->str('q', '', 80));
        return $this->json(['users' => User::directory($q, 40, (int) $u['id'])]);
    }

    // ------------------------------------------------------- Bildauslieferung

    public function userAvatar(Request $r): Response
    {
        $this->user();
        $target = User::find((int) $r->params['id']);
        return Files::serveProfileImage('avatars', $target['avatar_file'] ?? null);
    }

    public function conversationAvatar(Request $r): Response
    {
        $u = $this->user();
        $cid = (int) $r->params['id'];
        Chat::requireMember($cid, (int) $u['id']);
        $conv = Chat::conversation($cid);
        return Files::serveProfileImage('avatars', ($conv['type'] ?? '') === 'group' ? $conv['avatar_file'] : null);
    }

    public function logo(Request $r): Response
    {
        $file = Settings::get('logo_file');
        return Files::serveProfileImage('branding', $file !== '' ? $file : null);
    }
}
