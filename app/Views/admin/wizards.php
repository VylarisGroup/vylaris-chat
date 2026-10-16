<div class="space-y-6">
  <p class="max-w-2xl text-sm text-slate-500 dark:text-neutral-400">Die Assistenten führen Schritt für Schritt durch die Einrichtung. Die klassischen Formulare bleiben unverändert verfügbar; am Ende wird jeweils dasselbe gespeichert.</p>
  <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
<?php foreach ($wizards as $key => $w): ?>
    <a href="<?= e(url('admin/wizard/' . $key)) ?>" class="card group flex gap-4 p-5 transition hover:border-accent/50">
      <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-accent/10 text-accent"><i class="bx bx-<?= e($w['icon']) ?> text-2xl" aria-hidden="true"></i></span>
      <span class="min-w-0"><span class="block font-semibold group-hover:text-accent"><?= e($w['title']) ?></span><span class="mt-1 block text-sm text-slate-500 dark:text-neutral-400"><?= e($w['summary']) ?></span><span class="mt-2 block text-xs text-slate-400"><?= count($w['steps']) ?> Schritte</span></span>
    </a>
<?php endforeach; ?>
  </div>
</div>
