<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Mail;
use App\Services\Monitor;
use App\Services\Push;
use App\Services\Settings;

/** Betrieb: Zustandsprüfungen, Fehlerüberwachung, Echtzeitmodus und Push. */
final class AdminOperationsController extends Controller
{
    private function back(?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect('admin/operations');
    }

    public function index(Request $r): Response
    {
        return $this->view('admin/operations', [
            'section' => 'operations',
            'title' => 'Betrieb',
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
            's' => Settings::all(),
            'checks' => Monitor::checks(),
            'errors' => Monitor::errorStats(),
            'subs' => (int) Database::value('SELECT COUNT(*) FROM push_subscriptions'),
            'pushUsers' => (int) Database::value('SELECT COUNT(DISTINCT user_id) FROM push_subscriptions'),
            'myDevices' => Database::all('SELECT id, user_agent, p256dh, last_sent_at, last_status, last_error FROM push_subscriptions WHERE user_id = ? ORDER BY id', [(int) $this->user()['id']]),
            'curl' => function_exists('curl_init'),
            'openssl' => function_exists('openssl_pkey_new'),
            'mail' => Mail::configured(),
        ], 'layouts/main');
    }

    public function saveRealtime(Request $r): Response
    {
        $admin = $this->user();
        Settings::set('realtime_mode', $r->str('realtime_mode') === 'sse' ? 'sse' : 'poll');
        Audit::log('admin.settings_realtime', (int) $admin['id'], null, null, Settings::get('realtime_mode'));
        return $this->back('Echtzeitmodus gespeichert. Clients übernehmen ihn beim nächsten Laden der Seite.');
    }

    public function savePush(Request $r): Response
    {
        $admin = $this->user();
        $on = $r->str('push_enabled') === '1';
        if ($on && (!function_exists('curl_init') || !Push::ensureKeys())) {
            return $this->back(null, 'Push benötigt die PHP-Erweiterungen curl und openssl.');
        }
        Settings::set('push_enabled', $on ? '1' : '0');
        Audit::log('admin.settings_push', (int) $admin['id'], null, null, $on ? 'on' : 'off');
        return $this->back($on ? 'Push-Benachrichtigungen aktiviert. Nutzende schalten sie unter Einstellungen → Benachrichtigungen ein.' : 'Push-Benachrichtigungen deaktiviert.');
    }

    public function testPush(Request $r): Response
    {
        $admin = $this->user();
        if (!Push::enabled()) {
            return $this->back(null, 'Push ist nicht aktiviert.');
        }
        if (Push::countFor((int) $admin['id']) === 0) {
            return $this->back(null, 'Auf deinen Geräten ist Push noch nicht eingeschaltet. Aktiviere es zuerst unter Einstellungen → Benachrichtigungen (auf dem iPhone muss die App dafür zum Home-Bildschirm hinzugefügt sein).');
        }
        $res = Push::sendDetailed((int) $admin['id'], Push::payload((int) $admin['id'], 'Test: Push-Benachrichtigungen funktionieren.'));
        $ok = count(array_filter($res, static fn (array $x): bool => $x['ok']));
        $fails = array_map(static fn (array $x): string => '«' . ($x['ua'] !== '' ? mb_substr($x['ua'], 0, 40) : 'Gerät ' . $x['id']) . '»: ' . $x['error'], array_filter($res, static fn (array $x): bool => !$x['ok']));
        if ($ok === count($res)) {
            return $this->back("Test-Push an $ok Gerät(e) vom Push-Dienst angenommen. Er sollte gleich erscheinen – auch bei geschlossener App.");
        }
        return $this->back($ok > 0 ? "$ok von " . count($res) . ' Geräten erreicht.' : null, $ok > 0 ? null : 'Kein Gerät erreicht. ' . implode(' · ', $fails));
    }

    public function saveMonitor(Request $r): Response
    {
        $admin = $this->user();
        $email = mb_strtolower($r->str('monitor_email', '', 190));
        if ($email !== '' && !Auth::validEmail($email)) {
            return $this->back(null, 'Bitte gib eine gültige E-Mail-Adresse an.');
        }
        $th = max(1, min(1000, (int) $r->str('monitor_threshold', '10', 5)));
        $win = max(1, min(1440, (int) $r->str('monitor_window_min', '10', 5)));
        Settings::set('monitor_email', $email);
        Settings::set('monitor_threshold', (string) $th);
        Settings::set('monitor_window_min', (string) $win);
        Monitor::saveConfig($email, $th, $win);
        Audit::log('admin.settings_monitor', (int) $admin['id']);
        return $this->back('Überwachung gespeichert.');
    }

    public function testAlert(Request $r): Response
    {
        $this->user();
        $email = Settings::get('monitor_email');
        if ($email === '' || !Mail::configured()) {
            return $this->back(null, 'Hinterlege eine Alarm-Adresse und konfiguriere SMTP.');
        }
        $ok = Mail::send($email, '', 'Test: Fehlerüberwachung', 'Test', '<p>Dies ist eine Testnachricht der Fehlerüberwachung.</p>', "Dies ist eine Testnachricht der Fehlerüberwachung.\n");
        return $ok ? $this->back('Test-E-Mail gesendet.') : $this->back(null, 'Die E-Mail konnte nicht gesendet werden.');
    }

    /** Öffentlicher, minimaler Gesundheitscheck für Uptime-Monitore: nur "ok" oder 503, keine Details. */
    public function health(Request $r): Response
    {
        try {
            Database::value('SELECT 1');
            $ok = is_writable(storage_path());
        } catch (\Throwable) {
            $ok = false;
        }
        return new Response($ok ? 200 : 503, $ok ? 'ok' : 'fail', ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
    }
}
