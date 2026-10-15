<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;
use App\Services\Backup;
use App\Services\Crypto;
use App\Services\Jobs;
use App\Services\RateLimiter;
use App\Services\Settings;

/**
 * Backup-Verwaltung. Ein Backup enthält ALLE Nachrichten. Der Web-Download ist daher standardmässig deaktiviert
 * und verlangt, wenn aktiviert, das Passwort des Administrators; jeder Download wird protokolliert.
 */
final class AdminBackupController extends Controller
{
    private function back(?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect('admin/backup');
    }

    public function index(Request $r): Response
    {
        return $this->view('admin/backup', [
            'section' => 'backup',
            'title' => 'Backup',
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
            's' => Settings::all(),
            'files' => Backup::listFiles(),
            'jobs' => Database::all("SELECT id, status, error, created_at, finished_at FROM jobs WHERE type = 'backup' ORDER BY id DESC LIMIT 5"),
            'zip' => class_exists(\ZipArchive::class),
            'webDownload' => Settings::bool('backup_web_download'),
        ]);
    }

    public function create(Request $r): Response
    {
        $this->user();
        $pass = $r->str('passphrase', '', 200);
        if ($pass !== '' && mb_strlen($pass) < 12) {
            return $this->back(null, 'Die Passphrase muss mindestens 12 Zeichen lang sein (oder leer bleiben).');
        }
        if (!class_exists(\ZipArchive::class)) {
            return $this->back(null, 'Die PHP-Erweiterung zip fehlt.');
        }
        if (Database::value("SELECT COUNT(*) FROM jobs WHERE type = 'backup' AND status IN ('queued','running')") > 0) {
            return $this->back(null, 'Es läuft bereits ein Backup.');
        }
        Jobs::enqueue('backup', null, [
            'config' => $r->str('config') === '1',
            'passphrase_enc' => $pass !== '' ? Crypto::encrypt($pass) : '',
        ], 1);
        return $this->back('Backup wird im Hintergrund erstellt. Lade diese Seite in einigen Sekunden neu.');
    }

    public function settings(Request $r): Response
    {
        $admin = $this->user();
        Settings::set('backup_auto_days', (string) max(0, min(90, (int) $r->str('backup_auto_days', '0', 3))));
        Settings::set('backup_keep', (string) max(1, min(60, (int) $r->str('backup_keep', '7', 3))));
        Settings::set('backup_include_config', $r->str('backup_include_config') === '1' ? '1' : '0');
        $webDl = $r->str('backup_web_download') === '1';
        if ($webDl !== Settings::bool('backup_web_download')) {
            Audit::log($webDl ? 'backup.web_download_on' : 'backup.web_download_off', (int) $admin['id']);
        }
        Settings::set('backup_web_download', $webDl ? '1' : '0');
        Audit::log('admin.settings_backup', (int) $admin['id']);
        return $this->back('Einstellungen gespeichert.');
    }

    public function download(Request $r): Response
    {
        $admin = $this->user();
        $name = (string) $r->params['name'];
        if (!Settings::bool('backup_web_download')) {
            throw new HttpException(403, 'Der Download per Browser ist deaktiviert. Hole das Backup per SFTP aus storage/backups/ oder aktiviere die Option bewusst.');
        }
        if (!Backup::validName($name) || !is_file(Backup::dir() . '/' . $name)) {
            throw new HttpException(404, 'Backup nicht gefunden.');
        }
        if (!RateLimiter::hit('backup_dl', (string) $admin['id'], 5, 900)) {
            throw new HttpException(429, 'Zu viele Versuche. Bitte warte kurz.');
        }
        if (!password_verify($r->str('password', '', 500), $admin['password_hash'])) {
            Audit::log('backup.download_denied', (int) $admin['id'], 'backup', null, $name);
            return $this->back(null, 'Das Passwort ist falsch.');
        }
        Audit::log('backup.downloaded', (int) $admin['id'], 'backup', null, $name);
        return Response::file(Backup::dir() . '/' . $name, [
            'Content-Type' => 'application/zip',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function delete(Request $r): Response
    {
        $admin = $this->user();
        $name = (string) $r->params['name'];
        if (!Backup::delete($name)) {
            return $this->back(null, 'Backup nicht gefunden.');
        }
        Audit::log('backup.deleted', (int) $admin['id'], 'backup', null, $name);
        return $this->back('Backup gelöscht.');
    }

    public function verify(Request $r): Response
    {
        $this->user();
        $name = (string) $r->params['name'];
        if (!Backup::validName($name) || !is_file(Backup::dir() . '/' . $name)) {
            return $this->back(null, 'Backup nicht gefunden.');
        }
        $res = Backup::verify(Backup::dir() . '/' . $name, $r->str('passphrase', '', 200));
        return $res['ok']
            ? $this->back('Backup in Ordnung: ' . count($res['meta']['tables']) . ' Tabellen, ' . $res['meta']['files'] . ' Dateien, Prüfsumme stimmt.')
            : $this->back(null, 'Prüfung fehlgeschlagen: ' . $res['error']);
    }
}
