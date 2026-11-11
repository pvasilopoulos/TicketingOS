<?php $state = flash('forgot'); ?>
<div>
  <h1 class="text-2xl font-bold">Forgot your password?</h1>
  <p class="mt-1 text-sm text-muted">Enter your email and we&apos;ll send you a reset link.</p>

  <form method="post" action="/action/forgot" class="mt-8 space-y-4" data-pending="Sending…">
    <?= form_meta('forgot', '/forgot') ?>
    <?= banner($state) ?>
    <div>
      <label for="email" class="<?= LABEL_CLS ?>">Email</label>
      <input id="email" name="email" type="email" placeholder="you@company.com" required autocomplete="email" class="<?= INPUT_CLS ?>">
    </div>
    <button type="submit" class="<?= btn_cls('primary', 'lg', 'w-full') ?>">Send reset link</button>
  </form>

  <p class="mt-6 text-sm text-muted">
    <a href="/login" class="font-medium text-brand hover:underline">Back to sign in</a>
  </p>
</div>
