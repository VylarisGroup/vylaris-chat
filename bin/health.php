<?php
declare(strict_types=1);

/** Zustandsprüfung für Cron / Monitoring: gibt Probleme aus und endet mit Exit-Code 1, wenn eine Prüfung fehlschlägt. */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

$bad = 0;
foreach (App\Services\Monitor::checks() as $c) {
    echo ($c['ok'] ? '[ok]   ' : '[FAIL] ') . str_pad($c['name'], 30) . $c['detail'] . "\n";
    $bad += $c['ok'] ? 0 : 1;
}
exit($bad > 0 ? 1 : 0);
