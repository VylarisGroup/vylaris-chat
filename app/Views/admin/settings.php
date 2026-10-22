<?php use App\Core\Csrf;
$chk = static fn (string $k): string => ($s[$k] ?? '0') === '1' ? 'checked' : '';
$box = 'h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent';
?>
<div class="space-y-6">

<section class="card p-5 sm:p-6" aria-labelledby="h-org">
  <h2 id="h-org" class="mb-4 text-base font-semibold">Organisation und Registrierung</h2>
  <form method="post" action="<?= e(url('admin/settings/organization')) ?>" enctype="multipart/form-data" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div><label class="label" for="o-name">Firmenname</label><input id="o-name" name="company_name" class="input" required minlength="2" maxlength="60" value="<?= e($s['company_name']) ?>"></div>
    <div><label class="label" for="o-accent">Akzentfarbe</label>
      <div class="flex items-center gap-3"><input id="o-accent" name="accent_color" type="color" class="h-11 w-16 cursor-pointer rounded-lg border border-slate-300 bg-white p-1 dark:border-neutral-700 dark:bg-neutral-800" value="<?= e($s['accent_color']) ?>"><span class="text-xs text-slate-500 dark:text-neutral-400">Für Schaltflächen und Hervorhebungen. Muss mit weisser Schrift gut lesbar sein.</span></div></div>
    <div class="sm:col-span-2"><label class="label" for="o-logo">Logo</label>
      <div class="flex flex-wrap items-center gap-4">
        <?php if ($s['logo_file'] !== ''): ?><img src="<?= e(url('branding/logo')) ?>?v=<?= e(substr(md5($s['logo_file']), 0, 8)) ?>" alt="Aktuelles Logo" class="h-12 w-auto max-w-[160px] rounded-lg border border-slate-200 bg-white object-contain p-1 dark:border-neutral-700"><?php endif; ?>
        <input id="o-logo" name="logo" type="file" accept="image/png,image/jpeg,image/gif,image/webp" class="block text-sm file:mr-3 file:rounded-lg file:border-0 file:bg-slate-100 file:px-3 file:py-2 file:text-sm file:font-medium dark:file:bg-neutral-800">
        <?php if ($s['logo_file'] !== ''): ?><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remove_logo" value="1" class="<?= $box ?>"> Logo entfernen</label><?php endif; ?>
      </div></div>
    <div class="sm:col-span-2 space-y-3 border-t border-slate-200 pt-4 dark:border-neutral-800">
      <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="registration_enabled" value="1" <?= $chk('registration_enabled') ?> class="<?= $box ?> mt-0.5"><span><span class="font-medium">Selbstregistrierung erlauben</span><span class="block text-xs text-slate-500 dark:text-neutral-400">Aus (empfohlen): Konten entstehen nur durch Einladung oder durch Administratoren.</span></span></label>
      <div><label class="label" for="o-dom">Erlaubte E-Mail-Domains bei Selbstregistrierung</label><input id="o-dom" name="registration_domains" class="input" placeholder="firma.ch firma.com" value="<?= e($s['registration_domains']) ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Leer = jede Domain. Mehrere Domains mit Leerzeichen trennen.</p></div>
      <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="require_email_verification" value="1" <?= $chk('require_email_verification') ?> class="<?= $box ?> mt-0.5"><span><span class="font-medium">E-Mail-Bestätigung verlangen</span><span class="block text-xs text-slate-500 dark:text-neutral-400">Selbst registrierte Konten müssen die E-Mail-Adresse bestätigen. Erfordert funktionierenden SMTP-Versand.</span></span></label>
    </div>
    <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-sys">
  <h2 id="h-sys" class="mb-4 text-base font-semibold">System, Aufbewahrung und Benachrichtigungen</h2>
  <form method="post" action="<?= e(url('admin/settings/system')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div><label class="label" for="s-up">Maximale Dateigrösse (MB)</label><input id="s-up" name="max_upload_mb" type="number" min="1" max="2048" class="input" value="<?= (int) $s['max_upload_mb'] ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Der Server erlaubt aktuell höchstens <?= e(human_size($phpUpload)) ?> (PHP-Limit).</p></div>
    <div><label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" name="voice_enabled" value="1" <?= ($s['voice_enabled'] ?? '1') === '1' ? 'checked' : '' ?> class="h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Sprachnachrichten erlauben</span></label><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Mikrofon-Knopf im Eingabefeld. Aufnahmen werden wie Dateien gespeichert und mit den Nachrichten aufbewahrt.</p></div>
    <div><label class="label" for="s-vm">Maximale Dauer einer Sprachnachricht (Sekunden)</label><input id="s-vm" name="voice_max_seconds" type="number" min="5" max="1800" class="input" value="<?= (int) ($s['voice_max_seconds'] ?? 300) ?>"></div>
    <div><label class="label" for="s-edit">Bearbeitungsfrist für Nachrichten (Minuten)</label><input id="s-edit" name="message_edit_minutes" type="number" min="0" max="10080" class="input" value="<?= (int) $s['message_edit_minutes'] ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">0 = unbegrenzt.</p></div>
    <div><label class="label" for="s-rm">Nachrichten aufbewahren (Tage)</label><input id="s-rm" name="retention_days_messages" type="number" min="0" max="3650" class="input" value="<?= (int) $s['retention_days_messages'] ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">0 = unbegrenzt. Ältere Nachrichten und Dateien werden endgültig gelöscht.</p></div>
    <div><label class="label" for="s-ra">Protokoll aufbewahren (Tage)</label><input id="s-ra" name="retention_days_audit" type="number" min="0" max="3650" class="input" value="<?= (int) $s['retention_days_audit'] ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">0 = unbegrenzt.</p></div>
    <div><label class="label" for="s-ro">Nicht gesendete Uploads löschen nach (Tagen)</label><input id="s-ro" name="retention_days_inactive_orphan_uploads" type="number" min="1" max="30" class="input" value="<?= (int) $s['retention_days_inactive_orphan_uploads'] ?>"></div>
    <div class="flex items-end"><label class="flex items-start gap-3 text-sm"><input type="checkbox" name="email_notifications_enabled" value="1" <?= $chk('email_notifications_enabled') ?> class="<?= $box ?> mt-0.5"><span><span class="font-medium">E-Mail-Benachrichtigungen erlauben</span><span class="block text-xs text-slate-500 dark:text-neutral-400">Gesamtschalter. Jede Person wählt zusätzlich selbst, was sie erhält.</span></span></label></div>
    <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-smtp">
  <h2 id="h-smtp" class="mb-1 text-base font-semibold">E-Mail-Versand (SMTP)</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Benötigt für Einladungen, Passwort-Zurücksetzen, E-Mail-Bestätigung und Benachrichtigungen. E-Mails enthalten nie Nachrichteninhalte. Das Passwort wird verschlüsselt gespeichert.</p>
  <form method="post" action="<?= e(url('admin/settings/smtp')) ?>" class="grid gap-4 sm:grid-cols-2" autocomplete="off">
    <?= Csrf::field() ?>
    <div><label class="label" for="m-host">Server</label><input id="m-host" name="smtp_host" class="input" placeholder="smtp.example.com" maxlength="190" value="<?= e($s['smtp_host']) ?>"></div>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="label" for="m-port">Port</label><input id="m-port" name="smtp_port" type="number" min="1" max="65535" class="input" value="<?= (int) $s['smtp_port'] ?>"></div>
      <div><label class="label" for="m-sec">Verschlüsselung</label><select id="m-sec" name="smtp_security" class="input"><?php foreach (['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'Keine (nicht empfohlen)'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $s['smtp_security'] === $k ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select></div>
    </div>
    <div><label class="label" for="m-user">Benutzername</label><input id="m-user" name="smtp_user" class="input" maxlength="190" autocomplete="off" value="<?= e($s['smtp_user']) ?>"></div>
    <div><label class="label" for="m-pass">Passwort</label><input id="m-pass" name="smtp_pass" type="password" class="input" autocomplete="new-password" maxlength="500" placeholder="<?= $smtpPassSet ? '•••••••• (gespeichert, leer lassen zum Beibehalten)' : '' ?>">
      <?php if ($smtpPassSet): ?><label class="mt-2 flex items-center gap-2 text-xs"><input type="checkbox" name="smtp_pass_clear" value="1" class="<?= $box ?>"> Gespeichertes Passwort löschen</label><?php endif; ?></div>
    <div><label class="label" for="m-from">Absenderadresse</label><input id="m-from" name="smtp_from_email" type="email" class="input" placeholder="messenger@firma.ch" maxlength="190" value="<?= e($s['smtp_from_email']) ?>"></div>
    <div><label class="label" for="m-fn">Absendername</label><input id="m-fn" name="smtp_from_name" class="input" maxlength="100" placeholder="<?= e($s['company_name']) ?>" value="<?= e($s['smtp_from_name']) ?>"></div>
    <div class="sm:col-span-2 flex flex-wrap items-end gap-3 border-t border-slate-200 pt-4 dark:border-neutral-800">
      <button class="btn btn-primary" type="submit">Speichern</button>
      <div class="flex flex-1 flex-wrap items-end gap-2 sm:justify-end">
        <div><label class="label !mb-1" for="m-to">Testempfänger (optional)</label><input id="m-to" name="test_to" type="email" class="input sm:w-64" placeholder="leer = nur Verbindungstest" maxlength="190"></div>
        <button class="btn btn-secondary" type="submit" formaction="<?= e(url('admin/settings/smtp-test')) ?>"><i class="bx bx-send text-lg" aria-hidden="true"></i>Testen</button>
      </div>
    </div>
  </form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-maint">
  <h2 id="h-maint" class="mb-1 text-base font-semibold">Wartung</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Die Bereinigung läuft automatisch etwa stündlich. Für zuverlässigen Betrieb empfiehlt sich zusätzlich ein Cron-Job: <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800">php bin/cleanup.php</code>. Anwendungs-URL: <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800"><?= e($appUrl) ?></code></p>
  <form method="post" action="<?= e(url('admin/settings/cleanup')) ?>"><?= Csrf::field() ?><button class="btn btn-secondary" type="submit"><i class="bx bx-refresh text-lg" aria-hidden="true"></i>Bereinigung jetzt ausführen</button></form>
</section>

</div>
