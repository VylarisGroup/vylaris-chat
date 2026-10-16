<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Assistenten für die Administration. Jeder Assistent führt schrittweise durch dieselben Felder wie das klassische
 * Formular und sendet am Ende an dessen bestehende Adresse; die klassischen Seiten bleiben unverändert nutzbar.
 *
 * Feld: name, label, type (text|email|password|number|select|checkbox|radio), value, options, required, help, placeholder,
 * autocomplete, min/max, when (['feld' => 'wert'] – nur anzeigen/prüfen, wenn dieses Feld den Wert hat).
 * Schritt: title, intro, fields. Optional 'actions' ([feld => [wert => Pfad]]) wechselt die Zieladresse je nach Auswahl.
 */
final class Wizards
{
    /** Abschnitt der klassischen Seite → Assistent (für den Knopf «Mit Assistent»). */
    public const FOR_SECTION = [
        'users' => 'user', 'settings' => 'smtp', 'security' => 'sso', 'backup' => 'backup', 'license' => 'license',
    ];

    /** @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        $s = Settings::all();
        $callback = base_url() . '/login/sso/callback';
        $one = static fn (string $v): bool => $v === '1';
        return [
            'user' => [
                'title' => 'Person hinzufügen',
                'summary' => 'Per E-Mail einladen oder ein Konto direkt anlegen.',
                'icon' => 'user-plus',
                'action' => 'admin/users/invite',
                'submit' => 'Person hinzufügen',
                'steps' => [
                    ['title' => 'Wie soll die Person hinzukommen?', 'fields' => [
                        ['name' => 'mode', 'label' => 'Vorgehen', 'type' => 'radio', 'value' => 'invite', 'transient' => true, 'options' => [
                            'invite' => 'Einladung per E-Mail – die Person wählt Namen und Passwort selbst (empfohlen)',
                            'create' => 'Konto direkt anlegen – du legst Namen und optional ein Anfangspasswort fest',
                        ]],
                    ], 'actions' => ['mode' => ['invite' => 'admin/users/invite', 'create' => 'admin/users/create']]],
                    ['title' => 'Wer ist die Person?', 'fields' => [
                        ['name' => 'email', 'label' => 'E-Mail-Adresse', 'type' => 'email', 'required' => true, 'placeholder' => 'name@firma.ch', 'autocomplete' => 'off'],
                        ['name' => 'display_name', 'label' => 'Anzeigename', 'type' => 'text', 'required' => true, 'min' => 2, 'max' => 80, 'when' => ['mode' => 'create']],
                    ]],
                    ['title' => 'Rolle und Zugang', 'fields' => [
                        ['name' => 'role', 'label' => 'Rolle', 'type' => 'select', 'value' => 'member', 'options' => ['member' => 'Mitglied', 'admin' => 'Administrator'],
                            'help' => 'Administratoren verwalten die Installation, haben aber keinen Zugriff auf private Nachrichten.'],
                        ['name' => 'password', 'label' => 'Anfangspasswort (optional)', 'type' => 'password', 'autocomplete' => 'new-password', 'secret' => true, 'when' => ['mode' => 'create'],
                            'help' => 'Leer lassen: Die Person erhält eine E-Mail, um selbst ein Passwort festzulegen.'],
                    ]],
                ],
            ],
            'smtp' => [
                'title' => 'E-Mail-Versand einrichten',
                'summary' => 'SMTP-Server für Einladungen, Bestätigungen und Passwort-Rücksetzung.',
                'icon' => 'envelope',
                'action' => 'admin/settings/smtp',
                'submit' => 'E-Mail-Einstellungen speichern',
                'steps' => [
                    ['title' => 'Server', 'intro' => 'Die Angaben stehen meist in der Anleitung deines E-Mail-Anbieters.', 'fields' => [
                        ['name' => 'smtp_host', 'label' => 'Server', 'type' => 'text', 'value' => $s['smtp_host'], 'required' => true, 'placeholder' => 'smtp.example.com', 'max' => 190],
                        ['name' => 'smtp_port', 'label' => 'Port', 'type' => 'number', 'value' => (string) (int) $s['smtp_port'], 'min' => 1, 'max' => 65535, 'required' => true],
                        ['name' => 'smtp_security', 'label' => 'Verschlüsselung', 'type' => 'select', 'value' => $s['smtp_security'], 'options' => ['tls' => 'STARTTLS (Port 587)', 'ssl' => 'SSL/TLS (Port 465)', 'none' => 'Keine (nicht empfohlen)']],
                    ]],
                    ['title' => 'Anmeldung am Server', 'fields' => [
                        ['name' => 'smtp_user', 'label' => 'Benutzername', 'type' => 'text', 'value' => $s['smtp_user'], 'autocomplete' => 'off', 'max' => 190],
                        ['name' => 'smtp_pass', 'label' => 'Passwort', 'type' => 'password', 'autocomplete' => 'new-password', 'secret' => true, 'max' => 500,
                            'help' => $s['smtp_pass_enc'] !== '' ? 'Ein Passwort ist gespeichert. Leer lassen, um es beizubehalten.' : ''],
                    ]],
                    ['title' => 'Absender', 'fields' => [
                        ['name' => 'smtp_from_email', 'label' => 'Absenderadresse', 'type' => 'email', 'value' => $s['smtp_from_email'], 'required' => true, 'placeholder' => 'messenger@firma.ch', 'max' => 190],
                        ['name' => 'smtp_from_name', 'label' => 'Absendername', 'type' => 'text', 'value' => $s['smtp_from_name'], 'placeholder' => $s['company_name'], 'max' => 100],
                    ]],
                ],
                'after' => 'Danach kannst du unter «Einstellungen» eine Testnachricht senden.',
            ],
            'sso' => [
                'title' => 'Einmalanmeldung (SSO) einrichten',
                'summary' => 'Anmeldung über einen OpenID-Connect-Anbieter wie Microsoft Entra, Keycloak oder VylarisAuth.',
                'icon' => 'log-in-circle',
                'action' => 'admin/security/oidc',
                'submit' => 'Einmalanmeldung speichern',
                'steps' => [
                    ['title' => 'Anbieter', 'intro' => 'Lege beim Anbieter eine Anwendung an und trage dort diese Rückleitungs-Adresse (Redirect-URI) ein: ' . $callback, 'fields' => [
                        ['name' => 'oidc_issuer', 'label' => 'Discovery-/Aussteller-Adresse', 'type' => 'text', 'value' => $s['oidc_issuer'], 'required' => true, 'placeholder' => 'https://login.firma.ch', 'max' => 300,
                            'help' => 'Die Adresse des Anbieters genügt; der Rest wird automatisch ermittelt.'],
                    ]],
                    ['title' => 'Zugangsdaten', 'fields' => [
                        ['name' => 'oidc_client_id', 'label' => 'Client-ID', 'type' => 'text', 'value' => $s['oidc_client_id'], 'required' => true, 'autocomplete' => 'off', 'max' => 300],
                        ['name' => 'oidc_client_secret', 'label' => 'Client-Secret', 'type' => 'password', 'autocomplete' => 'new-password', 'secret' => true, 'max' => 500,
                            'help' => $s['oidc_client_secret_enc'] !== '' ? 'Ein Secret ist gespeichert. Leer lassen, um es beizubehalten.' : ''],
                    ]],
                    ['title' => 'Anmeldung', 'fields' => [
                        ['name' => 'oidc_button', 'label' => 'Beschriftung des Knopfs', 'type' => 'text', 'value' => $s['oidc_button'], 'max' => 60],
                        ['name' => 'oidc_autocreate', 'label' => 'Konten bei der ersten Anmeldung automatisch anlegen', 'type' => 'checkbox', 'value' => $s['oidc_autocreate']],
                        ['name' => 'oidc_domains', 'label' => 'Erlaubte E-Mail-Domänen (optional)', 'type' => 'text', 'value' => $s['oidc_domains'], 'placeholder' => 'firma.ch, firma.com', 'max' => 500],
                    ]],
                    ['title' => 'Aktivieren', 'fields' => [
                        ['name' => 'oidc_auth_method', 'label' => 'Client-Authentifizierung am Token-Endpunkt', 'type' => 'select', 'value' => $s['oidc_auth_method'], 'options' => ['auto' => 'Automatisch (empfohlen)', 'basic' => 'client_secret_basic', 'post' => 'client_secret_post']],
                        ['name' => 'oidc_enabled', 'label' => 'Einmalanmeldung jetzt aktivieren', 'type' => 'checkbox', 'value' => $s['oidc_enabled']],
                    ]],
                ],
            ],
            'backup' => [
                'title' => 'Backup einrichten',
                'summary' => 'Automatische Sicherungen und Aufbewahrung festlegen.',
                'icon' => 'archive',
                'action' => 'admin/backup/settings',
                'submit' => 'Backup-Einstellungen speichern',
                'steps' => [
                    ['title' => 'Rhythmus', 'fields' => [
                        ['name' => 'backup_auto_days', 'label' => 'Automatisch alle … Tage (0 = nur manuell)', 'type' => 'number', 'value' => $s['backup_auto_days'], 'min' => 0, 'max' => 90, 'required' => true],
                        ['name' => 'backup_keep', 'label' => 'Anzahl aufbewahrter Backups', 'type' => 'number', 'value' => $s['backup_keep'], 'min' => 1, 'max' => 60, 'required' => true],
                    ]],
                    ['title' => 'Inhalt und Download', 'fields' => [
                        ['name' => 'backup_include_config', 'label' => 'Konfigurationsdatei (mit Schlüsseln) ins Backup aufnehmen', 'type' => 'checkbox', 'value' => $s['backup_include_config'],
                            'help' => 'Nur mit starker Passphrase und sicher aufbewahrt sinnvoll.'],
                        ['name' => 'backup_web_download', 'label' => 'Download über die Weboberfläche erlauben', 'type' => 'checkbox', 'value' => $s['backup_web_download']],
                    ]],
                ],
            ],
            'license' => [
                'title' => 'Lizenz hinterlegen',
                'summary' => 'Lizenzschlüssel eintragen und sofort prüfen.',
                'icon' => 'key',
                'action' => 'admin/license/save',
                'submit' => 'Lizenz speichern und prüfen',
                'steps' => [
                    ['title' => 'Lizenzschlüssel', 'intro' => 'Ein Schlüssel für die ganze Installation. Jedes aktive Konto belegt einen Platz.', 'fields' => [
                        ['name' => 'license_key', 'label' => 'Lizenzschlüssel', 'type' => 'text', 'required' => true, 'placeholder' => 'XX-XXXX-XXXX-XXXX', 'autocomplete' => 'off', 'max' => 80,
                            'help' => License::key() !== '' ? 'Es ist bereits ein Schlüssel hinterlegt; ein neuer ersetzt ihn.' : ''],
                        ['name' => 'license_product', 'label' => 'Produkt-Kennung', 'type' => 'hidden', 'value' => $s['license_product']],
                    ]],
                ],
            ],
        ];
    }

    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }
}
