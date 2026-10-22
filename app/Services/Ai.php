<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Deferred;
use App\Core\HttpException;
use App\Models\User;

/**
 * KI-Assistent über einen OpenAI-kompatiblen Endpunkt (POST <Basis-URL>/chat/completions).
 *
 * Genau eine KI pro Installation. Sie ist ein Systemkonto (users.is_bot = 1) ohne Anmeldung, das wie eine Person
 * in Einzelchats und Gruppen Mitglied sein kann. In Gruppen antwortet sie nur auf @Erwähnung oder auf Antworten auf
 * eigene Nachrichten, im Einzelchat auf jede Nachricht. Welche früheren Nachrichten mitgeschickt werden, wird pro
 * Chat gewählt (nur die Anfrage / die letzten Nachrichten / der ganze Verlauf), nie mehr als das Token-Limit.
 */
final class Ai
{
    public const LEVELS = ['request', 'recent', 'full'];
    public const RECENT_MESSAGES = 20;
    public const HARD_CONTEXT_LIMIT = 90000; // Tokens, nicht überschreitbar

    // ------------------------------------------------------------ Einstellungen

    public static function enabled(): bool
    {
        return Settings::bool('ai_enabled') && trim(Settings::get('ai_endpoint')) !== '' && trim(Settings::get('ai_model')) !== '';
    }

    public static function name(): string
    {
        $n = trim(Settings::get('ai_name'));
        return $n !== '' ? $n : 'KI-Assistent';
    }

    public static function key(): string
    {
        return Crypto::decrypt(Settings::get('ai_key_enc'));
    }

    public static function maxContextTokens(): int
    {
        return max(500, min(self::HARD_CONTEXT_LIMIT, (int) Settings::get('ai_max_context_tokens', '30000')));
    }

    /** Vollständige URL des Chat-Endpunkts oder null bei ungültiger Einstellung. */
    public static function url(): ?string
    {
        $base = rtrim(trim(Settings::get('ai_endpoint')), '/');
        $p = parse_url($base);
        if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
            return null;
        }
        return str_ends_with($base, '/chat/completions') ? $base : $base . '/chat/completions';
    }

    /** Nutzungsrecht: Gäste nie; sonst gemäss Einstellung der Administration (alle / nur Admins / Liste). */
    public static function canUse(array $user): bool
    {
        if (!self::enabled() || !empty($user['is_bot']) || ($user['role'] ?? '') === 'guest') {
            return false;
        }
        switch (Settings::get('ai_access', 'all')) {
            case 'admins':
                return ($user['role'] ?? '') === 'admin';
            case 'list':
                $names = array_filter(array_map(static fn ($s) => ltrim(mb_strtolower(trim($s)), '@'), preg_split('/[\s,;]+/', Settings::get('ai_allowed_users')) ?: []));
                return in_array(mb_strtolower((string) ($user['username'] ?? '')), $names, true) || ($user['role'] ?? '') === 'admin';
            default:
                return true;
        }
    }

    // ------------------------------------------------------------ Bot-Konto

    public static function botId(): ?int
    {
        $id = Database::value('SELECT id FROM users WHERE is_bot = 1 AND deleted_at IS NULL ORDER BY id ASC LIMIT 1');
        return $id ? (int) $id : null;
    }

    /** Legt das Konto bei Bedarf an und hält den Anzeigenamen aktuell. */
    public static function ensureBot(): int
    {
        $id = self::botId();
        $name = mb_substr(self::name(), 0, 80);
        if ($id !== null) {
            Database::exec('UPDATE users SET display_name = ?, is_active = 1, updated_at = ? WHERE id = ?', [$name, now(), $id]);
            return $id;
        }
        $username = 'ki';
        foreach (['ki', 'ki-assistent', 'ki-bot'] as $cand) {
            if (!Database::value('SELECT 1 FROM users WHERE username = ?', [$cand])) {
                $username = $cand;
                break;
            }
        }
        $id = Database::insert(
            "INSERT INTO users (email, username, password_hash, display_name, role, is_active, is_bot, email_verified_at, created_at, updated_at)
             VALUES (?, ?, '!', ?, 'member', 1, 1, ?, ?, ?)",
            ['ki-assistent@bot.invalid', $username, $name, now(), now(), now()]
        );
        return (int) $id;
    }

    public static function botUser(): ?array
    {
        $id = self::botId();
        return $id ? User::find($id) : null;
    }

    // ------------------------------------------------------------ Chat-Einstellung

    /** Stufe für diesen Chat ändern: im Einzelchat die Person, in Gruppen die Moderation. */
    public static function setContext(int $conversationId, int $userId, string $level): void
    {
        if (!in_array($level, self::LEVELS, true)) {
            throw new HttpException(422, 'Ungültige Kontext-Stufe.');
        }
        $member = Chat::requireMember($conversationId, $userId);
        $conv = Chat::conversation($conversationId);
        $botId = self::botId();
        if (!$conv || !$botId || !Chat::membership($conversationId, $botId) || $conv['type'] === 'notes') {
            throw new HttpException(422, 'In diesem Chat ist keine KI.');
        }
        if ($conv['type'] === 'group' && !Chat::canModerate($member)) {
            throw new HttpException(403, 'Nur Moderatoren und Administratoren dürfen den KI-Kontext ändern.');
        }
        Database::exec('UPDATE conversations SET ai_context = ?, updated_at = ? WHERE id = ?', [$level, now(), $conversationId]);
        Events::push('conversation.updated', $conversationId);
        Audit::log('ai.context', $userId, 'conversation', $conversationId, $level);
    }

    /** Prüfung vor dem Hinzufügen der KI (Einzelchat oder Gruppe). */
    public static function assertMayAdd(array $actor): void
    {
        if (!self::enabled()) {
            throw new HttpException(422, 'Die KI ist nicht aktiviert.');
        }
        if (!self::canUse($actor)) {
            throw new HttpException(403, 'Du darfst die KI nicht verwenden.');
        }
    }

    // ------------------------------------------------------------ Auslöser

    /** Nach dem Senden einer Nachricht: prüfen, ob die KI antworten soll, und Antwort nach der Response erzeugen. */
    public static function afterSend(array $msg, array $sender): void
    {
        if (($msg['kind'] ?? '') !== 'text' || !empty($sender['is_bot']) || trim((string) $msg['body']) === '') {
            return;
        }
        $botId = self::botId();
        if (!$botId) {
            return;
        }
        $convId = (int) $msg['conversation_id'];
        $conv = Chat::conversation($convId);
        if (!$conv || $conv['type'] === 'notes' || !Chat::membership($convId, $botId)) {
            return;
        }
        $triggered = $conv['type'] === 'direct';
        if (!$triggered) {
            $bot = User::find($botId);
            $triggered = $bot && preg_match('/(?<![\w@])@' . preg_quote((string) $bot['username'], '/') . '(?![\w-])/iu', (string) $msg['body']) === 1;
            if (!$triggered && !empty($msg['reply_to_id'])) {
                $triggered = (int) Database::value('SELECT sender_id FROM messages WHERE id = ?', [(int) $msg['reply_to_id']]) === $botId;
            }
        }
        if (!$triggered) {
            return;
        }
        $messageId = (int) $msg['id'];
        $userId = (int) $sender['id'];
        if (!self::canUse($sender)) {
            Messages::system($convId, self::enabled()
                ? 'Du darfst die KI nicht verwenden. Bitte wende dich an die Administration.'
                : 'Die KI ist derzeit deaktiviert.');
            return;
        }
        if (!RateLimiter::hit('ai_user', (string) $userId, max(1, (int) Settings::get('ai_rate_per_hour', '40')), 3600)) {
            Messages::system($convId, 'Das Stundenlimit für Anfragen an die KI ist erreicht. Bitte versuche es später erneut.');
            return;
        }
        // «schreibt …»: Eintrag mit Zeitstempel in der Zukunft hält die Anzeige, bis die Antwort da ist
        self::setTyping($convId, $botId, true);
        Deferred::add(static function () use ($messageId, $convId, $botId, $userId): void {
            self::respond($messageId, $convId, $botId, $userId);
        });
    }

    private static function setTyping(int $convId, int $botId, bool $on): void
    {
        if ($on) {
            Database::exec(
                'INSERT INTO typing_state (conversation_id, user_id, typed_at) VALUES (?,?,?) ON DUPLICATE KEY UPDATE typed_at = VALUES(typed_at)',
                [$convId, $botId, gmdate('Y-m-d H:i:s', time() + 80)]
            );
        } else {
            Database::exec('DELETE FROM typing_state WHERE conversation_id = ? AND user_id = ?', [$convId, $botId]);
        }
    }

    private static function respond(int $messageId, int $convId, int $botId, int $userId): void
    {
        $timeout = max(10, min(120, (int) Settings::get('ai_timeout', '45')));
        @set_time_limit($timeout + 20);
        $bot = User::find($botId);
        $msg = Database::one('SELECT * FROM messages WHERE id = ?', [$messageId]);
        $conv = Chat::conversation($convId);
        if (!$bot || !$msg || !$conv || $msg['deleted_at'] !== null) {
            self::setTyping($convId, $botId, false);
            return;
        }
        try {
            $messages = self::buildMessages($conv, $msg, $botId);
            $text = self::complete($messages, $timeout);
        } catch (\Throwable $e) {
            log_error('KI-Anfrage fehlgeschlagen: ' . $e->getMessage());
            self::setTyping($convId, $botId, false);
            Messages::system($convId, 'Die KI konnte gerade nicht antworten. Bitte versuche es später erneut.');
            return;
        }
        $text = mb_substr($text, 0, Messages::MAX_BODY - 100);
        $group = $conv['type'] === 'group';
        try {
            Messages::create(
                $bot,
                $convId,
                $text,
                null,
                $group ? $messageId : null,
                false,
                null,
                'text',
                !empty($msg['thread_root_id']) ? (int) $msg['thread_root_id'] : null
            );
        } finally {
            self::setTyping($convId, $botId, false);
        }
    }

    // ------------------------------------------------------------ Kontext

    public static function estimateTokens(string $s): int
    {
        return (int) ceil(mb_strlen($s) / 3);
    }

    /**
     * Nachrichtenliste für die API aufbauen. Die KI sieht nur, was seit ihrem Beitritt im Chat geschrieben wurde.
     * @return array<int,array{role:string,content:string}>
     */
    public static function buildMessages(array $conv, array $trigger, int $botId): array
    {
        $convId = (int) $conv['id'];
        $group = $conv['type'] === 'group';
        $level = in_array($conv['ai_context'] ?? '', self::LEVELS, true) ? $conv['ai_context'] : 'recent';
        $member = Chat::membership($convId, $botId);
        $visible = (int) ($member['visible_from_message_id'] ?? 0);
        $threadRoot = !empty($trigger['thread_root_id']) ? (int) $trigger['thread_root_id'] : null;

        $system = trim(Settings::get('ai_system_prompt'));
        $system .= ($system !== '' ? "\n\n" : '') . 'Heutiges Datum: ' . gmdate('d.m.Y') . '.';
        if ($group) {
            $system .= ' Du bist Mitglied der Gruppe «' . $conv['name'] . '». Nachrichten von Personen beginnen mit «Name: ». Schreibe deine Antwort ohne ein solches Präfix.';
        } else {
            $other = (string) Database::value('SELECT u.display_name FROM conversation_members cm JOIN users u ON u.id = cm.user_id WHERE cm.conversation_id = ? AND cm.user_id <> ? LIMIT 1', [$convId, $botId]);
            $system .= ' Du führst einen privaten Chat mit ' . $other . '.';
        }

        $budget = self::maxContextTokens() - self::estimateTokens($system) - (int) Settings::get('ai_max_output_tokens', '1024');
        $budget = max(300, $budget);

        $filter = $threadRoot !== null ? '(m.id = ? OR m.thread_root_id = ?)' : 'm.thread_root_id IS NULL';
        $params = [$convId, $visible, (int) $trigger['id']];
        if ($threadRoot !== null) {
            array_push($params, $threadRoot, $threadRoot);
        }
        if ($level === 'request') {
            $rows = [$trigger];
            if (!empty($trigger['reply_to_id'])) {
                $parent = Database::one('SELECT * FROM messages WHERE id = ? AND conversation_id = ? AND deleted_at IS NULL', [(int) $trigger['reply_to_id'], $convId]);
                if ($parent && $parent['kind'] === 'text') {
                    array_unshift($rows, $parent);
                }
            }
        } else {
            $limit = $level === 'recent' ? self::RECENT_MESSAGES : 3000;
            $rows = Database::all(
                "SELECT m.* FROM messages m WHERE m.conversation_id = ? AND m.id > ? AND m.id <= ? AND m.deleted_at IS NULL AND m.kind = 'text' AND $filter
                 ORDER BY m.id DESC LIMIT $limit",
                $params
            );
            $rows = array_reverse($rows);
        }
        if (!$rows || (int) end($rows)['id'] !== (int) $trigger['id']) {
            $rows[] = $trigger;
        }

        $names = [];
        $attach = [];
        $ids = array_map(static fn ($r) => (int) $r['id'], $rows);
        foreach (Database::all('SELECT message_id, original_name FROM message_attachments WHERE message_id IN (' . Database::in($ids) . ')', $ids) as $a) {
            $attach[(int) $a['message_id']][] = $a['original_name'];
        }
        $items = [];
        foreach ($rows as $r) {
            $sid = (int) $r['sender_id'];
            if ($sid === 0) {
                continue;
            }
            if (!isset($names[$sid])) {
                $names[$sid] = (string) Database::value('SELECT display_name FROM users WHERE id = ?', [$sid]);
            }
            $body = trim((string) $r['body']);
            if (!empty($attach[(int) $r['id']])) {
                $body .= ($body !== '' ? "\n" : '') . '[Anhang: ' . implode(', ', $attach[(int) $r['id']]) . ' – Inhalt nicht sichtbar]';
            }
            if ($body === '') {
                continue;
            }
            $isBot = $sid === $botId;
            $items[] = [
                'role' => $isBot ? 'assistant' : 'user',
                'content' => (!$isBot && $group) ? $names[$sid] . ': ' . $body : $body,
                'id' => (int) $r['id'],
            ];
        }

        // Von hinten auffüllen, bis das Token-Limit erreicht ist (die auslösende Nachricht bleibt immer erhalten)
        $kept = [];
        $used = 0;
        for ($i = count($items) - 1; $i >= 0; $i--) {
            $cost = self::estimateTokens($items[$i]['content']) + 4;
            if ($kept && $used + $cost > $budget) {
                break;
            }
            if (!$kept && $cost > $budget) {
                $items[$i]['content'] = mb_substr($items[$i]['content'], 0, $budget * 3);
                $cost = $budget;
            }
            $used += $cost;
            array_unshift($kept, $items[$i]);
        }
        // Manche Server verlangen abwechselnde Rollen und eine Nutzer-Nachricht am Anfang
        while ($kept && $kept[0]['role'] === 'assistant') {
            array_shift($kept);
        }
        $merged = [];
        foreach ($kept as $it) {
            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['role'] === $it['role']) {
                $merged[$last]['content'] .= "\n" . $it['content'];
            } else {
                $merged[] = ['role' => $it['role'], 'content' => $it['content']];
            }
        }
        array_unshift($merged, ['role' => 'system', 'content' => $system]);
        return $merged;
    }

    // ------------------------------------------------------------ API

    /**
     * Anfrage an den Endpunkt (ohne Streaming).
     * @param array<int,array{role:string,content:string}> $messages
     */
    public static function complete(array $messages, int $timeout = 45, ?array $override = null): string
    {
        $url = $override['url'] ?? self::url();
        if (!$url) {
            throw new \RuntimeException('Endpunkt ungültig oder nicht gesetzt.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('PHP-Erweiterung curl fehlt.');
        }
        $key = $override['key'] ?? self::key();
        $model = $override['model'] ?? trim(Settings::get('ai_model'));
        $tempRaw = trim((string) ($override['temperature'] ?? Settings::get('ai_temperature')));
        $temperature = $tempRaw !== '' && is_numeric($tempRaw) ? max(0.0, min(2.0, (float) $tempRaw)) : null;
        $maxOut = max(16, (int) Settings::get('ai_max_output_tokens', '1024'));
        $tokenParam = 'max_tokens';

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $payload = ['model' => $model, 'messages' => $messages, 'stream' => false, $tokenParam => $maxOut];
            if ($temperature !== null) {
                $payload['temperature'] = $temperature;
            }
            $headers = ['Content-Type: application/json', 'Accept: application/json', 'User-Agent: Messenger/' . APP_VERSION];
            if ($key !== '') {
                $headers[] = 'Authorization: Bearer ' . $key;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => $headers,
            ]);
            $raw = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            if ($raw === false || $status === 0) {
                throw new \RuntimeException('Verbindung fehlgeschlagen: ' . $err);
            }
            $json = json_decode((string) $raw, true);
            if ($status >= 200 && $status < 300 && is_array($json)) {
                $text = self::extractText($json);
                if ($text === '') {
                    throw new \RuntimeException('Leere Antwort der KI.');
                }
                return $text;
            }
            $detail = is_array($json) ? (string) ($json['error']['message'] ?? $json['error'] ?? $json['message'] ?? '') : '';
            // Neuere Modelle verlangen max_completion_tokens bzw. erlauben keine eigene Temperatur
            if ($status === 400 && $attempt < 2) {
                if ($tokenParam === 'max_tokens' && stripos($detail, 'max_completion_tokens') !== false) {
                    $tokenParam = 'max_completion_tokens';
                    continue;
                }
                if ($temperature !== null && stripos($detail, 'temperature') !== false) {
                    $temperature = null;
                    continue;
                }
            }
            throw new \RuntimeException('HTTP ' . $status . ($detail !== '' ? ': ' . mb_substr($detail, 0, 300) : ''));
        }
        throw new \RuntimeException('Anfrage nicht möglich.');
    }

    private static function extractText(array $json): string
    {
        $content = $json['choices'][0]['message']['content'] ?? ($json['choices'][0]['text'] ?? '');
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $p) {
                if (is_array($p) && isset($p['text'])) {
                    $parts[] = (string) $p['text'];
                }
            }
            $content = implode('', $parts);
        }
        $content = (string) $content;
        // Denkblöcke einiger Modelle nicht anzeigen
        $content = preg_replace('~<think>.*?</think>~su', '', $content) ?? $content;
        return trim($content);
    }

    /** Verbindungstest für die Administration (mit den aktuell gespeicherten oder übergebenen Werten). */
    public static function test(?array $override = null): array
    {
        try {
            $text = self::complete([
                ['role' => 'system', 'content' => 'Antworte in höchstens fünf Wörtern.'],
                ['role' => 'user', 'content' => 'Sag kurz Hallo.'],
            ], 30, $override);
            return ['ok' => true, 'text' => mb_substr($text, 0, 200)];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
