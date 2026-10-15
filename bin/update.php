<?php
declare(strict_types=1);

/**
 * Aktualisierung per Kommandozeile (z. B. wenn die Webanfrage zu lange dauert):
 *   php bin/update.php check
 *   php bin/update.php apply [--no-backup]
 *   php bin/update.php rollback
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\Updater;

if (!Config::exists() || !is_file(storage_path('installed.lock'))) {
    fwrite(STDERR, "Der Messenger ist noch nicht installiert.\n");
    exit(1);
}
$cmd = $argv[1] ?? 'check';
$short = static fn (string $s): string => $s !== '' ? substr($s, 0, 7) : '-';
switch ($cmd) {
    case 'check':
        $st = Updater::check();
        if ($st['error'] !== '') {
            fwrite(STDERR, 'Fehler: ' . $st['error'] . "\n");
            exit(1);
        }
        $lab = static fn (string $tag, string $sha): string => $tag !== '' ? $tag : $short($sha);
        echo 'Installiert: ' . $lab($st['installed_tag'], $st['installed']) . "\nNeuester Stand: " . $lab($st['tag'], $st['latest']) . ' ' . $st['latest_msg'] . "\n";
        echo $st['available'] ? "Update verfügbar.\n" : "Aktuell.\n";
        exit($st['available'] ? 10 : 0);
    case 'apply':
        $res = Updater::install(!in_array('--no-backup', $argv, true));
        if (!$res['ok']) {
            fwrite(STDERR, 'Fehlgeschlagen: ' . $res['error'] . "\n");
            exit(1);
        }
        echo 'Fertig: ' . $short((string) ($res['from'] ?? '')) . ' -> ' . $short((string) ($res['to'] ?? '')) . ', ' . ($res['files'] ?? 0) . " Dateien.\n";
        exit(0);
    case 'rollback':
        $res = Updater::rollback();
        echo $res['ok'] ? 'Code zurückgesetzt (' . $res['restored'] . " Dateien). Die Datenbank wurde nicht verändert.\n" : 'Fehlgeschlagen: ' . $res['error'] . "\n";
        exit($res['ok'] ? 0 : 1);
    default:
        fwrite(STDERR, "Verwendung: php bin/update.php check|apply [--no-backup]|rollback\n");
        exit(2);
}
