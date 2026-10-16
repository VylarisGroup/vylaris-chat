<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Wizards;

/** Assistenten: schrittweise Eingabe, gesendet wird an die bestehenden Formular-Adressen. */
final class AdminWizardController extends Controller
{
    public function index(Request $r): Response
    {
        return $this->view('admin/wizards', [
            'section' => 'wizard',
            'title' => 'Assistenten',
            'admin' => $this->user(),
            'wizards' => Wizards::all(),
        ], 'layouts/main');
    }

    public function show(Request $r): Response
    {
        $name = (string) $r->params['name'];
        $w = Wizards::find($name);
        if ($w === null) {
            return $this->redirect('admin/wizard');
        }
        return $this->view('admin/wizard', [
            'section' => 'wizard',
            'title' => (string) $w['title'],
            'admin' => $this->user(),
            'name' => $name,
            'w' => $w,
        ], 'layouts/main');
    }
}
