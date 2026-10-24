<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Migrator;
use App\Core\Request;
use App\Core\Router;
use App\Middleware\Authenticate;
use App\Middleware\RequireAdmin;
use App\Middleware\VerifyCsrf;
use App\Models\User;

/**
 * Eingebaute Funktionsprüfung für Administratoren.
 *
 * - Umgebung, Sicherheit und Konfiguration werden direkt geprüft.
 * - Die Prüfungen der Anwendungslogik laufen in einer Datenbank-Transaktion mit Testkonten und werden am Ende
 *   VOLLSTÄNDIG zurückgerollt: Es bleiben keine Testdaten, Nachrichten oder Ereignisse zurück.
 * - Es werden keine E-Mails versendet und die laufende Sitzung wird nicht verändert.
 */
final class SelfTest
{
    private const TABLES = [
        'users', 'user_sessions', 'user_settings', 'conversations', 'conversation_members', 'messages', 'message_receipts',
        'message_reactions', 'message_attachments', 'message_mentions', 'message_reports', 'notifications', 'invitations',
        'password_resets', 'email_verifications', 'audit_logs', 'system_settings', 'rate_limits', 'events', 'typing_state',
        'privacy_requests', 'schema_migrations', 'user_recovery_codes', 'push_subscriptions', 'jobs', 'data_breaches',
        'polls', 'poll_options', 'poll_votes', 'calendar_events', 'event_rsvps', 'drafts', 'meetings',
    ];

    /** @var array<int,array{title:string,tests:array<int,array{name:string,status:string,detail:string,ms:int}>}> */
    private static array $groups = [];
    private static int $current = -1;

    /** Testdaten (nur während des Laufs) */
    private static array $ctx = [];

    // ------------------------------------------------------------------ Lauf

    public static function run(): array
    {
        $start = microtime(true);
        self::$groups = [];
        self::$ctx = [];

        self::group('Umgebung und Konfiguration', self::environment());
        self::group('Sicherheit', self::security());
        self::group('Routen und Zugriffsschutz', self::routing());
        self::group('Anwendungslogik (Testdaten, wird zurückgerollt)', self::logic(), true);
        self::group('Zwei-Faktor, SSO, Push, Hintergrundaufträge (Testdaten, wird zurückgerollt)', self::advancedLogic(), true);
        self::group('Zusammenarbeit: Threads, Umfragen, Termine, Entwürfe, Gäste (Testdaten, wird zurückgerollt)', self::collaboration(), true);
        self::group('Videokonferenzen (Testdaten, wird zurückgerollt)', self::meetings(), true);
        self::group('Notizen und KI-Assistent (Testdaten, wird zurückgerollt)', self::assistant(), true);
        self::group('Backup, Betrieb und Prüfpaket', self::operations());
        self::group('Lizenz', self::licensing());
        self::group('Aktualisierung', self::updating());
        self::group('Änderungsprotokoll', self::changelog());
        self::group('Assistenten', self::wizards());

        $sum = ['pass' => 0, 'fail' => 0, 'warn' => 0, 'skip' => 0];
        foreach (self::$groups as $g) {
            foreach ($g['tests'] as $t) {
                $sum[$t['status']]++;
            }
        }
        return [
            'at' => gmdate('Y-m-d H:i:s'),
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'version' => (string) Config::get('app.version', ''),
            'summary' => $sum,
            'groups' => self::$groups,
        ];
    }

    /** @param array<string,callable> $tests */
    private static function group(string $title, array $tests, bool $rollback = false): void
    {
        self::$groups[] = ['title' => $title, 'tests' => []];
        self::$current = count(self::$groups) - 1;
        $pdo = null;
        if ($rollback) {
            $pdo = Database::pdo();
            if ($pdo->inTransaction()) {
                self::record('Vorbereitung', 'skip', 'Bereits in einer Transaktion: Logiktests übersprungen.', 0);
                return;
            }
            $pdo->beginTransaction();
        }
        try {
            foreach ($tests as $name => $fn) {
                $t0 = microtime(true);
                try {
                    $res = $fn();
                    if (is_array($res) && ($res[0] ?? '') === 'warn') {
                        self::record($name, 'warn', (string) $res[1], self::ms($t0));
                    } elseif (is_array($res) && ($res[0] ?? '') === 'skip') {
                        self::record($name, 'skip', (string) $res[1], self::ms($t0));
                    } else {
                        self::record($name, 'pass', is_string($res) ? $res : '', self::ms($t0));
                    }
                } catch (\Throwable $e) {
                    $msg = $e instanceof HttpException ? 'HTTP ' . $e->status . ': ' . $e->getMessage() : $e->getMessage();
                    self::record($name, 'fail', $msg . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')', self::ms($t0));
                }
            }
        } finally {
            if ($pdo !== null && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private static function ms(float $t0): int
    {
        return (int) round((microtime(true) - $t0) * 1000);
    }

    private static function record(string $name, string $status, string $detail, int $ms): void
    {
        self::$groups[self::$current]['tests'][] = ['name' => $name, 'status' => $status, 'detail' => $detail, 'ms' => $ms];
    }

    // --------------------------------------------------------------- Helfer

    private static function ok(bool $cond, string $msg): void
    {
        if (!$cond) {
            throw new \RuntimeException($msg);
        }
    }

    private static function eq(mixed $expected, mixed $actual, string $what): void
    {
        if ($expected !== $actual) {
            throw new \RuntimeException($what . ': erwartet ' . json_encode($expected) . ', erhalten ' . json_encode($actual));
        }
    }

    /** Erwartet eine HttpException mit dem angegebenen Status. */
    private static function denies(int $status, callable $fn, string $what): void
    {
        try {
            $fn();
        } catch (HttpException $e) {
            if ($e->status !== $status) {
                throw new \RuntimeException($what . ': erwartet HTTP ' . $status . ', erhalten HTTP ' . $e->status . ' (' . $e->getMessage() . ')');
            }
            return;
        }
        throw new \RuntimeException($what . ': wurde nicht abgelehnt (erwartet HTTP ' . $status . ').');
    }

    private static function newUser(string $label, string $role = 'member'): array
    {
        $email = 'selftest-' . $label . '-' . bin2hex(random_bytes(4)) . '@selftest.invalid';
        $id = User::create($email, 'Test ' . $label, Auth::hashPassword('Test-Passwort-2026!'), $role, true);
        // Keine E-Mail-Benachrichtigungen an Testkonten
        User::settings($id);
        Database::exec('UPDATE user_settings SET notify_email_mentions = 0, notify_email_direct = 0 WHERE user_id = ?', [$id]);
        return User::find($id) ?? throw new \RuntimeException('Testkonto konnte nicht angelegt werden.');
    }

    private static function fresh(array $u): array
    {
        return User::find((int) $u['id']) ?? throw new \RuntimeException('Testkonto fehlt.');
    }

    private static function msgId(array $res): int
    {
        return (int) $res['message']['id'];
    }

    // ------------------------------------------------------------- Umgebung

    private static function environment(): array
    {
        return [
            'PHP-Version ab 8.3' => static function () {
                self::ok(version_compare(PHP_VERSION, '8.3.0', '>='), 'PHP ' . PHP_VERSION . ' ist zu alt (benötigt: 8.3 oder neuer).');
                return 'PHP ' . PHP_VERSION;
            },
            'Pflicht-Erweiterungen (pdo_mysql, mbstring, json, openssl, sodium, fileinfo)' => static function () {
                $missing = array_filter(['pdo_mysql', 'mbstring', 'json', 'openssl', 'sodium', 'fileinfo'], static fn ($e) => !extension_loaded($e));
                self::ok(!$missing, 'Fehlt: ' . implode(', ', $missing));
            },
            'Optionale Erweiterungen (gd, zip)' => static function () {
                $missing = array_filter(['gd' => 'gd', 'zip' => 'zip'], static fn ($e) => !extension_loaded($e));
                return $missing ? ['warn', 'Fehlt: ' . implode(', ', $missing) . ' (gd: Vorschaubilder und Profilbilder, zip: ZIP-Datenexport, sonst JSON).'] : 'gd und zip vorhanden';
            },
            'Konfigurationsdatei und Schlüssel (Verschlüsselung Hin- und Rückweg)' => static function () {
                self::ok(Config::exists(), 'config/config.php fehlt.');
                $c = Crypto::encrypt('selftest-geheimnis');
                self::eq('selftest-geheimnis', Crypto::decrypt($c), 'Entschlüsselung');
            },
            'Datenbankverbindung und UTC-Zeit' => static function () {
                $dbNow = strtotime((string) Database::value('SELECT UTC_TIMESTAMP()') . ' UTC');
                $diff = abs($dbNow - time());
                self::ok($diff < 120, 'Datenbankzeit weicht um ' . $diff . ' s von der Serverzeit ab.');
                return 'Abweichung ' . $diff . ' s';
            },
            'Datenbankschema vollständig (alle Tabellen vorhanden)' => static function () {
                $have = array_column(Database::all('SHOW TABLES'), array_key_first(Database::one('SHOW TABLES') ?? []) ?? 0);
                $missing = array_diff(self::TABLES, $have);
                self::ok(!$missing, 'Fehlende Tabellen: ' . implode(', ', $missing));
                return count(self::TABLES) . ' Tabellen';
            },
            'Migrationen vollständig angewendet' => static function () {
                $done = array_column(Database::all('SELECT version FROM schema_migrations'), 'version');
                $open = array_diff(Migrator::available(), $done);
                self::ok(!$open, 'Offen: ' . implode(', ', $open) . ' (php bin/migrate.php ausführen)');
                return count($done) . ' Migrationen';
            },
            'Speicherverzeichnisse beschreibbar' => static function () {
                $bad = [];
                foreach (['uploads', 'avatars', 'branding', 'cache', 'logs', 'sessions'] as $d) {
                    $p = storage_path($d);
                    if (!is_dir($p) && !@mkdir($p, 0750, true)) {
                        $bad[] = $d . ' (fehlt)';
                    } elseif (!is_writable($p)) {
                        $bad[] = $d . ' (nicht beschreibbar)';
                    }
                }
                self::ok(!$bad, implode(', ', $bad));
            },
            'Schreib-/Lesetest im Speicher' => static function () {
                $f = storage_path('cache/selftest-' . bin2hex(random_bytes(4)) . '.tmp');
                self::ok(@file_put_contents($f, 'ok') === 2, 'Schreiben fehlgeschlagen.');
                $r = @file_get_contents($f);
                @unlink($f);
                self::eq('ok', $r, 'Lesen');
            },
            'Speicher liegt ausserhalb des Web-Verzeichnisses' => static function () {
                $storage = realpath(storage_path()) ?: storage_path();
                $public = realpath(ROOT_PATH . '/public') ?: ROOT_PATH . '/public';
                self::ok(!str_starts_with($storage . '/', $public . '/'), 'storage/ liegt unterhalb von public/ und wäre direkt abrufbar.');
                self::ok(is_file(storage_path('.htaccess')), 'storage/.htaccess fehlt (zweite Schutzebene).');
            },
            'Fehlermeldungen werden nicht angezeigt (display_errors)' => static function () {
                $v = strtolower((string) ini_get('display_errors'));
                return in_array($v, ['', '0', 'off', 'false', 'no', 'stderr'], true) ? 'aus' : ['warn', 'display_errors ist an: Fehlerdetails könnten Besuchern angezeigt werden.'];
            },
            'HTTPS aktiv' => static function () {
                return is_https() ? 'aktiv' : ['warn', 'Die Verbindung ist nicht per HTTPS geschützt.'];
            },
            'Sitzungs-Cookie (HttpOnly, nur Cookies)' => static function () {
                if (PHP_SAPI === 'cli') {
                    return ['skip', 'Nur im Browser-Betrieb prüfbar.'];
                }
                $p = session_get_cookie_params();
                self::ok($p['httponly'] === true, 'Cookie ist nicht HttpOnly.');
                self::eq('1', (string) ini_get('session.use_only_cookies'), 'session.use_only_cookies');
                return 'SameSite ' . ($p['samesite'] ?? '–') . (is_https() && empty($p['secure']) ? ' (Secure fehlt)' : '');
            },
            'Upload-Limits passend zur Einstellung' => static function () {
                $php = min(Files::iniBytes('upload_max_filesize'), Files::iniBytes('post_max_size'));
                $cfg = Settings::int('max_upload_mb') * 1048576;
                return $cfg > $php ? ['warn', 'Eingestellt sind ' . Settings::int('max_upload_mb') . ' MB, PHP erlaubt nur ' . human_size($php) . '.'] : 'PHP-Limit ' . human_size($php);
            },
            'E-Mail-Versand konfiguriert' => static function () {
                return Mail::configured() ? 'SMTP konfiguriert' : ['warn', 'SMTP ist nicht konfiguriert: Einladungen, Bestätigungs- und Reset-Mails gehen nicht per E-Mail raus.'];
            },
            'Bereinigung läuft regelmässig' => static function () {
                $last = (int) Settings::get('last_cleanup_at');
                return $last > time() - 3 * 86400 ? 'zuletzt ' . local_dt(gmdate('Y-m-d H:i:s', $last)) : ['warn', 'Die Bereinigung lief seit über 3 Tagen nicht (Cron-Job einrichten: php bin/cleanup.php).'];
            },
            'Mindestens zwei aktive Administratoren' => static function () {
                $n = (int) Database::value("SELECT COUNT(*) FROM users WHERE role = 'admin' AND is_active = 1 AND deleted_at IS NULL");
                self::ok($n >= 1, 'Kein aktiver Administrator.');
                return $n < 2 ? ['warn', 'Nur ein Administrator: Bei Verlust des Zugangs ist die Verwaltung gesperrt.'] : $n . ' Administratoren';
            },
            'Datenschutzerklärung vollständig' => static function () {
                $missing = [];
                foreach (['privacy_controller' => 'Verantwortlicher', 'privacy_contact_email' => 'Kontaktadresse', 'privacy_hosting' => 'Hosting/Serverstandort'] as $k => $l) {
                    if (trim(Settings::get($k)) === '') {
                        $missing[] = $l;
                    }
                }
                return $missing ? ['warn', 'Unter Administration > Datenschutz fehlt: ' . implode(', ', $missing)] : 'Angaben hinterlegt';
            },
            'Überfällige Datenschutzanfragen' => static function () {
                $n = (int) Database::value("SELECT COUNT(*) FROM privacy_requests WHERE status IN ('open','in_progress') AND due_at < ?", [now()]);
                return $n > 0 ? ['warn', $n . ' Anfrage(n) haben die Antwortfrist überschritten.'] : 'keine';
            },
        ];
    }

    // ------------------------------------------------------------- Sicherheit

    private static function security(): array
    {
        return [
            'Passwortrichtlinie' => static function () {
                self::ok(Auth::passwordError('kurz1!') !== null, 'Zu kurzes Passwort wurde akzeptiert.');
                self::ok(Auth::passwordError('nurbuchstabenhier') !== null, 'Passwort ohne Ziffer/Sonderzeichen wurde akzeptiert.');
                self::ok(Auth::passwordError('1234567890123') !== null, 'Passwort ohne Buchstaben wurde akzeptiert.');
                self::ok(Auth::passwordError('Gutes-Passwort-2026') === null, 'Gültiges Passwort wurde abgelehnt.');
                self::ok(Auth::passwordError(str_repeat('a1', 100)) !== null, 'Übermässig langes Passwort wurde akzeptiert.');
            },
            'Passwort-Hash (Verifikation, individuelles Salz)' => static function () {
                $a = Auth::hashPassword('Geheimes-Passwort-1');
                $b = Auth::hashPassword('Geheimes-Passwort-1');
                self::ok($a !== $b, 'Hashes sind identisch (kein Salz).');
                self::ok(password_verify('Geheimes-Passwort-1', $a), 'Verifikation schlägt fehl.');
                self::ok(!password_verify('Falsch', $a), 'Falsches Passwort wird akzeptiert.');
                self::ok(!str_contains($a, 'Geheimes'), 'Hash enthält Klartext.');
            },
            'Verschlüsselung erkennt Manipulation' => static function () {
                $c = Crypto::encrypt('abc');
                $bin = base64_decode($c);
                $bin[strlen($bin) - 1] = $bin[strlen($bin) - 1] ^ "\x01";
                self::eq('', Crypto::decrypt(base64_encode($bin)), 'Manipulierter Chiffretext');
            },
            'E-Mail-Validierung' => static function () {
                self::ok(Auth::validEmail('anna@muster.ch'), 'Gültige Adresse abgelehnt.');
                foreach (['', 'anna', 'anna@', '@muster.ch', "a\nb@muster.ch", 'a b@muster.ch'] as $bad) {
                    self::ok(!Auth::validEmail($bad), 'Ungültige Adresse akzeptiert: ' . json_encode($bad));
                }
            },
            'Ausgabe-Escaping e()' => static function () {
                $out = e('<script>alert("x")</script>\'');
                self::ok(!str_contains($out, '<script>') && !str_contains($out, '"x"'), 'Sonderzeichen nicht maskiert.');
            },
            'CSRF-Prüfung (Token, falsches Token, fehlendes Token)' => static function () {
                if (session_status() !== PHP_SESSION_ACTIVE) {
                    return ['skip', 'Keine aktive Sitzung (nur im Browser-Betrieb prüfbar).'];
                }
                $tok = Csrf::token();
                $mk = static fn (array $post, array $server = []) => new Request('POST', '/x', [], $post, [], $server, '');
                self::ok(Csrf::valid($mk(['_csrf' => $tok])), 'Gültiges Token abgelehnt.');
                self::ok(Csrf::valid($mk([], ['HTTP_X_CSRF_TOKEN' => $tok])), 'Token im Header abgelehnt.');
                self::ok(!Csrf::valid($mk(['_csrf' => $tok . 'x'])), 'Falsches Token akzeptiert.');
                self::ok(!Csrf::valid($mk([])), 'Fehlendes Token akzeptiert.');
            },
            'Rate-Limiting greift' => static function () {
                $pdo = Database::pdo();
                $own = !$pdo->inTransaction();
                if ($own) {
                    $pdo->beginTransaction();
                }
                try {
                    $id = 'selftest-' . bin2hex(random_bytes(4));
                    $res = [];
                    for ($i = 0; $i < 4; $i++) {
                        $res[] = RateLimiter::hit('selftest', $id, 3, 60);
                    }
                    self::eq([true, true, true, false], $res, 'Treffer 1 bis 4');
                } finally {
                    if ($own && $pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
            },
            'Dateinamen werden bereinigt (Pfadtrick, Steuerzeichen)' => static function () {
                foreach (['../../etc/passwd', '..\\..\\boot.ini', "evil\x00.php", '<b>x</b>.txt', '...'] as $in) {
                    $out = Files::sanitizeName($in);
                    self::ok($out !== '' && !str_contains($out, '/') && !str_contains($out, '\\') && !str_contains($out, "\0") && !str_contains($out, '<'), 'Unsicherer Name für ' . json_encode($in) . ': ' . json_encode($out));
                }
            },
            'Speicherpfade verhindern Verzeichniswechsel' => static function () {
                self::denies(0, static function () {
                    try {
                        Files::path('../../config/config.php');
                    } catch (\InvalidArgumentException) {
                        throw new HttpException(0, 'ok');
                    }
                }, 'Pfadtrick');
                self::ok(str_starts_with(Files::path(str_repeat('a', 40)), storage_path('uploads')), 'Pfad liegt ausserhalb von uploads.');
            },
            'Sicherheits-Header (CSP, nosniff)' => static function () {
                if (PHP_SAPI === 'cli') {
                    return ['skip', 'Nur im Browser-Betrieb prüfbar.'];
                }
                $h = implode("\n", headers_list());
                self::ok(stripos($h, 'Content-Security-Policy:') !== false, 'Content-Security-Policy fehlt.');
                self::ok(stripos($h, 'X-Content-Type-Options: nosniff') !== false, 'X-Content-Type-Options fehlt.');
                self::ok(stripos($h, 'script-src \'self\'') !== false, 'script-src ist nicht auf \'self\' beschränkt.');
            },
        ];
    }

    // ----------------------------------------------------------------- Routen

    private static function routing(): array
    {
        $router = static function (): Router {
            $r = new Router();
            (require ROOT_PATH . '/app/routes.php')($r);
            return $r;
        };
        return [
            'Administrations-Menü: jeder Eintrag hat eine Route, Funktionen sind sichtbar eingeordnet' => static function () use ($router) {
                $src = (string) file_get_contents(ROOT_PATH . '/app/Views/layouts/main.php');
                self::ok((bool) preg_match_all("~\\['([a-z]+)', '(admin(?:/[a-z]+)?)', '([^']+)', '([a-z-]+)'\\]~", $src, $m, PREG_SET_ORDER), 'Menüeinträge nicht gefunden');
                $get = [];
                foreach ($router()->all() as $r) {
                    if ($r['method'] === 'GET') {
                        $get[] = $r['regex'];
                    }
                }
                $keys = [];
                foreach ($m as [, $key, $path, $label]) {
                    $keys[$key] = $path;
                    $found = false;
                    foreach ($get as $re) {
                        if (preg_match($re, '/' . $path)) {
                            $found = true;
                            break;
                        }
                    }
                    self::ok($found, 'Menüeintrag «' . $label . '» ohne GET-Route ' . $path);
                }
                foreach (['meets' => 'admin/meets', 'ai' => 'admin/ai'] as $k => $p) {
                    self::ok(($keys[$k] ?? '') === $p, 'Menüeintrag fehlt: ' . $p);
                }
                // Gruppe mit den Funktionen muss ein eigener, nicht in «System» versteckter Eintrag sein
                self::ok(str_contains($src, "['Funktionen'"), 'Gruppe «Funktionen» fehlt');
                return count($keys) . ' Menüeinträge';
            },
            'Alle Routen verweisen auf vorhandene Methoden' => static function () use ($router) {
                $routes = $router()->all();
                foreach ($routes as $r) {
                    [$class, $method] = $r['handler'];
                    self::ok(class_exists($class), 'Klasse fehlt: ' . $class);
                    self::ok(method_exists($class, $method), 'Methode fehlt: ' . $class . '::' . $method);
                }
                return count($routes) . ' Routen';
            },
            'Zustandsändernde Routen sind CSRF-geschützt' => static function () use ($router) {
                $bad = [];
                // Bewusste Ausnahme: Der Service Worker hat weder Sitzung noch CSRF-Token (Adresserneuerung, autorisiert über die alte Push-Adresse).
                $allowed = ['#^/api/push/resubscribe$#', '#^/api/meets/webhook$#'];
                foreach ($router()->all() as $r) {
                    if (in_array($r['regex'], $allowed, true)) {
                        continue;
                    }
                    if (in_array($r['method'], ['POST', 'PUT', 'PATCH', 'DELETE'], true) && !in_array(VerifyCsrf::class, $r['middleware'], true)) {
                        $bad[] = $r['method'] . ' ' . $r['regex'];
                    }
                }
                self::ok(!$bad, 'Ohne CSRF-Schutz: ' . implode('; ', $bad));
            },
            'Alle Admin-Routen erfordern Administratorrechte' => static function () use ($router) {
                $bad = [];
                $n = 0;
                foreach ($router()->all() as $r) {
                    if (str_contains($r['regex'], '/admin')) {
                        $n++;
                        if (!in_array(RequireAdmin::class, $r['middleware'], true) || !in_array(Authenticate::class, $r['middleware'], true)) {
                            $bad[] = $r['method'] . ' ' . $r['regex'];
                        }
                    }
                }
                self::ok(!$bad, 'Ohne Admin-Schutz: ' . implode('; ', $bad));
                return $n . ' Admin-Routen geprüft';
            },
            'Alle API-Routen erfordern Anmeldung' => static function () use ($router) {
                $bad = [];
                // Bewusste Ausnahmen für den Service Worker: öffentlicher VAPID-Schlüssel und Adresserneuerung (Rate-Limit, Autorisierung über die alte Adresse).
                $allowed = ['#^/api/push/resubscribe$#', '#^/api/push/key$#', '#^/api/meets/webhook$#'];
                foreach ($router()->all() as $r) {
                    if (in_array($r['regex'], $allowed, true)) {
                        continue;
                    }
                    if (str_contains($r['regex'], '/api/') && !in_array(Authenticate::class, $r['middleware'], true)) {
                        $bad[] = $r['method'] . ' ' . $r['regex'];
                    }
                }
                self::ok(!$bad, 'Ohne Anmeldung erreichbar: ' . implode('; ', $bad));
            },
            'Medien-Routen erfordern Anmeldung' => static function () use ($router) {
                foreach ($router()->all() as $r) {
                    if (str_contains($r['regex'], '/files/') || str_contains($r['regex'], '/avatar/')) {
                        self::ok(in_array(Authenticate::class, $r['middleware'], true), 'Ungeschützte Medienroute: ' . $r['regex']);
                    }
                }
            },
            'Kein Admin-Endpunkt liefert Nachrichteninhalte privater Chats' => static function () {
                // Statische Prüfung: Die Datenschutz-Verwaltung und der Datenauszug dürfen keine Nachrichtentexte lesen.
                $src = (string) file_get_contents(ROOT_PATH . '/app/Controllers/AdminPrivacyController.php');
                self::ok(!preg_match('/FROM\s+messages/i', $src) && !preg_match('/\bbody\b/', $src), 'AdminPrivacyController greift auf Nachrichten zu.');
                $svc = (string) file_get_contents(ROOT_PATH . '/app/Services/Privacy.php');
                self::ok(str_contains($svc, "CHAR_LENGTH(body) AS zeichen"), 'Metadaten-Export liest Nachrichtentext.');
            },
        ];
    }

    // ----------------------------------------------------------------- Logik

    private static function logic(): array
    {
        return [
            'Testkonten anlegen' => static function () {
                self::$ctx['a'] = self::newUser('A');
                self::$ctx['b'] = self::newUser('B');
                self::$ctx['c'] = self::newUser('C');
                self::ok((int) self::$ctx['a']['id'] > 0, 'Konto A fehlt.');
                return '3 Testkonten';
            },
            'Direktchat ist eindeutig (idempotent)' => static function () {
                $a = self::$ctx['a'];
                $b = self::$ctx['b'];
                $c1 = Chat::directWith((int) $a['id'], (int) $b['id']);
                $c2 = Chat::directWith((int) $b['id'], (int) $a['id']);
                self::eq($c1, $c2, 'Direktchat A/B');
                self::$ctx['dm'] = $c1;
                self::denies(422, static fn () => Chat::directWith((int) $a['id'], (int) $a['id']), 'Chat mit sich selbst');
            },
            'Nachricht senden und lesen (Mitglied)' => static function () {
                $a = self::$ctx['a'];
                $b = self::$ctx['b'];
                $res = Messages::send($a, self::$ctx['dm'], 'Geheimer-Testtext-' . bin2hex(random_bytes(3)), 'selftest-uid-0001', null, []);
                self::ok(!$res['duplicate'], 'Erste Nachricht als Duplikat markiert.');
                self::$ctx['m1'] = self::msgId($res);
                self::$ctx['m1_body'] = $res['message']['body'] ?? '';
                $page = Messages::fetch(self::$ctx['dm'], (int) $b['id'], null, null, null, 20);
                $ids = array_column($page['messages'], 'id');
                self::ok(in_array(self::$ctx['m1'], array_map('intval', $ids), true), 'B sieht die Nachricht nicht.');
            },
            'Doppelter Versand mit gleicher client_uid erzeugt keine zweite Nachricht' => static function () {
                $res = Messages::send(self::$ctx['a'], self::$ctx['dm'], 'Anderer Text', 'selftest-uid-0001', null, []);
                self::ok($res['duplicate'] === true, 'Duplikat nicht erkannt.');
                self::eq(self::$ctx['m1'], self::msgId($res), 'Nachrichten-ID');
            },
            'Fremde sehen weder Nachricht noch Verlauf (IDOR-Schutz)' => static function () {
                $c = self::$ctx['c'];
                self::denies(404, static fn () => Messages::fetch(self::$ctx['dm'], (int) $c['id'], null, null, null, 20), 'Verlauf abrufen');
                self::denies(404, static fn () => Messages::findForUser(self::$ctx['m1'], (int) $c['id']), 'Einzelne Nachricht');
                self::denies(404, static fn () => Messages::send($c, self::$ctx['dm'], 'Einbruch', null, null, []), 'Senden in fremden Chat');
                self::denies(404, static fn () => Messages::react($c, self::$ctx['m1'], '👍'), 'Reagieren auf fremde Nachricht');
                self::denies(404, static fn () => Chat::details(self::$ctx['dm'], (int) $c['id']), 'Chat-Details');
            },
            'Suche findet nur eigene Unterhaltungen' => static function () {
                $needle = trim((string) self::$ctx['m1_body']);
                self::ok($needle !== '', 'Kein Suchtext.');
                $mine = Search::run((int) self::$ctx['b']['id'], $needle);
                self::ok(count($mine['messages']) >= 1, 'Mitglied findet die Nachricht nicht.');
                $other = Search::run((int) self::$ctx['c']['id'], $needle);
                self::eq(0, count($other['messages']), 'Treffer bei Nicht-Mitglied');
            },
            'Bearbeiten: nur eigene Nachricht' => static function () {
                self::denies(403, static fn () => Messages::edit(self::$ctx['b'], self::$ctx['m1'], 'Manipuliert'), 'Fremde Nachricht bearbeiten');
                $e = Messages::edit(self::$ctx['a'], self::$ctx['m1'], 'Bearbeiteter Testtext');
                self::ok(!empty($e['edited_at']) || !empty($e['message']['edited_at']), 'edited_at fehlt.');
            },
            'Reaktion setzen und wieder entfernen' => static function () {
                $b = self::$ctx['b'];
                Messages::react($b, self::$ctx['m1'], '👍');
                self::eq(1, (int) Database::value('SELECT COUNT(*) FROM message_reactions WHERE message_id = ?', [self::$ctx['m1']]), 'Reaktionen nach Setzen');
                Messages::react($b, self::$ctx['m1'], '👍');
                self::eq(0, (int) Database::value('SELECT COUNT(*) FROM message_reactions WHERE message_id = ?', [self::$ctx['m1']]), 'Reaktionen nach Entfernen');
                self::denies(422, static fn () => Messages::react($b, self::$ctx['m1'], 'nicht-erlaubt'), 'Unbekannte Reaktion');
            },
            'Leere und zu lange Nachrichten werden abgelehnt' => static function () {
                self::denies(422, static fn () => Messages::send(self::$ctx['a'], self::$ctx['dm'], '   ', null, null, []), 'Leere Nachricht');
                self::denies(422, static fn () => Messages::send(self::$ctx['a'], self::$ctx['dm'], str_repeat('x', Messages::MAX_BODY + 1), null, null, []), 'Zu lange Nachricht');
            },
            'Lesestatus wird gesetzt' => static function () {
                $b = self::$ctx['b'];
                Messages::markRead(self::$ctx['dm'], (int) $b['id'], null);
                $last = (int) Database::value('SELECT last_read_message_id FROM conversation_members WHERE conversation_id = ? AND user_id = ?', [self::$ctx['dm'], $b['id']]);
                self::ok($last >= self::$ctx['m1'], 'last_read_message_id nicht aktualisiert.');
            },
            'Gruppe: erstellen, Mitglied hinzufügen, entfernen, verlassen' => static function () {
                $a = self::$ctx['a'];
                $b = self::$ctx['b'];
                $c = self::$ctx['c'];
                $gid = Chat::createGroup((int) $a['id'], 'Selftest-Gruppe', '', [(int) $b['id']]);
                self::$ctx['grp'] = $gid;
                self::ok(Chat::membership($gid, (int) $b['id']) !== null, 'B ist kein Mitglied.');
                self::denies(403, static fn () => Chat::addMembers($gid, (int) $b['id'], [(int) $c['id']]), 'Mitglied ohne Rechte fügt hinzu');
                Chat::addMembers($gid, (int) $a['id'], [(int) $c['id']]);
                self::ok(Chat::membership($gid, (int) $c['id']) !== null, 'C wurde nicht hinzugefügt.');
                Chat::removeMember($gid, (int) $a['id'], (int) $c['id']);
                self::ok(Chat::membership($gid, (int) $c['id']) === null, 'C wurde nicht entfernt.');
                self::denies(404, static fn () => Messages::fetch($gid, (int) $c['id'], null, null, null, 10), 'Entferntes Mitglied liest Gruppe');
                Chat::leave($gid, (int) $a['id']);
                $admins = (int) Database::value("SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ? AND left_at IS NULL AND role = 'admin'", [$gid]);
                self::ok($admins >= 1, 'Gruppe ohne Administrator zurückgeblieben.');
            },
            'Weiterleiten markiert die Kopie' => static function () {
                $b = self::$ctx['b'];
                $ids = Messages::forward(self::$ctx['a'], self::$ctx['m1'], [self::$ctx['dm']]);
                self::ok(count($ids) === 1, 'Keine Kopie erzeugt.');
                self::eq(1, (int) Database::value('SELECT forwarded FROM messages WHERE id = ?', [$ids[0]]), 'forwarded-Flag');
            },
            'Melden: nicht die eigene Nachricht, Meldung wird gespeichert' => static function () {
                self::denies(422, static fn () => Messages::report(self::$ctx['a'], self::$ctx['m1'], 'Test'), 'Eigene Nachricht melden');
                self::denies(404, static fn () => Messages::report(self::$ctx['c'], self::$ctx['m1'], 'Test-Grund'), 'Fremde Nachricht melden');
                Messages::report(self::$ctx['b'], self::$ctx['m1'], 'Test-Grund');
                self::eq(1, (int) Database::value("SELECT COUNT(*) FROM message_reports WHERE message_id = ? AND status = 'open'", [self::$ctx['m1']]), 'Offene Meldungen');
            },
            'Dateien: Fremde können Anhänge nicht abrufen' => static function () {
                $storage = bin2hex(random_bytes(20));
                $aid = Database::insert(
                    'INSERT INTO message_attachments (message_id, conversation_id, uploader_id, storage_name, original_name, mime_type, size_bytes, created_at) VALUES (?,?,?,?,?,?,?,?)',
                    [self::$ctx['m1'], self::$ctx['dm'], self::$ctx['a']['id'], $storage, 'geheim.pdf', 'application/pdf', 10, now()]
                );
                self::$ctx['att'] = $aid;
                self::denies(404, static fn () => Files::serve((int) self::$ctx['c']['id'], $aid, true, false), 'Datei für Nicht-Mitglied');
            },
            'Admin-Löschen einer gemeldeten Nachricht entfernt Inhalt und Anhänge' => static function () {
                Messages::removeReported((int) self::$ctx['a']['id'], self::$ctx['m1']);
                $row = Messages::find(self::$ctx['m1']);
                self::eq('', $row['body'], 'Text nach Entfernen');
                self::ok($row['deleted_at'] !== null, 'deleted_at fehlt.');
                self::eq(0, (int) Database::value('SELECT COUNT(*) FROM message_attachments WHERE message_id = ?', [self::$ctx['m1']]), 'Anhänge');
            },
            'Eigene Nachricht löschen: nur Absender' => static function () {
                $res = Messages::send(self::$ctx['a'], self::$ctx['dm'], 'Zu löschender Text', 'selftest-uid-0002', null, []);
                $id = self::msgId($res);
                self::denies(403, static fn () => Messages::delete(self::$ctx['b'], $id), 'Fremde Nachricht löschen');
                Messages::delete(self::$ctx['a'], $id);
                self::eq('', Messages::find($id)['body'], 'Text nach Löschen');
            },
            'Datenschutz: Selbstexport enthält eigene Inhalte, aber keine fremden' => static function () {
                $res = Messages::send(self::$ctx['a'], self::$ctx['dm'], 'EIGENER-INHALT-A', null, null, []);
                Messages::send(self::$ctx['b'], self::$ctx['dm'], 'FREMDER-INHALT-B', null, null, []);
                $json = json_encode(Privacy::collect((int) self::$ctx['a']['id'], 'full'));
                self::ok(str_contains($json, 'EIGENER-INHALT-A'), 'Eigener Inhalt fehlt im Export.');
                self::ok(!str_contains($json, 'FREMDER-INHALT-B'), 'Inhalt einer anderen Person im Export.');
                self::ok(!str_contains($json, 'password_hash') && !str_contains($json, '$argon') && !str_contains($json, '$2y$'), 'Passwort-Hash im Export.');
            },
            'Datenschutz: Admin-Auszug enthält keine Nachrichtentexte oder Dateinamen' => static function () {
                $data = Privacy::collect((int) self::$ctx['a']['id'], 'metadata');
                $json = json_encode($data);
                self::ok(!str_contains($json, 'EIGENER-INHALT-A'), 'Nachrichtentext im Metadaten-Auszug.');
                self::ok(!str_contains($json, 'geheim.pdf'), 'Dateiname im Metadaten-Auszug.');
                self::ok(count($data['nachrichten']) > 0 && !array_key_exists('body', $data['nachrichten'][0]), 'Nachrichtenfeld body vorhanden.');
                self::ok(str_contains($data['_hinweis']['umfang'], 'Nur Konto- und Metadaten'), 'Umfangshinweis fehlt.');
            },
            'Datenschutz: Einschränkung sperrt Senden, Hochladen und Verzeichnis' => static function () {
                $a = self::$ctx['a'];
                $admin = self::$ctx['c'];
                Privacy::restrict((int) $a['id'], (int) $admin['id'], 'Selbsttest');
                $ra = self::fresh($a);
                self::ok(Privacy::isRestricted($ra), 'Einschränkung nicht gesetzt.');
                self::denies(403, static fn () => Messages::send($ra, self::$ctx['dm'], 'Darf nicht', null, null, []), 'Senden');
                self::denies(403, static fn () => Messages::edit($ra, self::$ctx['m1'], 'x'), 'Bearbeiten');
                self::denies(403, static fn () => Messages::react($ra, self::$ctx['m1'], '👍'), 'Reagieren');
                self::denies(403, static fn () => Files::upload((int) $a['id'], self::$ctx['dm'], ['error' => UPLOAD_ERR_NO_FILE]), 'Hochladen');
                $dir = array_column(User::directory('Test A', 50), 'id');
                self::ok(!in_array((int) $a['id'], array_map('intval', $dir), true), 'Eingeschränkte Person im Verzeichnis.');
                self::ok(count(Privacy::collect((int) $a['id'], 'metadata')) > 0, 'Auszug trotz Einschränkung nicht möglich.');
                Privacy::unrestrict((int) $a['id'], (int) $admin['id']);
                $ra = self::fresh($a);
                self::ok(!Privacy::isRestricted($ra), 'Einschränkung nicht aufgehoben.');
                Messages::send($ra, self::$ctx['dm'], 'Wieder erlaubt', null, null, []);
            },
            'Datenschutz: Anfragen mit 30-Tage-Frist und Statuswechsel' => static function () {
                $a = self::$ctx['a'];
                $id = Privacy::createRequest((int) $a['id'], 'Test A', 'rectification', 'Selbsttest', 'user', (int) $a['id']);
                $row = Database::one('SELECT * FROM privacy_requests WHERE id = ?', [$id]);
                $days = (strtotime($row['due_at'] . ' UTC') - strtotime($row['created_at'] . ' UTC')) / 86400;
                self::ok(abs($days - Privacy::DEADLINE_DAYS) < 0.01, 'Frist ist ' . $days . ' statt ' . Privacy::DEADLINE_DAYS . ' Tage.');
                self::denies(422, static fn () => Privacy::createRequest((int) $a['id'], 'x', 'unbekannt', '', 'user', null), 'Unbekannte Art');
                Privacy::updateRequest($id, (int) self::$ctx['c']['id'], 'completed', 'Erledigt');
                $row = Database::one('SELECT status, handled_at FROM privacy_requests WHERE id = ?', [$id]);
                self::eq('completed', $row['status'], 'Status');
                self::ok($row['handled_at'] !== null, 'handled_at fehlt.');
                self::ok((int) Database::value("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'system'", [$a['id']]) >= 1, 'Benachrichtigung an Person fehlt.');
            },
            'Datenschutz: Inhalte löschen betrifft nur die eigene Person' => static function () {
                $a = self::$ctx['a'];
                $n = Privacy::eraseContent((int) $a['id']);
                self::ok($n >= 1, 'Keine Nachricht gelöscht.');
                self::eq(0, (int) Database::value("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND kind = 'text' AND deleted_at IS NULL", [$a['id']]), 'Verbleibende Nachrichten von A');
                self::ok((int) Database::value("SELECT COUNT(*) FROM messages WHERE sender_id = ? AND kind = 'text' AND deleted_at IS NULL", [self::$ctx['b']['id']]) >= 1, 'Nachrichten von B wurden mitgelöscht.');
            },
            'Datenschutz: Konto anonymisieren entfernt Personenbezug' => static function () {
                $a = self::$ctx['a'];
                $name = $a['display_name'];
                $sess = Database::insert('INSERT INTO user_sessions (user_id, session_hash, ip, user_agent, created_at, last_activity_at) VALUES (?,?,?,?,?,?)', [$a['id'], hash('sha256', 'selftest' . random_bytes(4)), '203.0.113.7', 'SelftestAgent', now(), now()]);
                self::ok($sess > 0, 'Testsitzung fehlt.');
                Messages::system(self::$ctx['dm'], $name . ' hat etwas getan.');
                Audit::log('selftest.event', (int) $a['id']);
                Auth::anonymize((int) $a['id'], 'selftest');
                $u = Database::one('SELECT * FROM users WHERE id = ?', [$a['id']]) ?? throw new \RuntimeException('Zeile fehlt.');
                self::eq('Gelöschter Benutzer', $u['display_name'], 'Anzeigename');
                self::ok(str_ends_with($u['email'], '@invalid.local'), 'E-Mail nicht ersetzt.');
                self::ok($u['deleted_at'] !== null && !(int) $u['is_active'], 'Konto nicht deaktiviert/gelöscht.');
                self::eq(0, (int) Database::value('SELECT COUNT(*) FROM user_sessions WHERE user_id = ?', [$a['id']]), 'Sitzungen');
                self::eq(0, (int) Database::value("SELECT COUNT(*) FROM audit_logs WHERE user_id = ? AND (ip <> '' OR user_agent <> '')", [$a['id']]), 'IP/Browser in Protokoll');
                self::eq(0, (int) Database::value("SELECT COUNT(*) FROM messages WHERE kind = 'system' AND conversation_id = ? AND body LIKE ?", [self::$ctx['dm'], '%' . User::escapeLike($name) . '%']), 'Name in Systemmeldungen');
                self::ok(!password_verify('Test-Passwort-2026!', $u['password_hash']), 'Altes Passwort funktioniert noch.');
            },
            'Gelöschte Konten verschwinden aus dem Verzeichnis' => static function () {
                $dir = array_column(User::directory('Gelöschter', 50), 'id');
                self::ok(!in_array((int) self::$ctx['a']['id'], array_map('intval', $dir), true), 'Gelöschtes Konto im Verzeichnis.');
            },
        ];
    }

    // ------------------------------------------- Threads, Umfragen, Termine, Gäste

    private static function meetings(): array
    {
        return [
            'Berechtigungen: Direktchat alle, Gruppe nur Moderation, Gäste nie' => static function () {
                $conv = ['direct' => ['type' => 'direct'], 'group' => ['type' => 'group']];
                $m = static fn (string $role) => ['role' => $role];
                self::ok(Meets::canHost(['role' => 'member'], $m('member'), $conv['direct']), 'Direktchat-Mitglied');
                self::ok(!Meets::canHost(['role' => 'member'], $m('member'), $conv['group']), 'Gruppen-Mitglied darf nicht starten');
                self::ok(Meets::canHost(['role' => 'member'], $m('moderator'), $conv['group']), 'Moderation darf starten');
                self::ok(Meets::canHost(['role' => 'member'], $m('admin'), $conv['group']), 'Gruppen-Admin darf starten');
                self::ok(!Meets::canHost(['role' => 'guest'], $m('admin'), $conv['direct']), 'Gast darf nie starten');
            },
            'Webhook: Signatur, Zeitstempel und Idempotenz' => static function () {
                $a = self::newUser('MA');
                $b = self::newUser('MB');
                $cid = Chat::createGroup((int) $a['id'], 'Selftest Meeting', '', [(int) $b['id']]);
                $row = Messages::create($a, $cid, 'Videomeeting gestartet', null, null, false, static function (int $mid) use ($cid, $a): void {
                    Database::insert('INSERT INTO meetings (conversation_id, message_id, room_code, started_by, started_at, expires_at) VALUES (?,?,?,?,?,?)', [$cid, $mid, 'selftest-room', (int) $a['id'], now(), gmdate('Y-m-d H:i:s', time() + 3600)]);
                }, 'meeting');
                Settings::set('meets_webhook_secret_enc', Crypto::encrypt('whsec_selftest_secret'));
                $body = json_encode(['event' => 'room.ended', 'data' => ['room' => ['code' => 'selftest-room'], 'reason' => 'host']]);
                $ts = (string) time();
                $sig = static fn (string $t, string $b, string $k = 'whsec_selftest_secret') => 'sha256=' . hash_hmac('sha256', $t . '.' . $b, $k);
                self::eq(401, Meets::handleWebhook($body, $ts, $sig($ts, $body, 'falsch')), 'Falsches Geheimnis');
                self::eq(401, Meets::handleWebhook($body, (string) (time() - 900), $sig((string) (time() - 900), $body)), 'Alter Zeitstempel');
                self::eq(401, Meets::handleWebhook($body . ' ', $ts, $sig($ts, $body)), 'Veränderter Rumpf');
                self::eq(401, Meets::handleWebhook($body, 'abc', 'x'), 'Ungültiger Zeitstempel');
                self::ok(Database::value('SELECT ended_at FROM meetings WHERE message_id = ?', [self::msgId(['message' => $row])]) === null, 'Meeting trotz ungültiger Aufrufe beendet');
                self::eq(204, Meets::handleWebhook($body, $ts, $sig($ts, $body)), 'Gültiger Aufruf');
                self::eq(204, Meets::handleWebhook($body, $ts, $sig($ts, $body)), 'Doppelte Zustellung');
                self::eq('host', (string) Database::value('SELECT ended_reason FROM meetings WHERE room_code = ?', ['selftest-room']), 'Beendigungsgrund');
                $other = json_encode(['event' => 'room.ended', 'data' => ['room' => ['code' => 'unbekannt']]]);
                self::eq(204, Meets::handleWebhook($other, $ts, $sig($ts, $other)), 'Fremder Raum wird ignoriert');
                $view = Meets::views([$row], (int) $b['id']);
                self::ok(!reset($view)['active'], 'Ansicht zeigt beendetes Meeting als aktiv');
            },
        ];
    }

    private static function assistant(): array
    {
        $keys = ['ai_enabled', 'ai_endpoint', 'ai_model', 'ai_access', 'ai_allowed_users', 'ai_max_context_tokens', 'ai_max_output_tokens', 'ai_system_prompt'];
        $saved = [];
        foreach ($keys as $k) {
            $saved[$k] = Settings::get($k);
        }
        $restore = static function () use ($saved): void {
            foreach ($saved as $k => $v) {
                Settings::set($k, $v);
            }
        };
        return [
            'Notizen: genau ein privater Chat pro Person, nicht für Gäste' => static function () {
                $a = self::newUser('NA');
                $id = Chat::ensureNotes((int) $a['id']);
                self::ok($id !== null && $id === Chat::ensureNotes((int) $a['id']), 'Notizen-Chat nicht stabil');
                self::eq(1, (int) Database::value("SELECT COUNT(*) FROM conversation_members WHERE conversation_id = ?", [$id]), 'Mitglieder der Notizen');
                self::eq('notes', (string) Database::value('SELECT type FROM conversations WHERE id = ?', [$id]), 'Typ');
                $g = self::newUser('NG', 'guest');
                self::ok(Chat::ensureNotes((int) $g['id']) === null, 'Gäste haben keine Notizen');
            },
            'Herkunftshinweis: fest verankert, Manipulationen werden zurückgesetzt' => static function () {
                $html = Watermark::html();
                self::ok(str_contains($html, 'href="https://vylaris.ch"') && str_contains($html, 'powered by <strong>VylarisChat</strong>') && str_contains($html, 'data-vl-mark'), 'Hinweis-Markup');
                foreach (['app/Views/layouts/main.php', 'app/Views/app.php'] as $f) {
                    self::ok(str_contains((string) file_get_contents(ROOT_PATH . '/' . $f), 'Watermark::html()'), $f . ' bindet den Hinweis nicht ein');
                }
                self::ok(str_contains((string) file_get_contents(ROOT_PATH . '/public/index.php'), 'Watermark::guard('), 'Wächter nicht in index.php');
                $page = static fn (string $inner) => new \App\Core\Response(200, '<html><body><aside data-admin-nav>' . $inner . '</aside></body></html>', ['Content-Type' => 'text/html; charset=utf-8']);
                $tampered = Watermark::guard($page('<a class="vl-mark" data-vl-mark href="https://example.org" style="display:none">eigener Text</a>'))->body;
                self::ok(str_contains($tampered, Watermark::html()) && !str_contains($tampered, 'example.org') && substr_count($tampered, 'data-vl-mark') === 1, 'Geänderter Hinweis wird nicht zurückgesetzt');
                $missing = Watermark::guard($page(''))->body;
                self::ok(str_contains($missing, 'vl-mark--fixed') && str_contains($missing, 'https://vylaris.ch'), 'Fehlender Hinweis wird nicht ergänzt');
                $app = Watermark::guard(new \App\Core\Response(200, '<html><body><div id="app"></div></body></html>', ['Content-Type' => 'text/html']))->body;
                self::ok(str_contains($app, '<template id="vl-mark-tpl">' . Watermark::html() . '</template>'), 'Vorlage im Messenger wird nicht ergänzt');
                $login = Watermark::guard(new \App\Core\Response(200, '<html><body>Login</body></html>', ['Content-Type' => 'text/html']))->body;
                self::ok(!str_contains($login, 'data-vl-mark'), 'Seiten ohne Seitenleiste bleiben frei');
            },
            'Sprachnachrichten: Formate, Dauer-Limit und Anhangs-Ansicht' => static function () {
                self::ok(isset(Files::VOICE_MIMES['audio/webm'], Files::VOICE_MIMES['audio/mp4']) && !isset(Files::VOICE_MIMES['text/plain'], Files::VOICE_MIMES['audio/x-wav']), 'Formatliste');
                $v = Files::voiceMaxSeconds();
                self::ok($v >= 5 && $v <= 1800, 'Dauer-Limit ausserhalb von 5 bis 1800 s: ' . $v);
                $view = Messages::attachmentView(['id' => 1, 'original_name' => 'Sprachnachricht-x.webm', 'mime_type' => 'audio/webm', 'size_bytes' => 100, 'is_image' => 0, 'width' => null, 'height' => null, 'is_voice' => 1, 'duration_ms' => 4200, 'created_at' => now()]);
                self::ok($view['voice'] === true && $view['duration_ms'] === 4200, 'Anhangs-Ansicht');
                self::ok(Database::value("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'message_attachments' AND column_name IN ('is_voice','duration_ms')") == 2, 'Spalten fehlen');
            },
            'KI: Endpunkt-Adresse und Nutzungsrecht' => static function () use ($restore) {
                try {
                    Settings::set('ai_endpoint', 'https://api.example.org/v1/');
                    self::eq('https://api.example.org/v1/chat/completions', Ai::url(), 'Basis-Adresse');
                    Settings::set('ai_endpoint', 'https://api.example.org/v1/chat/completions');
                    self::eq('https://api.example.org/v1/chat/completions', Ai::url(), 'Vollständige Adresse');
                    Settings::set('ai_endpoint', 'ftp://x');
                    self::ok(Ai::url() === null, 'Ungültiges Schema');
                    Settings::set('ai_endpoint', 'https://api.example.org/v1');
                    Settings::set('ai_model', 'm');
                    Settings::set('ai_enabled', '1');
                    $m = ['role' => 'member', 'username' => 'bea'];
                    $adm = ['role' => 'admin', 'username' => 'chef'];
                    $guest = ['role' => 'guest', 'username' => 'gast'];
                    Settings::set('ai_access', 'all');
                    self::ok(Ai::canUse($m) && Ai::canUse($adm) && !Ai::canUse($guest), 'Zugriff «alle»');
                    Settings::set('ai_access', 'admins');
                    self::ok(!Ai::canUse($m) && Ai::canUse($adm), 'Zugriff «Admins»');
                    Settings::set('ai_access', 'list');
                    Settings::set('ai_allowed_users', '@Bea, carl');
                    self::ok(Ai::canUse($m) && Ai::canUse($adm) && !Ai::canUse(['role' => 'member', 'username' => 'dora']), 'Zugriff «Liste»');
                    Settings::set('ai_enabled', '0');
                    self::ok(!Ai::canUse($adm), 'Deaktiviert');
                    Settings::set('ai_max_context_tokens', '999999');
                    self::eq(Ai::HARD_CONTEXT_LIMIT, Ai::maxContextTokens(), 'Hartes Limit 90 000');
                } finally {
                    $restore();
                }
            },
            'KI: Kontextaufbau (Stufen, Token-Limit, Rollen, Beitrittszeitpunkt)' => static function () use ($restore) {
                try {
                    Settings::set('ai_endpoint', 'https://api.example.org/v1');
                    Settings::set('ai_model', 'm');
                    Settings::set('ai_enabled', '1');
                    Settings::set('ai_access', 'all');
                    Settings::set('ai_system_prompt', 'Prompt');
                    Settings::set('ai_max_context_tokens', '30000');
                    $botId = Ai::ensureBot();
                    $bot = User::find($botId);
                    $a = self::newUser('KA');
                    $cid = Chat::directWith((int) $a['id'], $botId);
                    Messages::create($a, $cid, 'Erste Frage', null, null, false);
                    Messages::create($bot, $cid, 'Erste Antwort', null, null, false);
                    $del = Messages::create($a, $cid, 'Gelöscht', null, null, false);
                    Database::exec('UPDATE messages SET deleted_at = ? WHERE id = ?', [now(), (int) $del['id']]);
                    $trig = Messages::create($a, $cid, 'Zweite Frage', null, null, false);
                    $conv = Chat::conversation($cid);
                    $conv['ai_context'] = 'recent';
                    $msgs = Ai::buildMessages($conv, $trig, $botId);
                    self::eq(['system', 'user', 'assistant', 'user'], array_column($msgs, 'role'), 'Rollen (recent)');
                    self::ok(!str_contains(json_encode($msgs), 'Gelöscht'), 'Gelöschte Nachricht im Kontext');
                    $conv['ai_context'] = 'request';
                    self::eq(['system', 'user'], array_column(Ai::buildMessages($conv, $trig, $botId), 'role'), 'Stufe request');
                    $conv['ai_context'] = 'full';
                    self::eq(4, count(Ai::buildMessages($conv, $trig, $botId)), 'Stufe full');
                    Settings::set('ai_max_context_tokens', '500');
                    Settings::set('ai_max_output_tokens', '64');
                    $big = Messages::create($a, $cid, str_repeat('x', 3000), null, null, false);
                    $cut = Ai::buildMessages($conv, $big, $botId);
                    self::eq(2, count($cut), 'Token-Limit kürzt Verlauf, auslösende Nachricht bleibt');
                    self::ok(mb_strlen($cut[1]['content']) <= 436 * 3, 'Auslösende Nachricht wird gekürzt');
                } finally {
                    $restore();
                }
            },
        ];
    }

    private static function collaboration(): array
    {
        return [
            'Gruppe und Testkonten für die Zusammenarbeit' => static function () {
                self::$ctx['ca'] = self::newUser('CA');
                self::$ctx['cb'] = self::newUser('CB');
                self::$ctx['cc'] = self::newUser('CC');
                self::$ctx['cg'] = Chat::createGroup((int) self::$ctx['ca']['id'], 'Selftest Zusammenarbeit', '', [(int) self::$ctx['cb']['id']]);
                $r = Messages::send(self::$ctx['ca'], self::$ctx['cg'], 'Wurzel', null, null, []);
                self::$ctx['root'] = self::msgId($r);
            },
            'Threads: Antwort erscheint nicht im Hauptverlauf, Zusammenfassung stimmt' => static function () {
                $r = Messages::send(self::$ctx['cb'], self::$ctx['cg'], 'Thread-Antwort', null, null, [], self::$ctx['root']);
                self::$ctx['reply'] = self::msgId($r);
                $page = Messages::fetch(self::$ctx['cg'], (int) self::$ctx['ca']['id'], null, null, null, 50);
                $ids = array_map('intval', array_column($page['messages'], 'id'));
                self::ok(!in_array(self::$ctx['reply'], $ids, true), 'Thread-Antwort im Hauptverlauf.');
                $root = array_values(array_filter($page['messages'], fn ($m) => (int) $m['id'] === self::$ctx['root']))[0] ?? null;
                self::ok($root !== null && (int) ($root['thread']['count'] ?? 0) === 1, 'Thread-Zusammenfassung fehlt.');
                $t = Messages::thread((int) self::$ctx['ca']['id'], self::$ctx['root']);
                self::eq(1, count($t['replies']), 'Antworten im Thread');
            },
            'Threads: Antwort auf eine Antwort landet im selben Thread, Fremde haben keinen Zugriff' => static function () {
                $r = Messages::send(self::$ctx['ca'], self::$ctx['cg'], 'Zweite', null, null, [], self::$ctx['reply']);
                self::eq(self::$ctx['root'], (int) $r['message']['thread_root_id'], 'Wurzel der Antwort');
                self::denies(404, static fn () => Messages::thread((int) self::$ctx['cc']['id'], self::$ctx['root']), 'Thread fremder Gruppe');
                self::denies(404, static fn () => Messages::send(self::$ctx['cc'], self::$ctx['cg'], 'x', null, null, [], self::$ctx['root']), 'Antwort ohne Mitgliedschaft');
            },
            'Umfragen: Regeln für Einfach-/Mehrfachauswahl, Änderung, Rücknahme, Ende' => static function () {
                $a = self::$ctx['ca'];
                $b = self::$ctx['cb'];
                $p = Polls::create($a, self::$ctx['cg'], 'Selftest-Umfrage', ['Eins', 'Zwei', 'Drei'], false, false, null);
                $poll = $p['message']['poll'];
                $o = array_column($poll['options'], 'id');
                self::denies(422, static fn () => Polls::vote($b, $poll['id'], [$o[0], $o[1]]), 'Zwei Stimmen bei Einfachauswahl');
                self::denies(422, static fn () => Polls::vote($b, $poll['id'], [999999999]), 'Fremde Option');
                $v = Polls::vote($b, $poll['id'], [$o[0]]);
                self::eq(1, $v['poll']['options'][0]['votes'], 'Stimmen Option 1');
                $v = Polls::vote($b, $poll['id'], [$o[1]]);
                self::eq(0, $v['poll']['options'][0]['votes'], 'Stimmen nach Änderung');
                $v = Polls::vote($b, $poll['id'], []);
                self::eq(0, $v['poll']['total_voters'], 'Stimmen nach Rücknahme');
                self::denies(404, static fn () => Polls::vote(self::$ctx['cc'], $poll['id'], [$o[0]]), 'Abstimmen ohne Mitgliedschaft');
                self::denies(403, static fn () => Polls::close($b, $poll['id']), 'Beenden durch Fremde');
                Polls::close($a, $poll['id']);
                self::denies(422, static fn () => Polls::vote($b, $poll['id'], [$o[0]]), 'Abstimmen nach Ende');
                self::denies(422, static fn () => Polls::create($a, self::$ctx['cg'], 'Zu wenig', ['Nur eine'], false, false, null), 'Nur eine Option');
                self::denies(422, static fn () => Polls::create($a, self::$ctx['cg'], 'Doppelt', ['A', 'a'], false, false, null), 'Doppelte Optionen');
            },
            'Umfragen: anonym zeigt keine Namen' => static function () {
                $p = Polls::create(self::$ctx['ca'], self::$ctx['cg'], 'Anonyme Umfrage', ['Ja', 'Nein'], true, true, null)['message']['poll'];
                $v = Polls::vote(self::$ctx['cb'], $p['id'], array_column($p['options'], 'id'));
                self::eq(1, $v['poll']['total_voters'], 'Teilnehmende');
                foreach ($v['poll']['options'] as $o) {
                    self::eq([], $o['voters'], 'Namen bei anonymer Umfrage');
                }
            },
            'Termine: Erstellen, Zusagen, Ändern, Absagen, Rechte' => static function () {
                $in = ['title' => 'Selftest, Termin; "Q"', 'location' => "Raum 1\nZürich", 'description' => str_repeat('Ä', 100), 'starts_at' => gmdate('Y-m-d H:i', time() + 86400 * 3), 'ends_at' => gmdate('Y-m-d H:i', time() + 86400 * 3 + 3600)];
                $m = Calendar::create(self::$ctx['ca'], self::$ctx['cg'], $in);
                $ev = $m['message']['event'];
                self::$ctx['ev'] = $ev['id'];
                self::eq(1, $ev['counts']['yes'], 'Ersteller sagt automatisch zu');
                $r = Calendar::rsvp(self::$ctx['cb'], $ev['id'], 'maybe');
                self::eq(1, $r['event']['counts']['maybe'], 'Vielleicht');
                self::denies(422, static fn () => Calendar::rsvp(self::$ctx['cb'], $ev['id'], 'bogus'), 'Ungültiger Status');
                self::denies(404, static fn () => Calendar::rsvp(self::$ctx['cc'], $ev['id'], 'yes'), 'Zusage ohne Mitgliedschaft');
                self::denies(403, static fn () => Calendar::update(self::$ctx['cb'], $ev['id'], ['title' => 'Hack']), 'Ändern durch Fremde');
                $u = Calendar::update(self::$ctx['ca'], $ev['id'], ['title' => 'Selftest neu']);
                self::eq('Selftest neu', $u['event']['title'], 'Titel');
                self::eq(1, (int) Database::value('SELECT sequence FROM calendar_events WHERE id = ?', [$ev['id']]), 'SEQUENCE steigt bei Änderung');
                self::denies(422, static fn () => Calendar::create(self::$ctx['ca'], self::$ctx['cg'], ['title' => 'Rückwärts', 'starts_at' => '2030-01-02 10:00', 'ends_at' => '2030-01-02 09:00']), 'Ende vor Beginn');
            },
            'ICS: Struktur, Maskierung, Zeilenlänge, Zugriff, Feed' => static function () {
                $f = Calendar::icsFor(self::$ctx['ev'], (int) self::$ctx['cb']['id']);
                $ics = $f['body'];
                self::ok(str_starts_with($ics, "BEGIN:VCALENDAR\r\n") && str_contains($ics, "END:VEVENT\r\n") && str_contains($ics, 'BEGIN:VALARM'), 'ICS-Struktur');
                self::ok(!preg_match('/(?<!\r)\n/', $ics), 'Nur CRLF als Zeilenende.');
                foreach (explode("\r\n", $ics) as $line) {
                    self::ok(strlen($line) <= 75, 'Zeile länger als 75 Oktette.');
                }
                self::ok(str_contains(str_replace("\r\n ", '', $ics), 'SUMMARY:Selftest neu'), 'SUMMARY fehlt.');
                self::eq('a\\,b\\;c\\nd\\\\', Ics::escape("a,b;c\nd\\"), 'Maskierung');
                self::denies(404, static fn () => Calendar::icsFor(self::$ctx['ev'], (int) self::$ctx['cc']['id']), 'ICS ohne Mitgliedschaft');
                $token = Calendar::enableFeed((int) self::$ctx['cb']['id']);
                $feed = Calendar::feedFor($token);
                self::ok($feed !== null && str_contains(str_replace("\r\n ", '', $feed), 'Selftest neu'), 'Feed enthält den Termin nicht.');
                self::ok(Calendar::feedFor(str_repeat('a', 48)) === null, 'Falscher Token liefert Daten.');
                self::ok(Calendar::feedFor('../../etc/passwd') === null, 'Ungültiger Token akzeptiert.');
                $new = Calendar::enableFeed((int) self::$ctx['cb']['id']);
                self::ok(Calendar::feedFor($token) === null && Calendar::feedFor($new) !== null, 'Erneuern entwertet den alten Link nicht.');
                Calendar::disableFeed((int) self::$ctx['cb']['id']);
                self::ok(Calendar::feedFor($new) === null, 'Abgeschaltet, aber Link gültig.');
            },
            'Entwürfe: speichern, leeren, Fremde ausgeschlossen' => static function () {
                $a = (int) self::$ctx['ca']['id'];
                Drafts::save($a, self::$ctx['cg'], 0, 'Entwurf', null, 'selftestdev1');
                self::eq(1, count(Drafts::listFor($a)), 'Entwürfe');
                self::eq(0, count(Drafts::listFor((int) self::$ctx['cb']['id'])), 'Fremde Entwürfe');
                self::denies(404, static fn () => Drafts::save((int) self::$ctx['cc']['id'], self::$ctx['cg'], 0, 'x', null, ''), 'Entwurf ohne Mitgliedschaft');
                Drafts::save($a, self::$ctx['cg'], 0, '  ', null, 'selftestdev1');
                self::eq(0, count(Drafts::listFor($a)), 'Leerer Text löscht');
                Drafts::save($a, self::$ctx['cg'], 0, 'wieder', null, '');
                Messages::send(self::$ctx['ca'], self::$ctx['cg'], 'gesendet', null, null, []);
                self::eq(0, count(Drafts::listFor($a)), 'Senden löscht den Entwurf');
            },
            'Gäste: eingeschränkte Rechte, Verzeichnis, Ablauf' => static function () {
                $g = self::newUser('G', 'guest');
                $gid = (int) $g['id'];
                Database::exec('UPDATE users SET guest_expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() + 86400), $gid]);
                Chat::addMembersSilently(self::$ctx['cg'], [$gid]);
                $g = self::fresh($g);
                self::denies(403, static fn () => Chat::directWith($gid, (int) self::$ctx['ca']['id']), 'Direktchat durch Gast');
                self::denies(403, static fn () => Chat::createGroup($gid, 'Gastgruppe', '', []), 'Gruppe durch Gast');
                self::denies(403, static fn () => Polls::create($g, self::$ctx['cg'], 'Gast-Umfrage', ['a', 'b'], false, false, null), 'Umfrage durch Gast');
                self::denies(403, static fn () => Calendar::create($g, self::$ctx['cg'], ['title' => 'Gast', 'starts_at' => '2030-01-01 10:00']), 'Termin durch Gast');
                self::denies(403, static fn () => Chat::directWith((int) self::$ctx['ca']['id'], $gid), 'Direktchat mit Gast');
                self::ok(!in_array($gid, array_map('intval', array_column(User::directory('', 100, (int) self::$ctx['cc']['id']), 'id')), true), 'Gast im Verzeichnis anderer.');
                $dir = User::directory('', 100, $gid);
                self::ok(!in_array((int) self::$ctx['cc']['id'], array_map('intval', array_column($dir, 'id')), true), 'Gast sieht Nicht-Mitglieder.');
                foreach ($dir as $row) {
                    self::ok(empty($row['email']), 'Gast sieht E-Mail-Adresse.');
                }
                $res = Messages::send($g, self::$ctx['cg'], 'Gast schreibt', null, null, []);
                self::ok(!empty($res['message']['id']), 'Gast darf in seiner Gruppe schreiben.');
                self::ok(!Auth::guestExpired($g), 'Gast fälschlich abgelaufen.');
                Database::exec('UPDATE users SET guest_expires_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 60), $gid]);
                self::ok(Auth::guestExpired(self::fresh($g)), 'Abgelaufener Gast nicht erkannt.');
                self::ok(Auth::expireGuests() >= 1, 'Ablauf deaktiviert kein Konto.');
                self::eq(0, (int) Database::value('SELECT is_active FROM users WHERE id = ?', [$gid]), 'Konto nach Ablauf aktiv');
            },
            'Gäste: Einladung braucht Gruppe, Dauer ist begrenzt' => static function () {
                $adm = self::newUser('AD', 'admin');
                $email = 'gast-' . bin2hex(random_bytes(3)) . '@selftest.invalid';
                $r = Auth::invite($email, 'guest', (int) $adm['id'], null, [], 30);
                self::ok($r['ok'] === false, 'Gast ohne Gruppe wurde eingeladen.');
                $r = Auth::invite($email, 'guest', (int) $adm['id'], null, [self::$ctx['cg']], 9999);
                self::ok($r['ok'] === true, 'Gast-Einladung fehlgeschlagen.');
                self::eq(365, (int) Database::value('SELECT guest_days FROM invitations WHERE email = ? ORDER BY id DESC LIMIT 1', [$email]), 'Dauer wird auf 365 Tage begrenzt');
            },
        ];
    }

    // ----------------------------------------------- 2FA, SSO, Push, Jobs

    private static function advancedLogic(): array
    {
        return [
            'TOTP: Testvektor RFC 6238 und Base32' => static function () {
                $secret = Totp::base32Encode('12345678901234567890');
                self::eq('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $secret, 'Base32');
                self::eq('12345678901234567890', Totp::base32Decode($secret), 'Base32 zurück');
                self::eq('287082', Totp::code($secret, intdiv(59, 30)), 'Code für Zeit 59 (RFC 6238)');
                self::eq('081804', Totp::code($secret, intdiv(1111111109, 30)), 'Code für Zeit 1111111109');
            },
            'TOTP: Einrichtung, Replay-Schutz, Zeitfenster, Wiederherstellungscodes' => static function () {
                $u = self::newUser('T');
                $secret = Totp::beginSetup((int) $u['id']);
                $u = self::fresh($u);
                self::ok(!Totp::isEnabled($u), '2FA vor Bestätigung aktiv.');
                self::ok(Totp::enable($u, '000000') === null, 'Falscher Code aktivierte 2FA.');
                $step = intdiv(time(), 30);
                $codes = Totp::enable($u, Totp::code($secret, $step));
                self::ok(is_array($codes) && count($codes) === 10, 'Keine 10 Wiederherstellungscodes.');
                $u = self::fresh($u);
                self::ok(Totp::isEnabled($u), '2FA nach Bestätigung nicht aktiv.');
                self::ok(!str_contains((string) $u['totp_secret_enc'], $secret), 'Geheimnis liegt im Klartext in der Datenbank.');
                self::ok(!Totp::verifyLogin($u, Totp::code($secret, $step)), 'Wiederverwendeter Zeitschritt wurde akzeptiert.');
                self::ok(!Totp::verifyLogin(self::fresh($u), '123456') || Totp::code($secret, $step + 1) === '123456', 'Falscher Code akzeptiert.');
                self::ok(Totp::verifyLogin(self::fresh($u), Totp::code($secret, $step + 1)), 'Gültiger Code im Zeitfenster abgelehnt.');
                self::ok(!Totp::verifyLogin(self::fresh($u), Totp::code($secret, $step + 1)), 'Code ein zweites Mal akzeptiert.');
                self::ok(Totp::verifyLogin(self::fresh($u), $codes[0]), 'Wiederherstellungscode abgelehnt.');
                self::ok(!Totp::verifyLogin(self::fresh($u), $codes[0]), 'Wiederherstellungscode mehrfach verwendbar.');
                self::eq(9, Totp::recoveryLeft((int) $u['id']), 'Verbleibende Codes');
                Totp::disable((int) $u['id'], 'selftest');
                self::ok(!Totp::isEnabled(self::fresh($u)), '2FA nach Deaktivierung aktiv.');
                self::eq(0, Totp::recoveryLeft((int) $u['id']), 'Codes nach Deaktivierung');
            },
            'Anmeldung: 2FA-Pflicht und Zwischenzustand' => static function () {
                $admin = self::newUser('AD', 'admin');
                $member = self::newUser('MB');
                $was = Settings::get('require_2fa_admins');
                Settings::set('require_2fa_admins', '1');
                try {
                    self::ok(Auth::mustSetupTwoFactor($admin), 'Administrator ohne 2FA nicht umgeleitet.');
                    self::ok(!Auth::mustSetupTwoFactor($member), 'Mitglied muss 2FA einrichten.');
                    self::ok(!Auth::mustSetupTwoFactor(array_merge($admin, ['sso_subject' => 'abc'])), 'SSO-Administrator wird zu lokaler 2FA gezwungen.');
                } finally {
                    Settings::set('require_2fa_admins', $was);
                }
                $_SESSION['pending_2fa'] = ['uid' => (int) $member['id'], 'exp' => time() - 1];
                self::ok(Auth::pendingTwoFactorUser() === null, 'Abgelaufener Zwischenzustand gültig.');
                self::ok(!isset($_SESSION['pending_2fa']), 'Zwischenzustand nicht entfernt.');
                [$max, $win] = Auth::lockoutPolicy();
                self::ok($max >= 3 && $win >= 60, 'Sperrrichtlinie unplausibel.');
            },
            'OIDC: Prüfung der ID-Token-Ansprüche' => static function () {
                $c = ['iss' => 'https://idp.example/', 'aud' => 'client1', 'exp' => time() + 300, 'nonce' => 'n1', 'sub' => 'u1'];
                self::eq(null, Oidc::validateClaims($c, 'https://idp.example', 'client1', 'n1'), 'Gültiger Token');
                self::ok(Oidc::validateClaims(array_merge($c, ['iss' => 'https://evil.example']), 'https://idp.example', 'client1', 'n1') !== null, 'Falscher Aussteller akzeptiert.');
                self::ok(Oidc::validateClaims(array_merge($c, ['aud' => 'andere']), 'https://idp.example', 'client1', 'n1') !== null, 'Falsche Zielgruppe akzeptiert.');
                self::ok(Oidc::validateClaims($c, 'https://idp.example', 'client1', 'n2') !== null, 'Falsche nonce akzeptiert.');
                self::ok(Oidc::validateClaims(array_merge($c, ['exp' => time() - 600]), 'https://idp.example', 'client1', 'n1') !== null, 'Abgelaufener Token akzeptiert.');
                self::ok(Oidc::validateClaims(array_merge($c, ['aud' => ['client1', 'x']]), 'https://idp.example', 'client1', 'n1') !== null, 'Mehrere Zielgruppen ohne azp akzeptiert.');
            },
            'Push: Endpunkt-Allowlist und Signaturformat' => static function () {
                self::ok(Push::allowedEndpoint('https://fcm.googleapis.com/fcm/send/abc'), 'FCM abgelehnt.');
                self::ok(Push::allowedEndpoint('https://updates.push.services.mozilla.com/wpush/v2/abc'), 'Mozilla abgelehnt.');
                foreach (['http://fcm.googleapis.com/x', 'https://evil.example/x', 'https://fcm.googleapis.com.evil.example/x', 'https://user@fcm.googleapis.com/x', 'https://10.0.0.5/x', 'ftp://fcm.googleapis.com/x'] as $bad) {
                    self::ok(!Push::allowedEndpoint($bad), 'Unerlaubter Endpunkt akzeptiert: ' . $bad);
                }
                $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
                self::ok($key !== false, 'EC-Schlüssel nicht erzeugbar (openssl).');
                openssl_sign('x', $der, $key, OPENSSL_ALGO_SHA256);
                self::eq(64, strlen(Push::derToRaw($der)), 'ES256-Signatur (R||S)');
                $u = self::newUser('P');
                self::ok(!Push::subscribe((int) $u['id'], 'https://evil.example/push', 'ua'), 'Fremder Push-Endpunkt gespeichert.');
                self::ok(Push::subscribe((int) $u['id'], 'https://fcm.googleapis.com/fcm/send/selftest', 'ua'), 'Erlaubter Endpunkt nicht gespeichert.');
                self::eq(1, Push::countFor((int) $u['id']), 'Anzahl Abos');
                Push::unsubscribe((int) $u['id'], 'https://fcm.googleapis.com/fcm/send/selftest');
                self::eq(0, Push::countFor((int) $u['id']), 'Abos nach Abmeldung');
            },
            'Push: Verschlüsselung der Nutzlast (RFC 8291) und Schlüsselprüfung' => static function () {
                $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
                $d = openssl_pkey_get_details($ua);
                $pub = "\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT);
                $auth = random_bytes(16);
                $p256dh = Push::b64($pub);
                $a = Push::b64($auth);
                self::ok(Push::validKeys($p256dh, $a), 'Gültige Schlüssel abgelehnt.');
                foreach ([[null, $a], [$p256dh, null], ['', ''], ['AAAA', $a], [$p256dh, 'AAAA']] as [$k, $x]) {
                    self::ok(!Push::validKeys($k, $x), 'Ungültige Schlüssel akzeptiert.');
                }
                $msg = (string) json_encode(['title' => 'Firma', 'body' => 'Neue Nachricht von Bärbel – äöü €'], JSON_UNESCAPED_UNICODE);
                $enc = Push::encrypt($msg, $p256dh, $a);
                self::ok(!str_contains($enc, 'Bärbel'), 'Nutzlast ist nicht verschlüsselt.');
                self::eq($msg, Push::decrypt($enc, $ua, $a), 'Entschlüsselung');
                self::ok(Push::encrypt($msg, $p256dh, $a) !== $enc, 'Zwei Verschlüsselungen sind identisch (kein Zufall).');
                $bad = $enc;
                $bad[strlen($bad) - 3] = $bad[strlen($bad) - 3] ^ "\x01";
                try {
                    Push::decrypt($bad, $ua, $a);
                    self::ok(false, 'Manipulierte Nutzlast akzeptiert.');
                } catch (\RuntimeException) {
                }
                try {
                    Push::decrypt($enc, $ua, Push::b64(random_bytes(16)));
                    self::ok(false, 'Falsches Auth-Geheimnis akzeptiert.');
                } catch (\RuntimeException) {
                }
                self::ok(strlen($enc) <= 4096, 'Datensatz grösser als 4096 Byte.');
            },
            'Push: Nutzlast enthält nur Hinweistext, Adresserneuerung' => static function () {
                $u = self::newUser('Q');
                $pl = Push::payload((int) $u['id'], 'Neue Nachricht von X', 7, 9);
                self::eq('?c=7&m=9', $pl['url'], 'Link');
                self::eq('conv-7', $pl['tag'], 'Tag');
                self::ok($pl['badge'] >= 1, 'Zähler');
                $ua = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
                $d = openssl_pkey_get_details($ua);
                $k = Push::b64("\x04" . str_pad($d['ec']['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['ec']['y'], 32, "\0", STR_PAD_LEFT));
                $a = Push::b64(random_bytes(16));
                $old = 'https://fcm.googleapis.com/fcm/send/selftest-old';
                $new = 'https://fcm.googleapis.com/fcm/send/selftest-new';
                self::ok(Push::subscribe((int) $u['id'], $old, 'ua', $k, $a), 'Abo mit Schlüsseln nicht gespeichert.');
                self::eq(1, (int) Database::value('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ? AND p256dh IS NOT NULL', [$u['id']]), 'Schlüssel gespeichert');
                self::ok(!Push::resubscribe('https://fcm.googleapis.com/fcm/send/unknown', $new, $k, $a, 'ua'), 'Unbekannte alte Adresse akzeptiert.');
                self::ok(!Push::resubscribe($old, 'https://evil.example/x', $k, $a, 'ua'), 'Fremde neue Adresse akzeptiert.');
                self::ok(Push::resubscribe($old, $new, $k, $a, 'ua'), 'Erneuerung fehlgeschlagen.');
                self::eq(1, Push::countFor((int) $u['id']), 'Anzahl nach Erneuerung');
                self::eq(1, (int) Database::value('SELECT COUNT(*) FROM push_subscriptions WHERE endpoint = ?', [$new]), 'Neue Adresse gespeichert');
                Push::unsubscribe((int) $u['id'], $new);
            },
            'Hintergrundauftrag: Datenexport entsteht, ist nur dem Besitzer zugeordnet und wird entfernt' => static function () {
                $u = self::newUser('X');
                Database::exec("INSERT INTO jobs (type, user_id, payload, status, created_at, expires_at) VALUES ('export', ?, '{}', 'queued', ?, ?)", [$u['id'], now(), gmdate('Y-m-d H:i:s', time() + 3600)]);
                $id = (int) Database::pdo()->lastInsertId();
                Jobs::execute(Database::one('SELECT * FROM jobs WHERE id = ?', [$id]));
                $job = Database::one('SELECT * FROM jobs WHERE id = ?', [$id]);
                self::eq('done', $job['status'], 'Jobstatus (' . ($job['error'] ?? '') . ')');
                $path = Jobs::filePath($job);
                self::ok($path !== null && is_file($path) && filesize($path) > 100, 'Exportdatei fehlt.');
                self::ok(str_starts_with(basename((string) $path), 'u' . $u['id'] . '-'), 'Dateiname nicht dem Besitzer zugeordnet.');
                self::ok(!str_contains(basename((string) $path), '..'), 'Unsicherer Dateiname.');
                Jobs::eraseForUser((int) $u['id']);
                self::ok(!is_file((string) $path), 'Exportdatei nach Kontolöschung vorhanden.');
                Database::exec("UPDATE jobs SET status = 'done', file_name = 'x.zip', expires_at = ? WHERE id = ?", [gmdate('Y-m-d H:i:s', time() - 5), $id]);
            },
        ];
    }

    // ------------------------------------------ Backup, Betrieb, Prüfpaket

    private static function licensing(): array
    {
        return [
            'Lizenzstatus' => static function () {
                $st = License::status();
                if ($st['state'] !== 'ok') {
                    return ['warn', 'Keine gültige Lizenz (' . $st['message'] . ') – die Installation läuft im Nur-Lesen-Modus.'];
                }
                return 'Lizenz gültig, ' . $st['maxDevices'] . ' Plätze, Funktionen: ' . implode(', ', $st['features']);
            },
            'Lizenz: Signaturprüfung (gültig, manipuliert, fremder Schlüssel, zu alt)' => static function () {
                $kp = sodium_crypto_sign_keypair();
                $other = sodium_crypto_sign_keypair();
                $pem = base64_encode(hex2bin('302a300506032b6570032100') . sodium_crypto_sign_publickey($kp));
                {
                    $now = time();
                    $payload = json_encode(['valid' => true, 'code' => 'OK', 'ts' => $now]);
                    $sig = base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($kp)));
                    self::eq('OK', License::verified($payload, $sig, $now, $pem)['code'], 'Gültige Antwort');
                    $thrown = static function (callable $f): bool {
                        try {
                            $f();
                        } catch (\RuntimeException) {
                            return true;
                        }
                        return false;
                    };
                    self::ok($thrown(fn () => License::verified(str_replace('true', 'false', $payload), $sig, $now, $pem)), 'Manipulierte Nutzdaten akzeptiert.');
                    $forged = base64_encode(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($other)));
                    self::ok($thrown(fn () => License::verified($payload, $forged, $now, $pem)), 'Fremde Signatur akzeptiert.');
                    self::ok($thrown(fn () => License::verified($payload, $sig, $now + 3600, $pem)), 'Veraltete Antwort akzeptiert.');
                    self::ok($thrown(fn () => License::verified($payload, 'kein-base64!', $now, $pem)), 'Defekte Signatur akzeptiert.');
                }
            },
            'Lizenz: fester Schlüssel und Server' => static function () {
                self::ok(License::publicKeyRaw(License::PUBLIC_KEY) !== null, 'Eingebauter Schlüssel ungültig.');
                self::eq('EADF15A35395B758', strtoupper(substr(hash('sha256', (string) License::publicKeyRaw(License::PUBLIC_KEY)), 0, 16)), 'Prüfsumme des eingebauten Schlüssels');
                self::ok(str_starts_with(License::SERVER, 'https://'), 'Lizenzserver ohne HTTPS');
            },
            'Lizenz: öffentlicher Schlüssel (PEM und Base64)' => static function () {
                $raw = random_bytes(32);
                $der = hex2bin('302a300506032b6570032100') . $raw;
                $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----";
                self::ok(License::publicKeyRaw($pem) === $raw, 'PEM nicht gelesen.');
                self::ok(License::publicKeyRaw(base64_encode($der)) === $raw, 'Base64-DER nicht gelesen.');
                self::ok(License::publicKeyRaw('Müll') === null, 'Ungültiger Schlüssel akzeptiert.');
            },
            'Lizenz: Zuordnung Anfrage → Funktion (Scope)' => static function () {
                $cases = [
                    ['POST', '/api/conversations/12/messages', 'messaging'], ['POST', '/api/conversations/12/uploads', 'files'],
                    ['POST', '/api/conversations/group', 'groups'], ['POST', '/api/conversations/3/members', 'groups'],
                    ['PATCH', '/api/messages/9', 'messaging'], ['POST', '/security/2fa', '2fa'],
                    ['POST', '/admin/backup/create', 'backup'], ['GET', '/admin/privacy/review', 'export'],
                ];
                foreach ($cases as [$m, $p, $scope]) {
                    self::eq($scope, License::requirement($m, $p)['scope'] ?? null, "$m $p");
                }
                foreach ([['GET', '/api/conversations/12/messages'], ['POST', '/api/me/export'], ['POST', '/api/me/delete'], ['POST', '/api/messages/9/report'], ['DELETE', '/api/messages/9'], ['POST', '/login']] as [$m, $p]) {
                    self::ok(License::requirement($m, $p) === null, "$m $p darf nicht lizenzgebunden sein (Datenschutzrechte, Lesen, Anmeldung).");
                }
                foreach (License::SCOPES as $k => $_) {
                    self::ok(preg_match('/^[a-z0-9]+$/', $k) === 1, 'Scope-Kennung ungültig: ' . $k);
                }
            },
            'Lizenz: Platz-Kennung pseudonym und stabil' => static function () {
                $a = License::hwidFor(1);
                self::ok($a === License::hwidFor(1) && $a !== License::hwidFor(2), 'Kennung nicht stabil/eindeutig.');
                self::ok(strlen($a) <= 128 && str_starts_with($a, 'u-'), 'Format unerwartet.');
            },
        ];
    }

    private static function wizards(): array
    {
        return [
            'Assistenten: Definitionen und Ziele' => static function () {
                $routes = (string) file_get_contents(ROOT_PATH . '/app/routes.php');
                $icons = (string) @file_get_contents(ROOT_PATH . '/public/assets/vendor/boxicons/css/boxicons.min.css');
                foreach (Wizards::all() as $key => $w) {
                    self::ok(str_contains($routes, "'/" . $w['action'] . "'"), "Assistent $key: Ziel /{$w['action']} ist keine Route.");
                    self::ok($icons === '' || str_contains($icons, 'bx-' . $w['icon'] . ':before'), "Assistent $key: Symbol {$w['icon']} fehlt.");
                    $names = [];
                    foreach ($w['steps'] as $st) {
                        foreach ($st['fields'] as $f) {
                            self::ok(!empty($f['name']) && !empty($f['label']), "Assistent $key: Feld ohne Name oder Beschriftung.");
                            self::ok(!isset($names[$f['name']]), "Assistent $key: Feld {$f['name']} doppelt.");
                            $names[$f['name']] = true;
                        }
                    }
                    self::ok(count($w['steps']) >= 1, "Assistent $key ohne Schritte.");
                }
                return count(Wizards::all()) . ' Assistenten';
            },
        ];
    }

    private static function changelog(): array
    {
        return [
            'Änderungsprotokoll: Datei lesen' => static function () {
                $e = Changelog::entries();
                self::ok($e !== [], 'docs/CHANGELOG.md fehlt oder ist leer.');
                self::eq(APP_VERSION, $e[0]['version'], 'Neuester Eintrag entspricht der installierten Version');
                self::ok($e[0]['items'] !== [], 'Eintrag ohne Stichpunkte');
            },
            'Änderungsprotokoll: ungesehene Einträge' => static function () {
                $en = [
                    ['version' => '1.6.0', 'title' => '', 'items' => ['a']],
                    ['version' => '1.5.0', 'title' => '', 'items' => ['b']],
                    ['version' => '1.0.0', 'title' => '', 'items' => ['c']],
                    ['version' => '2.0.0', 'title' => '', 'items' => ['zukünftig']],
                ];
                $v = static fn (?string $seen) => array_column(Changelog::select($en, '1.6.0', $seen), 'version');
                self::eq(['1.6.0'], $v(null), 'Ohne Vermerk nur der aktuelle Eintrag');
                self::eq(['1.6.0', '1.5.0'], $v('1.0.0'), 'Seit 1.0.0');
                self::eq([], $v('1.6.0'), 'Bereits bestätigt');
                self::eq('<code>x</code> &lt;b&gt;', Changelog::render('`x` <b>'), 'Darstellung maskiert HTML');
            },
        ];
    }

    private static function updating(): array
    {
        return [
            'Update: Repository-Angabe lesen' => static function () {
                self::eq('acme/app', Updater::parseRepo('acme/app'), 'owner/repo');
                self::eq('acme/app', Updater::parseRepo('https://github.com/acme/app.git'), 'URL mit .git');
                self::eq('acme/app', Updater::parseRepo(' https://github.com/acme/app/ '), 'URL mit Schrägstrich');
                foreach (['', 'acme', 'https://evil.example/acme/app', 'a/b/c', '../x/y', 'acme/app; rm -rf'] as $bad) {
                    self::ok(Updater::parseRepo($bad) === null, 'Ungültige Angabe akzeptiert: ' . $bad);
                }
            },
            'Update: Voraussetzungen und Schutzpfade' => static function () {
                $pre = Updater::prerequisites();
                if (!$pre['zip'] || !$pre['curl']) {
                    return ['warn', 'zip/curl fehlen – Updates sind nicht möglich.'];
                }
                return $pre['writable'] ? 'zip, curl und Schreibrechte vorhanden' : ['warn', 'Code-Verzeichnisse nicht beschreibbar – Updates aus der Weboberfläche sind nicht möglich.'];
            },
            'Update: Versionsvergleich der Releases' => static function () {
                self::ok(Updater::newer('v1.10.0', 'v1.9.0'), 'v1.10.0 muss neuer sein als v1.9.0');
                self::ok(!Updater::newer('v1.0.5', 'v1.1.0'), 'Ältere Version darf nicht angeboten werden');
                self::ok(!Updater::newer('v1.1.0', '1.1.0'), 'Gleiche Version (mit/ohne v) ist nicht neu');
                self::ok(Updater::newer('v1.0.0', ''), 'Ohne Installationsstand ist alles neu');
                self::ok(Updater::newer('nightly', 'v1.0.0'), 'Nicht vergleichbare Namen gelten als neu');
            },
            'Update: Feste Quelle und Status' => static function () {
                $st = Updater::state();
                self::ok(array_key_exists('available', $st) && array_key_exists('installed', $st), 'Statusfelder fehlen.');
                self::eq(Updater::REPO, $st['repo'], 'Feste Update-Quelle');
                return 'Quelle: ' . $st['repo'] . ' (Releases)';
            },
        ];
    }

    private static function operations(): array
    {
        return [
            'Backup: erstellen, Prüfsumme prüfen, Inhalt' => static function () {
                if (!class_exists(\ZipArchive::class)) {
                    return ['skip', 'PHP-Erweiterung zip fehlt.'];
                }
                $r = Backup::create(false, '', false);
                $path = Backup::dir() . '/' . $r['file'];
                try {
                    self::ok(Backup::validName($r['file']), 'Backup-Dateiname ungültig.');
                    $v = Backup::verify($path);
                    self::ok($v['ok'], 'Prüfung fehlgeschlagen: ' . ($v['error'] ?? ''));
                    self::ok(in_array('users', $v['meta']['tables'], true) && in_array('messages', $v['meta']['tables'], true), 'Tabellen fehlen im Backup.');
                    self::ok(!in_array('user_sessions', array_diff($v['meta']['tables'], ['user_sessions (nur Struktur)']), true), 'Sitzungen im Backup gesichert.');
                    $zip = new \ZipArchive();
                    $zip->open($path);
                    $sql = (string) $zip->getFromName('database.sql');
                    self::ok(str_contains($sql, 'CREATE TABLE') && str_contains($sql, 'INSERT INTO `users`'), 'Dump unvollständig.');
                    self::ok($zip->getFromName('config/config.php') === false, 'config.php ohne Auftrag im Backup.');
                    $zip->close();
                    // manipuliertes Backup wird erkannt
                    $bad = $path . '.bad';
                    copy($path, $bad);
                    $z2 = new \ZipArchive();
                    $z2->open($bad);
                    $z2->addFromString('database.sql', 'DROP TABLE users');
                    $z2->close();
                    self::ok(!Backup::verify($bad)['ok'], 'Manipuliertes Backup nicht erkannt.');
                    @unlink($bad);
                    return round($r['size'] / 1024) . ' KB';
                } finally {
                    @unlink($path);
                }
            },
            'Backup: verschlüsselt (AES-256), falsche Passphrase scheitert' => static function () {
                if (!class_exists(\ZipArchive::class) || !defined('ZipArchive::EM_AES_256')) {
                    return ['skip', 'ZIP-Verschlüsselung nicht verfügbar.'];
                }
                $r = Backup::create(false, 'selftest-passphrase-1', false);
                $path = Backup::dir() . '/' . $r['file'];
                try {
                    self::ok(Backup::verify($path, 'selftest-passphrase-1')['ok'], 'Richtige Passphrase abgelehnt.');
                    self::ok(!Backup::verify($path, 'falsch-falsch-falsch')['ok'], 'Falsche Passphrase akzeptiert.');
                    self::ok(!Backup::verify($path)['ok'], 'Verschlüsseltes Backup ohne Passphrase lesbar.');
                } finally {
                    @unlink($path);
                }
            },
            'Backup: Download-Namen sind eingeschränkt, Web-Download standardmässig aus' => static function () {
                foreach (['../config/config.php', 'backup-1.zip', 'backup-20260101-000000-abcdef.zip/../x', 'x.zip'] as $bad) {
                    self::ok(!Backup::validName($bad), 'Unsicherer Name akzeptiert: ' . $bad);
                }
                self::ok(Backup::validName('backup-20260101-000000-abcdef.zip'), 'Gültiger Name abgelehnt.');
                self::ok(!Settings::bool('backup_web_download'), 'Web-Download der Backups ist aktiv (enthält alle Nachrichten).');
            },
            'Backup: Storage-Verzeichnis ist nicht per Web erreichbar' => static function () {
                self::ok(is_file(ROOT_PATH . '/storage/.htaccess') || !str_starts_with(realpath(storage_path()) ?: '', realpath(ROOT_PATH . '/public') ?: '/nonexistent'), 'storage/ liegt im Web-Verzeichnis ohne Schutz.');
            },
            'Monitoring: Zustandsprüfungen und Fehlerstatistik' => static function () {
                $checks = Monitor::checks();
                self::ok(count($checks) >= 6, 'Zu wenige Prüfungen.');
                self::ok($checks[0]['name'] === 'Datenbank' && $checks[0]['ok'], 'Datenbankprüfung fehlgeschlagen.');
                $st = Monitor::errorStats();
                self::ok(isset($st['last_hour'], $st['last_day']), 'Fehlerstatistik unvollständig.');
                $failed = array_filter($checks, static fn ($c) => !$c['ok'] && $c['level'] === 'error');
                self::ok(!$failed, 'Kritische Prüfung fehlgeschlagen: ' . implode(', ', array_column($failed, 'name')));
            },
            'PWA: Manifest, Service Worker und Symbole vorhanden' => static function () {
                foreach (['sw.js', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/icons/icon-maskable-512.png', 'assets/icons/apple-touch-icon.png', 'assets/icons/badge-96.png'] as $f) {
                    self::ok(is_file(ROOT_PATH . '/public/' . $f), 'Datei fehlt: ' . $f);
                }
                $sw = (string) file_get_contents(ROOT_PATH . '/public/sw.js');
                self::ok(str_contains($sw, "'push'") && str_contains($sw, 'notificationclick'), 'Service Worker unvollständig.');
                self::ok(str_contains($sw, 'event.data') && str_contains($sw, 'pushsubscriptionchange'), 'Service Worker liest die verschlüsselte Nutzlast nicht oder erneuert das Abo nicht.');
                self::ok(!str_contains($sw, 'visibilityState'), 'Service Worker unterdrückt Hinweise bei sichtbarer App (iOS entzieht dann das Abo).');
                $m = (new \App\Controllers\PageController())->manifest(new Request('GET', '/manifest.webmanifest', [], [], [], [], ''));
                $j = json_decode((string) $m->body, true);
                self::ok(is_array($j) && $j['display'] === 'standalone' && count($j['icons']) >= 3, 'Manifest ungültig.');
            },
            'Datenschutzverletzungen: Register und Frist-Zähler' => static function () {
                $before = \App\Controllers\AdminPrivacyController::breachesDue();
                Database::exec("INSERT INTO data_breaches (discovered_at, description, risk, created_at, updated_at) VALUES (?, 'Selbsttest', 'high', ?, ?)", [now(), now(), now()]);
                self::eq($before + 1, \App\Controllers\AdminPrivacyController::breachesDue(), 'Offene Verletzung mit Risiko wird gezählt');
                Database::exec("DELETE FROM data_breaches WHERE description = 'Selbsttest'");
            },
            'Prüfpaket: Verzeichnis, Checkliste und Fingerabdruck' => static function () {
                $s = Settings::all();
                $reg = PrivacyReview::register($s);
                self::ok(count($reg['activities']) >= 6, 'Verzeichnis zu kurz.');
                foreach ($reg['activities'] as $a) {
                    foreach (['name', 'purpose', 'subjects', 'data', 'recipients', 'abroad', 'retention', 'toms'] as $k) {
                        self::ok(trim((string) ($a[$k] ?? '')) !== '', 'Feld leer: ' . $a['name'] . '/' . $k);
                    }
                }
                $c = PrivacyReview::checklist($s);
                self::ok($c['total'] >= 20 && $c['done'] <= $c['total'], 'Checkliste unplausibel.');
                self::eq(PrivacyReview::fingerprint($s), PrivacyReview::fingerprint($s), 'Fingerabdruck stabil');
                self::ok(PrivacyReview::fingerprint($s) !== PrivacyReview::fingerprint(array_merge($s, ['privacy_hosting' => 'anderswo'])), 'Fingerabdruck reagiert nicht auf Änderungen.');
                self::ok(str_contains(PrivacyReview::registerMarkdown($s), '# Verzeichnis der Bearbeitungstätigkeiten'), 'Markdown-Export fehlerhaft.');
            },
        ];
    }
}
