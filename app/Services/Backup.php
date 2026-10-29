<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;

/**
 * Backup und Wiederherstellung: SQL-Dump + Dateien aus storage/ (uploads, avatars, branding) als ZIP.
 *
 * Wichtig: Ein Backup enthält ALLE Nachrichten im Klartext (die Anwendung ist nicht Ende-zu-Ende-verschlüsselt).
 * Deshalb wird es standardmässig nur im Serververzeichnis storage/backups abgelegt; ein Web-Download ist
 * eine bewusst zu aktivierende Option. Optional lässt sich das ZIP mit einer Passphrase (AES-256) verschlüsseln.
 */
final class Backup
{
    public const DELIM = "\n--@@--\n";
    /** Flüchtige Tabellen, die nicht gesichert werden müssen. */
    private const SKIP_TABLES = ['rate_limits', 'events', 'typing_state', 'user_sessions', 'jobs'];
    private const FILE_DIRS = ['uploads', 'avatars', 'branding'];

    public static function dir(): string
    {
        $d = storage_path('backups');
        if (!is_dir($d)) {
            @mkdir($d, 0700, true);
        }
        if (!is_file($d . '/index.html')) {
            @file_put_contents($d . '/index.html', '');
        }
        return $d;
    }

    /** @return array{file:string,size:int} */
    public static function create(bool $includeConfig = false, string $passphrase = '', bool $record = true): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('Die PHP-Erweiterung zip fehlt.');
        }
        @set_time_limit(0);
        $dir = self::dir();
        $tmpSql = tempnam($dir, 'dump');
        if ($tmpSql === false) {
            throw new \RuntimeException('Temporäre Datei konnte nicht angelegt werden.');
        }
        $name = 'backup-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.zip';
        $path = $dir . '/' . $name;
        try {
            $tables = self::dumpTo($tmpSql);
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('ZIP konnte nicht erstellt werden.');
            }
            $zip->addFile($tmpSql, 'database.sql');
            $fileCount = 0;
            $bytes = 0;
            foreach (self::FILE_DIRS as $sub) {
                $base = storage_path($sub);
                if (!is_dir($base)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                    if ($f->isFile() && !$f->isLink()) {
                        $rel = $sub . '/' . ltrim(substr($f->getPathname(), strlen($base)), '/\\');
                        $zip->addFile($f->getPathname(), 'files/' . str_replace('\\', '/', $rel));
                        $fileCount++;
                        $bytes += $f->getSize();
                    }
                }
            }
            if ($includeConfig && Config::exists()) {
                $zip->addFile(Config::file(), 'config/config.php');
            }
            $meta = [
                'format' => 1,
                'created_at' => gmdate('c'),
                'schema_version' => (string) Database::value('SELECT MAX(version) FROM schema_migrations'),
                'tables' => $tables,
                'files' => $fileCount,
                'file_bytes' => $bytes,
                'sql_sha256' => hash_file('sha256', $tmpSql),
                'includes_config' => $includeConfig,
                'encrypted' => $passphrase !== '',
            ];
            $zip->addFromString('meta.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            if ($passphrase !== '') {
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $zip->setEncryptionIndex($i, \ZipArchive::EM_AES_256, $passphrase);
                }
            }
            if (!$zip->close()) {
                throw new \RuntimeException('ZIP konnte nicht geschrieben werden (Speicherplatz?).');
            }
        } catch (\Throwable $e) {
            @unlink($path);
            throw $e;
        } finally {
            @unlink($tmpSql);
        }
        @chmod($path, 0600);
        if ($record) {
            Settings::set('backup_last_at', (string) time());
            Audit::log('backup.created', null, 'backup', null, $name);
            self::rotate();
        }
        return ['file' => $name, 'size' => (int) filesize($path)];
    }

    /** @return string[] Namen der gesicherten Tabellen */
    private static function dumpTo(string $file): array
    {
        $cfg = (array) Config::get('db');
        $pdo = Database::connect($cfg);
        $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $out = fopen($file, 'wb');
        if (!$out) {
            throw new \RuntimeException('Dump-Datei nicht beschreibbar.');
        }
        fwrite($out, "-- Messenger Backup " . gmdate('c') . self::DELIM);
        fwrite($out, "SET NAMES utf8mb4" . self::DELIM . "SET FOREIGN_KEY_CHECKS = 0" . self::DELIM . "SET time_zone = '+00:00'" . self::DELIM . "SET sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" . self::DELIM);
        $done = [];
        $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_NUM);
        foreach ($tables as [$t]) {
            $t = (string) $t;
            $q = '`' . str_replace('`', '``', $t) . '`';
            $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(PDO::FETCH_NUM);
            fwrite($out, "DROP TABLE IF EXISTS $q" . self::DELIM . $create[1] . self::DELIM);
            if (in_array($t, self::SKIP_TABLES, true)) {
                $done[] = $t . ' (nur Struktur)';
                continue;
            }
            $stmt = $pdo->query("SELECT * FROM $q");
            $cols = null;
            $batch = [];
            $size = 0;
            while (($row = $stmt->fetch(PDO::FETCH_NUM)) !== false) {
                if ($cols === null) {
                    $cols = [];
                    for ($i = 0; $i < $stmt->columnCount(); $i++) {
                        $cols[] = '`' . str_replace('`', '``', $stmt->getColumnMeta($i)['name']) . '`';
                    }
                }
                $vals = [];
                foreach ($row as $v) {
                    $vals[] = $v === null ? 'NULL' : $pdo->quote((string) $v);
                }
                $line = '(' . implode(',', $vals) . ')';
                $batch[] = $line;
                $size += strlen($line);
                if ($size > 500000) {
                    fwrite($out, "INSERT INTO $q (" . implode(',', $cols) . ") VALUES " . implode(",\n", $batch) . self::DELIM);
                    $batch = [];
                    $size = 0;
                }
            }
            $stmt->closeCursor();
            if ($batch) {
                fwrite($out, "INSERT INTO $q (" . implode(',', $cols) . ") VALUES " . implode(",\n", $batch) . self::DELIM);
            }
            $done[] = $t;
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1" . self::DELIM);
        fclose($out);
        return $done;
    }

    /**
     * Prüft ein Backup (lesbar, Prüfsumme der Datenbank stimmt). Erstellt nichts und ändert nichts.
     *
     * @return array{ok:bool,error?:string,meta?:array}
     */
    public static function verify(string $zipPath, string $passphrase = ''): array
    {
        $zip = new \ZipArchive();
        if (!is_file($zipPath) || $zip->open($zipPath) !== true) {
            return ['ok' => false, 'error' => 'ZIP nicht lesbar'];
        }
        if ($passphrase !== '') {
            $zip->setPassword($passphrase);
        }
        $metaRaw = $zip->getFromName('meta.json');
        if ($metaRaw === false) {
            $zip->close();
            return ['ok' => false, 'error' => $passphrase === '' ? 'meta.json nicht lesbar (verschlüsselt? Passphrase nötig)' : 'meta.json nicht lesbar (Passphrase falsch?)'];
        }
        $meta = json_decode($metaRaw, true);
        $ctx = hash_init('sha256');
        $stream = $zip->getStream('database.sql');
        if (!$stream || !is_array($meta)) {
            $zip->close();
            return ['ok' => false, 'error' => 'database.sql fehlt'];
        }
        while (!feof($stream)) {
            hash_update($ctx, (string) fread($stream, 1048576));
        }
        fclose($stream);
        $zip->close();
        if (!hash_equals((string) ($meta['sql_sha256'] ?? ''), hash_final($ctx))) {
            return ['ok' => false, 'error' => 'Prüfsumme der Datenbank stimmt nicht', 'meta' => $meta];
        }
        return ['ok' => true, 'meta' => $meta];
    }

    /**
     * Stellt ein Backup wieder her (überschreibt Datenbank-Tabellen und Dateien!). Nur für die Kommandozeile gedacht.
     *
     * @return array{tables:int,statements:int,files:int}
     */
    public static function restore(string $zipPath, string $passphrase = ''): array
    {
        $check = self::verify($zipPath, $passphrase);
        if (!$check['ok']) {
            throw new \RuntimeException('Backup ungültig: ' . ($check['error'] ?? '?'));
        }
        $zip = new \ZipArchive();
        $zip->open($zipPath);
        if ($passphrase !== '') {
            $zip->setPassword($passphrase);
        }
        $tmp = storage_path('tmp') . '/restore-' . bin2hex(random_bytes(4));
        @mkdir($tmp, 0700, true);
        $sqlFile = $tmp . '/database.sql';
        file_put_contents($sqlFile, (string) $zip->getFromName('database.sql'));
        $pdo = Database::pdo();
        $n = 0;
        $tables = 0;
        $fh = fopen($sqlFile, 'rb');
        $buf = '';
        $run = static function (string $stmt) use ($pdo, &$n, &$tables) {
            $stmt = trim($stmt);
            if ($stmt === '' || str_starts_with($stmt, '--')) {
                return;
            }
            $pdo->exec($stmt);
            $n++;
            if (stripos($stmt, 'CREATE TABLE') === 0) {
                $tables++;
            }
        };
        while (!feof($fh)) {
            $buf .= (string) fread($fh, 1048576);
            while (($pos = strpos($buf, self::DELIM)) !== false) {
                $run(substr($buf, 0, $pos));
                $buf = substr($buf, $pos + strlen(self::DELIM));
            }
        }
        $run($buf);
        fclose($fh);
        @unlink($sqlFile);
        @rmdir($tmp);

        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (!str_starts_with($entry, 'files/') || str_contains($entry, '..') || str_ends_with($entry, '/')) {
                continue;
            }
            $rel = substr($entry, 6);
            $top = explode('/', $rel)[0];
            if (!in_array($top, self::FILE_DIRS, true)) {
                continue;
            }
            $dest = storage_path($rel);
            @mkdir(dirname($dest), 0750, true);
            $in = $zip->getStream($entry);
            if ($in) {
                $o = fopen($dest, 'wb');
                stream_copy_to_stream($in, $o);
                fclose($o);
                fclose($in);
                $files++;
            }
        }
        $zip->close();
        return ['tables' => $tables, 'statements' => $n, 'files' => $files];
    }

    /** @return array<int,array{name:string,size:int,created:int}> neueste zuerst */
    public static function listFiles(): array
    {
        $out = [];
        foreach (glob(self::dir() . '/backup-*.zip') ?: [] as $f) {
            $out[] = ['name' => basename($f), 'size' => (int) filesize($f), 'created' => (int) filemtime($f)];
        }
        usort($out, static fn ($a, $b) => $b['created'] <=> $a['created']);
        return $out;
    }

    public static function validName(string $n): bool
    {
        return (bool) preg_match('/^backup-\d{8}-\d{6}-[a-f0-9]{6}\.zip$/', $n);
    }

    public static function delete(string $name): bool
    {
        return self::validName($name) && is_file(self::dir() . '/' . $name) && @unlink(self::dir() . '/' . $name);
    }

    /** Älteste Backups entfernen, sodass höchstens "backup_keep" bleiben. */
    public static function rotate(): int
    {
        $keep = max(1, Settings::int('backup_keep'));
        $n = 0;
        foreach (array_slice(self::listFiles(), $keep) as $f) {
            $n += self::delete($f['name']) ? 1 : 0;
        }
        return $n;
    }

    /** Geplantes Backup (bei Wartung): nur wenn aktiviert und fällig. Nutzt den Job-Mechanismus. */
    public static function maybeScheduled(): void
    {
        $days = Settings::int('backup_auto_days');
        if ($days <= 0 || time() - Settings::int('backup_last_at') < $days * 86400 - 600) {
            return;
        }
        // Atomar reservieren, damit nur ein Prozess das Backup anstösst
        Database::exec("INSERT IGNORE INTO system_settings (k, v, updated_at) VALUES ('backup_last_at', '0', ?)", [now()]);
        $claimed = Database::exec("UPDATE system_settings SET v = ?, updated_at = ? WHERE k = 'backup_last_at' AND CAST(v AS UNSIGNED) < ?", [(string) time(), now(), time() - $days * 86400 + 600]);
        if ($claimed > 0) {
            Jobs::enqueue('backup', null, ['config' => Settings::bool('backup_include_config')], 1);
        }
    }
}
