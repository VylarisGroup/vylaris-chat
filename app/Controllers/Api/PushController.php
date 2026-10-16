<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Push;
use App\Services\RateLimiter;
use App\Services\Settings;

final class PushController extends Controller
{
    public function subscribe(Request $r): Response
    {
        $u = $this->user();
        if (!Push::enabled()) {
            throw new HttpException(409, 'Push-Benachrichtigungen sind nicht aktiviert.');
        }
        if (!RateLimiter::hit('push_sub', (string) $u['id'], 20, 3600)) {
            throw new HttpException(429, 'Zu viele Versuche.');
        }
        if (Push::countFor((int) $u['id']) >= 10) {
            throw new HttpException(422, 'Es sind bereits zu viele Geräte registriert.');
        }
        if (!Push::subscribe((int) $u['id'], $r->str('endpoint', '', 1000), (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), $r->str('p256dh', '', 100) ?: null, $r->str('auth', '', 40) ?: null)) {
            throw new HttpException(422, 'Dieser Push-Dienst wird nicht unterstützt.');
        }
        return $this->json(['ok' => true], 201);
    }

    /** Die App ist nicht mehr sichtbar (Tab im Hintergrund, PWA geschlossen): ab jetzt wieder Push zustellen. */
    public function hidden(Request $r): Response
    {
        Database::exec('UPDATE users SET last_visible_at = NULL WHERE id = ?', [(int) $this->user()['id']]);
        return $this->json(['ok' => true]);
    }

    /** Öffentlicher VAPID-Schlüssel für den Service Worker (z. B. wenn der Browser das Abo erneuert hat). */
    public function key(Request $r): Response
    {
        if (!Push::enabled()) {
            throw new HttpException(404, 'Push ist nicht aktiviert.');
        }
        return $this->json(['public_key' => Settings::get('vapid_public')]);
    }

    /** Vom Service Worker bei «pushsubscriptionchange»: Push-Adresse ersetzen, ohne Sitzung. */
    public function resubscribe(Request $r): Response
    {
        if (!Push::enabled()) {
            throw new HttpException(404, 'Push ist nicht aktiviert.');
        }
        if (!RateLimiter::hit('push_resub', client_ip(), 30, 3600)) {
            throw new HttpException(429, 'Zu viele Versuche.');
        }
        $ok = Push::resubscribe($r->str('old_endpoint', '', 1000), $r->str('endpoint', '', 1000), $r->str('p256dh', '', 100) ?: null, $r->str('auth', '', 40) ?: null, (string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        if (!$ok) {
            throw new HttpException(422, 'Unbekanntes Gerät.');
        }
        return $this->json(['ok' => true]);
    }

    public function unsubscribe(Request $r): Response
    {
        $u = $this->user();
        Push::unsubscribe((int) $u['id'], $r->str('endpoint', '', 1000));
        return $this->json(['ok' => true]);
    }

    /** Hinweistext für den Service Worker (kein Nachrichteninhalt). */
    public function latest(Request $r): Response
    {
        $u = $this->user();
        $n = Database::one(
            'SELECT n.text, n.conversation_id, n.message_id FROM notifications n WHERE n.user_id = ? AND n.read_at IS NULL AND n.created_at > ? ORDER BY n.id DESC LIMIT 1',
            [(int) $u['id'], gmdate('Y-m-d H:i:s', time() - 600)]
        );
        $title = Settings::get('company_name');
        if (!$n) {
            return $this->json(['title' => $title, 'body' => 'Neue Aktivität im Messenger', 'url' => '', 'tag' => 'msgr-generic']);
        }
        $url = $n['conversation_id'] ? '?c=' . (int) $n['conversation_id'] . ($n['message_id'] ? '&m=' . (int) $n['message_id'] : '') : '';
        return $this->json(['title' => $title, 'body' => $n['text'], 'url' => $url, 'tag' => 'conv-' . (int) $n['conversation_id']]);
    }
}
