<?php
use App\Middleware\SecurityHeaders;
use App\Services\Settings;

$v = static fn (string $f): string => (string) @filemtime(ROOT_PATH . '/public/' . $f);
?><!doctype html>
<html lang="de">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex,nofollow">
  <meta name="csrf-token" content="<?= e($csrf) ?>">
  <meta name="app-base" content="<?= e(app_base_path()) ?>">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="<?= e(Settings::get('accent_color')) ?>">
  <link rel="manifest" href="<?= e(url('manifest.webmanifest')) ?>">
  <link rel="icon" type="image/png" href="<?= e(url('assets/icons/icon-192.png')) ?>">
  <link rel="apple-touch-icon" href="<?= e(url('assets/icons/apple-touch-icon.png')) ?>">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="<?= e(mb_substr($title, 0, 12)) ?>">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <title><?= e($title) ?></title>
  <link rel="stylesheet" href="<?= e(url('assets/vendor/boxicons/css/boxicons.min.css')) ?>">
  <link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= e($v('assets/css/app.css')) ?>">
  <style nonce="<?= e(SecurityHeaders::nonce()) ?>">:root{--accent: <?= e(Settings::accentRgb()) ?>;} html,body{height:100%;height:100dvh}</style>
  <script src="<?= e(url('assets/js/theme.js')) ?>"></script>
</head>
<body class="bg-white text-slate-800 antialiased dark:bg-neutral-900 dark:text-neutral-100">
  <a href="#message-list" class="sr-only focus:not-sr-only focus:absolute focus:left-2 focus:top-2 focus:z-[200] focus:rounded-lg focus:bg-white focus:px-3 focus:py-2 focus:text-accent">Zum Nachrichtenverlauf springen</a>
  <div id="app" class="h-full"></div>
  <noscript>
    <div class="p-8 text-center">Für den Messenger wird JavaScript benötigt. Bitte aktiviere JavaScript in deinem Browser.</div>
  </noscript>
  <script type="module" src="<?= e(url('assets/js/app.js')) ?>?v=<?= e($v('assets/js/app.js')) ?>"></script>
</body>
</html>
