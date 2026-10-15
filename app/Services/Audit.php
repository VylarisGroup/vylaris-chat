<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Protokollierung sicherheitsrelevanter Ereignisse. Keine Passwörter, Tokens oder Nachrichteninhalte loggen. */
final class Audit
{
    public static function log(string $action, ?int $userId = null, ?string $targetType = null, ?int $targetId = null, string $details = ''): void
    {
        try {
            Database::exec(
                'INSERT INTO audit_logs (user_id, action, target_type, target_id, details, ip, user_agent, created_at) VALUES (?,?,?,?,?,?,?,?)',
                [
                    $userId,
                    mb_substr($action, 0, 60),
                    $targetType,
                    $targetId,
                    mb_substr($details, 0, 1000),
                    client_ip(),
                    mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
                    now(),
                ]
            );
        } catch (\Throwable $e) {
            log_error('Audit-Log fehlgeschlagen: ' . $e->getMessage());
        }
    }
}
