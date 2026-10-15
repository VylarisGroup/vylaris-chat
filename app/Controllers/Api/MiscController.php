<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Files;
use App\Services\Notifications;
use App\Services\RateLimiter;
use App\Services\Search;

/** Suche, Benachrichtigungen und Dateiauslieferung. */
final class MiscController extends Controller
{
    public function search(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        if (!RateLimiter::hit('search', (string) $uid, 60, 60)) {
            return $this->json(['error' => 'Zu viele Suchanfragen. Bitte warte kurz.'], 429);
        }
        $res = Search::run($uid, $r->str('q', '', 100), [
            'type' => $r->str('type', 'all', 20),
            'from_user' => $r->int('from_user'),
            'from' => $r->str('from', '', 40),
            'to' => $r->str('to', '', 40),
            'conversation_id' => $r->int('conversation_id'),
        ]);
        return $this->json($res);
    }

    public function notifications(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        return $this->json(['notifications' => Notifications::listFor($uid), 'unread' => Notifications::unreadCount($uid)]);
    }

    public function markNotifications(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        Notifications::markRead($uid, $r->bool('all') ? null : $r->intList('ids'));
        return $this->json(['unread' => Notifications::unreadCount($uid)]);
    }

    public function file(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        return Files::serve($uid, (int) $r->params['id'], $r->query('download') === '1', $r->query('thumb') === '1');
    }
}
