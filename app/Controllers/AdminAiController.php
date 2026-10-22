<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\Ai;
use App\Services\Audit;
use App\Services\Crypto;
use App\Services\Settings;

/** KI-Assistent: OpenAI-kompatibler Endpunkt, Modell, Systemprompt und Zugriff. */
final class AdminAiController extends Controller
{
    private function back(?string $notice = null, ?string $error = null): Response
    {
        if ($notice !== null) {
            Session::flash('notice', $notice);
        }
        if ($error !== null) {
            Session::flash('error', $error);
        }
        return $this->redirect('admin/ai');
    }

    public function index(Request $r): Response
    {
        $key = Ai::key();
        $botId = Ai::botId();
        return $this->view('admin/ai', [
            'section' => 'ai',
            'title' => 'KI-Assistent',
            'notice' => Session::flash('notice'),
            'error' => Session::flash('error'),
            'admin' => $this->user(),
            'enabled' => Ai::enabled(),
            'wanted' => Settings::bool('ai_enabled'),
            'name' => Settings::get('ai_name'),
            'endpoint' => Settings::get('ai_endpoint'),
            'hasKey' => $key !== '',
            'keyHint' => $key !== '' ? substr($key, 0, 4) . '…' . substr($key, -3) : '',
            'model' => Settings::get('ai_model'),
            'prompt' => Settings::get('ai_system_prompt'),
            'temperature' => Settings::get('ai_temperature'),
            'ctx' => Ai::maxContextTokens(),
            'maxOut' => (int) Settings::get('ai_max_output_tokens', '1024'),
            'timeout' => (int) Settings::get('ai_timeout', '45'),
            'access' => Settings::get('ai_access', 'all'),
            'allowed' => Settings::get('ai_allowed_users'),
            'rate' => (int) Settings::get('ai_rate_per_hour', '40'),
            'username' => $botId ? (string) Database::value('SELECT username FROM users WHERE id = ?', [$botId]) : 'ki',
            'chats' => $botId ? (int) Database::value('SELECT COUNT(*) FROM conversation_members WHERE user_id = ? AND left_at IS NULL', [$botId]) : 0,
            'answers' => $botId ? (int) Database::value('SELECT COUNT(*) FROM messages WHERE sender_id = ?', [$botId]) : 0,
        ], 'layouts/main');
    }

    public function save(Request $r): Response
    {
        $admin = $this->user();
        $endpoint = trim($r->str('endpoint', '', 300));
        if ($endpoint !== '') {
            $p = parse_url($endpoint);
            if (!$p || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || empty($p['host'])) {
                return $this->back(null, 'Die Adresse des Endpunkts ist ungültig (z. B. https://api.openai.com/v1).');
            }
        }
        $model = trim($r->str('model', '', 120));
        $temp = trim($r->str('temperature', '', 6));
        if ($temp !== '' && (!is_numeric($temp) || (float) $temp < 0 || (float) $temp > 2)) {
            return $this->back(null, 'Die Temperatur muss zwischen 0 und 2 liegen (oder leer bleiben).');
        }
        $access = $r->str('access', 'all', 10);
        if (!in_array($access, ['all', 'admins', 'list'], true)) {
            $access = 'all';
        }
        $key = trim($r->str('api_key', '', 300));
        if ($key !== '') {
            Settings::set('ai_key_enc', Crypto::encrypt($key));
        }
        if ($r->bool('remove_key')) {
            Settings::set('ai_key_enc', '');
        }
        Settings::set('ai_endpoint', $endpoint);
        Settings::set('ai_model', $model);
        Settings::set('ai_name', mb_substr(trim($r->str('name', 'KI-Assistent', 80)) ?: 'KI-Assistent', 0, 80));
        Settings::set('ai_system_prompt', mb_substr(trim($r->str('prompt', '', 4000)), 0, 4000));
        Settings::set('ai_temperature', $temp);
        Settings::set('ai_max_context_tokens', (string) max(500, min(Ai::HARD_CONTEXT_LIMIT, (int) $r->str('ctx', '30000', 7))));
        Settings::set('ai_max_output_tokens', (string) max(64, min(16000, (int) $r->str('max_out', '1024', 6))));
        Settings::set('ai_timeout', (string) max(10, min(120, (int) $r->str('timeout', '45', 4))));
        Settings::set('ai_rate_per_hour', (string) max(1, min(1000, (int) $r->str('rate', '40', 5))));
        Settings::set('ai_access', $access);
        Settings::set('ai_allowed_users', mb_substr(trim($r->str('allowed', '', 1000)), 0, 1000));
        $want = $r->bool('enabled');
        if ($want && ($endpoint === '' || $model === '')) {
            Settings::set('ai_enabled', '0');
            return $this->back(null, 'Zum Aktivieren bitte Endpunkt und Modell angeben.');
        }
        Settings::set('ai_enabled', $want ? '1' : '0');
        if ($want) {
            Ai::ensureBot();
        } elseif (Ai::botId()) {
            Ai::ensureBot(); // Anzeigename aktuell halten
        }
        Audit::log('admin.ai_settings', (int) $admin['id'], null, null, json_encode(['enabled' => $want, 'model' => $model, 'access' => $access, 'key_changed' => $key !== '']));
        return $this->back('Einstellungen gespeichert.');
    }

    public function test(Request $r): Response
    {
        if (Ai::url() === null || trim(Settings::get('ai_model')) === '') {
            return $this->back(null, 'Bitte zuerst Endpunkt und Modell speichern.');
        }
        $res = Ai::test();
        if (!$res['ok']) {
            return $this->back(null, 'Verbindungstest fehlgeschlagen: ' . $res['error']);
        }
        return $this->back('Verbindung erfolgreich. Antwort der KI: «' . $res['text'] . '»');
    }
}
