<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Meets;
use App\Services\RateLimiter;

/** Videokonferenzen: starten, beitreten, beenden, Status und Webhook des Meeting-Dienstes. */
final class MeetingController extends Controller
{
    private function id(Request $r): int
    {
        return (int) ($r->params['id'] ?? 0);
    }

    public function start(Request $r): Response
    {
        return $this->json(Meets::start($this->user(), $this->id($r)));
    }

    public function join(Request $r): Response
    {
        return $this->json(Meets::join($this->user(), $this->id($r)));
    }

    public function end(Request $r): Response
    {
        return $this->json(Meets::end($this->user(), $this->id($r)));
    }

    public function status(Request $r): Response
    {
        return $this->json(Meets::status($this->user(), $this->id($r)));
    }

    /** Ohne Sitzung. Antwortet innerhalb weniger Millisekunden (der Dienst wartet höchstens 8 Sekunden). */
    public function webhook(Request $r): Response
    {
        if (!RateLimiter::hit('meet_hook', client_ip(), 600, 60)) {
            return new Response(429);
        }
        return new Response(Meets::handleWebhook($r->rawBody(), $r->header('X-TRS-Timestamp'), $r->header('X-TRS-Signature')));
    }
}
