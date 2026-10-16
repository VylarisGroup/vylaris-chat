<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use App\Core\Deferred;

/**
 * Lizenzierung über TRS License (Prüf-API, Ed25519-signierte Antworten).
 *
 * Modell: ein Firmenschlüssel pro Installation. Jedes aktive Konto belegt einen "Geräteplatz" des Schlüssels
 * (maxDevices). Übermittelt werden nur der Schlüssel, ein pseudonymer Platz-Bezeichner je Konto (HMAC) und
 * die IP-Adresse des Servers – keine Namen, E-Mail-Adressen oder Nachrichten.
 * Ohne gültige Lizenz läuft die Installation im Nur-Lesen-Modus (Administration und Datenschutzrechte bleiben nutzbar).
 */
final class License
{
    /** Schlüssel der Funktionen (Features/Scopes), die eine Lizenz freischalten kann. */
    public const SCOPES = [
        'messaging' => ['Nachrichten', 'Nachrichten senden, bearbeiten, reagieren, weiterleiten und Direktchats starten.'],
        'groups'    => ['Gruppen', 'Gruppen anlegen und verwalten (Mitglieder, Rollen, Gruppenbild).'],
        'files'     => ['Dateien', 'Dateien und Bilder hochladen.'],
        'export'    => ['Export', 'Administrative Exporte und das Prüfpaket. (Die eigene Datenauskunft der Nutzer bleibt aus rechtlichen Gründen immer verfügbar.)'],
        '2fa'       => ['Zwei-Faktor', 'Neue Einrichtung der Zwei-Faktor-Authentifizierung. (Bereits eingerichtete 2FA wird weiter geprüft.)'],
        'sso'       => ['Einmalanmeldung', 'Anmeldung über OpenID Connect (SSO).'],
        'push'      => ['Push', 'Web-Push-Benachrichtigungen.'],
        'realtime'  => ['Echtzeit', 'Echtzeit-Zustellung (SSE); sonst Abfrage im Intervall.'],
        'backup'    => ['Backup', 'Backup-Verwaltung und automatische Backups in der Weboberfläche. (Die Kommandozeile bin/backup.php und bin/restore.php bleibt immer nutzbar.)'],
    ];

    private const REFRESH_OK = 21600;     // 6 h
    private const REFRESH_BAD = 900;      // 15 min
    private const RETRY_AFTER_FAIL = 600; // 10 min
    private static bool $refreshQueued = false;
    private static ?array $statusMemo = null;

    // ------------------------------------------------------------ Einstellungen

    public static function key(): string
    {
        return strtoupper(trim(Crypto::decrypt(Settings::get('license_key_enc'))));
    }

    public static function setKey(string $key): void
    {
        Settings::set('license_key_enc', $key === '' ? '' : Crypto::encrypt(strtoupper(trim($key))));
        self::$statusMemo = null;
        @unlink(self::cacheFile());
    }

    /** Fester Lizenzserver und fester öffentlicher Schlüssel (Ed25519). Nur für Entwicklung/Tests per config.php überschreibbar ('license' => ['server' => …, 'public_key' => …]). */
    public const SERVER = 'https://licence.vylaris.ch';
    public const PUBLIC_KEY = "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEAhV7L176abx5a05XciRbOOeFj6wkCnNiDrwEXw7KceiU=\n-----END PUBLIC KEY-----";

    public static function server(): string
    {
        return rtrim(trim((string) Config::get('license.server', '')) ?: self::SERVER, '/');
    }

    public static function publicKey(): string
    {
        return trim((string) Config::get('license.public_key', '')) ?: self::PUBLIC_KEY;
    }

    private static function cacheFile(): string
    {
        return storage_path('cache/license.json');
    }

    /** Roher 32-Byte-Ed25519-Schlüssel aus PEM oder Base64-DER (SubjectPublicKeyInfo). */
    public static function publicKeyRaw(string $pem): ?string
    {
        $b64 = preg_replace('/-----[A-Z ]+-----|\s+/', '', $pem) ?? '';
        $der = base64_decode($b64, true);
        if ($der === false) {
            return null;
        }
        if (strlen($der) === 44 && str_starts_with($der, hex2bin('302a300506032b6570032100'))) {
            return substr($der, 12);
        }
        return strlen($der) === 32 ? $der : null;
    }

    public static function publicKeyFingerprint(): string
    {
        $raw = self::publicKeyRaw(self::publicKey());
        return $raw === null ? '—' : strtoupper(substr(hash('sha256', $raw), 0, 16));
    }

    // ------------------------------------------------------------ Server-Aufrufe

    /** @return array{payload:string,signature:string} Rohantwort der Prüf-API (noch ungeprüft) */
    private static function post(string $endpoint, array $body): array
    {
        $url = self::server() . '/api/v1/' . $endpoint;
        if (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://127.0.0.1') && !str_starts_with($url, 'http://localhost')) {
            throw new \RuntimeException('Der Lizenzserver muss per HTTPS erreichbar sein.');
        }
        if (!function_exists('curl_init')) {
            throw new \RuntimeException('Die PHP-Erweiterung curl fehlt.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_TIMEOUT => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
        ]);
        $raw = curl_exec($ch);
        $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false) {
            throw new \RuntimeException('Lizenzserver nicht erreichbar: ' . $err);
        }
        if ($http === 429) {
            throw new \RuntimeException('Lizenzserver: zu viele Anfragen (HTTP 429).');
        }
        $a = json_decode((string) $raw, true);
        if ($http !== 200 || !is_array($a) || !isset($a['payload'], $a['signature'])) {
            throw new \RuntimeException('Unerwartete Antwort des Lizenzservers (HTTP ' . $http . ').');
        }
        return ['payload' => (string) $a['payload'], 'signature' => (string) $a['signature']];
    }

    /**
     * Ruft die Prüf-API auf und prüft die Signatur.
     *
     * @return array<string,mixed> Signierte Nutzdaten (valid, code, license, ts)
     * @throws \RuntimeException bei Netz-, Signatur- oder Formatfehlern
     */
    public static function call(string $endpoint, array $body): array
    {
        $a = self::post($endpoint, $body);
        return self::verified($a['payload'], $a['signature']);
    }

    /** @return array<string,mixed> */
    public static function verified(string $payload, string $signature, ?int $now = null, ?string $publicKey = null): array
    {
        $pub = self::publicKeyRaw($publicKey ?? self::publicKey());
        $sig = base64_decode($signature, true);
        if ($pub === null || $sig === false || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new \RuntimeException('Signatur oder öffentlicher Schlüssel ungültig.');
        }
        if (!sodium_crypto_sign_verify_detached($sig, $payload, $pub)) {
            throw new \RuntimeException('Die Signatur der Lizenzantwort ist ungültig.');
        }
        $data = json_decode($payload, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Lizenzantwort nicht lesbar.');
        }
        if (abs(($now ?? time()) - (int) ($data['ts'] ?? 0)) > 300) {
            throw new \RuntimeException('Lizenzantwort zu alt oder Serveruhr weicht ab (mehr als 5 Minuten).');
        }
        return $data;
    }

    private static function base(): array
    {
        $b = ['key' => self::key()];
        $product = trim(Settings::get('license_product'));
        if ($product !== '') {
            $b['product'] = $product;
        }
        return $b;
    }

    // ------------------------------------------------------------ Status

    /** Prüft den Schlüssel beim Server und speichert das signierte Ergebnis. @return array{ok:bool,error?:string} */
    public static function refresh(): array
    {
        self::$statusMemo = null;
        if (self::key() === '') {
            @unlink(self::cacheFile());
            return ['ok' => false, 'error' => 'Kein Lizenzschlüssel hinterlegt.'];
        }
        $mark = storage_path('cache/license.attempt');
        @touch($mark);
        try {
            $a = self::post('validate', self::base());
            self::verified($a['payload'], $a['signature']);
            $payload = $a['payload'];
            $sig = $a['signature'];
            file_put_contents(self::cacheFile(), json_encode([
                'key_hash' => hash('sha256', self::key()),
                'checked_at' => time(),
                'payload' => $payload,
                'signature' => $sig,
            ]), LOCK_EX);
            self::$statusMemo = null;
            return ['ok' => true];
        } catch (\Throwable $e) {
            log_error('Lizenz: ' . $e->getMessage());
            @file_put_contents(storage_path('cache/license.fail'), $e->getMessage());
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Aktueller Lizenzstatus aus dem signierten Zwischenspeicher.
     *
     * state: none (kein Schlüssel) | unverified (noch nie / zu lange nicht geprüft) | invalid (Server sagt: ungültig) | ok
     *
     * @return array<string,mixed>
     */
    public static function status(): array
    {
        if (self::$statusMemo !== null) {
            return self::$statusMemo;
        }
        $st = ['state' => 'none', 'code' => 'NO_KEY', 'message' => 'Es ist kein Lizenzschlüssel hinterlegt.', 'features' => [], 'maxDevices' => 0, 'devices' => 0, 'plan' => '', 'productName' => '', 'expiresAt' => null, 'trial' => false, 'checkedAt' => null];
        if (self::key() === '') {
            return self::$statusMemo = $st;
        }
        $st['state'] = 'unverified';
        $st['code'] = 'UNVERIFIED';
        $st['message'] = 'Die Lizenz wurde noch nicht beim Lizenzserver geprüft.';
        $c = is_file(self::cacheFile()) ? json_decode((string) file_get_contents(self::cacheFile()), true) : null;
        $age = null;
        if (is_array($c) && ($c['key_hash'] ?? '') === hash('sha256', self::key())) {
            try {
                $d = self::verified((string) $c['payload'], (string) $c['signature'], (int) $c['checked_at']);
                $age = time() - (int) $c['checked_at'];
                $lic = is_array($d['license'] ?? null) ? $d['license'] : [];
                $st['code'] = (string) ($d['code'] ?? '');
                $st['checkedAt'] = (int) $c['checked_at'];
                $st['features'] = array_values(array_filter((array) ($lic['features'] ?? []), 'is_string'));
                $st['maxDevices'] = (int) ($lic['maxDevices'] ?? 0);
                $st['devices'] = (int) ($lic['devices'] ?? 0);
                $st['plan'] = (string) ($lic['plan'] ?? '');
                $st['productName'] = (string) ($lic['productName'] ?? '');
                $st['trial'] = (bool) ($lic['trial'] ?? false);
                $st['expiresAt'] = $lic['expiresAt'] ?? null;
                if (!empty($d['valid'])) {
                    $exp = $st['expiresAt'] ? strtotime((string) $st['expiresAt']) : null;
                    if ($exp !== null && $exp !== false && $exp < time()) {
                        $st['state'] = 'invalid';
                        $st['code'] = 'EXPIRED';
                        $st['message'] = 'Die Lizenz ist abgelaufen.';
                    } elseif ($age > max(1, Settings::int('license_grace_hours')) * 3600) {
                        $st['state'] = 'unverified';
                        $st['code'] = 'STALE';
                        $st['message'] = 'Die Lizenz konnte seit längerer Zeit nicht beim Lizenzserver bestätigt werden.';
                    } else {
                        $st['state'] = 'ok';
                        $st['message'] = 'Lizenz gültig.';
                    }
                } else {
                    $st['state'] = 'invalid';
                    $st['message'] = self::codeMessage($st['code']);
                }
            } catch (\Throwable $e) {
                $st['message'] = 'Gespeicherter Lizenzstatus ist ungültig: ' . $e->getMessage();
            }
        }
        // Hintergrundprüfung (nach der Antwort), mit Begrenzung der Häufigkeit
        $interval = $st['state'] === 'ok' ? self::REFRESH_OK : self::REFRESH_BAD;
        if ($st['state'] !== 'none' && ($age === null || $age > $interval) && !self::$refreshQueued) {
            $mark = storage_path('cache/license.attempt');
            if (!is_file($mark) || filemtime($mark) < time() - self::RETRY_AFTER_FAIL || ($age === null && !is_file(self::cacheFile()) && filemtime($mark) < time() - 60)) {
                self::$refreshQueued = true;
                @touch($mark);
                Deferred::add(static function (): void {
                    if (self::refresh()['ok']) {
                        self::reconcile();
                    }
                });
            }
        }
        return self::$statusMemo = $st;
    }

    public static function codeMessage(string $code): string
    {
        return match ($code) {
            'NOT_FOUND' => 'Der Lizenzschlüssel ist unbekannt.',
            'BLOCKED' => 'Die Lizenz wurde gesperrt.',
            'EXPIRED' => 'Die Lizenz ist abgelaufen.',
            'WRONG_PRODUCT' => 'Der Schlüssel gehört zu einem anderen Produkt.',
            'DEVICE_LIMIT' => 'Alle Geräteplätze der Lizenz sind belegt.',
            'NOT_ACTIVATED' => 'Der Platz ist nicht aktiviert.',
            'HWID_REQUIRED' => 'Platzkennung fehlt.',
            default => 'Die Lizenz ist nicht gültig (' . $code . ').',
        };
    }

    public static function active(): bool
    {
        return self::status()['state'] === 'ok';
    }

    /** Ist die Funktion (Scope) lizenziert? Ohne gültige Lizenz nie. */
    public static function has(string $scope): bool
    {
        $s = self::status();
        return $s['state'] === 'ok' && in_array($scope, $s['features'], true);
    }

    // ------------------------------------------------------------ Plätze (Konten)

    public static function hwidFor(int $userId): string
    {
        return 'u-' . substr(hash_hmac('sha256', 'seat|' . $userId, (string) Config::get('app.key', '')), 0, 32);
    }

    public static function activeUsers(): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM users WHERE is_active = 1 AND deleted_at IS NULL');
    }

    public static function seatsUsed(): int
    {
        return (int) Database::value('SELECT COUNT(*) FROM license_seats');
    }

    /** Darf ein weiteres Konto angelegt werden? @return string|null Fehlermeldung */
    public static function canAddUser(): ?string
    {
        $s = self::status();
        if ($s['state'] !== 'ok') {
            return 'Neue Konten sind nicht möglich: ' . $s['message'] . ' Bitte wende dich an die Administration.';
        }
        if (self::activeUsers() >= max(0, $s['maxDevices'])) {
            return 'Das Benutzerlimit der Lizenz (' . $s['maxDevices'] . ') ist erreicht. Bitte wende dich an die Administration.';
        }
        return null;
    }

    /** Belegt einen Platz für das Konto beim Lizenzserver. @return string|null Fehlermeldung */
    public static function claimSeat(int $userId): ?string
    {
        $hwid = self::hwidFor($userId);
        try {
            $d = self::call('activate', self::base() + ['hwid' => $hwid, 'name' => 'Konto']);
        } catch (\Throwable $e) {
            log_error('Lizenz-Aktivierung: ' . $e->getMessage());
            return 'Der Lizenzserver ist derzeit nicht erreichbar. Bitte versuche es später erneut.';
        }
        if (empty($d['valid'])) {
            $code = (string) ($d['code'] ?? '');
            self::forgetStatus();
            return $code === 'DEVICE_LIMIT'
                ? 'Das Benutzerlimit der Lizenz ist erreicht. Bitte wende dich an die Administration.'
                : self::codeMessage($code);
        }
        Database::exec('INSERT IGNORE INTO license_seats (user_id, hwid, activated_at) VALUES (?,?,?)', [$userId, $hwid, now()]);
        return null;
    }

    public static function releaseSeat(int $userId): void
    {
        try {
            self::call('deactivate', self::base() + ['hwid' => self::hwidFor($userId)]);
        } catch (\Throwable $e) {
            log_error('Lizenz-Deaktivierung: ' . $e->getMessage());
            return; // Platz bleibt vermerkt; der nächste Abgleich versucht es erneut
        }
        Database::exec('DELETE FROM license_seats WHERE user_id = ?', [$userId]);
    }

    private static function forgetStatus(): void
    {
        self::$statusMemo = null;
    }

    /**
     * Gleicht Plätze mit den Konten ab: aktive Konten ohne Platz belegen einen (bis zum Limit),
     * Plätze gelöschter/deaktivierter Konten werden freigegeben. Niemand wird ausgesperrt.
     *
     * @return array{claimed:int,released:int,over:int,error?:string}
     */
    public static function reconcile(): array
    {
        $out = ['claimed' => 0, 'released' => 0, 'over' => 0];
        if (self::key() === '') {
            return $out;
        }
        $stale = Database::all('SELECT s.user_id FROM license_seats s LEFT JOIN users u ON u.id = s.user_id WHERE u.id IS NULL OR u.deleted_at IS NOT NULL OR u.is_active = 0 LIMIT 200');
        foreach ($stale as $row) {
            self::releaseSeat((int) $row['user_id']);
            $out['released']++;
        }
        if (self::status()['state'] !== 'ok') {
            return $out;
        }
        $missing = Database::all('SELECT u.id FROM users u LEFT JOIN license_seats s ON s.user_id = u.id WHERE u.is_active = 1 AND u.deleted_at IS NULL AND s.user_id IS NULL ORDER BY u.id ASC LIMIT 200');
        foreach ($missing as $i => $row) {
            $err = self::claimSeat((int) $row['id']);
            if ($err !== null) {
                $out['over'] = count($missing) - $i;
                $out['error'] = $err;
                break;
            }
            $out['claimed']++;
        }
        if ($out['claimed'] > 0 || $out['released'] > 0) {
            self::refresh();
        }
        return $out;
    }

    /** Regelmässige Wartung (Cron/Bereinigung): Status auffrischen, Plätze abgleichen. */
    public static function maintain(): void
    {
        if (self::key() === '') {
            return;
        }
        $st = self::status();
        if ($st['checkedAt'] === null || time() - (int) $st['checkedAt'] > ($st['state'] === 'ok' ? self::REFRESH_OK : self::REFRESH_BAD)) {
            self::refresh();
        }
        self::reconcile();
    }

    /** Gleicht nach der Antwort ab (z. B. nach Kontoänderungen). */
    public static function reconcileLater(): void
    {
        if (self::key() !== '') {
            Deferred::add(static fn () => self::reconcile());
        }
    }

    // ------------------------------------------------------------ Registrierung

    public static function keyMatches(string $input): bool
    {
        $k = self::key();
        return $k !== '' && hash_equals($k, strtoupper(trim($input)));
    }

    // ------------------------------------------------------------ Zugriffsregeln

    /**
     * Welche Funktion (Scope) braucht diese Anfrage, bzw. ist sie ein reiner Schreibzugriff, der ohne Lizenz gesperrt ist?
     *
     * @return array{scope:?string,write:bool}|null null = frei zugänglich
     */
    public static function requirement(string $method, string $path): ?array
    {
        $path = '/' . trim($path, '/');
        $m = strtoupper($method);
        $rules = [
            // [Methoden, Regex, Scope, ohne Lizenz gesperrt]
            [['POST'], '#^/api/conversations/direct$#', 'messaging', true],
            [['POST'], '#^/api/conversations/group$#', 'groups', true],
            [['POST'], '#^/api/conversations/\d+/messages$#', 'messaging', true],
            [['POST'], '#^/api/conversations/\d+/uploads$#', 'files', true],
            [['POST'], '#^/api/conversations/\d+/(members|avatar)$#', 'groups', true],
            [['DELETE', 'PATCH'], '#^/api/conversations/\d+/members/\d+$#', 'groups', true],
            [['PATCH'], '#^/api/conversations/\d+$#', 'groups', true],
            [['DELETE'], '#^/api/conversations/\d+/avatar$#', 'groups', true],
            [['POST'], '#^/api/conversations/\d+/typing$#', 'messaging', true],
            [['PATCH'], '#^/api/messages/\d+$#', 'messaging', true],
            [['POST'], '#^/api/messages/\d+/(reactions|forward)$#', 'messaging', true],
            [['POST'], '#^/api/push/subscribe$#', 'push', false],
            [['POST'], '#^/security/2fa$#', '2fa', false],
            [['POST'], '#^/admin/users/\d+/export$#', 'export', false],
            [['GET', 'POST'], '#^/admin/privacy/(review|verzeichnis\.md)#', 'export', false],
            [['POST'], '#^/admin/backup/(create|settings|[^/]+/(download|delete|verify))$#', 'backup', false],
        ];
        foreach ($rules as [$methods, $re, $scope, $write]) {
            if (in_array($m, $methods, true) && preg_match($re, $path)) {
                return ['scope' => $scope, 'write' => $write];
            }
        }
        return null;
    }
}
