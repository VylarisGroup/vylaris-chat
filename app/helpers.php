<?php
declare(strict_types=1);

use App\Core\Config;

/** HTML-Escaping für Templates. */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Aktuelle UTC-Zeit im DB-Format. */
function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

/** DB-Zeit (UTC) als ISO-8601 für die API. */
function iso(?string $dt): ?string
{
    if ($dt === null || $dt === '') {
        return null;
    }
    return gmdate('Y-m-d\TH:i:s\Z', strtotime($dt . ' UTC'));
}

/** Pfad im Speicherverzeichnis. */
function storage_path(string $sub = ''): string
{
    $base = Config::get('paths.storage') ?: ROOT_PATH . '/storage';
    return rtrim($base, '/\\') . ($sub !== '' ? '/' . ltrim($sub, '/\\') : '');
}

function is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }
    if (Config::get('app.trust_proxy') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return false;
}

/** Absolute URL zur Anwendung (ohne abschliessenden Slash). */
function base_url(): string
{
    $configured = (string) Config::get('app.url', '');
    if ($configured !== '') {
        return rtrim($configured, '/');
    }
    $scheme = is_https() ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . app_base_path();
}

/** URL-Pfad, unter dem die Anwendung liegt (z. B. "" oder "/chat"). */
function app_base_path(): string
{
    static $path = null;
    if ($path === null) {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $dir = rtrim(dirname($script), '/');
        $dir = ($dir === '.' || $dir === '\\') ? '' : $dir;
        // Document Root = Projektordner: der Webserver leitet intern nach /public um, die URL enthält dieses Segment aber nicht
        $uriPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (str_ends_with($dir, '/public') && !str_starts_with($uriPath, $dir . '/') && $uriPath !== $dir) {
            $dir = substr($dir, 0, -7);
        }
        $path = $dir;
    }
    return $path;
}

function url(string $path = ''): string
{
    return app_base_path() . '/' . ltrim($path, '/');
}

function random_token(int $bytes = 32): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (Config::get('app.trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $first = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            $ip = $first;
        }
    }
    return substr((string) $ip, 0, 45);
}

function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $v = (float) $bytes;
    while ($v >= 1024 && $i < count($units) - 1) {
        $v /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) (int) $v : number_format($v, 1, '.', "'")) . ' ' . $units[$i];
}

function log_error(string $message, array $context = []): void
{
    $dir = storage_path('logs');
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    $line = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;
    if ($context) {
        $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    @file_put_contents($dir . '/error.log', $line . "\n", FILE_APPEND | LOCK_EX);
    \App\Services\Monitor::record($message);
}

/** DB-Zeit (UTC) in der Anzeige-Zeitzone (config app.timezone, Standard Europe/Zurich). */
function local_dt(?string $utc, string $format = 'd.m.Y H:i'): string
{
    if ($utc === null || $utc === '') {
        return '–';
    }
    try {
        $tz = new DateTimeZone((string) Config::get('app.timezone', 'Europe/Zurich'));
        return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone($tz)->format($format);
    } catch (Throwable) {
        return $utc;
    }
}
