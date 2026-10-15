<?php
declare(strict_types=1);

/**
 * Backup per Kommandozeile / Cron, z. B. täglich um 02:30:
 *   30 2 * * * php /pfad/zum/projekt/bin/backup.php >/dev/null 2>&1
 * Optionen:  --config            config/config.php (enthält app.key!) mit sichern
 *            --passphrase-file=F ZIP mit AES-256 verschlüsseln (Passphrase aus Datei F, erste Zeile)
 * Ergebnis: storage/backups/backup-*.zip (Rotation gemäss Einstellung "Backups behalten").
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Backup;

$pass = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--passphrase-file=')) {
        $pass = trim((string) strtok((string) @file_get_contents(substr($a, 18)), "\n"));
    }
}
try {
    $r = Backup::create(in_array('--config', $argv, true), $pass);
    echo 'Backup erstellt: ' . storage_path('backups') . '/' . $r['file'] . ' (' . round($r['size'] / 1048576, 2) . " MB)\n";
} catch (Throwable $e) {
    log_error('Backup fehlgeschlagen: ' . $e->getMessage());
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
