<?php
declare(strict_types=1);

/**
 * Optionaler Worker für Hintergrundaufträge (Datenexport, Backup), z. B. jede Minute per Cron:
 *   * * * * * php /pfad/zum/projekt/bin/worker.php >/dev/null 2>&1
 * Ohne Cron werden Aufträge automatisch nach der Anfrage bzw. bei der Wartung ausgeführt.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Jobs;

@set_time_limit(0);
echo Jobs::runPending(20, 600) . " Auftrag/Aufträge ausgeführt\n";
