<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;
use App\Services\License;
use App\Services\Settings;

/** Lizenzverwaltung: Schlüssel, Status, Funktionen (Scopes) und Benutzerplätze. */
final class AdminLicenseController extends Controller
{
    private function back(?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect('admin/license');
    }

    public function index(Request $r): Response
    {
        return $this->view('admin/license', [
            'section' => 'license',
            'title' => 'Lizenz',
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
            's' => Settings::all(),
            'status' => License::status(),
            'hasKey' => License::key() !== '',
            'keyHint' => License::key() !== '' ? substr(License::key(), 0, 3) . '…' . substr(License::key(), -4) : '',
            'scopes' => License::SCOPES,
            'activeUsers' => License::activeUsers(),
            'seats' => License::seatsUsed(),
            'fingerprint' => License::publicKeyFingerprint(),
            'lastError' => is_file(storage_path('cache/license.fail')) ? (string) @file_get_contents(storage_path('cache/license.fail')) : '',
        ], 'layouts/main');
    }

    public function save(Request $r): Response
    {
        $admin = $this->user();
        Settings::set('license_product', $r->str('license_product', '', 80));
        $key = $r->str('license_key', '', 80);
        if ($key !== '') {
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9\-]{5,78}$/', trim($key))) {
                return $this->back(null, 'Das Format des Lizenzschlüssels ist ungültig.');
            }
            License::setKey($key);
        } else {
            @unlink(storage_path('cache/license.json'));
        }
        return $this->verify((int) $admin['id'], 'Einstellungen gespeichert.');
    }

    public function check(Request $r): Response
    {
        return $this->verify((int) $this->user()['id'], null);
    }

    private function verify(int $adminId, ?string $prefix): Response
    {
        $res = License::refresh();
        Audit::log('admin.license_check', $adminId, null, null, $res['ok'] ? License::status()['code'] : 'error');
        if (!$res['ok']) {
            return $this->back(null, trim(($prefix ? $prefix . ' ' : '') . 'Prüfung fehlgeschlagen: ' . ($res['error'] ?? '')));
        }
        $st = License::status();
        if ($st['state'] !== 'ok') {
            return $this->back(null, trim(($prefix ? $prefix . ' ' : '') . $st['message']));
        }
        @unlink(storage_path('cache/license.fail'));
        $sync = License::reconcile();
        $msg = trim(($prefix ? $prefix . ' ' : '') . 'Lizenz gültig.');
        if ($sync['claimed'] || $sync['released']) {
            $msg .= ' Plätze abgeglichen: ' . $sync['claimed'] . ' belegt, ' . $sync['released'] . ' freigegeben.';
        }
        if ($sync['over'] > 0) {
            return $this->back($msg, $sync['over'] . ' Konto/Konten überschreiten das Limit der Lizenz und belegen keinen Platz. Sie bleiben nutzbar, es können aber keine neuen Konten hinzukommen.');
        }
        return $this->back($msg);
    }

    public function remove(Request $r): Response
    {
        $admin = $this->user();
        if ($r->str('confirm') !== '1') {
            return $this->back(null, 'Bitte bestätige das Entfernen.');
        }
        foreach (\App\Core\Database::all('SELECT user_id FROM license_seats') as $row) {
            License::releaseSeat((int) $row['user_id']);
        }
        License::setKey('');
        Audit::log('admin.license_removed', (int) $admin['id']);
        return $this->back('Lizenzschlüssel entfernt. Die Installation läuft im Nur-Lesen-Modus.');
    }
}
