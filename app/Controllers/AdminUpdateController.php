<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Audit;
use App\Services\Crypto;
use App\Services\Settings;
use App\Services\Updater;

/** Aktualisierung aus einem GitHub-Repository (Branch). */
final class AdminUpdateController extends Controller
{
    private function back(?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect('admin/update');
    }

    public function index(Request $r): Response
    {
        return $this->view('admin/update', [
            'section' => 'update',
            'title' => 'Aktualisierung',
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
            's' => Settings::all(),
            'st' => Updater::state(),
            'pre' => Updater::prerequisites(),
            'backups' => Updater::codeBackups(),
            'hasToken' => Settings::get('update_token_enc') !== '',
            'version' => APP_VERSION,
            'migrated' => $this->schemaCurrent(),
        ], 'layouts/main');
    }

    private function schemaCurrent(): bool
    {
        $avail = \App\Core\Migrator::available();
        return $avail === [] || @file_get_contents(storage_path('cache/schema.version')) === end($avail);
    }

    public function save(Request $r): Response
    {
        $admin = $this->user();
        $in = $r->str('update_repo', '', 200);
        $repo = $in === '' ? '' : Updater::parseRepo($in);
        if ($repo === null) {
            return $this->back(null, 'Bitte «Besitzer/Repository» oder die GitHub-Adresse angeben, z. B. meine-firma/messenger.');
        }
        $channel = $r->str('update_channel') === 'branch' ? 'branch' : 'release';
        $branch = $r->str('update_branch', 'main', 100);
        if ($branch === '' || !preg_match('#^[A-Za-z0-9._/-]{1,100}$#', $branch) || str_contains($branch, '..')) {
            return $this->back(null, 'Der Branch-Name ist ungültig.');
        }
        $vis = $r->str('update_visibility') === 'private' ? 'private' : 'public';
        Settings::set('update_repo', $repo);
        Settings::set('update_channel', $channel);
        Settings::set('update_branch', $branch);
        Settings::set('update_visibility', $vis);
        $token = $r->str('update_token', '', 300);
        if ($vis === 'public') {
            Settings::set('update_token_enc', '');
        } elseif ($token !== '') {
            Settings::set('update_token_enc', Crypto::encrypt($token));
        }
        @unlink(storage_path('cache/update.json'));
        Audit::log('admin.update_settings', (int) $admin['id'], null, null, $repo . ($channel === 'branch' ? '@' . $branch : ' (Releases)') . ' (' . $vis . ')');
        if ($vis === 'private' && Settings::get('update_token_enc') === '') {
            return $this->back('Einstellungen gespeichert.', 'Für ein privates Repository fehlt noch das Zugriffstoken.');
        }
        return $this->check($r, 'Einstellungen gespeichert. ');
    }

    public function check(Request $r, string $prefix = ''): Response
    {
        $st = Updater::check();
        if ($st['error'] !== '') {
            return $this->back(null, trim($prefix . 'Prüfung fehlgeschlagen: ' . $st['error']));
        }
        return $this->back($prefix . ($st['available'] ? 'Ein Update ist verfügbar.' : 'Die Installation ist auf dem neuesten Stand.'));
    }

    public function install(Request $r): Response
    {
        $admin = $this->user();
        if ($r->str('confirm') !== '1') {
            return $this->back(null, 'Bitte bestätige das Update.');
        }
        $res = Updater::install($r->str('backup') === '1');
        if (!$res['ok']) {
            return $this->back(null, 'Update fehlgeschlagen: ' . ($res['error'] ?? ''));
        }
        if (($res['files'] ?? 0) === 0) {
            return $this->back('Es gab nichts zu aktualisieren.');
        }
        Audit::log('admin.update_installed', (int) $admin['id'], null, null, substr((string) $res['to'], 0, 7));
        return $this->back('Update installiert (' . ((Updater::state()['installed_tag'] !== '') ? Updater::state()['installed_tag'] : substr((string) $res['to'], 0, 7)) . ', ' . $res['files'] . ' Dateien' . ($res['removed'] ? ', ' . $res['removed'] . ' entfernt' : '') . '). Neue Datenbank-Migrationen werden beim nächsten Seitenaufruf automatisch angewendet.');
    }

    /** Den heutigen Stand des Branches als «installiert» übernehmen, ohne Dateien zu ändern. */
    public function baseline(Request $r): Response
    {
        $st = Updater::state();
        if ($st['latest'] === '') {
            return $this->back(null, 'Bitte zuerst prüfen.');
        }
        Settings::set('update_installed_sha', $st['latest']);
        Settings::set('update_installed_tag', $st['channel'] === 'release' ? $st['tag'] : '');
        Settings::set('update_installed_at', now());
        Settings::set('update_installed_msg', $st['latest_msg']);
        Audit::log('admin.update_baseline', (int) $this->user()['id'], null, null, substr($st['latest'], 0, 7));
        return $this->back('Der aktuelle Stand wurde als installiert vermerkt.');
    }

    public function rollback(Request $r): Response
    {
        if ($r->str('confirm') !== '1') {
            return $this->back(null, 'Bitte bestätige den Rückbau.');
        }
        $res = Updater::rollback();
        return $res['ok']
            ? $this->back('Code zurückgesetzt (' . $res['restored'] . ' Dateien). Die Datenbank wurde nicht verändert.')
            : $this->back(null, 'Rückbau fehlgeschlagen: ' . ($res['error'] ?? ''));
    }
}
