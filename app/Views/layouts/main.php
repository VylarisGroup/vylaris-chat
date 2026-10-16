<?php
use App\Core\Csrf;
use App\Middleware\SecurityHeaders;
use App\Services\Settings;

$company = Settings::get('company_name');
$v = static fn (string $f): string => (string) @filemtime(ROOT_PATH . '/public/' . $f);
$privacyOpen = \App\Services\Privacy::openCount();
$nav = [
    ['dashboard', 'admin', 'Übersicht', 'grid-alt'],
    ['users', 'admin/users', 'Benutzer', 'user'],
    ['groups', 'admin/groups', 'Gruppen', 'group'],
    ['reports', 'admin/reports', 'Meldungen', 'flag'],
    ['privacy', 'admin/privacy', 'Datenschutz', 'lock-alt'],
    ['security', 'admin/security', 'Sicherheit', 'shield-quarter'],
    ['backup', 'admin/backup', 'Backup', 'archive'],
    ['operations', 'admin/operations', 'Betrieb', 'pulse'],
    ['license', 'admin/license', 'Lizenz', 'key'],
    ['update', 'admin/update', 'Aktualisierung', 'cloud-download'],
    ['changelog', 'admin/changelog', 'Änderungen', 'news'],
    ['settings', 'admin/settings', 'Einstellungen', 'cog'],
    ['tests', 'admin/tests', 'Systemtest', 'check-shield'],
    ['logs', 'admin/logs', 'Protokoll', 'list-ul'],
];
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title><?= e($title ?? 'Administration') ?> · Administration · <?= e($company) ?></title>
  <link rel="stylesheet" href="<?= e(url('assets/vendor/boxicons/css/boxicons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= e($v('assets/css/app.css')) ?>">
  <style nonce="<?= e(SecurityHeaders::nonce()) ?>">:root{--accent: <?= e(Settings::accentRgb()) ?>;}</style>
  <script src="<?= e(url('assets/js/theme.js')) ?>"></script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-800 antialiased dark:bg-neutral-950 dark:text-neutral-100">
  <a href="#main" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-50 focus:rounded-lg focus:bg-white focus:px-3 focus:py-2">Zum Inhalt springen</a>
  <div class="mx-auto flex min-h-screen max-w-[1400px] flex-col lg:flex-row">
    <aside class="shrink-0 border-b border-slate-200 bg-white lg:min-h-screen lg:w-64 lg:border-b-0 lg:border-r dark:border-neutral-800 dark:bg-neutral-900">
      <div class="flex items-center gap-2.5 px-5 py-4">
        <?php if (Settings::get('logo_file') !== ''): ?>
          <img src="<?= e(url('branding/logo')) ?>" alt="" class="h-8 w-8 rounded-lg object-contain">
        <?php else: ?>
          <span class="flex h-8 w-8 items-center justify-center rounded-lg bg-accent text-white"><i class="bx bx-shield text-xl" aria-hidden="true"></i></span>
        <?php endif; ?>
        <div class="min-w-0">
          <p class="truncate text-sm font-semibold"><?= e($company) ?></p>
          <p class="text-xs text-slate-500 dark:text-neutral-400">Administration</p>
        </div>
      </div>
      <nav aria-label="Administration" class="flex gap-1 overflow-x-auto px-3 pb-3 lg:flex-col lg:pb-0">
        <?php foreach ($nav as [$key, $path, $label, $ic]): ?>
          <a href="<?= e(url($path)) ?>" <?= $section === $key ? 'aria-current="page"' : '' ?>
             class="flex shrink-0 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition <?= $section === $key ? 'bg-accent/10 text-accent' : 'text-slate-600 hover:bg-slate-100 dark:text-neutral-300 dark:hover:bg-neutral-800' ?>">
            <i class="bx bx-<?= e($ic) ?> text-xl" aria-hidden="true"></i><?= e($label) ?>
            <?php if ($key === 'privacy' && $privacyOpen > 0): ?><span class="ml-auto rounded-full bg-accent px-2 py-0.5 text-xs font-semibold text-white" title="Offene Datenschutzanfragen"><?= (int) $privacyOpen ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </nav>
      <div class="hidden border-t border-slate-200 p-3 lg:mt-6 lg:block dark:border-neutral-800">
        <a href="<?= e(url('')) ?>" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium text-slate-600 hover:bg-slate-100 dark:text-neutral-300 dark:hover:bg-neutral-800"><i class="bx bx-left-arrow-alt text-xl" aria-hidden="true"></i>Zurück zum Messenger</a>
      </div>
    </aside>
    <main id="main" class="min-w-0 flex-1 p-4 sm:p-6 lg:p-8">
      <div class="mb-6 flex items-center justify-between gap-3">
        <h1 class="text-2xl font-semibold tracking-tight"><?= e($title ?? '') ?></h1>
        <a href="<?= e(url('')) ?>" class="btn btn-secondary btn-sm lg:hidden"><i class="bx bx-left-arrow-alt text-base" aria-hidden="true"></i>Messenger</a>
      </div>
      <?php $clPending = isset($admin['id']) && ($section ?? '') !== 'changelog' ? \App\Services\Changelog::pending((int) $admin['id']) : []; ?>
      <?php if ($clPending): ?>
        <section class="card mb-4 border-accent/40 p-5" aria-labelledby="h-cl">
          <h2 id="h-cl" class="flex items-center gap-2 text-base font-semibold"><i class="bx bx-gift text-xl text-accent" aria-hidden="true"></i>Neu in Version <?= e(APP_VERSION) ?></h2>
          <?php foreach ($clPending as $cl): ?>
            <div class="mt-3"><h3 class="text-sm font-semibold">Version <?= e($cl['version']) ?><?= $cl['title'] !== '' ? ' – ' . e($cl['title']) : '' ?></h3>
              <ul class="mt-1 list-disc space-y-1 pl-5 text-sm text-slate-600 dark:text-neutral-300"><?php foreach ($cl['items'] as $it): ?><li><?= \App\Services\Changelog::render($it) ?></li><?php endforeach; ?></ul></div>
          <?php endforeach; ?>
          <form method="post" action="<?= e(url('admin/changelog/seen')) ?>" class="mt-4 flex flex-wrap items-center gap-3"><?= \App\Core\Csrf::field() ?><button class="btn btn-primary btn-sm" type="submit">Verstanden</button><a class="text-sm underline" href="<?= e(url('admin/changelog')) ?>">Alle Änderungen</a></form>
        </section>
      <?php endif; ?>
      <?php if (($section ?? '') !== 'update' && \App\Services\Updater::available()): ?>
        <div class="alert alert-info" role="status"><i class="bx bx-cloud-download mt-0.5 text-lg" aria-hidden="true"></i><span class="min-w-0 break-words"><strong>Update verfügbar.</strong> <a class="font-medium underline" href="<?= e(url('admin/update')) ?>">Aktualisierung öffnen</a></span></div>
      <?php endif; ?>
      <?php if (($section ?? '') !== 'license' && !\App\Services\License::active()): ?>
        <div class="alert alert-error" role="alert"><i class="bx bx-lock-alt mt-0.5 text-lg" aria-hidden="true"></i><span class="min-w-0 break-words"><strong>Nur-Lesen-Modus:</strong> <?= e(\App\Services\License::status()['message']) ?> <a class="font-medium underline" href="<?= e(url('admin/license')) ?>">Lizenz verwalten</a></span></div>
      <?php endif; ?>
      <?php if (!empty($notice)): ?>
        <div class="alert alert-success" role="status"><i class="bx bx-check-circle mt-0.5 text-lg" aria-hidden="true"></i><span class="min-w-0 break-words"><?= e($notice) ?></span></div>
      <?php endif; ?>
      <?php if (!empty($error)): ?>
        <div class="alert alert-error" role="alert"><i class="bx bx-error-circle mt-0.5 text-lg" aria-hidden="true"></i><span class="min-w-0 break-words"><?= e($error) ?></span></div>
      <?php endif; ?>
      <?= $content ?>
    </main>
  </div>
</body>
</html>
