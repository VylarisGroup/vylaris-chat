<?php
/** @var int $page @var int $pages @var array $query */
if ($pages <= 1) {
    return;
}
$link = static fn (int $p): string => '?' . http_build_query(array_filter($query + ['page' => $p], static fn ($v) => $v !== '' && $v !== null));
?>
<nav class="mt-4 flex items-center justify-between text-sm" aria-label="Seiten">
  <span class="text-slate-500 dark:text-neutral-400">Seite <?= (int) $page ?> von <?= (int) $pages ?></span>
  <div class="flex gap-2">
    <?php if ($page > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e($link($page - 1)) ?>">Zurück</a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn btn-secondary btn-sm" href="<?= e($link($page + 1)) ?>">Weiter</a><?php endif; ?>
  </div>
</nav>
