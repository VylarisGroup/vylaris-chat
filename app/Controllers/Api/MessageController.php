<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Messages;

final class MessageController extends Controller
{
    private function id(Request $r): int
    {
        return (int) ($r->params['id'] ?? 0);
    }

    public function edit(Request $r): Response
    {
        return $this->json(['message' => Messages::edit($this->user(), $this->id($r), $r->str('body', '', Messages::MAX_BODY + 100))]);
    }

    public function delete(Request $r): Response
    {
        Messages::delete($this->user(), $this->id($r));
        return $this->json(['ok' => true]);
    }

    public function react(Request $r): Response
    {
        return $this->json(['message' => Messages::react($this->user(), $this->id($r), $r->str('emoji', '', 32))]);
    }

    public function forward(Request $r): Response
    {
        $ids = Messages::forward($this->user(), $this->id($r), $r->intList('conversation_ids'));
        return $this->json(['forwarded' => $ids], 201);
    }

    public function report(Request $r): Response
    {
        Messages::report($this->user(), $this->id($r), $r->str('reason', '', 500));
        return $this->json(['ok' => true]);
    }
}
