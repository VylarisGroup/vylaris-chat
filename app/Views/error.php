<div class="text-center">
  <p class="text-5xl font-semibold tracking-tight text-accent"><?= (int) $status ?></p>
  <h1 class="mt-3 text-xl font-semibold tracking-tight"><?= e($title) ?></h1>
  <p class="mt-2 text-sm text-slate-500 dark:text-neutral-400"><?= e($message) ?></p>
  <a href="<?= e(url('')) ?>" class="btn btn-primary mt-6 w-full">Zur Startseite</a>
</div>
