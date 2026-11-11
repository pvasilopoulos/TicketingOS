<?php
/** @var string $token */
$state = flash('reset');
if ($token === ''): ?>
  <?= auth_head('key-round', 'Invalid link', 'This reset link is missing its token or has expired.') ?>
  <a href="/forgot" class="auth-btn inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl text-[15px] font-semibold text-white">Request a new link <?= icon('arrow-right', 17) ?></a>
<?php else: ?>
  <?= auth_head('key-round', 'Set a new password', 'Choose a strong password you’ll remember.') ?>

  <form method="post" action="/action/reset" class="space-y-5" data-pending="Updating…">
    <?= form_meta('reset') ?>
    <?= banner($state) ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <?= auth_field(['name' => 'password', 'label' => 'New password', 'icon' => 'key-round', 'type' => 'password', 'placeholder' => 'Create a password', 'autocomplete' => 'new-password', 'reveal' => true, 'strength' => true, 'autofocus' => true]) ?>
    <?= auth_field(['name' => 'confirm', 'label' => 'Confirm password', 'icon' => 'lock', 'type' => 'password', 'placeholder' => 'Repeat the password', 'autocomplete' => 'new-password', 'reveal' => true]) ?>
    <div class="pt-1"><?= auth_submit('Set new password') ?></div>
  </form>

  <div class="mt-7 border-t border-border/70 pt-6 text-center text-sm">
    <a href="/login" class="inline-flex items-center gap-1.5 font-semibold text-brand hover:underline"><?= icon('arrow-left', 15) ?> Back to sign in</a>
  </div>
<?php endif; ?>
