<?php use App\Core\Csrf;
$st = $selftest;
$sum = $st['summary'] ?? null;
$badge = [
    'pass' => ['bxs-check-circle text-emerald-500', 'Bestanden'],
    'fail' => ['bxs-x-circle text-red-500', 'Fehlgeschlagen'],
    'warn' => ['bxs-error text-amber-500', 'Hinweis'],
    'skip' => ['bx-minus-circle text-slate-400', 'Übersprungen'],
];
$filters = ['issues' => 'Nur Probleme', 'all' => 'Alle Tests'];
?>
<div class="space-y-6">
  <section class="card p-5 sm:p-6">
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h2 class="text-base font-semibold">Funktionstest des Messengers</h2>
        <p class="mt-1 max-w-2xl text-sm text-slate-500 dark:text-neutral-400">Prüft Umgebung, Konfiguration, Sicherheit, Zugriffsschutz der Routen sowie die Kernfunktionen (Chat, Suche, Meldungen, Datenschutz-Funktionen). Die Logiktests laufen mit Testkonten in einer Datenbank-Transaktion, die danach <strong>vollständig zurückgerollt</strong> wird: Es bleiben keine Daten zurück, es werden keine E-Mails versendet. Auf der Kommandozeile: <code class="rounded bg-slate-100 px-1.5 py-0.5 text-xs dark:bg-neutral-800">php bin/selftest.php</code></p>
      </div>
      <form method="post" action="<?= e(url('admin/tests/run')) ?>"><?= Csrf::field() ?><button class="btn btn-primary" type="submit"><i class="bx bx-play text-lg" aria-hidden="true"></i>Tests jetzt ausführen</button></form>
    </div>
    <?php if ($sum): ?>
      <dl class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <?php foreach (['pass' => 'Bestanden', 'fail' => 'Fehlgeschlagen', 'warn' => 'Hinweise', 'skip' => 'Übersprungen'] as $k => $l): ?>
          <div class="rounded-xl bg-slate-50 p-3 dark:bg-neutral-800/60"><dt class="text-xs text-slate-500 dark:text-neutral-400"><?= e($l) ?></dt><dd class="text-2xl font-semibold <?= $k === 'fail' && $sum[$k] > 0 ? 'text-red-600 dark:text-red-400' : '' ?>"><?= (int) $sum[$k] ?></dd></div>
        <?php endforeach; ?>
      </dl>
      <p class="mt-3 text-xs text-slate-500 dark:text-neutral-400">Stand <?= e(local_dt($st['at'], 'd.m.Y H:i:s')) ?> · Dauer <?= (int) $st['duration_ms'] ?> ms<?= $st['version'] !== '' ? ' · Version ' . e($st['version']) : '' ?></p>
    <?php endif; ?>
  </section>

  <?php if (!$st): ?>
    <p class="py-8 text-center text-sm text-slate-500">Noch kein Testlauf vorhanden.</p>
  <?php else: ?>
    <nav class="flex gap-1" aria-label="Filter"><?php foreach ($filters as $k => $l): ?><a class="btn btn-sm <?= $filter === $k ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e(url('admin/tests?show=' . $k)) ?>" <?= $filter === $k ? 'aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach; ?></nav>
    <?php foreach ($st['groups'] as $g):
      $tests = $filter === 'all' ? $g['tests'] : array_values(array_filter($g['tests'], static fn ($t) => in_array($t['status'], ['fail', 'warn'], true)));
      $passed = count(array_filter($g['tests'], static fn ($t) => $t['status'] === 'pass')); ?>
      <section class="card p-5 sm:p-6">
        <div class="mb-2 flex items-center justify-between gap-3"><h3 class="text-base font-semibold"><?= e($g['title']) ?></h3><span class="text-xs text-slate-500 dark:text-neutral-400"><?= $passed ?> von <?= count($g['tests']) ?> bestanden</span></div>
        <?php if (!$tests): ?>
          <p class="py-3 text-sm text-slate-500 dark:text-neutral-400">Keine Probleme in dieser Gruppe.</p>
        <?php else: ?>
          <ul class="divide-y divide-slate-100 dark:divide-neutral-800">
            <?php foreach ($tests as $t): [$ic, $lab] = $badge[$t['status']]; ?>
              <li class="flex items-start gap-3 py-2.5 text-sm">
                <i class="bx <?= e($ic) ?> mt-0.5 text-xl" aria-hidden="true"></i><span class="sr-only"><?= e($lab) ?>:</span>
                <span class="min-w-0 flex-1"><span class="font-medium"><?= e($t['name']) ?></span>
                  <?php if ($t['detail'] !== ''): ?><span class="block break-words text-xs <?= $t['status'] === 'fail' ? 'text-red-600 dark:text-red-400' : 'text-slate-500 dark:text-neutral-400' ?>"><?= e($t['detail']) ?></span><?php endif; ?></span>
                <span class="shrink-0 text-xs text-slate-400"><?= (int) $t['ms'] ?> ms</span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
