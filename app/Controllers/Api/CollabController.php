<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Calendar;
use App\Services\Chat;
use App\Services\Drafts;
use App\Services\Messages;
use App\Services\Polls;
use App\Services\RateLimiter;

/** Umfragen, Termine, Entwürfe und Kalender-Feed. */
final class CollabController extends Controller
{
    private function id(Request $r): int
    {
        return (int) ($r->params['id'] ?? 0);
    }

    // ------------------------------------------------------------- Umfragen

    public function createPoll(Request $r): Response
    {
        $options = $r->input('options', []);
        $res = Polls::create(
            $this->user(),
            $this->id($r),
            $r->str('question', '', 400),
            is_array($options) ? array_map(static fn ($o) => is_scalar($o) ? mb_substr((string) $o, 0, 200) : '', array_slice($options, 0, 30)) : [],
            $r->bool('multiple'),
            $r->bool('anonymous'),
            $r->input('closes_at') !== null ? $r->str('closes_at', '', 40) : null
        );
        return $this->json($res, 201);
    }

    public function vote(Request $r): Response
    {
        return $this->json(['message' => Polls::vote($this->user(), $this->id($r), $r->intList('option_ids'))]);
    }

    public function closePoll(Request $r): Response
    {
        return $this->json(['message' => Polls::close($this->user(), $this->id($r))]);
    }

    // --------------------------------------------------------------- Termine

    /** @return array<string,mixed> */
    private function eventInput(Request $r): array
    {
        $in = [];
        foreach (['title', 'description', 'location', 'starts_at', 'ends_at', 'start_date', 'end_date'] as $k) {
            if ($r->input($k) !== null) {
                $in[$k] = $r->str($k, '', $k === 'description' ? 2100 : 300);
            }
        }
        if ($r->input('all_day') !== null) {
            $in['all_day'] = $r->bool('all_day');
        }
        return $in;
    }

    public function createEvent(Request $r): Response
    {
        return $this->json(Calendar::create($this->user(), $this->id($r), $this->eventInput($r)), 201);
    }

    public function updateEvent(Request $r): Response
    {
        return $this->json(['message' => Calendar::update($this->user(), $this->id($r), $this->eventInput($r))]);
    }

    public function cancelEvent(Request $r): Response
    {
        return $this->json(['message' => Calendar::cancel($this->user(), $this->id($r))]);
    }

    public function rsvp(Request $r): Response
    {
        return $this->json(['message' => Calendar::rsvp($this->user(), $this->id($r), $r->str('status', '', 10))]);
    }

    /** Einzelner Termin als .ics-Datei (Seitenaufruf mit Sitzung, daher kein JSON). */
    public function ics(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        $id = (int) preg_replace('/\.ics$/i', '', (string) ($r->params['id'] ?? '0'));
        $file = Calendar::icsFor($id, $uid);
        return new Response(200, $file['body'], [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $file['filename'] . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    // --------------------------------------------------------------- Entwürfe

    public function saveDraft(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        Drafts::save(
            $uid,
            $this->id($r),
            max(0, $r->int('thread_root_id')),
            $r->str('body', '', Messages::MAX_BODY + 50),
            $r->input('reply_to_id') ? $r->int('reply_to_id') : null,
            $r->str('device', '', 12)
        );
        return $this->json(['ok' => true]);
    }

    public function drafts(Request $r): Response
    {
        return $this->json(['drafts' => Drafts::listFor((int) $this->user()['id'])]);
    }

    // --------------------------------------------------- Nachricht und Thread

    public function showMessage(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        ['message' => $msg] = Messages::findForUser($this->id($r), $uid);
        return $this->json(['message' => Messages::hydrate([$msg], $uid)[0]]);
    }

    public function thread(Request $r): Response
    {
        return $this->json(Messages::thread((int) $this->user()['id'], $this->id($r)));
    }

    // --------------------------------------------------------- Kalender-Feed

    public function feedStatus(Request $r): Response
    {
        return $this->json(['enabled' => Calendar::feedEnabled((int) $this->user()['id'])]);
    }

    public function feedEnable(Request $r): Response
    {
        $uid = (int) $this->user()['id'];
        if (!RateLimiter::hit('feed_regen', (string) $uid, 10, 3600)) {
            throw new HttpException(429, 'Zu viele Änderungen. Bitte versuche es später erneut.');
        }
        return $this->json(['enabled' => true, 'url' => Calendar::feedUrl(Calendar::enableFeed($uid))]);
    }

    public function feedDisable(Request $r): Response
    {
        Calendar::disableFeed((int) $this->user()['id']);
        return $this->json(['enabled' => false]);
    }

    /** Öffentlich, aber nur mit dem geheimen Token aus dem Link. Begrenzt Fehlversuche je Adresse. */
    public function feed(Request $r): Response
    {
        $ip = client_ip();
        if (!RateLimiter::hit('feed_ip', $ip, 240, 3600)) {
            return new Response(429, 'Zu viele Anfragen.', ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '600']);
        }
        if (RateLimiter::blocked('feed_bad', $ip, 20, 600)) {
            return new Response(429, 'Zu viele Anfragen.', ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '600']);
        }
        $body = Calendar::feedFor((string) ($r->params['token'] ?? ''));
        if ($body === null) {
            RateLimiter::hit('feed_bad', $ip, 20, 600);
            return new Response(404, 'Nicht gefunden.', ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        return new Response(200, $body, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="termine.ics"',
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
