<?php
declare(strict_types=1);

/**
 * Installationsassistent.
 *
 * Schutz vor fremder Installation: Vor dem ersten Schritt muss der Code aus der Datei storage/setup_code.txt
 * eingegeben werden (nur wer Dateizugriff auf den Server hat, kann ihn lesen).
 * Nach erfolgreicher Installation wird storage/installed.lock geschrieben und dieser Assistent sperrt sich.
 */

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Core\Csrf;
use App\Core\Database;
use App\Core\Migrator;
use App\Core\Session;
use App\Middleware\SecurityHeaders;
use App\Models\User;
use App\Services\Auth;
use App\Services\Crypto;
use App\Services\Mail;
use App\Services\Settings;

SecurityHeaders::send();
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$lockFile = storage_path('installed.lock');

// ------------------------------------------------------------------ Sperre
if (is_file($lockFile)) {
    http_response_code(403);
    setup_page('Installation abgeschlossen', '
      <div class="text-center">
        <span class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><i class="bx bx-lock-alt text-3xl" aria-hidden="true"></i></span>
        <h1 class="mb-2 text-xl font-semibold">Die Anwendung ist bereits installiert</h1>
        <p class="text-sm text-slate-500">Der Installationsassistent ist gesperrt und kann bestehende Daten nicht überschreiben. Aus Sicherheitsgründen solltest du die Datei <code>setup.php</code> vom Server löschen.</p>
        <a href="' . e(url('')) . '" class="btn btn-primary mt-6 w-full">Zur Anwendung</a>
      </div>', 0);
    exit;
}

// ------------------------------------------------------- Voraussetzungen
$dirs = [
    'config' => ROOT_PATH . '/config',
    'storage' => storage_path(),
    'storage/uploads' => storage_path('uploads'),
    'storage/logs' => storage_path('logs'),
    'storage/sessions' => storage_path('sessions'),
    'storage/cache' => storage_path('cache'),
    'storage/avatars' => storage_path('avatars'),
    'storage/branding' => storage_path('branding'),
];
foreach (['uploads', 'logs', 'sessions', 'cache', 'avatars', 'branding'] as $sub) {
    if (!is_dir(storage_path($sub))) {
        @mkdir(storage_path($sub), 0750, true);
    }
}

$codeFile = storage_path('setup_code.txt');
if (!is_file($codeFile) && is_dir(storage_path()) && is_writable(storage_path())) {
    @file_put_contents($codeFile, strtoupper(bin2hex(random_bytes(6))) . "\n", LOCK_EX);
    @chmod($codeFile, 0640);
}

Session::start();

$state = &$_SESSION['setup'];
$state ??= [];
$errors = [];
$notice = null;

function checks(array $dirs): array
{
    $list = [];
    $add = static function (string $label, bool $ok, string $detail = '', bool $required = true) use (&$list) {
        $list[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail, 'required' => $required];
    };
    $add('PHP 8.3 oder neuer', PHP_VERSION_ID >= 80300, 'Installiert: ' . PHP_VERSION);
    foreach (['pdo_mysql' => 'PDO MySQL', 'mbstring' => 'mbstring', 'openssl' => 'OpenSSL', 'sodium' => 'Sodium', 'fileinfo' => 'Fileinfo', 'json' => 'JSON', 'ctype' => 'ctype', 'gd' => 'GD (Bildverarbeitung)'] as $ext => $name) {
        $add('PHP-Erweiterung: ' . $name, extension_loaded($ext), extension_loaded($ext) ? '' : 'Bitte beim Hoster aktivieren');
    }
    $add('Datei-Uploads aktiviert', (bool) ini_get('file_uploads'), 'upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size'));
    foreach ($dirs as $label => $path) {
        $ok = is_dir($path) && is_writable($path);
        $add('Schreibrechte: ' . $label . '/', $ok, $ok ? '' : 'Verzeichnis muss für PHP beschreibbar sein (z. B. chmod 750 / 755)');
    }
    $add('HTTPS-Verbindung', is_https(), is_https() ? '' : 'Diese Seite wurde ohne HTTPS aufgerufen. Im Produktivbetrieb ist HTTPS zwingend.', false);
    $add('Sichere Session-Cookies möglich', is_https() || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true), (is_https() || in_array($_SERVER['SERVER_NAME'] ?? '', ['localhost', '127.0.0.1'], true)) ? '' : 'Ohne HTTPS können Session-Cookies nicht als „Secure“ markiert werden.', false);
    return $list;
}

function checks_ok(array $list): bool
{
    foreach ($list as $c) {
        if ($c['required'] && !$c['ok']) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------- Aktionen
$action = $_SERVER['REQUEST_METHOD'] === 'POST' ? (string) ($_POST['action'] ?? '') : '';
if ($action !== '') {
    $given = (string) ($_POST['_csrf'] ?? '');
    if (empty($_SESSION['csrf']) || !hash_equals((string) $_SESSION['csrf'], $given)) {
        $errors[] = 'Die Sitzung ist abgelaufen. Bitte versuche es erneut.';
        $action = '';
    }
}

$post = static fn (string $k, string $d = ''): string => trim((string) ($_POST[$k] ?? $d));

if ($action === 'code') {
    $_SESSION['setup_tries'] = ($_SESSION['setup_tries'] ?? 0) + 1;
    $expected = is_file($codeFile) ? trim((string) file_get_contents($codeFile)) : '';
    if ($_SESSION['setup_tries'] > 10) {
        $errors[] = 'Zu viele Versuche. Lösche die Browser-Sitzung (Cookies) und versuche es erneut.';
    } elseif ($expected !== '' && hash_equals(strtoupper($expected), strtoupper(preg_replace('/\s+/', '', $post('code')) ?? ''))) {
        $state['code_ok'] = true;
        $_SESSION['setup_tries'] = 0;
    } else {
        usleep(700000);
        $errors[] = 'Der Setup-Code ist falsch.';
    }
}

$authorized = !empty($state['code_ok']);

if ($authorized && $action === 'db') {
    $db = ['host' => $post('db_host', 'localhost'), 'port' => (int) $post('db_port', '3306') ?: 3306, 'name' => $post('db_name'), 'user' => $post('db_user'), 'pass' => (string) ($_POST['db_pass'] ?? '')];
    $state['db_form'] = $db;
    if ($db['host'] === '' || $db['name'] === '' || $db['user'] === '') {
        $errors[] = 'Bitte fülle Server, Datenbankname und Benutzer aus.';
    } elseif (!preg_match('/^[A-Za-z0-9_.$-]+$/', $db['name'])) {
        $errors[] = 'Der Datenbankname enthält ungültige Zeichen.';
    } else {
        try {
            $pdo = Database::connect($db);
            $ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
            $hasUsers = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
            if ($hasUsers && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
                $errors[] = 'Diese Datenbank enthält bereits eine Installation mit Benutzern. Der Assistent überschreibt keine bestehenden Daten. Wähle eine leere Datenbank.';
            } else {
                $state['db'] = $db;
                $state['db_version'] = $ver;
                $notice = 'Verbindung erfolgreich (' . $ver . ').';
            }
        } catch (Throwable $e) {
            $errors[] = 'Verbindung fehlgeschlagen: ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 200);
        }
    }
}

if ($authorized && $action === 'org') {
    $org = [
        'company' => $post('company'),
        'accent' => $post('accent', '#2f6fed'),
        'url' => rtrim($post('url'), '/'),
        'trust_proxy' => !empty($_POST['trust_proxy']),
        'admin_name' => $post('admin_name'),
        'admin_email' => mb_strtolower($post('admin_email')),
    ];
    $pw = (string) ($_POST['admin_pass'] ?? '');
    $pw2 = (string) ($_POST['admin_pass2'] ?? '');
    $state['org_form'] = $org;
    if (mb_strlen($org['company']) < 2 || mb_strlen($org['company']) > 80) {
        $errors[] = 'Bitte gib einen Unternehmensnamen an (2 bis 80 Zeichen).';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $org['accent'])) {
        $errors[] = 'Die Akzentfarbe muss im Format #rrggbb angegeben werden.';
    }
    $parts = parse_url($org['url']);
    if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host']) || isset($parts['query'])) {
        $errors[] = 'Bitte gib die öffentliche Adresse der Anwendung an (z. B. https://chat.firma.ch).';
    } elseif (($parts['scheme'] ?? '') === 'http' && !in_array($parts['host'], ['localhost', '127.0.0.1'], true)) {
        $errors[] = 'Ausserhalb von localhost ist HTTPS zwingend. Bitte verwende eine https://-Adresse.';
    }
    if (mb_strlen($org['admin_name']) < 2) {
        $errors[] = 'Bitte gib den Namen der Administratorin oder des Administrators an.';
    }
    if (!Auth::validEmail($org['admin_email'])) {
        $errors[] = 'Bitte gib eine gültige E-Mail-Adresse für das Administratorkonto an.';
    }
    if ($pw !== $pw2) {
        $errors[] = 'Die Passwörter stimmen nicht überein.';
    } elseif ($err = Auth::passwordError($pw, $org['admin_email'])) {
        $errors[] = $err;
    }
    if (!$errors) {
        $state['org'] = $org;
        $state['admin_hash'] = Auth::hashPassword($pw); // Klartext wird nicht in der Session gehalten
    }
}

$smtpCfg = static function () use ($post): array {
    return [
        'host' => $post('smtp_host'),
        'port' => (int) $post('smtp_port', '587') ?: 587,
        'security' => in_array($post('smtp_security'), ['tls', 'ssl', 'none'], true) ? $post('smtp_security') : 'tls',
        'user' => $post('smtp_user'),
        'pass' => (string) ($_POST['smtp_pass'] ?? '') !== '' ? (string) $_POST['smtp_pass'] : '',
        'from_email' => mb_strtolower($post('smtp_from')),
        'from_name' => $post('smtp_from_name'),
    ];
};

$smtpResult = null;
if ($authorized && in_array($action, ['smtp_test', 'smtp_mail', 'smtp_save'], true)) {
    $cfg = $smtpCfg();
    if ($cfg['pass'] === '' && !empty($state['smtp']['pass'])) {
        $cfg['pass'] = $state['smtp']['pass']; // bereits eingegebenes Passwort behalten
    }
    $state['smtp_form'] = $cfg;
    if ($cfg['host'] === '' || !Auth::validEmail($cfg['from_email'])) {
        $errors[] = 'Bitte gib mindestens SMTP-Server und eine gültige Absenderadresse an.';
    } elseif ($action === 'smtp_test') {
        $smtpResult = Mail::testConnection($cfg);
        if ($smtpResult['ok']) {
            $state['smtp'] = $cfg;
            $state['smtp_verified'] = true;
        }
    } elseif ($action === 'smtp_mail') {
        $to = mb_strtolower($post('smtp_test_to', $state['org']['admin_email'] ?? ''));
        if (!Auth::validEmail($to)) {
            $errors[] = 'Bitte gib eine gültige Empfängeradresse für die Testmail an.';
        } else {
            $smtpResult = Mail::sendTest($cfg, $to);
            $smtpResult['to'] = $to;
            if ($smtpResult['ok']) {
                $state['smtp'] = $cfg;
                $state['smtp_verified'] = true;
            }
        }
    } else {
        $state['smtp'] = $cfg;
        $state['smtp_done'] = true;
    }
}
if ($authorized && $action === 'smtp_skip') {
    $state['smtp'] = null;
    $state['smtp_done'] = true;
}

$installResult = null;
if ($authorized && $action === 'install' && !empty($state['db']) && !empty($state['org']) && !empty($state['smtp_done'])) {
    $installResult = $errors ? null : run_install($state);
    if ($installResult['ok']) {
        $_SESSION['setup'] = [];
        $_SESSION['setup_installed'] = true;
        $state = [];
    } else {
        $errors[] = $installResult['error'];
    }
}

// ------------------------------------------------------------ Installation
function run_install(array $state): array
{
    $db = $state['db'];
    $org = $state['org'];
    $configFile = Config::file();
    $configWritten = false;
    try {
        $pdo = Database::connect($db);
        // Gefahrlos erneut ausführbar: Wenn Benutzer existieren, wird nichts überschrieben
        if ($pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn() && (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
            return ['ok' => false, 'error' => 'Die Datenbank enthält bereits Benutzer. Es wird nichts überschrieben.'];
        }
        $applied = Migrator::run($pdo);

        $config = [
            'app' => [
                'key' => base64_encode(random_bytes(32)),
                'url' => $org['url'],
                'force_https' => str_starts_with($org['url'], 'https://'),
                'trust_proxy' => (bool) $org['trust_proxy'],
                'installed_at' => gmdate('c'),
                'version' => APP_VERSION,
            ],
            'db' => $db,
            'paths' => ['storage' => null],
        ];
        $php = "<?php\n// Von setup.php erzeugt. Enthält Zugangsdaten: nicht weitergeben und nicht öffentlich ablegen.\nreturn " . var_export($config, true) . ";\n";
        if (@file_put_contents($configFile, $php, LOCK_EX) === false) {
            return ['ok' => false, 'error' => 'Die Konfigurationsdatei config/config.php konnte nicht geschrieben werden. Prüfe die Schreibrechte.'];
        }
        $configWritten = true;
        @chmod($configFile, 0640);
        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($configFile, true);
        }
        Config::override($config);

        Settings::set('company_name', $org['company']);
        Settings::set('accent_color', strtolower($org['accent']));
        Settings::set('registration_enabled', '0');
        Settings::set('require_email_verification', '1');
        if (!empty($state['smtp'])) {
            $s = $state['smtp'];
            Settings::set('smtp_host', $s['host']);
            Settings::set('smtp_port', (string) $s['port']);
            Settings::set('smtp_security', $s['security']);
            Settings::set('smtp_user', $s['user']);
            Settings::set('smtp_pass_enc', Crypto::encrypt((string) $s['pass']));
            Settings::set('smtp_from_email', $s['from_email']);
            Settings::set('smtp_from_name', $s['from_name']);
        }
        $adminId = User::create($org['admin_email'], $org['admin_name'], $state['admin_hash'], 'admin', true);
        \App\Services\Changelog::markSeen($adminId); // frische Installation: kein «Neu in dieser Version»
        \App\Services\Audit::log('system.installed', $adminId, 'user', $adminId, 'Version ' . APP_VERSION . ', Migrationen: ' . implode(', ', $applied));

        // Abschlussprüfung
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach (['users', 'conversations', 'messages', 'system_settings', 'audit_logs'] as $t) {
            if (!in_array($t, $tables, true)) {
                throw new RuntimeException("Tabelle $t fehlt nach der Installation.");
            }
        }
        if ((int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() < 1) {
            throw new RuntimeException('Das Administratorkonto wurde nicht angelegt.');
        }
        if (@file_put_contents(storage_path('installed.lock'), gmdate('c') . ' ' . APP_VERSION . "\n", LOCK_EX) === false) {
            throw new RuntimeException('Die Sperrdatei storage/installed.lock konnte nicht geschrieben werden.');
        }
        @unlink(storage_path('setup_code.txt'));
        // Zugangsdaten nicht länger als nötig in der Session halten
        return ['ok' => true, 'mail_configured' => !empty($state['smtp']), 'url' => $org['url']];
    } catch (Throwable $e) {
        log_error('Installation fehlgeschlagen: ' . $e->getMessage());
        // Rollback: halb fertige Konfiguration entfernen, damit der Assistent erneut laufen kann
        if ($configWritten) {
            @unlink($configFile);
        }
        return ['ok' => false, 'error' => 'Installation fehlgeschlagen: ' . mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 250)];
    }
}

// ------------------------------------------------------------------ Ausgabe
function setup_page(string $title, string $body, int $step): void
{
    $steps = ['Voraussetzungen', 'Datenbank', 'Unternehmen', 'E-Mail', 'Installation'];
    $nav = '';
    if ($step > 0) {
        $nav = '<ol class="mb-6 flex flex-wrap items-center gap-2 text-xs font-medium">';
        foreach ($steps as $i => $label) {
            $n = $i + 1;
            $cls = $n < $step ? 'bg-emerald-100 text-emerald-700' : ($n === $step ? 'bg-accent text-white' : 'bg-slate-100 text-slate-500');
            $nav .= '<li class="flex items-center gap-1.5 rounded-full px-3 py-1 ' . $cls . '"><span>' . $n . '</span><span class="hidden sm:inline">' . e($label) . '</span></li>';
        }
        $nav .= '</ol>';
    }
    $accent = '47 111 237';
    echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow">'
        . '<title>' . e($title) . ' · Installation</title>'
        . '<link rel="stylesheet" href="' . e(url('assets/vendor/boxicons/css/boxicons.min.css')) . '">'
        . '<link rel="stylesheet" href="' . e(url('assets/css/app.css')) . '?v=' . e((string) @filemtime(ROOT_PATH . '/public/assets/css/app.css')) . '">'
        . '<style nonce="' . e(SecurityHeaders::nonce()) . '">:root{--accent:' . $accent . ';}</style>'
        . '<script src="' . e(url('assets/js/theme.js')) . '"></script></head>'
        . '<body class="min-h-screen bg-slate-100 text-slate-800 antialiased dark:bg-neutral-950 dark:text-neutral-100">'
        . '<main class="mx-auto max-w-2xl px-4 py-10">'
        . '<div class="mb-6 flex items-center gap-3"><span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-white shadow-soft"><i class="bx bxs-chat text-2xl" aria-hidden="true"></i></span><span class="text-xl font-semibold tracking-tight">Unternehmens-Messenger · Installation</span></div>'
        . $nav
        . '<div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-soft sm:p-8 dark:border-neutral-800 dark:bg-neutral-900">' . $body . '</div>'
        . '<p class="mt-6 text-center text-xs text-slate-500">Version ' . e(APP_VERSION) . '</p>'
        . '</main></body></html>';
}

function field(string $name, string $label, string $value = '', string $type = 'text', string $extra = '', string $hint = ''): string
{
    $id = 'f_' . $name;
    return '<div><label for="' . e($id) . '" class="label">' . e($label) . '</label>'
        . '<input id="' . e($id) . '" name="' . e($name) . '" type="' . e($type) . '" value="' . e($value) . '" class="input" ' . $extra . '>'
        . ($hint !== '' ? '<p class="mt-1 text-xs text-slate-500">' . e($hint) . '</p>' : '') . '</div>';
}

function alerts(array $errors, ?string $notice): string
{
    $h = '';
    foreach ($errors as $err) {
        $h .= '<div class="alert alert-error" role="alert"><i class="bx bx-error-circle text-lg" aria-hidden="true"></i><span>' . e($err) . '</span></div>';
    }
    if ($notice) {
        $h .= '<div class="alert alert-success" role="status"><i class="bx bx-check-circle text-lg" aria-hidden="true"></i><span>' . e($notice) . '</span></div>';
    }
    return $h;
}

$csrf = Csrf::field();
$self = e(url('setup.php'));

// Erfolgsseite
if (!empty($_SESSION['setup_installed']) || ($installResult['ok'] ?? false)) {
    $res = $installResult ?? ['mail_configured' => null, 'url' => base_url()];
    unset($_SESSION['setup_installed']);
    $body = '<div class="text-center"><span class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><i class="bx bx-check-circle text-3xl" aria-hidden="true"></i></span>'
        . '<h1 class="mb-2 text-xl font-semibold">Installation abgeschlossen</h1>'
        . '<p class="text-sm text-slate-500">Das Administratorkonto wurde angelegt und der Installationsassistent ist jetzt gesperrt.</p></div>'
        . '<ul class="mt-6 space-y-2 text-sm">'
        . '<li class="flex gap-2"><i class="bx bx-check text-emerald-600 text-lg"></i>Datenbank-Schema angelegt und Konfiguration gespeichert</li>'
        . '<li class="flex gap-2"><i class="bx bx-check text-emerald-600 text-lg"></i>Setup-Code gelöscht, <code>storage/installed.lock</code> geschrieben</li>'
        . (($res['mail_configured'] ?? false) ? '<li class="flex gap-2"><i class="bx bx-check text-emerald-600 text-lg"></i>SMTP ist konfiguriert</li>' : '<li class="flex gap-2"><i class="bx bx-error text-amber-600 text-lg"></i>SMTP ist nicht konfiguriert: Einladungen und Passwort-Rücksetzung per E-Mail funktionieren erst nach der Einrichtung unter Administration → Einstellungen.</li>')
        . '</ul>'
        . '<div class="alert alert-info mt-6"><i class="bx bx-info-circle text-lg"></i><span><strong>Bitte jetzt:</strong> Lösche <code>setup.php</code> vom Server, stelle sicher, dass <code>config/</code> und <code>storage/</code> nicht aus dem Web erreichbar sind, und sichere die Datei <code>config/config.php</code> (enthält den Schlüssel für gespeicherte Geheimnisse).</span></div>'
        . '<a href="' . e(url('login')) . '" class="btn btn-primary mt-6 w-full">Zur Anmeldung</a>';
    setup_page('Fertig', $body, 0);
    exit;
}

// Schritt bestimmen
$list = checks($dirs);
$checksOk = checks_ok($list);
$step = 0; // 0 = Code
if ($authorized) {
    $step = 1;
    if ($checksOk && ($state['checks_ack'] ?? false)) {
        $step = 2;
        if (!empty($state['db'])) {
            $step = 3;
            if (!empty($state['org'])) {
                $step = 4;
                if (!empty($state['smtp_done'])) {
                    $step = 5;
                }
            }
        }
    }
}
if ($authorized && $action === 'ack' && $checksOk) {
    $state['checks_ack'] = true;
    $step = max($step, 2);
    if (!empty($state['db'])) {
        $step = max($step, 3);
    }
}
if ($authorized && isset($_GET['step']) && ctype_digit((string) $_GET['step'])) {
    $want = (int) $_GET['step'];
    if ($want >= 1 && $want <= $step) {
        $step = $want;
    }
}

// Schritt 0: Setup-Code
if ($step === 0) {
    $body = '<h1 class="mb-1 text-xl font-semibold">Setup-Code eingeben</h1>'
        . '<p class="mb-5 text-sm text-slate-500">Damit niemand fremdes die Installation übernehmen kann, wurde auf dem Server ein einmaliger Code erzeugt. Öffne die Datei <code>storage/setup_code.txt</code> (per FTP, SFTP oder Dateimanager deines Hosters) und gib den Inhalt hier ein.</p>'
        . alerts($errors, $notice)
        . (is_file($codeFile) ? '' : '<div class="alert alert-error"><i class="bx bx-error-circle text-lg"></i><span>Die Datei <code>storage/setup_code.txt</code> konnte nicht erstellt werden. Mache das Verzeichnis <code>storage/</code> für PHP beschreibbar und lade diese Seite neu.</span></div>')
        . '<form method="post" action="' . $self . '" class="space-y-4">' . $csrf . '<input type="hidden" name="action" value="code">'
        . field('code', 'Setup-Code', '', 'text', 'required autofocus autocomplete="off" spellcheck="false" maxlength="40"')
        . '<button class="btn btn-primary w-full" type="submit">Weiter</button></form>';
    setup_page('Setup-Code', $body, 0);
    exit;
}

// Schritt 1: Voraussetzungen
if ($step === 1) {
    $rows = '';
    foreach ($list as $c) {
        $icon = $c['ok'] ? '<i class="bx bx-check-circle text-xl text-emerald-600" aria-label="in Ordnung"></i>' : ($c['required'] ? '<i class="bx bx-x-circle text-xl text-red-600" aria-label="Fehler"></i>' : '<i class="bx bx-error text-xl text-amber-600" aria-label="Warnung"></i>');
        $rows .= '<li class="flex items-start gap-3 py-2.5">' . $icon . '<div><div class="text-sm font-medium">' . e($c['label']) . '</div>'
            . ($c['detail'] !== '' ? '<div class="text-xs text-slate-500">' . e($c['detail']) . '</div>' : '') . '</div></li>';
    }
    $body = '<h1 class="mb-1 text-xl font-semibold">Systemvoraussetzungen</h1><p class="mb-4 text-sm text-slate-500">Der Assistent prüft PHP-Version, Erweiterungen und Schreibrechte.</p>'
        . alerts($errors, $notice)
        . '<ul class="divide-y divide-slate-100">' . $rows . '</ul>'
        . ($checksOk
            ? '<form method="post" action="' . $self . '" class="mt-6">' . $csrf . '<input type="hidden" name="action" value="ack"><button class="btn btn-primary w-full" type="submit">Weiter zur Datenbank</button></form>'
            : '<div class="alert alert-error mt-6"><i class="bx bx-error-circle text-lg"></i><span>Bitte behebe die markierten Punkte und lade die Seite neu.</span></div><a class="btn btn-secondary w-full" href="' . $self . '">Erneut prüfen</a>');
    setup_page('Voraussetzungen', $body, 1);
    exit;
}

// Schritt 2: Datenbank
if ($step === 2) {
    $f = $state['db_form'] ?? ['host' => 'localhost', 'port' => 3306, 'name' => '', 'user' => '', 'pass' => ''];
    $body = '<h1 class="mb-1 text-xl font-semibold">Datenbank</h1><p class="mb-4 text-sm text-slate-500">Zugangsdaten zu einer leeren MySQL- oder MariaDB-Datenbank (Angaben findest du im Hosting-Panel).</p>'
        . alerts($errors, $notice)
        . '<form method="post" action="' . $self . '" class="space-y-4">' . $csrf . '<input type="hidden" name="action" value="db">'
        . '<div class="grid gap-4 sm:grid-cols-3"><div class="sm:col-span-2">' . field('db_host', 'Server', (string) $f['host'], 'text', 'required') . '</div>' . field('db_port', 'Port', (string) $f['port'], 'number', 'required min="1" max="65535"') . '</div>'
        . field('db_name', 'Datenbankname', (string) $f['name'], 'text', 'required autocomplete="off"')
        . field('db_user', 'Benutzer', (string) $f['user'], 'text', 'required autocomplete="off"')
        . field('db_pass', 'Passwort', '', 'password', 'autocomplete="new-password"', 'Wird nur in config/config.php gespeichert.')
        . '<button class="btn btn-primary w-full" type="submit">Verbindung testen und weiter</button></form>';
    setup_page('Datenbank', $body, 2);
    exit;
}

// Schritt 3: Unternehmen und Administrator
if ($step === 3) {
    $detected = (is_https() ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . app_base_path();
    $o = $state['org_form'] ?? ['company' => '', 'accent' => '#2f6fed', 'url' => $detected, 'trust_proxy' => false, 'admin_name' => '', 'admin_email' => ''];
    $body = '<h1 class="mb-1 text-xl font-semibold">Unternehmen und Administrator</h1><p class="mb-4 text-sm text-slate-500">Diese Angaben lassen sich später im Adminbereich ändern (ausser der Adresse und dem Administratorkonto).</p>'
        . alerts($errors, $notice)
        . '<form method="post" action="' . $self . '" class="space-y-4">' . $csrf . '<input type="hidden" name="action" value="org">'
        . field('company', 'Unternehmensname', (string) $o['company'], 'text', 'required maxlength="80"')
        . '<div><label for="f_accent" class="label">Akzentfarbe</label><input id="f_accent" name="accent" type="color" value="' . e((string) $o['accent']) . '" class="h-11 w-24 cursor-pointer rounded-lg border border-slate-300 bg-white p-1"></div>'
        . field('url', 'Öffentliche Adresse der Anwendung', (string) $o['url'], 'url', 'required', 'Wird für Links in E-Mails verwendet (nicht aus der Anfrage abgeleitet). Muss mit https:// beginnen.')
        . '<label class="flex items-start gap-2 text-sm"><input type="checkbox" name="trust_proxy" value="1" class="mt-1" ' . (!empty($o['trust_proxy']) ? 'checked' : '') . '><span>Die Anwendung läuft hinter einem Reverse-Proxy oder Load Balancer, der <code>X-Forwarded-For</code> und <code>X-Forwarded-Proto</code> setzt.</span></label>'
        . '<hr class="border-slate-200">'
        . field('admin_name', 'Name der Administratorin / des Administrators', (string) $o['admin_name'], 'text', 'required maxlength="80" autocomplete="off"')
        . field('admin_email', 'E-Mail-Adresse', (string) $o['admin_email'], 'email', 'required maxlength="190" autocomplete="off"')
        . field('admin_pass', 'Passwort', '', 'password', 'required minlength="10" maxlength="72" autocomplete="new-password"', 'Mindestens 10 Zeichen, mit Buchstaben und mindestens einer Ziffer oder einem Sonderzeichen. Es gibt kein Standardpasswort.')
        . field('admin_pass2', 'Passwort wiederholen', '', 'password', 'required maxlength="72" autocomplete="new-password"')
        . '<div class="flex gap-3"><a class="btn btn-secondary" href="' . $self . '?step=2">Zurück</a><button class="btn btn-primary flex-1" type="submit">Weiter</button></div></form>';
    setup_page('Unternehmen', $body, 3);
    exit;
}

// Schritt 4: SMTP
if ($step === 4) {
    $s = $state['smtp_form'] ?? ['host' => '', 'port' => 587, 'security' => 'tls', 'user' => '', 'pass' => '', 'from_email' => '', 'from_name' => $state['org']['company'] ?? ''];
    $resultHtml = '';
    if ($smtpResult !== null) {
        $resultHtml = $smtpResult['ok']
            ? '<div class="alert alert-success" role="status"><i class="bx bx-check-circle text-lg"></i><span>' . (isset($smtpResult['to']) ? 'Testmail an ' . e($smtpResult['to']) . ' wurde gesendet.' : 'SMTP-Verbindung erfolgreich.') . '</span></div>'
            : '<div class="alert alert-error" role="alert"><i class="bx bx-error-circle text-lg"></i><span>' . e($smtpResult['error']) . '</span></div>';
    }
    $sec = static fn (string $v, string $label) => '<option value="' . $v . '"' . (($s['security'] ?? 'tls') === $v ? ' selected' : '') . '>' . $label . '</option>';
    $body = '<h1 class="mb-1 text-xl font-semibold">E-Mail-Versand (SMTP)</h1><p class="mb-4 text-sm text-slate-500">Für Einladungen, Passwort-Rücksetzung, E-Mail-Bestätigung und Benachrichtigungen. Das Passwort wird verschlüsselt in der Datenbank abgelegt.</p>'
        . alerts($errors, $notice) . $resultHtml
        . '<form method="post" action="' . $self . '" class="space-y-4">' . $csrf
        . '<div class="grid gap-4 sm:grid-cols-3"><div class="sm:col-span-2">' . field('smtp_host', 'SMTP-Server', (string) $s['host'], 'text', 'autocomplete="off"') . '</div>' . field('smtp_port', 'Port', (string) $s['port'], 'number', 'min="1" max="65535"') . '</div>'
        . '<div><label for="f_smtp_security" class="label">Verschlüsselung</label><select id="f_smtp_security" name="smtp_security" class="input">' . $sec('tls', 'STARTTLS (Port 587, empfohlen)') . $sec('ssl', 'SSL/TLS (Port 465)') . $sec('none', 'Keine (nur für lokale Testserver)') . '</select></div>'
        . '<div class="grid gap-4 sm:grid-cols-2">' . field('smtp_user', 'Benutzername', (string) $s['user'], 'text', 'autocomplete="off"') . field('smtp_pass', 'Passwort', '', 'password', 'autocomplete="new-password"', !empty($state['smtp']['pass']) ? 'Leer lassen, um das eingegebene Passwort zu behalten.' : '') . '</div>'
        . '<div class="grid gap-4 sm:grid-cols-2">' . field('smtp_from', 'Absenderadresse', (string) $s['from_email'], 'email', 'maxlength="190"') . field('smtp_from_name', 'Absendername', (string) $s['from_name'], 'text', 'maxlength="80"') . '</div>'
        . field('smtp_test_to', 'Testmail senden an', (string) ($state['org']['admin_email'] ?? ''), 'email', 'maxlength="190"')
        . '<div class="flex flex-wrap gap-2">'
        . '<button class="btn btn-secondary" type="submit" name="action" value="smtp_test">Verbindung testen</button>'
        . '<button class="btn btn-secondary" type="submit" name="action" value="smtp_mail">Testmail senden</button>'
        . '<button class="btn btn-primary" type="submit" name="action" value="smtp_save">Speichern und weiter</button></div>'
        . (empty($state['smtp_verified']) ? '<p class="text-xs text-amber-700">Empfehlung: Teste die Verbindung, bevor du fortfährst.</p>' : '')
        . '</form>'
        . '<form method="post" action="' . $self . '" class="mt-4 border-t border-slate-200 pt-4">' . $csrf . '<input type="hidden" name="action" value="smtp_skip"><button class="text-sm font-medium text-slate-500 underline hover:text-slate-700" type="submit">SMTP später konfigurieren (Einladungen und Passwort-Rücksetzung per E-Mail funktionieren dann noch nicht)</button></form>'
        . '<a class="btn btn-secondary mt-4" href="' . $self . '?step=3">Zurück</a>';
    setup_page('E-Mail', $body, 4);
    exit;
}

// Schritt 5: Zusammenfassung und Installation
$o = $state['org'];
$d = $state['db'];
$s = $state['smtp'] ?? null;
$body = '<h1 class="mb-1 text-xl font-semibold">Installation starten</h1><p class="mb-4 text-sm text-slate-500">Prüfe die Angaben. Beim Installieren werden die Tabellen angelegt, die Konfiguration geschrieben und das Administratorkonto erstellt.</p>'
    . alerts($errors, $notice)
    . '<dl class="divide-y divide-slate-100 text-sm">'
    . '<div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Datenbank</dt><dd class="text-right">' . e($d['user']) . '@' . e($d['host']) . ':' . (int) $d['port'] . '/' . e($d['name']) . '</dd></div>'
    . '<div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Unternehmen</dt><dd class="text-right">' . e($o['company']) . '</dd></div>'
    . '<div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Adresse</dt><dd class="text-right">' . e($o['url']) . '</dd></div>'
    . '<div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">Administrator</dt><dd class="text-right">' . e($o['admin_name']) . ' (' . e($o['admin_email']) . ')</dd></div>'
    . '<div class="flex justify-between gap-4 py-2"><dt class="text-slate-500">SMTP</dt><dd class="text-right">' . ($s ? e($s['host']) . ':' . (int) $s['port'] : 'nicht konfiguriert') . '</dd></div>'
    . '</dl>'
    . '<form method="post" action="' . $self . '" class="mt-6 space-y-4">' . $csrf . '<input type="hidden" name="action" value="install">'
    . '<div class="flex gap-3"><a class="btn btn-secondary" href="' . $self . '?step=4">Zurück</a><button class="btn btn-primary flex-1" type="submit">Jetzt installieren</button></div></form>';
setup_page('Installation', $body, 5);
