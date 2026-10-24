<?php
declare(strict_types=1);

/**
 * Front Controller. Alle Anfragen (ausser statische Dateien) laufen hier durch.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Deferred;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\Session;
use App\Core\View;
use App\Middleware\SecurityHeaders;

// Noch nicht installiert: zum Installationsassistenten
if (!Config::exists() || !is_file(storage_path('installed.lock'))) {
    header('Location: ' . url('setup.php'));
    exit;
}

// HTTPS erzwingen (Konfiguration app.force_https)
if (Config::get('app.force_https') && !is_https()) {
    $target = 'https://' . (parse_url(base_url(), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost')) . ($_SERVER['REQUEST_URI'] ?? '/');
    header('Location: ' . $target, true, 301);
    exit;
}

SecurityHeaders::send();

// Wartungsmodus während eines Updates (Datei verfällt nach 10 Minuten von selbst)
$maintenance = storage_path('cache/maintenance.flag');
if (is_file($maintenance) && filemtime($maintenance) > time() - 600) {
    http_response_code(503);
    header('Retry-After: 30');
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Wartungsarbeiten: Der Messenger wird gerade aktualisiert. Bitte in einer Minute erneut versuchen.';
    exit;
}

// Neue Datenbank-Migrationen nach einem Update automatisch anwenden (Marker-Datei, damit der Normalfall nichts kostet)
try {
    $available = \App\Core\Migrator::available();
    $latest = $available ? (string) end($available) : '';
    $marker = storage_path('cache/schema.version');
    if ($latest !== '' && @file_get_contents($marker) !== $latest) {
        $lock = @fopen(storage_path('cache/schema.lock'), 'c');
        if ($lock && flock($lock, LOCK_EX)) {
            if (@file_get_contents($marker) !== $latest) {
                \App\Core\Migrator::run(\App\Core\Database::pdo());
                @file_put_contents($marker, $latest);
            }
            flock($lock, LOCK_UN);
        }
    }
} catch (Throwable $e) {
    log_error('Automatische Migration fehlgeschlagen: ' . $e->getMessage());
}

set_exception_handler(static function (Throwable $e): void {
    log_error(get_class($e) . ': ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine()]);
    http_response_code(500);
    echo 'Interner Fehler. Bitte versuche es später erneut.';
});

$request = null;
try {
    Session::start();
    $request = Request::capture();
    $router = new Router();
    (require ROOT_PATH . '/app/routes.php')($router);
    $response = $router->dispatch($request);
} catch (HttpException $e) {
    $response = errorResponse($request, $e->status, $e->getMessage(), $e->extra);
} catch (Throwable $e) {
    log_error(get_class($e) . ': ' . $e->getMessage(), ['file' => basename($e->getFile()), 'line' => $e->getLine(), 'path' => $request?->path]);
    $response = errorResponse($request, 500, 'Es ist ein interner Fehler aufgetreten. Bitte versuche es erneut.');
}

$response = \App\Services\Watermark::guard($response);
$response->send();
Deferred::run();

function errorResponse(?Request $request, int $status, string $message, array $extra = []): Response
{
    if ($request === null || $request->wantsJson()) {
        $headers = isset($extra['retry_after']) ? ['Retry-After' => (string) $extra['retry_after']] : [];
        $res = Response::json(['error' => $message] + $extra, $status);
        $res->headers += $headers;
        return $res;
    }
    $titles = [403 => 'Kein Zugriff', 404 => 'Seite nicht gefunden', 405 => 'Nicht erlaubt', 419 => 'Sitzung abgelaufen', 429 => 'Zu viele Anfragen', 500 => 'Fehler'];
    try {
        return Response::html(View::render('error', ['title' => $titles[$status] ?? 'Fehler', 'status' => $status, 'message' => $message], 'layouts/auth'), $status);
    } catch (Throwable) {
        return Response::html('<h1>' . (int) $status . '</h1><p>' . htmlspecialchars($message) . '</p>', $status);
    }
}
