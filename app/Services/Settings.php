<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/** Systemweite Einstellungen (Tabelle system_settings) mit Standardwerten. */
final class Settings
{
    public const DEFAULTS = [
        'company_name' => 'Unternehmens-Messenger',
        'accent_color' => '#2f6fed',
        'logo_file' => '',
        'registration_enabled' => '0',
        'registration_domains' => '',
        'require_email_verification' => '1',
        'max_upload_mb' => '25',
        'retention_days_messages' => '0',
        'retention_days_audit' => '365',
        'retention_days_inactive_orphan_uploads' => '2',
        'message_edit_minutes' => '0',
        'email_notifications_enabled' => '1',
        'smtp_host' => '',
        'smtp_port' => '587',
        'smtp_security' => 'tls',
        'smtp_user' => '',
        'smtp_pass_enc' => '',
        'smtp_from_email' => '',
        'smtp_from_name' => '',
        'privacy_controller' => '',
        'privacy_contact_email' => '',
        'privacy_dpo' => '',
        'privacy_representative' => '',
        'privacy_hosting' => '',
        'privacy_recipients' => '',
        'privacy_scope' => 'both',
        'privacy_authority_eu' => '',
        'privacy_extra' => '',
        'privacy_updated_at' => '',
        'selftest_last' => '',
        'require_2fa_admins' => '0',
        'password_max_age_days' => '0',
        'lockout_attempts' => '8',
        'lockout_minutes' => '15',
        'realtime_mode' => 'poll',
        'push_enabled' => '0',
        'vapid_public' => '',
        'vapid_private_enc' => '',
        'backup_auto_days' => '0',
        'backup_keep' => '7',
        'backup_include_config' => '0',
        'backup_last_at' => '0',
        'backup_web_download' => '0',
        'monitor_email' => '',
        'monitor_threshold' => '10',
        'monitor_window_min' => '10',
        'monitor_last_alert' => '0',
        'update_installed_sha' => '',
        'update_installed_tag' => '',
        'update_installed_at' => '',
        'update_installed_msg' => '',
        'license_key_enc' => '',
        'license_product' => '',
        'license_grace_hours' => '168',
        'oidc_enabled' => '0',
        'oidc_issuer' => '',
        'oidc_client_id' => '',
        'oidc_client_secret_enc' => '',
        'oidc_button' => 'Mit Firmenkonto anmelden',
        'oidc_autocreate' => '0',
        'oidc_domains' => '',
        'oidc_auth_method' => 'auto',
        'review_checklist' => '',
        'review_record' => '',
        'last_cleanup_at' => '0',
    ];

    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Database::all('SELECT k, v FROM system_settings') as $row) {
                    self::$cache[$row['k']] = $row['v'];
                }
            } catch (\Throwable) {
                // Tabelle existiert noch nicht (z. B. während der Installation)
            }
        }
        return self::$cache;
    }

    public static function get(string $key, ?string $default = null): string
    {
        $all = self::all();
        return (string) ($all[$key] ?? $default ?? '');
    }

    public static function int(string $key): int
    {
        return (int) self::get($key);
    }

    public static function bool(string $key): bool
    {
        return self::get($key) === '1';
    }

    public static function set(string $key, string $value): void
    {
        Database::exec(
            'INSERT INTO system_settings (k, v, updated_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at)',
            [$key, $value, now()]
        );
        if (self::$cache !== null) {
            self::$cache[$key] = $value;
        }
    }

    /** Öffentliche Branding-Daten (ohne Geheimnisse). */
    public static function branding(): array
    {
        return [
            'company_name' => self::get('company_name'),
            'accent_color' => self::get('accent_color'),
            'has_logo' => self::get('logo_file') !== '',
        ];
    }

    /** Akzentfarbe "#rrggbb" in "r g b" für CSS-Variablen. */
    public static function accentRgb(): string
    {
        $hex = ltrim(self::get('accent_color'), '#');
        if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
            $hex = '2f6fed';
        }
        return hexdec(substr($hex, 0, 2)) . ' ' . hexdec(substr($hex, 2, 2)) . ' ' . hexdec(substr($hex, 4, 2));
    }
}
