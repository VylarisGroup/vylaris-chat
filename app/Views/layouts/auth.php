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
  <title><?= e($title ?? 'Anmelden') ?> · <?= e($company) ?></title>
  <link rel="stylesheet" href="<?= e(url('assets/vendor/boxicons/css/boxicons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= e($v('assets/css/app.css')) ?>">
  <style nonce="<?= e(SecurityHeaders::nonce()) ?>">:root{--accent: <?= e(Settings::accentRgb()) ?>;}</style>
  <script src="<?= e(url('assets/js/theme.js')) ?>"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased dark:bg-neutral-950 dark:text-neutral-100">
  <main class="mx-auto flex min-h-screen max-w-md flex-col justify-center px-4 py-10">
    <div class="mb-6 flex items-center justify-center gap-3">
      <?php if (Settings::get('logo_file') !== ''): ?>
        <img src="<?= e(url('branding/logo')) ?>" alt="" class="h-10 w-auto max-w-[160px] object-contain">
      <?php else: ?>
        <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-accent text-white shadow-soft"><i class="bx bxs-chat text-2xl" aria-hidden="true"></i></span>
      <?php endif; ?>
      <span class="text-xl font-semibold tracking-tight"><?= e($company) ?></span>
    </div>
    <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-soft sm:p-8 dark:border-neutral-800 dark:bg-neutral-900">
      <?= $content ?>
    </div>
    <p class="mt-6 text-center text-xs text-slate-500 dark:text-neutral-500">Verbindung geschützt per HTTPS · Nachrichten werden auf dem Server gespeichert · <a class="underline hover:text-accent" href="<?= e(url('privacy')) ?>">Datenschutz</a></p>
  </main>
</body>
</html>
