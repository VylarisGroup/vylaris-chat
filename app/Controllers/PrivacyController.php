<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Core\Response;
use App\Services\Auth;
use App\Services\Settings;

/** Öffentliche Datenschutzerklärung (ohne Anmeldung erreichbar, wie von Art. 19 DSG und Art. 13 DSGVO verlangt). */
final class PrivacyController extends Controller
{
    public function policy(Request $r): Response
    {
        $s = Settings::all();
        return $this->view('privacy/policy', [
            'title' => 'Datenschutzerklärung',
            's' => $s,
            'signedIn' => Auth::user() !== null,
        ], 'layouts/public');
    }
}
