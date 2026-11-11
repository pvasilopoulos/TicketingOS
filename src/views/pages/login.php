<?php $state = flash('login'); ?>
<div>
  <h1 class="text-2xl font-bold">Welcome back</h1>
  <p class="mt-1 text-sm text-muted">Sign in to your workspace.</p>

  <form method="post" action="/action/login" class="mt-8 space-y-4" data-pending="Signing in…">
    <?= form_meta('login', '/login') ?>
    <?php if (($_GET['reset'] ?? '') === '1'): ?>
      <div class="rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">
        Password updated. Sign in with your new password.
      </div>
    <?php endif; ?>
    <?= banner($state) ?>
    <div>
      <label for="email" class="<?= LABEL_CLS ?>">Email</label>
      <input id="email" name="email" type="email" placeholder="you@company.com" required autocomplete="email" value="<?= e(old('login', 'email')) ?>" class="<?= INPUT_CLS ?>">
    </div>
    <div>
      <div class="flex items-center justify-between">
        <label for="password" class="<?= LABEL_CLS ?>">Password</label>
        <a href="/forgot" class="mb-1.5 text-xs font-medium text-brand hover:underline">Forgot?</a>
      </div>
      <input id="password" name="password" type="password" placeholder="••••••••" required autocomplete="current-password" class="<?= INPUT_CLS ?>">
    </div>
    <button type="submit" class="<?= btn_cls('primary', 'lg', 'w-full') ?>">Sign in</button>
  </form>

  <p class="mt-6 text-sm text-muted">
    No workspace yet?
    <a href="/register" class="font-medium text-brand hover:underline">Create one</a>
  </p>
</div>
