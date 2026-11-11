<?php $state = flash('login'); ?>
<?= auth_head('lock-keyhole', 'Welcome back', 'Sign in to continue to ' . app_name() . '.') ?>

<form method="post" action="/action/login" class="space-y-5" data-pending="Signing in…">
  <?= form_meta('login', '/login') ?>
  <?php if (($_GET['reset'] ?? '') === '1'): ?>
    <?= alert_success('Password updated. Sign in with your new password.', true) ?>
  <?php endif; ?>
  <?= banner($state) ?>
  <?= auth_field(['name' => 'email', 'label' => 'Email', 'icon' => 'mail', 'type' => 'email', 'placeholder' => 'you@company.com', 'autocomplete' => 'email', 'value' => old('login', 'email'), 'autofocus' => true]) ?>
  <?= auth_field(['name' => 'password', 'label' => 'Password', 'icon' => 'key-round', 'type' => 'password', 'placeholder' => 'Your password', 'autocomplete' => 'current-password', 'reveal' => true,
      'aside' => '<a href="/forgot" class="text-xs font-medium text-brand hover:underline">Forgot password?</a>']) ?>
  <div class="pt-1"><?= auth_submit('Sign in') ?></div>
</form>

<div class="mt-7 border-t border-border/70 pt-6 text-center text-sm text-muted">
  New here? <a href="/register" class="font-semibold text-brand hover:underline">Create a workspace</a>
</div>
