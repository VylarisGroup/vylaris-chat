<?php
declare(strict_types=1);

/**
 * Wendet neue Datenbank-Migrationen aus database/migrations/ an (nach einem Update der Anwendung):
 *   php bin/migrate.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Migrator;

if (!Config::exists()) {
    fwrite(STDERR, "Der Messenger ist noch nicht installiert (config/config.php fehlt).\n");
    exit(1);
}

try {
    $applied = Migrator::run(Database::pdo());
    echo $applied ? "Angewendet:\n  " . implode("\n  ", $applied) . "\n" : "Die Datenbank ist auf dem aktuellen Stand.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . "\n");
    exit(1);
}
