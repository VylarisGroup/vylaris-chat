<?php
declare(strict_types=1);

/**
 * Gemeinsamer Start für Web, Setup und CLI-Skripte.
 * Lädt Autoloader, Konfiguration und Fehlerbehandlung.
 */

define('ROOT_PATH', dirname(__DIR__));
/** Die Version steht in der Datei VERSION (z. B. «v2.0.0»); hier ohne führendes «v». */
define('APP_VERSION', ltrim(trim((string) @file_get_contents(ROOT_PATH . '/VERSION')), 'vV') ?: '0.0.0');

if (PHP_VERSION_ID < 80300) {
    http_response_code(500);
    exit('Dieses Projekt benötigt PHP 8.3 oder neuer. Installiert: ' . PHP_VERSION);
}

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');

spl_autoload_register(static function (string $class): void {
    $maps = [
        'App\\' => ROOT_PATH . '/app/',
        'PHPMailer\\PHPMailer\\' => ROOT_PATH . '/lib/PHPMailer/',
    ];
    foreach ($maps as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
});

require_once __DIR__ . '/helpers.php';
