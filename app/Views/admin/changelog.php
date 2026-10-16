<?php use App\Services\Changelog; ?>
<div class="space-y-6">
  <p class="text-sm text-slate-500 dark:text-neutral-400">Installierte Version: <strong><?= e($version) ?></strong></p>
<?php foreach ($entries as $cl): ?>
  <section class="card p-5 sm:p-6">
    <h2 class="text-base font-semibold">Version <?= e($cl['version']) ?><?= $cl['title'] !== '' ? ' – ' . e($cl['title']) : '' ?><?php if ($cl['version'] === $version): ?> <span class="ml-2 rounded-full bg-accent/10 px-2 py-0.5 text-xs font-medium text-accent">installiert</span><?php endif; ?></h2>
    <ul class="mt-2 list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-neutral-300"><?php foreach ($cl['items'] as $it): ?><li><?= Changelog::render($it) ?></li><?php endforeach; ?></ul>
  </section>
<?php endforeach; ?>
<?php if (!$entries): ?><div class="card p-5 text-sm text-slate-500">Kein Änderungsprotokoll vorhanden.</div><?php endif; ?>
</div>
