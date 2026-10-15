<?php
use App\Middleware\SecurityHeaders;
use App\Services\Settings;

$company = Settings::get('company_name');
$v = static fn (string $f): string => (string) @filemtime(ROOT_PATH . '/public/' . $f);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= e($title ?? 'Datenschutz') ?> · <?= e($company) ?></title>
  <link rel="stylesheet" href="<?= e(url('assets/vendor/boxicons/css/boxicons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= e($v('assets/css/app.css')) ?>">
  <style nonce="<?= e(SecurityHeaders::nonce()) ?>">:root{--accent: <?= e(Settings::accentRgb()) ?>;}</style>
  <script src="<?= e(url('assets/js/theme.js')) ?>"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased dark:bg-neutral-950 dark:text-neutral-100">
  <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">Zum Inhalt springen</a>
  <header class="border-b border-slate-200 bg-white dark:border-neutral-800 dark:bg-neutral-900">
    <div class="mx-auto flex max-w-4xl items-center justify-between gap-3 px-4 py-3">
      <div class="flex min-w-0 items-center gap-3">
        <?php if (Settings::get('logo_file') !== ''): ?>
          <img src="<?= e(url('branding/logo')) ?>" alt="" class="h-8 w-auto max-w-[140px] object-contain">
        <?php else: ?>
          <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-accent text-white"><i class="bx bxs-chat text-xl" aria-hidden="true"></i></span>
        <?php endif; ?>
        <span class="truncate text-base font-semibold"><?= e($company) ?></span>
      </div>
      <a class="btn btn-secondary btn-sm" href="<?= e(url(!empty($signedIn) ? '' : 'login')) ?>"><i class="bx bx-left-arrow-alt text-base" aria-hidden="true"></i><?= !empty($signedIn) ? 'Zum Messenger' : 'Zur Anmeldung' ?></a>
    </div>
  </header>
  <main id="main" class="mx-auto max-w-4xl px-4 py-8 sm:py-10">
    <?= $content ?>
  </main>
</body>
</html>
