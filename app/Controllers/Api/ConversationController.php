<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Chat;
use App\Services\Events;
use App\Services\Files;
use App\Services\Messages;

final class ConversationController extends Controller
{
    private function id(Request $r): int
    {
        return (int) ($r->params['id'] ?? 0);
    }

    public function index(Request $r): Response
    {
        return $this->json(['conversations' => Chat::listFor((int) $this->user()['id'])]);
    }

    public function show(Request $r): Response
    {
        return $this->json(['conversation' => Chat::details($this->id($r), (int) $this->user()['id'])]);
    }

    public function direct(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = Chat::directWith($uid, $r->int('user_id'));
        return $this->json(['conversation' => Chat::listFor($uid, [$id])[0] ?? null], 201);
    }

    public function createGroup(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = Chat::createGroup($uid, $r->str('name', '', 100), $r->str('description', '', 500), $r->intList('member_ids'));
        return $this->json(['conversation' => Chat::listFor($uid, [$id])[0] ?? null], 201);
    }

    public function update(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = $this->id($r);
        Chat::updateGroup(
            $id,
            $uid,
            $r->input('name') !== null ? $r->str('name', '', 100) : null,
            $r->input('description') !== null ? $r->str('description', '', 500) : null
        );
        return $this->json(['conversation' => Chat::listFor($uid, [$id])[0] ?? null]);
    }

    public function avatar(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = $this->id($r);
        $member = Chat::requireMember($id, $uid);
        $conv = Chat::requireGroup($id);
        if (!Chat::canModerate($member)) {
            throw new HttpException(403, 'Nur Moderatoren und Administratoren dürfen das Gruppenbild ändern.');
        }
        $file = $r->file('file');
        if (!$file) {
            throw new HttpException(422, 'Bitte wähle ein Bild aus.');
        }
        $name = Files::saveProfileImage($file, 'avatars', 'square', 256);
        Files::deleteProfileImage('avatars', $conv['avatar_file']);
        Database::exec('UPDATE conversations SET avatar_file = ?, updated_at = ? WHERE id = ?', [$name, now(), $id]);
        Events::push('conversation.updated', $id);
        return $this->json(['conversation' => Chat::listFor($uid, [$id])[0] ?? null]);
    }

    public function removeAvatar(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = $this->id($r);
        $member = Chat::requireMember($id, $uid);
        $conv = Chat::requireGroup($id);
        if (!Chat::canModerate($member)) {
            throw new HttpException(403, 'Keine Berechtigung.');
        }
        Files::deleteProfileImage('avatars', $conv['avatar_file']);
        Database::exec('UPDATE conversations SET avatar_file = NULL, updated_at = ? WHERE id = ?', [now(), $id]);
        Events::push('conversation.updated', $id);
        return $this->json(['conversation' => Chat::listFor($uid, [$id])[0] ?? null]);
    }

    public function addMembers(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = $this->id($r);
        $added = Chat::addMembers($id, $uid, $r->intList('user_ids'));
        return $this->json(['added' => $added, 'details' => Chat::details($id, $uid)]);
    }

    public function removeMember(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = $this->id($r);
        Chat::removeMember($id, $uid, (int) $r->params['uid']);
        $left = Chat::membership($id, $uid) === null;
        return $this->json(['left' => $left, 'details' => $left ? null : Chat::details($id, $uid)]);
    }

    public function setRole(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = $this->id($r);
        Chat::setRole($id, $uid, (int) $r->params['uid'], $r->str('role'));
        return $this->json(['details' => Chat::details($id, $uid)]);
    }

    public function leave(Request $r): Response
    {
        Chat::leave($this->id($r), (int) $this->user()['id']);
        return $this->json(['ok' => true]);
    }

    public function prefs(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = $this->id($r);
        $prefs = [];
        foreach (['muted', 'archived', 'pinned'] as $k) {
            if ($r->input($k) !== null) {
                $prefs[$k] = $r->bool($k);
            }
        }
        Chat::setPrefs($id, $uid, $prefs);
        return $this->json(['conversation' => Chat::listFor($uid, [$id])[0] ?? null]);
    }

    // ------------------------------------------------------------ Nachrichten

    public function messages(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $before = $r->query('before') !== null ? (int) $r->query('before') : null;
        $after = $r->query('after') !== null ? (int) $r->query('after') : null;
        $around = $r->query('around') !== null ? (int) $r->query('around') : null;
        return $this->json(Messages::fetch($this->id($r), $uid, $before, $after, $around, (int) $r->query('limit', 40)));
    }

    public function send(Request $r): Response
    {
        $user = $this->user();
        $res = Messages::send(
            $user,
            $this->id($r),
            $r->str('body', '', Messages::MAX_BODY + 100),
            $r->input('client_uid') !== null ? $r->str('client_uid', '', 36) : null,
            $r->input('reply_to_id') ? $r->int('reply_to_id') : null,
            $r->intList('attachment_ids'),
            $r->input('thread_root_id') ? $r->int('thread_root_id') : null
        );
        return $this->json($res, $res['duplicate'] ? 200 : 201);
    }

    public function read(Request $r): Response
    {
        $res = Messages::markRead($this->id($r), (int) $this->user()['id'], $r->input('message_id') !== null ? $r->int('message_id') : null);
        return $this->json($res);
    }

    public function typing(Request $r): Response
    {
        Messages::typing($this->id($r), (int) $this->user()['id']);
        return $this->json(['ok' => true]);
    }

    public function upload(Request $r): Response
    {
        $file = $r->file('file');
        if (!$file) {
            throw new HttpException(422, 'Es wurde keine Datei übertragen (möglicherweise ist sie grösser als ' . human_size(Files::maxBytes()) . ').');
        }
        return $this->json(['attachment' => Files::upload((int) $this->user()['id'], $this->id($r), $file)], 201);
    }
}
