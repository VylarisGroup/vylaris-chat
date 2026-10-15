<?php
declare(strict_types=1);

/**
 * Funktionsprüfung auf der Kommandozeile:  php bin/selftest.php
 * Exit-Code 1, wenn ein Test fehlschlägt. Das Ergebnis erscheint auch im Admin-Dashboard.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require dirname(__DIR__) . '/app/bootstrap.php';

use App\Core\Config;
use App\Services\SelfTest;
use App\Services\Settings;

if (!Config::exists()) {
    fwrite(STDERR, "Der Messenger ist noch nicht installiert.\n");
    exit(1);
}

$res = SelfTest::run();
Settings::set('selftest_last', json_encode($res, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
$icons = ['pass' => 'OK  ', 'fail' => 'FAIL', 'warn' => 'WARN', 'skip' => 'SKIP'];
foreach ($res['groups'] as $g) {
    echo "\n== {$g['title']}\n";
    foreach ($g['tests'] as $t) {
        echo sprintf("[%s] %s%s\n", $icons[$t['status']], $t['name'], $t['detail'] !== '' ? '  -> ' . $t['detail'] : '');
    }
}
$s = $res['summary'];
echo "\nBestanden: {$s['pass']}  Fehlgeschlagen: {$s['fail']}  Warnungen: {$s['warn']}  Übersprungen: {$s['skip']}  ({$res['duration_ms']} ms)\n";
exit($s['fail'] > 0 ? 1 : 0);
