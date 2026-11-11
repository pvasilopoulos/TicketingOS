<?php
/** @var string $content */
$user = require_super_admin();
ob_start(); ?>
<div class="min-h-screen">
  <header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-border bg-surface px-4 lg:px-8">
    <a href="/platform" class="flex items-center gap-2.5 font-semibold">
      <span class="grid h-9 w-9 place-items-center rounded-lg bg-sky-500 text-white"><?= icon('shield-check', 20) ?></span>
      <span>TicketingOS <span class="text-muted">· Platform</span></span>
    </a>
    <div class="ml-auto flex items-center gap-1">
      <?= theme_toggle() ?>
      <?= render_partial('partials/user_menu', ['user' => $user, 'showPrefs' => false]) ?>
    </div>
  </header>
  <main class="mx-auto max-w-6xl px-4 py-8 lg:px-8 animate-in"><?= $content ?></main>
</div>
<?php
echo render_partial('layouts/base', ['content' => ob_get_clean()]);
