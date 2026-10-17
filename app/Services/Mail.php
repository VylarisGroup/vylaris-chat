<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

/** SMTP-Versand über PHPMailer (TLS-Zertifikate werden geprüft). */
final class Mail
{
    public static function configured(): bool
    {
        return Settings::get('smtp_host') !== '' && Settings::get('smtp_from_email') !== '';
    }

    /** SMTP-Konfiguration aus den Systemeinstellungen. */
    public static function currentConfig(): array
    {
        return [
            'host' => Settings::get('smtp_host'),
            'port' => (int) Settings::get('smtp_port', '587'),
            'security' => Settings::get('smtp_security', 'tls'),
            'user' => Settings::get('smtp_user'),
            'pass' => Crypto::decrypt(Settings::get('smtp_pass_enc')),
            'from_email' => Settings::get('smtp_from_email'),
            'from_name' => Settings::get('smtp_from_name') ?: Settings::get('company_name'),
        ];
    }

    private static function build(array $cfg): PHPMailer
    {
        $m = new PHPMailer(true);
        $m->isSMTP();
        $m->Host = $cfg['host'];
        $m->Port = (int) $cfg['port'];
        $m->Timeout = 12;
        $m->CharSet = 'UTF-8';
        $m->SMTPAutoTLS = ($cfg['security'] !== 'none');
        if ($cfg['security'] === 'ssl') {
            $m->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($cfg['security'] === 'tls') {
            $m->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $m->SMTPSecure = '';
        }
        if ($cfg['user'] !== '') {
            $m->SMTPAuth = true;
            $m->Username = $cfg['user'];
            $m->Password = $cfg['pass'];
        }
        $m->setFrom($cfg['from_email'], str_replace(["\r", "\n"], '', (string) $cfg['from_name']));
        return $m;
    }

    /** Verbindungstest ohne Mailversand. @return array{ok:bool,error:string} */
    public static function testConnection(array $cfg): array
    {
        try {
            $m = self::build($cfg);
            if (!$m->smtpConnect()) {
                return ['ok' => false, 'error' => 'Verbindung fehlgeschlagen.'];
            }
            $m->smtpClose();
            return ['ok' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::safeError($e)];
        }
    }

    /** Testmail mit der übergebenen Konfiguration. @return array{ok:bool,error:string} */
    public static function sendTest(array $cfg, string $to): array
    {
        return self::deliver($cfg, $to, '', 'Testnachricht', self::wrap('Testnachricht', '<p>Diese Testnachricht bestätigt, dass der SMTP-Versand funktioniert.</p>'), "Diese Testnachricht bestätigt, dass der SMTP-Versand funktioniert.");
    }

    /** Sendet mit den gespeicherten Einstellungen. Fehler werden geloggt, nie an den Benutzer durchgereicht. */
    public static function send(string $to, string $toName, string $subject, string $title, string $htmlBody, string $textBody): bool
    {
        if (!self::configured() || str_ends_with(strtolower($to), '.invalid')) { // .invalid: reserviert für Testadressen (Selbsttest)
            return false;
        }
        $res = self::deliver(self::currentConfig(), $to, $toName, $subject, self::wrap($title, $htmlBody), $textBody);
        if (!$res['ok']) {
            log_error('Mailversand fehlgeschlagen: ' . $res['error']);
        }
        return $res['ok'];
    }

    private static function deliver(array $cfg, string $to, string $toName, string $subject, string $html, string $text): array
    {
        try {
            $m = self::build($cfg);
            $m->addAddress($to, str_replace(["\r", "\n"], '', $toName));
            $m->Subject = str_replace(["\r", "\n"], ' ', $subject);
            $m->isHTML(true);
            $m->Body = $html;
            $m->AltBody = $text;
            $m->send();
            return ['ok' => true, 'error' => ''];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => self::safeError($e)];
        }
    }

    /** Fehlertext ohne Zugangsdaten. */
    private static function safeError(\Throwable $e): string
    {
        $msg = $e instanceof MailException ? $e->getMessage() : 'Unerwarteter Fehler beim Mailversand.';
        return mb_substr(preg_replace('/\s+/', ' ', $msg) ?? '', 0, 300);
    }

    public static function wrap(string $title, string $bodyHtml): string
    {
        $company = e(Settings::get('company_name'));
        $accent = e(Settings::get('accent_color'));
        return '<!doctype html><html lang="de"><body style="margin:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;color:#1f2430">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:32px 12px">'
            . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;background:#fff;border-radius:14px;overflow:hidden">'
            . '<tr><td style="background:' . $accent . ';color:#fff;padding:20px 28px;font-size:18px;font-weight:bold">' . $company . '</td></tr>'
            . '<tr><td style="padding:28px;font-size:15px;line-height:1.55"><h1 style="font-size:20px;margin:0 0 16px">' . e($title) . '</h1>' . $bodyHtml . '</td></tr>'
            . '<tr><td style="padding:16px 28px;font-size:12px;color:#7a8190;background:#fafbfc">Diese Nachricht wurde automatisch von ' . $company . ' versendet.</td></tr>'
            . '</table></td></tr></table></body></html>';
    }

    public static function button(string $url, string $label): string
    {
        return '<p style="margin:24px 0"><a href="' . e($url) . '" style="background:' . e(Settings::get('accent_color')) . ';color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;display:inline-block;font-weight:bold">' . e($label) . '</a></p>'
            . '<p style="font-size:12px;color:#7a8190;word-break:break-all">Falls der Button nicht funktioniert: ' . e($url) . '</p>';
    }
}
