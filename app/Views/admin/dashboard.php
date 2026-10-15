<?php
$card = static function (string $label, string|int $value, string $icon, ?string $hint = null, ?string $href = null): void { ?>
  <<?= $href ? 'a href="' . e(url($href)) . '"' : 'div' ?> class="card flex items-start gap-4 p-5 <?= $href ? 'transition hover:border-accent/40' : '' ?>">
    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent/10 text-2xl text-accent"><i class="bx bx-<?= e($icon) ?>" aria-hidden="true"></i></span>
    <span class="min-w-0">
      <span class="block text-2xl font-semibold tracking-tight"><?= e($value) ?></span>
      <span class="block text-sm text-slate-600 dark:text-neutral-300"><?= e($label) ?></span>
      <?php if ($hint): ?><span class="block text-xs text-slate-500 dark:text-neutral-400"><?= e($hint) ?></span><?php endif; ?>
    </span>
  </<?= $href ? 'a' : 'div' ?>>
<?php };
?>
<section aria-label="Kennzahlen" class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
  <?php $card('Aktive Benutzer', $stats['users_active'], 'user', $stats['online'] . ' online · ' . $stats['admins'] . ' Administratoren', 'admin/users'); ?>
  <?php $card('Gruppen', $stats['groups'], 'group', $stats['directs'] . ' Einzelchats', 'admin/groups'); ?>
  <?php $card('Nachrichten', number_format($stats['messages_total'], 0, ',', '’'), 'message-rounded-dots', $stats['messages_day'] . ' in den letzten 24 Stunden'); ?>
  <?php $card('Dateien', $stats['files'], 'folder', human_size($stats['file_bytes']) . ' belegt'); ?>
  <?php $card('Offene Meldungen', $stats['reports_open'], 'flag', 'Gemeldete Nachrichten zur Prüfung', 'admin/reports'); ?>
  <?php $card('Offene Einladungen', $stats['invitations'], 'envelope', $stats['users_unverified'] . ' Konten ohne bestätigte E-Mail', 'admin/users'); ?>
</section>
<?php if ($stats['failed_logins'] > 0): ?>
  <p class="mt-4 text-sm text-slate-600 dark:text-neutral-300"><i class="bx bx-info-circle align-middle text-base" aria-hidden="true"></i> <?= (int) $stats['failed_logins'] ?> fehlgeschlagene Anmeldeversuche in den letzten 24 Stunden. <a class="font-medium text-accent hover:underline" href="<?= e(url('admin/logs?action=login')) ?>">Protokoll ansehen</a></p>
<?php endif; ?>

<?php
$st = $selftest ?? null;
$sum = $st['summary'] ?? null;
$stOk = $sum && $sum['fail'] === 0;
?>
<section class="card mt-8 p-5" aria-labelledby="h-selftest">
  <div class="flex flex-wrap items-start justify-between gap-3">
    <div>
      <h2 id="h-selftest" class="text-base font-semibold">Funktionstest</h2>
      <?php if ($sum): ?>
        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm">
          <span class="inline-flex items-center gap-1.5 font-medium <?= $stOk ? 'text-emerald-700 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' ?>"><i class="bx <?= $stOk ? 'bxs-check-circle' : 'bxs-x-circle' ?> text-lg" aria-hidden="true"></i><?= $stOk ? 'Alle Tests bestanden' : (int) $sum['fail'] . ' Test(s) fehlgeschlagen' ?></span>
          <span class="text-slate-600 dark:text-neutral-300"><?= (int) $sum['pass'] ?> bestanden · <?= (int) $sum['warn'] ?> Hinweise · <?= (int) $sum['skip'] ?> übersprungen</span>
          <span class="text-xs text-slate-500 dark:text-neutral-400">Stand <?= e(local_dt($st['at'])) ?> (<?= (int) $st['duration_ms'] ?> ms)</span>
        </p>
      <?php else: ?>
        <p class="mt-1 text-sm text-slate-500 dark:text-neutral-400">Noch nicht ausgeführt. Der Test prüft Umgebung, Sicherheit, Zugriffsschutz und die Kernfunktionen mit Testdaten, die danach vollständig zurückgerollt werden.</p>
      <?php endif; ?>
    </div>
    <form method="post" action="<?= e(url('admin/tests/run')) ?>" class="flex gap-2"><?= \App\Core\Csrf::field() ?><input type="hidden" name="return" value="dashboard">
      <button class="btn btn-primary btn-sm" type="submit"><i class="bx bx-play text-base" aria-hidden="true"></i>Tests ausführen</button>
      <a class="btn btn-secondary btn-sm" href="<?= e(url('admin/tests')) ?>">Details</a></form>
  </div>
  <?php if ($st): $issues = []; foreach ($st['groups'] as $g) { foreach ($g['tests'] as $t) { if (in_array($t['status'], ['fail', 'warn'], true)) { $issues[] = $t; } } } ?>
    <?php if ($issues): ?>
      <ul class="mt-4 divide-y divide-slate-100 rounded-xl border border-slate-200 text-sm dark:divide-neutral-800 dark:border-neutral-700">
        <?php foreach (array_slice($issues, 0, 6) as $t): ?>
          <li class="flex items-start gap-2 px-3 py-2"><i class="bx <?= $t['status'] === 'fail' ? 'bxs-x-circle text-red-500' : 'bxs-error text-amber-500' ?> mt-0.5 text-lg" aria-hidden="true"></i>
            <span class="min-w-0"><span class="font-medium"><?= e($t['name']) ?></span><span class="block break-words text-xs text-slate-500 dark:text-neutral-400"><?= e($t['detail']) ?></span></span></li>
        <?php endforeach; ?>
      </ul>
      <?php if (count($issues) > 6): ?><p class="mt-2 text-xs text-slate-500">und <?= count($issues) - 6 ?> weitere im <a class="font-medium text-accent hover:underline" href="<?= e(url('admin/tests')) ?>">Systemtest</a>.</p><?php endif; ?>
    <?php endif; ?>
  <?php endif; ?>
</section>

<div class="mt-6 grid gap-6 xl:grid-cols-2">
  <section class="card p-5" aria-labelledby="h-health">
    <h2 id="h-health" class="mb-3 text-base font-semibold">Systemzustand</h2>
    <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
      <?php foreach ($health as [$label, $value, $ok]): ?>
        <li class="flex items-center justify-between gap-4 py-2.5 text-sm">
          <span class="flex items-center gap-2"><i class="bx <?= $ok ? 'bxs-check-circle text-emerald-500' : 'bxs-error text-amber-500' ?> text-lg" aria-hidden="true"></i><?= e($label) ?></span>
          <span class="text-right font-medium"><span class="sr-only"><?= $ok ? 'In Ordnung: ' : 'Achtung: ' ?></span><?= e($value) ?></span>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>
  <section class="card p-5" aria-labelledby="h-recent">
    <div class="mb-3 flex items-center justify-between"><h2 id="h-recent" class="text-base font-semibold">Letzte Ereignisse</h2><a class="text-sm font-medium text-accent hover:underline" href="<?= e(url('admin/logs')) ?>">Alle anzeigen</a></div>
    <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
      <?php foreach ($recent as $a): ?>
        <li class="py-2.5 text-sm">
          <p class="font-medium"><?= e($a['action']) ?></p>
          <p class="text-xs text-slate-500 dark:text-neutral-400"><?= e(local_dt($a['created_at'])) ?> · <?= e($a['display_name'] ?? 'System') ?></p>
        </li>
      <?php endforeach; ?>
      <?php if (!$recent): ?><li class="py-6 text-center text-sm text-slate-500">Noch keine Ereignisse.</li><?php endif; ?>
    </ul>
  </section>
</div>
<aside class="alert alert-info mt-8"><i class="bx bx-lock-alt mt-0.5 text-lg" aria-hidden="true"></i>
  <p>Administratoren haben <strong>keinen Zugriff auf private Nachrichten</strong>. Einsehbar sind nur Nachrichten, die Mitglieder ausdrücklich melden. Jede Einsicht wird im Protokoll festgehalten. Der Messenger ist nicht Ende-zu-Ende-verschlüsselt: Nachrichten liegen im Klartext in der Datenbank. Schütze Datenbank und Backups entsprechend.</p>
</aside>
