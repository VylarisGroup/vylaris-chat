<?php
declare(strict_types=1);

/**
 * Wartungsskript für Cron, z. B. alle 15 Minuten:
 *   0,15,30,45 * * * * php /pfad/zum/projekt/bin/cleanup.php >/dev/null 2>&1
 * Bereinigt Ereignisse, abgelaufene Tokens und Sitzungen, verwaiste Uploads und wendet die Aufbewahrungsfristen an.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\Retention;
use App\Services\Settings;

if (!Config::exists() || !is_file(storage_path('installed.lock'))) {
    fwrite(STDERR, "Der Messenger ist noch nicht installiert.\n");
    exit(1);
}

try {
    $stats = Retention::run();
    Settings::set('last_cleanup_at', (string) time());
    foreach ($stats as $k => $v) {
        echo str_pad($k, 18) . $v . "\n";
    }
} catch (Throwable $e) {
    log_error('Cleanup fehlgeschlagen: ' . $e->getMessage());
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
