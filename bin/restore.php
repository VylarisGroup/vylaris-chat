<?php
declare(strict_types=1);

/**
 * Wiederherstellung aus einem Backup (nur Kommandozeile).
 *   php bin/restore.php storage/backups/backup-....zip [--passphrase-file=F] [--verify] [--yes]
 *
 * ACHTUNG: überschreibt alle Tabellen der konfigurierten Datenbank sowie Dateien in storage/uploads, avatars, branding.
 * Ohne --yes wird nur geprüft (wie --verify). config/config.php wird nie automatisch überschrieben; ist sie im ZIP
 * enthalten, extrahiere sie bei Bedarf von Hand (unzip -j backup.zip config/config.php -d config/).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';

use App\Services\Backup;

$file = $argv[1] ?? '';
$pass = '';
foreach ($argv as $a) {
    if (str_starts_with($a, '--passphrase-file=')) {
        $pass = trim((string) strtok((string) @file_get_contents(substr($a, 18)), "\n"));
    }
}
if ($file === '' || !is_file($file)) {
    fwrite(STDERR, "Aufruf: php bin/restore.php <backup.zip> [--passphrase-file=F] [--verify] [--yes]\n");
    exit(2);
}
$check = Backup::verify($file, $pass);
if (!$check['ok']) {
    fwrite(STDERR, 'Backup ungültig: ' . $check['error'] . "\n");
    exit(1);
}
$m = $check['meta'];
echo "Backup in Ordnung: erstellt {$m['created_at']}, Schema {$m['schema_version']}, " . count($m['tables']) . " Tabellen, {$m['files']} Dateien.\n";
if (!in_array('--yes', $argv, true) || in_array('--verify', $argv, true)) {
    echo "Nur geprüft. Zum Wiederherstellen --yes angeben (überschreibt die aktuelle Datenbank und Dateien).\n";
    exit(0);
}
try {
    $r = Backup::restore($file, $pass);
    echo "Wiederhergestellt: {$r['tables']} Tabellen, {$r['statements']} Anweisungen, {$r['files']} Dateien.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
