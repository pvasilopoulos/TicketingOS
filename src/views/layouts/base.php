<?php
/** @var string $content */
$pageTitle = page_title() ? page_title() . ' · ' . app_name() : app_name() . ' — Helpdesk';
$assetV = @filemtime(ROOT . '/public/assets/app.css') ?: 1;
$jsIcons = ['bell' => icon('bell', 16), 'x' => icon('x', 15), 'x-sm' => icon('x', 14), 'file-text' => icon('file-text', 15, 'shrink-0 text-muted'), 'loader' => icon('loader-circle', 13, 'animate-spin'), 'file-image' => icon('file-image', 16), 'file-video' => icon('file-video', 16), 'file-spreadsheet' => icon('file-spreadsheet', 16), 'file-archive' => icon('file-archive', 16), 'chevron-left' => icon('chevron-left', 22), 'chevron-right' => icon('chevron-right', 22), 'download' => icon('download', 18), 'external-link' => icon('external-link', 18), 'x-lg' => icon('x', 20), 'copy' => icon('copy', 14), 'check' => icon('check', 14)];
?><!DOCTYPE html>
<html lang="en" class="h-full">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?= e($pageTitle) ?></title>
  <meta name="description" content="A complete, modern support ticketing system.">
  <?php if ($bl = brand_logo_url()): ?><link rel="icon" href="<?= e($bl) ?>"><?php else: ?>
  <link rel="icon" href="/favicon.ico" sizes="48x48"><link rel="icon" href="/app-icons/logo.svg" type="image/svg+xml"><?php endif; ?>
  <!-- PWA -->
  <link rel="manifest" href="/manifest.webmanifest">
  <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
  <meta name="theme-color" content="#121722" media="(prefers-color-scheme: dark)">
  <meta name="mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="<?= e(app_name()) ?>">
  <meta name="apple-mobile-web-app-status-bar-style" content="default">
  <link rel="apple-touch-icon" href="/app-icons/apple-touch-icon.png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;500&display=swap" rel="stylesheet">
  <script>
    // Avoid theme flash: apply stored/preferred theme before paint.
    (function () {
      try {
        var t = localStorage.getItem('theme');
        var dark = t ? t === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        if (dark) document.documentElement.classList.add('dark');
      } catch (e) {}
    })();
  </script>
  <link rel="stylesheet" href="/assets/app.css?v=<?= $assetV ?>">
  <script>
    window.APP = <?= json_encode([
        'csrf' => csrf_token(),
        'vapidKey' => vapid_configured() ? env('VAPID_PUBLIC_KEY') : '',
        'icons' => $jsIcons,
    ], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?>;
  </script>
  <script src="/assets/app.js?v=<?= @filemtime(ROOT . '/public/assets/app.js') ?: 1 ?>" defer></script>
  <script src="/assets/editor.js?v=<?= @filemtime(ROOT . '/public/assets/editor.js') ?: 1 ?>" defer></script>
  <script src="/assets/ticket.js?v=<?= @filemtime(ROOT . '/public/assets/ticket.js') ?: 1 ?>" defer></script>
</head>
<body class="min-h-full">
<?= $content ?>
</body>
</html>
