<?php
$tone = $tone ?? 'info';
$icon = ['success' => 'bx-check-circle', 'error' => 'bx-error-circle', 'info' => 'bx-info-circle'][$tone] ?? 'bx-info-circle';
?>
<div class="text-center">
  <span class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full <?= $tone === 'success' ? 'bg-emerald-100 text-emerald-600 dark:bg-emerald-950 dark:text-emerald-400' : ($tone === 'error' ? 'bg-red-100 text-red-600 dark:bg-red-950 dark:text-red-400' : 'bg-accent/10 text-accent') ?>"><i class="bx <?= e($icon) ?> text-3xl" aria-hidden="true"></i></span>
  <h1 class="mb-2 text-xl font-semibold tracking-tight"><?= e($heading) ?></h1>
  <p class="text-sm text-slate-500 dark:text-neutral-400"><?= e($text) ?></p>
  <?php if (!empty($link)): ?>
    <a href="<?= e(url($link[0])) ?>" class="btn btn-primary mt-6 w-full"><?= e($link[1]) ?></a>
  <?php endif; ?>
</div>
