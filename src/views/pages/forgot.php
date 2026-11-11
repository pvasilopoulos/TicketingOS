<?php $state = flash('forgot'); ?>
<?= auth_head('mail-check', 'Forgot your password?', 'Enter your email and we’ll send you a reset link.') ?>

<form method="post" action="/action/forgot" class="space-y-5" data-pending="Sending…">
  <?= form_meta('forgot', '/forgot') ?>
  <?= banner($state, true) ?>
  <?= auth_field(['name' => 'email', 'label' => 'Email', 'icon' => 'mail', 'type' => 'email', 'placeholder' => 'you@company.com', 'autocomplete' => 'email', 'autofocus' => true]) ?>
  <div class="pt-1"><?= auth_submit('Send reset link') ?></div>
</form>

<div class="mt-7 border-t border-border/70 pt-6 text-center text-sm">
  <a href="/login" class="inline-flex items-center gap-1.5 font-semibold text-brand hover:underline"><?= icon('arrow-left', 15) ?> Back to sign in</a>
</div>
