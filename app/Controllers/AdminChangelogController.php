<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Changelog;

final class AdminChangelogController extends Controller
{
    public function index(Request $r): Response
    {
        return $this->view('admin/changelog', [
            'section' => 'changelog',
            'title' => 'Änderungen',
            'admin' => $this->user(),
            'entries' => Changelog::entries(),
            'version' => APP_VERSION,
        ], 'layouts/main');
    }

    public function seen(Request $r): Response
    {
        Changelog::markSeen((int) $this->user()['id']);
        $back = $r->str('back', 'admin', 120);
        return $this->redirect(preg_match('#^admin(/[a-z/-]*)?$#', $back) === 1 ? $back : 'admin');
    }
}
