<?php
declare(strict_types=1);

namespace App\Controllers\Api;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\Audit;
use App\Services\Jobs;
use App\Services\Privacy;
use App\Services\RateLimiter;

/** Selbstbedienung für Betroffenenrechte: eigener Datenexport (inkl. Inhalte) und Anfragen an den Verantwortlichen. */
final class PrivacyApiController extends Controller
{
    /** Vollständiger Export der eigenen Daten. Erfordert das Passwort, da der Export sensible Inhalte bündelt. */
    public function export(Request $r): Response
    {
        $u = $this->user();
        $uid = (int) $u['id'];
        if (!RateLimiter::hit('data_export', (string) $uid, 5, 3600)) {
            throw new HttpException(429, 'Zu viele Exporte. Bitte versuche es später erneut.');
        }
        if (!password_verify($r->str('password', '', 500), $u['password_hash'])) {
            Audit::log('privacy.export_denied', $uid);
            throw new HttpException(422, 'Das Passwort ist falsch.');
        }
        $open = (int) Database::value("SELECT COUNT(*) FROM jobs WHERE user_id = ? AND type = 'export' AND status IN ('queued','running')", [$uid]);
        if ($open > 0) {
            throw new HttpException(409, 'Es läuft bereits ein Export. Bitte warte, bis er fertig ist.');
        }
        Audit::log('privacy.self_export', $uid, 'user', $uid, 'full');
        $id = Jobs::enqueue('export', $uid);
        return $this->json(['ok' => true, 'id' => $id], 202);
    }

    public function exports(Request $r): Response
    {
        $u = $this->user();
        $rows = Database::all("SELECT id, status, file_size, error, created_at, expires_at FROM jobs WHERE user_id = ? AND type = 'export' ORDER BY id DESC LIMIT 5", [(int) $u['id']]);
        return $this->json(['exports' => array_map(static fn ($j) => [
            'id' => (int) $j['id'],
            'status' => $j['status'],
            'size' => (int) $j['file_size'],
            'error' => $j['error'],
            'created_at' => iso($j['created_at']),
            'expires_at' => iso($j['expires_at']),
        ], $rows)]);
    }

    public function exportDownload(Request $r): Response
    {
        $u = $this->user();
        $job = Database::one("SELECT * FROM jobs WHERE id = ? AND user_id = ? AND type = 'export'", [(int) $r->params['id'], (int) $u['id']]);
        $path = $job ? Jobs::filePath($job) : null;
        if (!$job || $path === null) {
            throw new HttpException(404, 'Der Export ist nicht (mehr) verfügbar.');
        }
        Audit::log('privacy.export_downloaded', (int) $u['id'], 'job', (int) $job['id']);
        $name = Jobs::downloadName($job);
        return Response::file($path, [
            'Content-Type' => str_ends_with($name, '.zip') ? 'application/zip' : 'application/json; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $name) . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function requests(Request $r): Response
    {
        $u = $this->user();
        $rows = Database::all(
            'SELECT id, type, message, status, response, due_at, handled_at, created_at FROM privacy_requests WHERE user_id = ? ORDER BY id DESC LIMIT 20',
            [(int) $u['id']]
        );
        return $this->json(['requests' => array_map(static fn ($x) => [
            'id' => (int) $x['id'],
            'type' => $x['type'],
            'type_label' => Privacy::TYPES[$x['type']] ?? $x['type'],
            'message' => $x['message'],
            'status' => $x['status'],
            'status_label' => Privacy::STATUSES[$x['status']] ?? $x['status'],
            'response' => $x['response'],
            'due_at' => iso($x['due_at']),
            'handled_at' => $x['handled_at'] ? iso($x['handled_at']) : null,
            'created_at' => iso($x['created_at']),
        ], $rows)]);
    }

    public function createRequest(Request $r): Response
    {
        $u = $this->user();
        $uid = (int) $u['id'];
        if (!RateLimiter::hit('privacy_request', (string) $uid, 5, 86400)) {
            throw new HttpException(429, 'Du hast heute bereits mehrere Anfragen gestellt. Bitte warte, bis diese bearbeitet sind.');
        }
        $type = $r->str('type', '', 20);
        $message = trim($r->str('message', '', 2000));
        if (!isset(Privacy::TYPES[$type])) {
            throw new HttpException(422, 'Bitte wähle die Art der Anfrage.');
        }
        if ($type !== 'access' && $type !== 'portability' && mb_strlen($message) < 5) {
            throw new HttpException(422, 'Bitte beschreibe dein Anliegen kurz (mindestens 5 Zeichen).');
        }
        $id = Privacy::createRequest($uid, (string) $u['display_name'], $type, $message, 'user', $uid);
        return $this->json(['ok' => true, 'id' => $id], 201);
    }
}
