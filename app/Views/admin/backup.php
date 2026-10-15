<?php use App\Core\Csrf;
$sz = static fn (int $b): string => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, (int) round($b / 1024)) . ' KB';
$last = (int) $s['backup_last_at'];
?>
<div class="space-y-6">
<?php if (!$zip): ?><div class="alert alert-error" role="alert"><i class="bx bx-error-circle mt-0.5 text-lg" aria-hidden="true"></i><span>Die PHP-Erweiterung <code>zip</code> fehlt. Backups sind so nicht möglich.</span></div><?php endif; ?>

<div class="alert alert-info" role="note"><i class="bx bx-info-circle mt-0.5 text-lg" aria-hidden="true"></i>
  <span><strong>Ein Backup enthält alle Nachrichten im Klartext</strong> (der Messenger ist nicht Ende-zu-Ende-verschlüsselt) sowie alle hochgeladenen Dateien. Behandle es wie die Datenbank selbst: Zugriff beschränken, bevorzugt mit Passphrase verschlüsseln. Die Datei <code>config/config.php</code> (enthält den Schlüssel <code>app.key</code>) gehört zwingend ebenfalls ins Backup-Konzept – ohne sie sind SMTP-Passwort und 2FA-Geheimnisse nicht wiederherstellbar.</span></div>

<section class="card p-5 sm:p-6" aria-labelledby="h-new">
  <h2 id="h-new" class="mb-1 text-base font-semibold">Backup erstellen</h2>
  <p class="mb-4 text-sm text-slate-500 dark:text-neutral-400">Letztes Backup: <?= $last > 0 ? e(local_dt(gmdate('Y-m-d H:i:s', $last), 'd.m.Y H:i')) : 'noch keins' ?>. Gespeichert in <code>storage/backups/</code>.</p>
  <form method="post" action="<?= e(url('admin/backup/create')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div><label class="label" for="bk-pass">Passphrase (optional, AES-256)</label><input id="bk-pass" name="passphrase" type="password" minlength="12" maxlength="200" autocomplete="new-password" class="input"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Mindestens 12 Zeichen. Ohne Passphrase ist das ZIP unverschlüsselt. Notiere sie sicher – sie ist nicht wiederherstellbar.</p></div>
    <label class="flex items-start gap-2 text-sm sm:mt-6"><input type="checkbox" name="config" value="1" class="mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span><code>config/config.php</code> mitsichern (enthält Datenbankzugang und app.key – nur mit Passphrase empfohlen)</span></label>
    <div class="sm:col-span-2"><button class="btn btn-primary" type="submit" <?= $zip ? '' : 'disabled' ?>><i class="bx bx-archive-in text-lg" aria-hidden="true"></i>Backup jetzt erstellen</button></div>
  </form>
  <?php if ($jobs): ?>
    <ul class="mt-4 space-y-1 text-xs text-slate-500 dark:text-neutral-400">
      <?php foreach ($jobs as $j): ?><li>#<?= (int) $j['id'] ?> · <?= e(local_dt($j['created_at'], 'd.m.Y H:i')) ?> · <?= e(['queued' => 'wartet', 'running' => 'läuft', 'done' => 'fertig', 'failed' => 'fehlgeschlagen'][$j['status']] ?? $j['status']) ?><?= $j['error'] ? ' – ' . e($j['error']) : '' ?></li><?php endforeach; ?>
    </ul>
  <?php endif; ?>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-list">
  <h2 id="h-list" class="mb-3 text-base font-semibold">Vorhandene Backups</h2>
  <?php if (!$files): ?><p class="py-4 text-center text-sm text-slate-500">Noch keine Backups vorhanden.</p>
  <?php else: ?>
  <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
    <?php foreach ($files as $f): ?>
      <li class="flex flex-wrap items-center gap-3 py-3">
        <i class="bx bx-archive text-2xl text-slate-400" aria-hidden="true"></i>
        <div class="min-w-0 flex-1"><p class="truncate text-sm font-medium"><?= e($f['name']) ?></p><p class="text-xs text-slate-500 dark:text-neutral-400"><?= e(local_dt(gmdate('Y-m-d H:i:s', $f['created']), 'd.m.Y H:i')) ?> · <?= e($sz($f['size'])) ?></p></div>
        <form method="post" action="<?= e(url('admin/backup/' . $f['name'] . '/verify')) ?>" class="flex items-center gap-2"><?= Csrf::field() ?>
          <input name="passphrase" type="password" class="input !w-36 !py-1 text-xs" placeholder="Passphrase" aria-label="Passphrase (falls verschlüsselt)" autocomplete="off">
          <button class="btn btn-secondary btn-sm" type="submit">Prüfen</button></form>
        <?php if ($webDownload): ?>
        <form method="post" action="<?= e(url('admin/backup/' . $f['name'] . '/download')) ?>" class="flex items-center gap-2"><?= Csrf::field() ?>
          <input name="password" type="password" required class="input !w-36 !py-1 text-xs" placeholder="Dein Passwort" aria-label="Dein Passwort" autocomplete="current-password">
          <button class="btn btn-secondary btn-sm" type="submit">Download</button></form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('admin/backup/' . $f['name'] . '/delete')) ?>"><?= Csrf::field() ?><button class="btn btn-ghost btn-sm !text-red-600" type="submit">Löschen</button></form>
      </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-set">
  <h2 id="h-set" class="mb-3 text-base font-semibold">Automatisierung und Aufbewahrung</h2>
  <form method="post" action="<?= e(url('admin/backup/settings')) ?>" class="grid gap-4 sm:grid-cols-2">
    <?= Csrf::field() ?>
    <div><label class="label" for="bk-days">Automatisches Backup alle … Tage</label><input id="bk-days" name="backup_auto_days" type="number" min="0" max="90" class="input" value="<?= (int) $s['backup_auto_days'] ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">0 = aus. Läuft bei der Wartung im Hintergrund, ohne Passphrase – für verschlüsselte Backups nutze <code>bin/backup.php --passphrase-file=…</code> per Cron.</p></div>
    <div><label class="label" for="bk-keep">Backups behalten</label><input id="bk-keep" name="backup_keep" type="number" min="1" max="60" class="input" value="<?= (int) $s['backup_keep'] ?>"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Ältere werden automatisch gelöscht. Gelöschte Daten können bis dahin noch in Backups liegen (steht in der Datenschutzerklärung).</p></div>
    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="backup_include_config" value="1" <?= $s['backup_include_config'] === '1' ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span>Automatische Backups enthalten <code>config.php</code></span></label>
    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="backup_web_download" value="1" <?= $webDownload ? 'checked' : '' ?> class="mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent"><span><strong>Download im Browser erlauben</strong> (verlangt dein Passwort, wird protokolliert). Standard: aus – Administratoren sollen keine Nachrichteninhalte erhalten; hole Backups per SFTP.</span></label>
    <div class="sm:col-span-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-rest">
  <h2 id="h-rest" class="mb-2 text-base font-semibold">Wiederherstellung</h2>
  <p class="mb-2 text-sm text-slate-600 dark:text-neutral-300">Die Wiederherstellung überschreibt Datenbank und Dateien und läuft deshalb bewusst nur auf der Kommandozeile:</p>
  <pre class="overflow-x-auto rounded-lg bg-slate-100 p-3 text-xs dark:bg-neutral-800">php bin/restore.php storage/backups/backup-….zip            # nur prüfen
php bin/restore.php storage/backups/backup-….zip --yes      # wiederherstellen
php bin/restore.php … --passphrase-file=pass.txt --yes      # bei verschlüsseltem Backup</pre>
  <p class="mt-2 text-xs text-slate-500 dark:text-neutral-400">Teste die Wiederherstellung regelmässig auf einer Kopie – ein nie getestetes Backup ist kein Backup.</p>
</section>
</div>
