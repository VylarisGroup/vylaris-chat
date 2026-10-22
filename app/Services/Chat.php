<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Models\User;

/** Unterhaltungen, Mitgliedschaften und Rollen. Nachrichten: siehe Messages. */
final class Chat
{
    public const ROLE_RANK = ['member' => 1, 'moderator' => 2, 'admin' => 3];

    // ------------------------------------------------------------ Mitgliedschaft

    public static function membership(int $conversationId, int $userId): ?array
    {
        return Database::one(
            'SELECT * FROM conversation_members WHERE conversation_id = ? AND user_id = ? AND left_at IS NULL',
            [$conversationId, $userId]
        );
    }

    /** Mitgliedschaft erzwingen. Antwortet mit 404, damit fremde IDs nicht erraten werden können (IDOR-Schutz). */
    public static function requireMember(int $conversationId, int $userId): array
    {
        $m = self::membership($conversationId, $userId);
        if (!$m) {
            throw new HttpException(404, 'Unterhaltung nicht gefunden.');
        }
        return $m;
    }

    public static function conversation(int $id): ?array
    {
        return Database::one('SELECT * FROM conversations WHERE id = ?', [$id]);
    }

    public static function canModerate(array $member): bool
    {
        return self::ROLE_RANK[$member['role']] >= self::ROLE_RANK['moderator'];
    }

    public static function requireGroup(int $conversationId): array
    {
        $c = self::conversation($conversationId);
        if (!$c || $c['type'] !== 'group') {
            throw new HttpException(404, 'Gruppe nicht gefunden.');
        }
        return $c;
    }

    // ----------------------------------------------------------------- Anlegen

    /**
     * Notizen: ein Chat mit sich selbst für Texte, Links und Dateien. Genau eine Unterhaltung je Person (Typ «notes»), nie für Gäste.
     * @return int|null Unterhaltungs-ID, null bei Gästen
     */
    public static function ensureNotes(int $userId): ?int
    {
        static $cache = [];
        if (array_key_exists($userId, $cache)) {
            return $cache[$userId];
        }
        $key = 'notes:' . $userId;
        $id = Database::value('SELECT id FROM conversations WHERE direct_key = ?', [$key]);
        if ($id) {
            Database::exec("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?,?, 'member', ?) ON DUPLICATE KEY UPDATE left_at = NULL", [(int) $id, $userId, now()]);
            return $cache[$userId] = (int) $id;
        }
        $u = User::find($userId);
        if (!$u || $u['role'] === 'guest' || (int) ($u['is_bot'] ?? 0) === 1) {
            return $cache[$userId] = null;
        }
        $id = (int) Database::transaction(static function () use ($key, $userId): int {
            Database::exec("INSERT IGNORE INTO conversations (type, direct_key, name, created_by, created_at, updated_at) VALUES ('notes', ?, 'Notizen', ?, ?, ?)", [$key, $userId, now(), now()]);
            $cid = (int) Database::value('SELECT id FROM conversations WHERE direct_key = ?', [$key]);
            Database::exec("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at, pinned) VALUES (?,?, 'member', ?, 1) ON DUPLICATE KEY UPDATE left_at = NULL", [$cid, $userId, now()]);
            return $cid;
        });
        return $cache[$userId] = $id;
    }

    public static function directWith(int $userId, int $otherId): int
    {
        if ($userId === $otherId) {
            throw new HttpException(422, 'Du kannst keine Unterhaltung mit dir selbst starten.');
        }
        $other = User::find($otherId);
        if (!$other || !(int) $other['is_active']) {
            throw new HttpException(404, 'Benutzer nicht gefunden.');
        }
        $self = User::find($userId);
        if (!empty($other['is_bot'])) {
            Ai::assertMayAdd($self ?? []);
        }
        if (($self['role'] ?? '') === 'guest' || $other['role'] === 'guest') {
            throw new HttpException(403, 'Mit Gästen sind keine Einzelchats möglich. Gäste nutzen die Gruppen, zu denen sie eingeladen wurden.');
        }
        $key = min($userId, $otherId) . ':' . max($userId, $otherId);
        return (int) Database::transaction(function () use ($key, $userId, $otherId) {
            $row = Database::one('SELECT id FROM conversations WHERE direct_key = ?', [$key]);
            if ($row) {
                $id = (int) $row['id'];
            } else {
                $id = Database::insert(
                    "INSERT INTO conversations (type, direct_key, created_by, created_at, updated_at) VALUES ('direct', ?, ?, ?, ?)",
                    [$key, $userId, now(), now()]
                );
            }
            foreach ([$userId, $otherId] as $uid) {
                Database::exec(
                    "INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?,?, 'member', ?)
                     ON DUPLICATE KEY UPDATE left_at = NULL",
                    [$id, $uid, now()]
                );
            }
            Events::push('conversation.added', $id, null, $otherId);
            return $id;
        });
    }

    /** @param int[] $memberIds */
    public static function createGroup(int $creatorId, string $name, string $description, array $memberIds): int
    {
        if ((User::find($creatorId)['role'] ?? '') === 'guest') {
            throw new HttpException(403, 'Gäste können keine Gruppen erstellen.');
        }
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            throw new HttpException(422, 'Der Gruppenname muss zwischen 2 und 100 Zeichen lang sein.');
        }
        $description = mb_substr(trim($description), 0, 500);
        $memberIds = array_values(array_diff(array_unique($memberIds), [$creatorId]));
        if (count($memberIds) > 499) {
            throw new HttpException(422, 'Eine Gruppe darf höchstens 500 Mitglieder haben.');
        }
        // Gäste werden nur durch Administratoren in Gruppen aufgenommen
        $valid = $memberIds ? array_column(Database::all(
            "SELECT id FROM users WHERE id IN (" . Database::in($memberIds) . ") AND is_active = 1 AND deleted_at IS NULL AND role <> 'guest'",
            $memberIds
        ), 'id') : [];

        $id = (int) Database::transaction(function () use ($creatorId, $name, $description, $valid) {
            $id = Database::insert(
                "INSERT INTO conversations (type, name, description, created_by, created_at, updated_at) VALUES ('group', ?, ?, ?, ?, ?)",
                [$name, $description, $creatorId, now(), now()]
            );
            Database::exec("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?,?, 'admin', ?)", [$id, $creatorId, now()]);
            foreach ($valid as $uid) {
                Database::exec("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?,?, 'member', ?)", [$id, (int) $uid, now()]);
            }
            return $id;
        });
        Messages::system($id, (User::find($creatorId)['display_name'] ?? 'Jemand') . ' hat die Gruppe erstellt.');
        foreach ($valid as $uid) {
            Events::push('conversation.added', $id, null, (int) $uid);
            Notifications::create((int) $uid, 'group_added', $creatorId, $id, null, 'Du wurdest zur Gruppe „' . $name . '“ hinzugefügt.');
        }
        Audit::log('group.created', $creatorId, 'conversation', $id);
        return $id;
    }

    /** Für Einladungen: Mitglieder ohne Systemnachricht hinzufügen. */
    public static function addMembersSilently(int $conversationId, array $userIds): void
    {
        foreach ($userIds as $uid) {
            Database::exec(
                "INSERT INTO conversation_members (conversation_id, user_id, role, joined_at) VALUES (?,?, 'member', ?) ON DUPLICATE KEY UPDATE left_at = NULL",
                [$conversationId, (int) $uid, now()]
            );
            Events::push('conversation.added', $conversationId, null, (int) $uid);
        }
    }

    // ------------------------------------------------------ Gruppenverwaltung

    public static function updateGroup(int $conversationId, int $actorId, ?string $name, ?string $description): void
    {
        $member = self::requireMember($conversationId, $actorId);
        self::requireGroup($conversationId);
        if (!self::canModerate($member)) {
            throw new HttpException(403, 'Nur Moderatoren und Administratoren dürfen Gruppeninformationen ändern.');
        }
        $sets = [];
        $params = [];
        if ($name !== null) {
            $name = trim($name);
            if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
                throw new HttpException(422, 'Der Gruppenname muss zwischen 2 und 100 Zeichen lang sein.');
            }
            $sets[] = 'name = ?';
            $params[] = $name;
        }
        if ($description !== null) {
            $sets[] = 'description = ?';
            $params[] = mb_substr(trim($description), 0, 500);
        }
        if (!$sets) {
            return;
        }
        $sets[] = 'updated_at = ?';
        $params[] = now();
        $params[] = $conversationId;
        Database::exec('UPDATE conversations SET ' . implode(', ', $sets) . ' WHERE id = ?', $params);
        if ($name !== null) {
            Messages::system($conversationId, (User::find($actorId)['display_name'] ?? 'Jemand') . ' hat die Gruppe in „' . $name . '“ umbenannt.');
        }
        Events::push('conversation.updated', $conversationId);
        Audit::log('group.updated', $actorId, 'conversation', $conversationId);
    }

    /** @param int[] $userIds */
    public static function addMembers(int $conversationId, int $actorId, array $userIds): int
    {
        $member = self::requireMember($conversationId, $actorId);
        $conv = self::requireGroup($conversationId);
        if (!self::canModerate($member)) {
            throw new HttpException(403, 'Nur Moderatoren und Administratoren dürfen Mitglieder hinzufügen.');
        }
        $userIds = array_values(array_unique(array_filter($userIds)));
        if (!$userIds) {
            return 0;
        }
        $current = (int) Database::value('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL', [$conversationId]);
        if ($current + count($userIds) > 500) {
            throw new HttpException(422, 'Eine Gruppe darf höchstens 500 Mitglieder haben.');
        }
        $actorRole = (string) (User::find($actorId)['role'] ?? 'member');
        $users = Database::all(
            'SELECT id, display_name, role, is_bot FROM users WHERE id IN (' . Database::in($userIds) . ') AND is_active = 1 AND deleted_at IS NULL',
            $userIds
        );
        foreach ($users as $cand) {
            if (!empty($cand['is_bot'])) {
                Ai::assertMayAdd(User::find($actorId) ?? []);
            }
            if ($cand['role'] === 'guest' && $actorRole !== 'admin') {
                throw new HttpException(403, 'Gäste kann nur die Administration zu Gruppen hinzufügen.');
            }
        }
        $actorName = User::find($actorId)['display_name'] ?? 'Jemand';
        $added = 0;
        foreach ($users as $u) {
            $uid = (int) $u['id'];
            $existing = Database::one('SELECT left_at FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [$conversationId, $uid]);
            if ($existing && $existing['left_at'] === null) {
                continue;
            }
            $lastId = (int) ($conv['last_message_id'] ?? 0);
            if ($existing) {
                Database::exec("UPDATE conversation_members SET left_at = NULL, role = 'member', joined_at = ?, last_read_message_id = ? WHERE conversation_id = ? AND user_id = ?", [now(), $lastId, $conversationId, $uid]);
            } else {
                Database::exec("INSERT INTO conversation_members (conversation_id, user_id, role, joined_at, last_read_message_id) VALUES (?,?, 'member', ?, ?)", [$conversationId, $uid, now(), $lastId]);
            }
            Messages::system($conversationId, $actorName . ' hat ' . $u['display_name'] . ' hinzugefügt.');
            Events::push('conversation.added', $conversationId, null, $uid);
            if (!empty($u['is_bot'])) {
                Messages::system($conversationId, 'Die KI antwortet nur, wenn sie mit @' . (string) Database::value('SELECT username FROM users WHERE id = ?', [$uid]) . ' erwähnt wird oder man auf ihre Nachricht antwortet. Dann werden je nach Einstellung auch frühere Nachrichten dieser Gruppe an den KI-Anbieter übermittelt.');
            } else {
                Notifications::create($uid, 'group_added', $actorId, $conversationId, null, 'Du wurdest zur Gruppe „' . $conv['name'] . '“ hinzugefügt.');
            }
            $added++;
        }
        if ($added) {
            Events::push('conversation.updated', $conversationId);
            Audit::log('group.members_added', $actorId, 'conversation', $conversationId, (string) $added);
        }
        return $added;
    }

    public static function removeMember(int $conversationId, int $actorId, int $targetId): void
    {
        $actor = self::requireMember($conversationId, $actorId);
        self::requireGroup($conversationId);
        $target = self::membership($conversationId, $targetId);
        if (!$target) {
            throw new HttpException(404, 'Mitglied nicht gefunden.');
        }
        if ($actorId === $targetId) {
            self::leave($conversationId, $actorId);
            return;
        }
        if (!self::canModerate($actor)) {
            throw new HttpException(403, 'Keine Berechtigung, Mitglieder zu entfernen.');
        }
        // Moderatoren dürfen nur normale Mitglieder entfernen, Administratoren alle ausser sich selbst über diese Route
        if (self::ROLE_RANK[$target['role']] >= self::ROLE_RANK[$actor['role']] && $actor['role'] !== 'admin') {
            throw new HttpException(403, 'Du kannst dieses Mitglied nicht entfernen.');
        }
        Database::exec('UPDATE conversation_members SET left_at = ? WHERE conversation_id = ? AND user_id = ?', [now(), $conversationId, $targetId]);
        $names = Database::all('SELECT id, display_name FROM users WHERE id IN (?, ?)', [$actorId, $targetId]);
        $map = array_column($names, 'display_name', 'id');
        Messages::system($conversationId, ($map[$actorId] ?? 'Jemand') . ' hat ' . ($map[$targetId] ?? 'ein Mitglied') . ' entfernt.');
        Events::push('conversation.removed', $conversationId, null, $targetId);
        Events::push('conversation.updated', $conversationId);
        Audit::log('group.member_removed', $actorId, 'conversation', $conversationId, 'user ' . $targetId);
    }

    public static function leave(int $conversationId, int $userId): void
    {
        $m = self::requireMember($conversationId, $userId);
        self::requireGroup($conversationId);
        Database::exec('UPDATE conversation_members SET left_at = ? WHERE conversation_id = ? AND user_id = ?', [now(), $conversationId, $userId]);
        self::repairAdmins($conversationId);
        Messages::system($conversationId, (User::find($userId)['display_name'] ?? 'Jemand') . ' hat die Gruppe verlassen.');
        Events::push('conversation.removed', $conversationId, null, $userId);
        Events::push('conversation.updated', $conversationId);
        Audit::log('group.left', $userId, 'conversation', $conversationId);
    }

    public static function setRole(int $conversationId, int $actorId, int $targetId, string $role): void
    {
        $actor = self::requireMember($conversationId, $actorId);
        self::requireGroup($conversationId);
        if ($actor['role'] !== 'admin') {
            throw new HttpException(403, 'Nur Administratoren dürfen Rollen vergeben.');
        }
        if (!isset(self::ROLE_RANK[$role])) {
            throw new HttpException(422, 'Ungültige Rolle.');
        }
        $target = self::membership($conversationId, $targetId);
        if (!$target) {
            throw new HttpException(404, 'Mitglied nicht gefunden.');
        }
        if ($role !== 'member' && (User::find($targetId)['role'] ?? '') === 'guest') {
            throw new HttpException(422, 'Gäste können nicht zu Moderatoren oder Administratoren ernannt werden.');
        }
        if ($target['role'] === 'admin' && $role !== 'admin') {
            $admins = (int) Database::value("SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ? AND role = 'admin' AND left_at IS NULL", [$conversationId]);
            if ($admins <= 1) {
                throw new HttpException(422, 'Eine Gruppe braucht mindestens einen Administrator.');
            }
        }
        Database::exec('UPDATE conversation_members SET role = ? WHERE conversation_id = ? AND user_id = ?', [$role, $conversationId, $targetId]);
        Events::push('conversation.updated', $conversationId);
        Audit::log('group.role_changed', $actorId, 'conversation', $conversationId, "user $targetId -> $role");
    }

    /** Stellt sicher, dass jede aktive Gruppe mindestens einen Administrator hat. */
    public static function repairAdmins(int $conversationId): void
    {
        $left = (int) Database::value('SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL', [$conversationId]);
        if ($left === 0) {
            return;
        }
        $admins = (int) Database::value("SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ? AND role = 'admin' AND left_at IS NULL", [$conversationId]);
        if ($admins > 0) {
            return;
        }
        $next = Database::one(
            "SELECT id FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL
             ORDER BY FIELD(role, 'moderator', 'member'), joined_at ASC, id ASC LIMIT 1",
            [$conversationId]
        );
        if ($next) {
            Database::exec("UPDATE conversation_members SET role = 'admin' WHERE id = ?", [$next['id']]);
        }
    }

    /** Persönliche Einstellungen: stummschalten, archivieren, anpinnen. */
    public static function setPrefs(int $conversationId, int $userId, array $prefs): void
    {
        self::requireMember($conversationId, $userId);
        if ((self::conversation($conversationId)['type'] ?? '') === 'notes') {
            return; // Notizen sind immer oben und nicht archivierbar
        }
        $sets = [];
        $params = [];
        foreach (['muted', 'archived', 'pinned'] as $key) {
            if (array_key_exists($key, $prefs)) {
                $sets[] = "$key = ?";
                $params[] = $prefs[$key] ? 1 : 0;
            }
        }
        if (!$sets) {
            return;
        }
        $params[] = $conversationId;
        $params[] = $userId;
        Database::exec('UPDATE conversation_members SET ' . implode(', ', $sets) . ' WHERE conversation_id = ? AND user_id = ?', $params);
        Events::push('conversation.prefs', $conversationId, null, $userId);
    }

    // ------------------------------------------------------------------ Listen

    /**
     * Unterhaltungsliste eines Benutzers inklusive Ungelesen-Zähler.
     * @param int[]|null $onlyIds auf bestimmte Unterhaltungen einschränken
     */
    public static function listFor(int $userId, ?array $onlyIds = null, ?bool $archived = null): array
    {
        if ($onlyIds === null && $archived === null) {
            self::ensureNotes($userId);
        }
        $where = 'm.user_id = ? AND m.left_at IS NULL';
        // Platzhalter-Reihenfolge: unread (Absender), mentions (Benutzer), dann WHERE
        $params = [$userId, $userId, $userId];
        if ($archived !== null) {
            $where .= ' AND m.archived = ?';
            $params[] = $archived ? 1 : 0;
        }
        if ($onlyIds !== null) {
            if (!$onlyIds) {
                return [];
            }
            $where .= ' AND c.id IN (' . Database::in($onlyIds) . ')';
            array_push($params, ...$onlyIds);
        }
        $rows = Database::all(
            "SELECT c.id, c.type, c.name, c.description, c.avatar_file, c.ai_context, c.last_message_id, c.last_message_at, c.created_at,
                    m.role, m.muted, m.archived, m.pinned, m.last_read_message_id,
                    (SELECT COUNT(*) FROM messages x WHERE x.conversation_id = c.id
                        AND x.id > GREATEST(m.last_read_message_id, m.visible_from_message_id)
                        AND x.sender_id IS NOT NULL AND x.sender_id <> ? AND x.deleted_at IS NULL AND x.kind <> 'system' AND x.thread_root_id IS NULL) AS unread,
                    (SELECT COUNT(*) FROM messages x JOIN message_mentions mm ON mm.message_id = x.id AND mm.user_id = ?
                        WHERE x.conversation_id = c.id AND x.id > m.last_read_message_id AND x.deleted_at IS NULL AND x.thread_root_id IS NULL) AS unread_mentions,
                    (SELECT COUNT(*) FROM conversation_members cm WHERE cm.conversation_id = c.id AND cm.left_at IS NULL) AS member_count
             FROM conversation_members m
             JOIN conversations c ON c.id = m.conversation_id
             WHERE $where
             ORDER BY m.pinned DESC, COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC
             LIMIT 300",
            $params
        );
        if (!$rows) {
            return [];
        }
        $ids = array_map(fn ($r) => (int) $r['id'], $rows);

        // Letzte Nachrichten
        $lastIds = array_filter(array_map(fn ($r) => $r['last_message_id'] ? (int) $r['last_message_id'] : null, $rows));
        $last = [];
        if ($lastIds) {
            foreach (Database::all(
                'SELECT m.id, m.sender_id, m.kind, m.body, m.deleted_at, m.created_at, u.display_name,
                        (SELECT COUNT(*) FROM message_attachments a WHERE a.message_id = m.id) AS att
                 FROM messages m LEFT JOIN users u ON u.id = m.sender_id WHERE m.id IN (' . Database::in($lastIds) . ')',
                array_values($lastIds)
            ) as $mrow) {
                $last[(int) $mrow['id']] = $mrow;
            }
        }

        // Gegenüber in Einzelchats
        $directIds = array_map(fn ($r) => (int) $r['id'], array_filter($rows, fn ($r) => $r['type'] === 'direct'));
        $others = [];
        if ($directIds) {
            $urows = Database::all(
                'SELECT cm.conversation_id, ' . User::PUBLIC_COLUMNS . '
                 FROM conversation_members cm JOIN users u ON u.id = cm.user_id LEFT JOIN user_settings s ON s.user_id = u.id
                 WHERE cm.conversation_id IN (' . Database::in($directIds) . ') AND cm.user_id <> ?',
                array_merge($directIds, [$userId])
            );
            foreach ($urows as $u) {
                $others[(int) $u['conversation_id']] = User::publicView($u);
            }
        }

        $botId = Ai::botId();
        $aiConvs = $botId ? array_map('intval', array_column(Database::all('SELECT conversation_id FROM conversation_members WHERE user_id = ? AND left_at IS NULL AND conversation_id IN (' . Database::in($ids) . ')', array_merge([$botId], $ids)), 'conversation_id')) : [];

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $lm = $r['last_message_id'] ? ($last[(int) $r['last_message_id']] ?? null) : null;
            $preview = null;
            if ($lm) {
                $text = $lm['deleted_at'] !== null ? 'Nachricht gelöscht' : (trim((string) $lm['body']) !== '' ? mb_substr(preg_replace('/\s+/u', ' ', (string) $lm['body']) ?? '', 0, 120) : ((int) $lm['att'] > 0 ? 'Anhang' : ''));
                if ($lm['deleted_at'] === null && $lm['kind'] === 'poll') {
                    $text = 'Umfrage: ' . $text;
                } elseif ($lm['deleted_at'] === null && $lm['kind'] === 'event') {
                    $text = 'Termin: ' . $text;
                } elseif ($lm['deleted_at'] === null && $lm['kind'] === 'meeting') {
                    $text = 'Videomeeting';
                }
                $preview = [
                    'text' => $text,
                    'sender' => $lm['kind'] === 'system' ? null : $lm['display_name'],
                    'sender_id' => $lm['sender_id'] ? (int) $lm['sender_id'] : null,
                    'kind' => $lm['kind'],
                    'deleted' => $lm['deleted_at'] !== null,
                    'at' => iso($lm['created_at']),
                ];
            }
            $other = $others[$id] ?? null;
            $out[] = [
                'id' => $id,
                'type' => $r['type'],
                'name' => $r['type'] === 'direct' ? ($other['display_name'] ?? 'Unbekannt') : ($r['type'] === 'notes' ? 'Notizen' : $r['name']),
                'description' => $r['description'],
                'has_avatar' => $r['type'] === 'group' && !empty($r['avatar_file']),
                'avatar_v' => !empty($r['avatar_file']) ? substr(md5($r['avatar_file']), 0, 8) : null,
                'other' => $other,
                'member_count' => (int) $r['member_count'],
                'ai_context' => $r['ai_context'],
                'has_ai' => in_array($id, $aiConvs, true),
                'role' => $r['role'],
                'muted' => (bool) $r['muted'],
                'archived' => (bool) $r['archived'],
                'pinned' => (bool) $r['pinned'],
                'unread' => (int) $r['unread'],
                'unread_mentions' => (int) $r['unread_mentions'],
                'last_read_message_id' => (int) $r['last_read_message_id'],
                'last_message' => $preview,
                'last_message_at' => iso($r['last_message_at'] ?? $r['created_at']),
            ];
        }
        return $out;
    }

    /** Detailansicht für den rechten Bereich: Mitglieder, geteilte Dateien und Links. */
    public static function details(int $conversationId, int $userId): array
    {
        $member = self::requireMember($conversationId, $userId);
        $conv = self::conversation($conversationId);
        $rows = Database::all(
            'SELECT ' . User::PUBLIC_COLUMNS . ', cm.role AS member_role, cm.joined_at
             FROM conversation_members cm JOIN users u ON u.id = cm.user_id LEFT JOIN user_settings s ON s.user_id = u.id
             WHERE cm.conversation_id = ? AND cm.left_at IS NULL
             ORDER BY FIELD(cm.role, \'admin\', \'moderator\', \'member\'), u.display_name ASC',
            [$conversationId]
        );
        $viewerIsGuest = (User::find($userId)['role'] ?? '') === 'guest';
        $members = array_map(function ($r) use ($viewerIsGuest) {
            $v = User::publicView($r);
            $v['member_role'] = $r['member_role'];
            if ($viewerIsGuest) {
                $v['email'] = ''; // Gäste sehen keine Adressen
            }
            return $v;
        }, $rows);

        $files = array_map([Messages::class, 'attachmentView'], Database::all(
            'SELECT a.*, m.created_at AS message_at FROM message_attachments a JOIN messages m ON m.id = a.message_id
             WHERE a.conversation_id = ? AND m.deleted_at IS NULL AND m.id > ? ORDER BY a.id DESC LIMIT 40',
            [$conversationId, (int) $member['visible_from_message_id']]
        ));

        $links = [];
        $msgs = Database::all(
            "SELECT m.id, m.body, m.created_at FROM messages m
             WHERE m.conversation_id = ? AND m.deleted_at IS NULL AND m.id > ? AND (m.body LIKE '%http://%' OR m.body LIKE '%https://%')
             ORDER BY m.id DESC LIMIT 100",
            [$conversationId, (int) $member['visible_from_message_id']]
        );
        foreach ($msgs as $msg) {
            if (preg_match_all('~https?://[^\s<>"\']+~iu', (string) $msg['body'], $mm)) {
                foreach ($mm[0] as $link) {
                    $link = rtrim($link, '.,;:!?)]}');
                    $host = parse_url($link, PHP_URL_HOST);
                    if ($host && !isset($links[$link])) {
                        $links[$link] = ['url' => $link, 'host' => $host, 'message_id' => (int) $msg['id'], 'at' => iso($msg['created_at'])];
                    }
                }
            }
            if (count($links) >= 30) {
                break;
            }
        }

        return [
            'id' => (int) $conv['id'],
            'type' => $conv['type'],
            'name' => $conv['name'],
            'description' => $conv['description'],
            'created_at' => iso($conv['created_at']),
            'my_role' => $member['role'],
            'ai_context' => $conv['ai_context'] ?? 'recent',
            'has_ai' => (bool) array_filter($members, static fn ($m) => !empty($m['bot'])),
            'members' => $members,
            'files' => $files,
            'links' => array_values($links),
        ];
    }
}
