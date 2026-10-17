<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;
use App\Services\Crypto;
use App\Services\Meets;
use App\Services\Settings;

/** Videokonferenzen (TheRedStonee Meets): Schlüssel, Webhook und Verbindungstest. */
final class AdminMeetsController extends Controller
{
    private function back(?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect('admin/meets');
    }

    public function index(Request $r): Response
    {
        $key = Meets::key();
        return $this->view('admin/meets', [
            'section' => 'meets',
            'title' => 'Videokonferenzen',
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
            'enabled' => Settings::bool('meets_enabled'),
            'hasKey' => $key !== '',
            'keyHint' => $key !== '' ? substr($key, 0, 5) . '…' . substr($key, -4) : '',
            'ring' => Settings::bool('meets_ring'),
            'pushOn' => \App\Services\Push::enabled(),
            'keyFromConfig' => Settings::get('meets_key_enc') === '' && $key !== '',
            'secretFromConfig' => Settings::get('meets_webhook_secret_enc') === '' && Meets::webhookSecret() !== '',
            'hasSecret' => Meets::webhookSecret() !== '',
            'webhookUrl' => base_url() . '/api/meets/webhook',
            'origin' => base_url(),
            'embedOrigin' => Meets::embedOrigin(),
            'active' => (int) \App\Core\Database::value('SELECT COUNT(*) FROM meetings WHERE ended_at IS NULL AND expires_at > ?', [now()]),
            'total' => (int) \App\Core\Database::value('SELECT COUNT(*) FROM meetings'),
        ], 'layouts/main');
    }

    public function save(Request $r): Response
    {
        $admin = $this->user();
        $key = trim($r->str('api_key', '', 120));
        if ($key !== '') {
            if (!preg_match('/^trsm_[A-Za-z0-9_\-]{8,100}$/', $key)) {
                return $this->back(null, 'Das Format des API-Schlüssels ist ungültig (er beginnt mit «trsm_»).');
            }
            Settings::set('meets_key_enc', Crypto::encrypt($key));
        }
        $secret = trim($r->str('webhook_secret', '', 160));
        if ($secret !== '') {
            if (!preg_match('/^whsec_[A-Za-z0-9_\-]{8,140}$/', $secret)) {
                return $this->back(null, 'Das Format des Webhook-Geheimnisses ist ungültig (es beginnt mit «whsec_»).');
            }
            Settings::set('meets_webhook_secret_enc', Crypto::encrypt($secret));
        }
        if ($r->bool('remove_key')) {
            Settings::set('meets_key_enc', '');
        }
        if ($r->bool('remove_secret')) {
            Settings::set('meets_webhook_secret_enc', '');
        }
        Settings::set('meets_ring', $r->bool('ring') ? '1' : '0');
        $want = $r->bool('enabled');
        if ($want && Meets::key() === '') {
            Settings::set('meets_enabled', '0');
            return $this->back(null, 'Bitte zuerst einen API-Schlüssel eintragen.');
        }
        Settings::set('meets_enabled', $want ? '1' : '0');
        Audit::log('admin.meets_settings', (int) $admin['id'], null, null, json_encode(['enabled' => $want, 'key_changed' => $key !== '', 'secret_changed' => $secret !== '', 'ring' => $r->bool('ring')]));
        return $this->back('Einstellungen gespeichert.' . ($want && Meets::webhookSecret() === '' ? ' Hinweis: Ohne Webhook-Geheimnis wird die Teilnehmeranzeige nur beim Öffnen des Chats aktualisiert.' : ''));
    }

    public function test(Request $r): Response
    {
        if (Meets::key() === '') {
            return $this->back(null, 'Es ist noch kein API-Schlüssel gespeichert.');
        }
        $res = Meets::test();
        if (!$res['ok']) {
            return $this->back(null, 'Verbindungstest fehlgeschlagen: ' . $res['error']);
        }
        $k = $res['key'];
        $origins = array_map(static fn ($o) => rtrim((string) $o, '/'), (array) ($k['embedOrigins'] ?? []));
        $msg = sprintf('Verbindung erfolgreich. Schlüssel «%s», höchstens %s gleichzeitige Räume, %s Anfragen pro Minute.', (string) ($k['name'] ?? 'unbenannt'), (string) ($k['maxRooms'] ?? '?'), (string) ($k['rateLimit'] ?? '?'));
        if (!in_array(rtrim(base_url(), '/'), $origins, true)) {
            return $this->back($msg, 'Die Adresse dieses Messengers (' . base_url() . ') ist beim Schlüssel nicht als erlaubte Einbettungs-Domain (embedOrigins) hinterlegt. Bitte TheRedStonee bitten, sie einzutragen, sonst bleibt der eingebettete Raum leer.');
        }
        if (empty($k['webhook']['configured'])) {
            return $this->back($msg . ' Hinweis: Beim Schlüssel ist noch keine Webhook-Adresse hinterlegt (Teilnehmeranzeige live).');
        }
        return $this->back($msg . ' Einbettung und Webhook sind eingerichtet.');
    }
}
