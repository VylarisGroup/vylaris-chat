<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Models\User;
use App\Services\Audit;
use App\Services\Auth;
use App\Services\Privacy;
use App\Services\PrivacyReview;
use App\Services\Settings;

/**
 * Administration der Betroffenenrechte (DSG / DSGVO): Anfragen, Verantwortlichen-Angaben, Datenauszug.
 * Administratoren erhalten hier nie Zugriff auf Nachrichteninhalte (siehe Privacy::collect, Scope "metadata").
 */
final class AdminPrivacyController extends Controller
{
    private function page(string $template, array $vars, string $title): Response
    {
        $vars += [
            'section' => 'privacy',
            'title' => $title,
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
        ];
        return $this->view($template, $vars, 'layouts/main');
    }

    private function back(string $path, ?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect($path);
    }

    public function index(Request $r): Response
    {
        $status = $r->str('status', 'active', 20);
        $where = match ($status) {
            'all' => '1=1',
            'closed' => "pr.status IN ('completed','rejected')",
            default => "pr.status IN ('open','in_progress')",
        };
        $requests = Database::all(
            "SELECT pr.*, u.display_name, u.email, h.display_name AS handler
             FROM privacy_requests pr LEFT JOIN users u ON u.id = pr.user_id LEFT JOIN users h ON h.id = pr.handled_by
             WHERE $where ORDER BY (pr.status IN ('open','in_progress')) DESC, pr.due_at ASC LIMIT 200"
        );
        $stats = [
            'open' => Privacy::openCount(),
            'overdue' => (int) Database::value("SELECT COUNT(*) FROM privacy_requests WHERE status IN ('open','in_progress') AND due_at < ?", [now()]),
            'restricted' => (int) Database::value('SELECT COUNT(*) FROM users WHERE processing_restricted_at IS NOT NULL AND deleted_at IS NULL'),
        ];
        $restricted = Database::all('SELECT id, display_name, email, processing_restricted_at, processing_restricted_note FROM users WHERE processing_restricted_at IS NOT NULL AND deleted_at IS NULL ORDER BY processing_restricted_at DESC LIMIT 100');
        $q = trim($r->str('q', '', 80));
        $found = [];
        if ($q !== '') {
            $like = '%' . User::escapeLike($q) . '%';
            $found = Database::all(
                'SELECT id, display_name, email, is_active FROM users WHERE deleted_at IS NULL AND (display_name LIKE ? OR email LIKE ? OR username LIKE ?) ORDER BY display_name LIMIT 20',
                [$like, $like, $like]
            );
        }
        return $this->page('admin/privacy', [
            's' => Settings::all(), 'requests' => $requests, 'status' => $status, 'stats' => $stats,
            'restricted' => $restricted, 'q' => $q, 'found' => $found,
        ], 'Datenschutz');
    }

    private function clean(Request $r, string $key, int $max): string
    {
        return trim(str_replace("\r\n", "\n", $r->str($key, '', $max)));
    }

    public function saveSettings(Request $r): Response
    {
        $admin = $this->user();
        $email = $this->clean($r, 'privacy_contact_email', 190);
        if ($email !== '' && !Auth::validEmail($email)) {
            return $this->back('admin/privacy', null, 'Bitte gib eine gültige Kontakt-E-Mail-Adresse an.');
        }
        $scope = $r->str('privacy_scope', 'both', 10);
        if (!in_array($scope, ['ch', 'eu', 'both'], true)) {
            $scope = 'both';
        }
        Settings::set('privacy_controller', $this->clean($r, 'privacy_controller', 600));
        Settings::set('privacy_contact_email', mb_strtolower($email));
        Settings::set('privacy_dpo', $this->clean($r, 'privacy_dpo', 400));
        Settings::set('privacy_representative', $this->clean($r, 'privacy_representative', 400));
        Settings::set('privacy_hosting', $this->clean($r, 'privacy_hosting', 600));
        Settings::set('privacy_recipients', $this->clean($r, 'privacy_recipients', 1000));
        Settings::set('privacy_authority_eu', $this->clean($r, 'privacy_authority_eu', 400));
        Settings::set('privacy_extra', $this->clean($r, 'privacy_extra', 2000));
        Settings::set('privacy_scope', $scope);
        Settings::set('privacy_updated_at', local_dt(now(), 'd.m.Y'));
        Audit::log('admin.settings_privacy', (int) $admin['id']);
        return $this->back('admin/privacy', 'Angaben zur Datenschutzerklärung gespeichert.');
    }

    public function createRequest(Request $r): Response
    {
        $admin = $this->user();
        $type = $r->str('type', '', 20);
        $message = $this->clean($r, 'message', 2000);
        $who = trim($r->str('subject', '', 190));
        if (!isset(Privacy::TYPES[$type])) {
            return $this->back('admin/privacy', null, 'Bitte wähle die Art der Anfrage.');
        }
        if ($who === '') {
            return $this->back('admin/privacy', null, 'Bitte gib die betroffene Person an (E-Mail-Adresse oder Name).');
        }
        $user = Auth::validEmail(mb_strtolower($who)) ? User::findByEmail(mb_strtolower($who)) : null;
        $uid = $user && $user['deleted_at'] === null ? (int) $user['id'] : null;
        $id = Privacy::createRequest($uid, $uid ? $user['display_name'] : $who, $type, $message, 'admin', (int) $admin['id']);
        return $this->back('admin/privacy', 'Anfrage #' . $id . ' erfasst. Frist: ' . Privacy::DEADLINE_DAYS . ' Tage.');
    }

    public function updateRequest(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        if (!Database::value('SELECT 1 FROM privacy_requests WHERE id = ?', [$id])) {
            throw new HttpException(404, 'Anfrage nicht gefunden.');
        }
        Privacy::updateRequest($id, (int) $admin['id'], $r->str('status', '', 20), $this->clean($r, 'response', 2000));
        return $this->back('admin/privacy', 'Anfrage #' . $id . ' aktualisiert.');
    }

    /** Datenauszug einer Person (nur Konto- und Metadaten, ohne Nachrichteninhalte). */
    public function exportUser(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        $target = User::find($id);
        if (!$target || $target['deleted_at'] !== null) {
            throw new HttpException(404, 'Benutzer nicht gefunden.');
        }
        Audit::log('privacy.admin_export', (int) $admin['id'], 'user', $id, 'metadata');
        return Privacy::download($id, 'metadata');
    }

    // ---------------------------------------------------------- Prüfpaket

    public function review(Request $r): Response
    {
        $set = Settings::all();
        return $this->page('admin/privacy_review', [
            'check' => PrivacyReview::checklist($set),
            'register' => PrivacyReview::register($set),
            'record' => PrivacyReview::record($set),
            'fingerprint' => PrivacyReview::fingerprint($set),
            's' => $set,
        ], 'Prüfpaket Datenschutz');
    }

    public function saveChecklist(Request $r): Response
    {
        $admin = $this->user();
        $old = json_decode((string) Settings::get('review_checklist'), true);
        $old = is_array($old) ? $old : [];
        $new = [];
        $checked = (array) ($r->input('items') ?? []);
        foreach (array_keys(PrivacyReview::MANUAL) as $id) {
            if (in_array($id, $checked, true)) {
                $new[$id] = $old[$id] ?? ['by' => (string) $admin['display_name'], 'at' => gmdate('Y-m-d')];
            }
        }
        Settings::set('review_checklist', json_encode($new));
        Audit::log('admin.privacy_checklist', (int) $admin['id']);
        return $this->back('admin/privacy/review', 'Checkliste gespeichert.');
    }

    public function saveRecord(Request $r): Response
    {
        $admin = $this->user();
        $reviewer = $this->clean($r, 'reviewer', 200);
        $date = $r->str('date', '', 10);
        if ($reviewer === '' || !preg_match('/^\d{4}-\d\d-\d\d$/', $date)) {
            return $this->back('admin/privacy/review', null, 'Bitte gib die prüfende Stelle und das Datum an.');
        }
        Settings::set('review_record', json_encode([
            'reviewer' => $reviewer,
            'date' => $date,
            'notes' => $this->clean($r, 'notes', 2000),
            'fingerprint' => PrivacyReview::fingerprint(Settings::all()),
            'recorded_by' => (string) $admin['display_name'],
            'recorded_at' => gmdate('Y-m-d'),
        ], JSON_UNESCAPED_UNICODE));
        Audit::log('admin.privacy_review_record', (int) $admin['id']);
        return $this->back('admin/privacy/review', 'Prüfvermerk gespeichert.');
    }

    public function registerDownload(Request $r): Response
    {
        $admin = $this->user();
        Audit::log('admin.privacy_register_download', (int) $admin['id']);
        return new Response(200, PrivacyReview::registerMarkdown(Settings::all()), [
            'Content-Type' => 'text/markdown; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="verzeichnis-bearbeitungstaetigkeiten.md"',
            'Cache-Control' => 'no-store',
        ]);
    }

    // ------------------------------------------- Register der Datenschutzverletzungen

    public function breaches(Request $r): Response
    {
        $rows = Database::all('SELECT * FROM data_breaches ORDER BY status ASC, discovered_at DESC LIMIT 200');
        return $this->page('admin/breaches', ['rows' => $rows], 'Datenschutzverletzungen');
    }

    private function dt(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || !preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d$/', $v)) {
            return null;
        }
        $tz = new \DateTimeZone((string) \App\Core\Config::get('app.timezone', 'Europe/Zurich'));
        return (new \DateTimeImmutable(str_replace('T', ' ', $v) . ':00', $tz))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    public function createBreach(Request $r): Response
    {
        $admin = $this->user();
        $found = $this->dt($r->str('discovered_at', '', 16));
        $desc = $this->clean($r, 'description', 3000);
        if ($found === null || mb_strlen($desc) < 10) {
            return $this->back('admin/privacy/breaches', null, 'Bitte Zeitpunkt der Kenntnisnahme und eine Beschreibung (mind. 10 Zeichen) angeben.');
        }
        $risk = in_array($r->str('risk'), ['low', 'medium', 'high'], true) ? $r->str('risk') : 'low';
        $count = $r->str('affected_count', '', 9);
        $id = Database::insert(
            'INSERT INTO data_breaches (discovered_at, occurred_at, description, data_categories, affected_count, risk, measures, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
            [$found, $this->dt($r->str('occurred_at', '', 16)), $desc, $this->clean($r, 'data_categories', 500), ctype_digit($count) ? (int) $count : null, $risk, $this->clean($r, 'measures', 3000), (int) $admin['id'], now(), now()]
        );
        Audit::log('privacy.breach_created', (int) $admin['id'], 'breach', $id, $risk);
        return $this->back('admin/privacy/breaches', 'Datenschutzverletzung erfasst. Prüfe die Meldepflicht (Frist: 72 Stunden ab Kenntnis).');
    }

    public function updateBreach(Request $r): Response
    {
        $admin = $this->user();
        $id = (int) $r->params['id'];
        if (!Database::one('SELECT id FROM data_breaches WHERE id = ?', [$id])) {
            throw new HttpException(404, 'Eintrag nicht gefunden.');
        }
        $risk = in_array($r->str('risk'), ['low', 'medium', 'high'], true) ? $r->str('risk') : 'low';
        Database::exec(
            'UPDATE data_breaches SET risk = ?, measures = ?, authority_notified_at = ?, authority_reason = ?, individuals_notified_at = ?, status = ?, updated_at = ? WHERE id = ?',
            [$risk, $this->clean($r, 'measures', 3000), $this->dt($r->str('authority_notified_at', '', 16)), $this->clean($r, 'authority_reason', 500), $this->dt($r->str('individuals_notified_at', '', 16)), $r->str('status') === 'closed' ? 'closed' : 'open', now(), $id]
        );
        Audit::log('privacy.breach_updated', (int) $admin['id'], 'breach', $id);
        return $this->back('admin/privacy/breaches', 'Eintrag gespeichert.');
    }

    /** Offene Verletzungen mit Risiko, bei denen die 72-Stunden-Frist ohne Meldung abgelaufen ist oder bald abläuft. */
    public static function breachesDue(): int
    {
        return (int) Database::value("SELECT COUNT(*) FROM data_breaches WHERE status = 'open' AND risk <> 'low' AND authority_notified_at IS NULL AND (authority_reason IS NULL OR authority_reason = '')");
    }
}
