<?php
declare(strict_types=1);

namespace App\Core;

use PDO;

/**
 * Einfache, nachvollziehbare Schema-Migrationen: database/migrations/NNN_name.sql in Dateinamen-Reihenfolge.
 * Angewendete Versionen stehen in der Tabelle schema_migrations. Statements enden mit ";" am Zeilenende.
 */
final class Migrator
{
    /** @return array<int,string> Dateinamen aller vorhandenen Migrationen */
    public static function available(): array
    {
        $files = glob(ROOT_PATH . '/database/migrations/*.sql') ?: [];
        sort($files, SORT_STRING);
        return array_map('basename', $files);
    }

    /** @return array<int,string> Namen der neu angewendeten Migrationen */
    public static function run(PDO $pdo): array
    {
        $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');

        $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $applied = [];
        foreach (self::available() as $file) {
            if (in_array($file, $done, true)) {
                continue;
            }
            $sql = (string) file_get_contents(ROOT_PATH . '/database/migrations/' . $file);
            foreach (self::statements($sql) as $stmt) {
                try {
                    $pdo->exec($stmt);
                } catch (\PDOException $e) {
                    // Erneuter Lauf nach teilweise angewendeter Migration: vorhandene Spalten/Indizes/Tabellen sind kein Fehler.
                    $code = (int) ($e->errorInfo[1] ?? 0);
                    if (!in_array($code, [1060, 1061, 1050, 1091], true)) {
                        throw $e;
                    }
                }
            }
            $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (?, ?)')->execute([$file, gmdate('Y-m-d H:i:s')]);
            $applied[] = $file;
        }
        return $applied;
    }

    /** @return string[] */
    public static function statements(string $sql): array
    {
        // Kommentarzeilen entfernen
        $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $sql)), static fn ($l) => !str_starts_with(ltrim($l), '--'));
        $parts = preg_split('/;\s*\n/', implode("\n", $lines) . "\n") ?: [];
        return array_values(array_filter(array_map('trim', $parts), static fn ($s) => $s !== ''));
    }

    public static function pending(PDO $pdo): array
    {
        try {
            $done = $pdo->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            $done = [];
        }
        return array_values(array_diff(self::available(), $done));
    }
}
