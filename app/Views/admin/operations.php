<?php use App\Core\Csrf;
$box = 'mt-0.5 h-4 w-4 rounded border-slate-300 text-accent focus:ring-accent';
$cls = ['ok' => 'text-emerald-600 dark:text-emerald-400', 'warn' => 'text-amber-600 dark:text-amber-400', 'error' => 'text-red-600 dark:text-red-400'];
$ico = ['ok' => 'check-circle', 'warn' => 'error', 'error' => 'x-circle'];
?>
<div class="space-y-6">
<section class="card p-5 sm:p-6" aria-labelledby="h-chk">
  <h2 id="h-chk" class="mb-3 text-base font-semibold">Zustand</h2>
  <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
    <?php foreach ($checks as $c): ?>
      <li class="flex items-center gap-3 py-2 text-sm"><i class="bx bx-<?= e($ico[$c['level']]) ?> text-xl <?= e($cls[$c['level']]) ?>" aria-hidden="true"></i><span class="w-56 font-medium"><?= e($c['name']) ?></span><span class="text-slate-600 dark:text-neutral-300"><?= e($c['detail']) ?></span><span class="sr-only"><?= $c['ok'] ? 'in Ordnung' : 'Achtung' ?></span></li>
    <?php endforeach; ?>
  </ul>
  <p class="mt-3 text-xs text-slate-500 dark:text-neutral-400">Für externe Überwachung (Uptime-Dienst): <code><?= e(url('health')) ?></code> liefert <code>ok</code> (200) oder <code>fail</code> (503), ohne Details. Per Cron: <code>php bin/health.php</code> (Exit-Code ≠ 0 bei Problemen).</p>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-err">
  <h2 id="h-err" class="mb-1 text-base font-semibold">Fehlerüberwachung</h2>
  <p class="mb-3 text-sm text-slate-500 dark:text-neutral-400"><?= (int) $errors['last_hour'] ?> Fehler in der letzten Stunde, <?= (int) $errors['last_day'] ?> in 24 Stunden. Log-Datei: <?= e(human_size($errors['total_size'])) ?> (wird ab 2 MB rotiert).</p>
  <form method="post" action="<?= e(url('admin/operations/monitor')) ?>" class="mb-4 grid gap-4 sm:grid-cols-3">
    <?= Csrf::field() ?>
    <div class="sm:col-span-3"><label class="label" for="m-mail">Alarm per E-Mail an</label><input id="m-mail" name="monitor_email" type="email" class="input" maxlength="190" value="<?= e($s['monitor_email']) ?>" placeholder="it@firma.ch"><p class="mt-1 text-xs text-slate-500 dark:text-neutral-400">Leer = kein Versand. Maximal eine Alarm-Mail pro Stunde. Benötigt funktionierendes SMTP<?= $mail ? '' : ' (derzeit nicht konfiguriert)' ?>; bei einem Datenbankausfall kann die Mail nicht versendet werden – nutze dann zusätzlich einen externen Uptime-Dienst.</p></div>
    <div><label class="label" for="m-th">Ab … Fehlern</label><input id="m-th" name="monitor_threshold" type="number" min="1" max="1000" class="input" value="<?= (int) $s['monitor_threshold'] ?>"></div>
    <div><label class="label" for="m-win">… innerhalb von Minuten</label><input id="m-win" name="monitor_window_min" type="number" min="1" max="1440" class="input" value="<?= (int) $s['monitor_window_min'] ?>"></div>
    <div class="flex items-end gap-2"><button class="btn btn-primary" type="submit">Speichern</button></div>
  </form>
  <form method="post" action="<?= e(url('admin/operations/test-alert')) ?>" class="mb-4"><?= Csrf::field() ?><button class="btn btn-secondary btn-sm" type="submit">Test-E-Mail senden</button></form>
  <?php if ($errors['lines']): ?>
    <h3 class="mb-1 text-sm font-semibold">Letzte Einträge</h3>
    <pre class="max-h-64 overflow-auto rounded-lg bg-slate-100 p-3 text-xs dark:bg-neutral-800"><?= e(implode("\n", $errors['lines'])) ?></pre>
  <?php else: ?><p class="text-sm text-slate-500">Keine Fehler protokolliert.</p><?php endif; ?>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-rt">
  <h2 id="h-rt" class="mb-1 text-base font-semibold">Echtzeit</h2>
  <p class="mb-3 text-sm text-slate-500 dark:text-neutral-400">Standard ist kurzes Polling (alle 2,5 s im aktiven Fenster) – läuft auf jedem Hosting. Der Echtzeitmodus (Server-Sent Events) liefert Nachrichten nach rund einer Sekunde und spart Anfragen, <strong>belegt aber pro geöffnetem Tab einen PHP-Prozess</strong>. Auf Shared Hosting mit wenigen Prozessen kann das die Seite blockieren – teste ihn mit realistischer Nutzerzahl.</p>
  <form method="post" action="<?= e(url('admin/operations/realtime')) ?>" class="flex flex-wrap items-center gap-3"><?= Csrf::field() ?>
    <select name="realtime_mode" class="input !w-auto" aria-label="Echtzeitmodus"><option value="poll" <?= $s['realtime_mode'] !== 'sse' ? 'selected' : '' ?>>Polling (empfohlen)</option><option value="sse" <?= $s['realtime_mode'] === 'sse' ? 'selected' : '' ?>>Echtzeit (SSE)</option></select>
    <button class="btn btn-primary" type="submit">Speichern</button></form>
</section>

<section class="card p-5 sm:p-6" aria-labelledby="h-push">
  <h2 id="h-push" class="mb-1 text-base font-semibold">Push-Benachrichtigungen und App-Installation</h2>
  <p class="mb-3 text-sm text-slate-500 dark:text-neutral-400">Push funktioniert nur über HTTPS. Die Push-Dienste von Google, Mozilla oder Apple erhalten nur <em>verschlüsselte</em> Daten (RFC 8291), die allein das Gerät lesen kann – der Nachrichtentext wird nie übertragen. Zugestellt wird nur bei Direktnachrichten und Erwähnungen an Personen, die gerade nicht aktiv sind. Die App lässt sich zusätzlich als PWA installieren (Browsermenü → „App installieren“ bzw. „Zum Home-Bildschirm“).</p>
  <?php if (!$curl || !$openssl): ?><div class="alert alert-error mb-3"><i class="bx bx-error-circle mt-0.5 text-lg"></i><span>Es fehlen PHP-Erweiterungen (<?= !$curl ? 'curl ' : '' ?><?= !$openssl ? 'openssl' : '' ?>).</span></div><?php endif; ?>
  <form method="post" action="<?= e(url('admin/operations/push')) ?>" class="flex flex-wrap items-center gap-3"><?= Csrf::field() ?>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="push_enabled" value="1" <?= $s['push_enabled'] === '1' ? 'checked' : '' ?> class="<?= $box ?>"> Push aktivieren</label>
    <button class="btn btn-primary" type="submit">Speichern</button></form>
  <?php if ($s['push_enabled'] === '1'): ?>
    <p class="mt-3 text-sm text-slate-600 dark:text-neutral-300"><?= $subs ?> Gerät(e) von <?= $pushUsers ?> Person(en) registriert. Schlüssel (VAPID, öffentlich): <code class="break-all text-xs"><?= e(substr($s['vapid_public'], 0, 24)) ?>…</code></p>
    <?php if (!empty($myDevices)): ?>
      <div class="mt-3 overflow-x-auto"><table class="w-full text-left text-sm"><caption class="sr-only">Meine Push-Geräte</caption>
        <thead><tr class="text-xs uppercase tracking-wide text-slate-500"><th scope="col" class="py-1 pr-3">Gerät</th><th scope="col" class="py-1 pr-3">Verschlüsselt</th><th scope="col" class="py-1 pr-3">Letzte Zustellung</th><th scope="col" class="py-1">Status</th></tr></thead>
        <tbody class="divide-y divide-slate-100 dark:divide-neutral-800"><?php foreach ($myDevices as $dv): ?>
          <tr><td class="max-w-[16rem] truncate py-1.5 pr-3" title="<?= e($dv['user_agent']) ?>"><?= e($dv['user_agent'] !== '' ? mb_substr($dv['user_agent'], 0, 60) : 'Gerät ' . $dv['id']) ?></td>
            <td class="py-1.5 pr-3"><?= $dv['p256dh'] ? 'ja' : 'nein (Altgerät – Push in den Einstellungen aus- und wieder einschalten)' ?></td>
            <td class="py-1.5 pr-3"><?= $dv['last_sent_at'] ? e(local_dt($dv['last_sent_at'], 'd.m.Y H:i')) : '–' ?></td>
            <td class="py-1.5"><?= $dv['last_error'] ? '<span class="text-red-600">' . e($dv['last_error']) . '</span>' : ($dv['last_status'] ? 'OK (' . (int) $dv['last_status'] . ')' : '–') ?></td></tr>
        <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
    <form method="post" action="<?= e(url('admin/operations/test-push')) ?>" class="mt-2"><?= Csrf::field() ?><button class="btn btn-secondary btn-sm" type="submit">Test-Push an meine Geräte</button></form>
  <?php endif; ?>
</section>
</div>
