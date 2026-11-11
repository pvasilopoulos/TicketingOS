<?php
/** @var string $content */
ob_start(); ?>
<div class="relative isolate flex min-h-dvh flex-col">
  <div class="auth-bg" aria-hidden="true"><div class="auth-blob auth-blob-1"></div><div class="auth-blob auth-blob-2"></div><div class="auth-blob auth-blob-3"></div></div>

  <header class="relative z-10 flex items-center justify-between px-5 pt-[max(1.25rem,env(safe-area-inset-top))] sm:px-8">
    <a href="/login" class="flex min-w-0 items-center gap-2.5 font-semibold tracking-tight">
      <?= brand_mark(36) ?>
      <span class="truncate text-[15px]"><?= e(app_name()) ?></span>
    </a>
    <?= theme_toggle() ?>
  </header>

  <main class="relative z-10 flex flex-1 items-center justify-center px-4 py-10 sm:px-6">
    <div class="auth-card w-full max-w-[26rem] animate-in rounded-3xl border border-border/70 bg-surface/80 p-7 backdrop-blur-2xl sm:p-9">
      <?= $content ?>
    </div>
  </main>

  <footer class="relative z-10 flex items-center justify-center gap-1.5 px-4 pb-[max(1.25rem,env(safe-area-inset-bottom))] text-xs text-muted">
    <?= icon('shield-check', 13) ?> Secure, encrypted sign-in
  </footer>
</div>
<?php
echo render_partial('layouts/base', ['content' => ob_get_clean()]);
