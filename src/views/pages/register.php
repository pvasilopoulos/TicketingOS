<?php $state = flash('register'); ?>
<?= auth_head('sparkles', 'Create your workspace', 'Your own helpdesk, ready in under a minute.') ?>

<form method="post" action="/action/register" class="space-y-5" data-pending="Creating workspace…">
  <?= form_meta('register', '/register') ?>
  <?= banner($state) ?>
  <?= auth_field(['name' => 'organizationName', 'label' => 'Company name', 'icon' => 'building-2', 'placeholder' => 'Acme Inc', 'autocomplete' => 'organization', 'value' => old('register', 'organizationName'), 'autofocus' => true]) ?>
  <?= auth_field(['name' => 'name', 'label' => 'Your name', 'icon' => 'user-round', 'placeholder' => 'Jane Doe', 'autocomplete' => 'name', 'value' => old('register', 'name')]) ?>
  <?= auth_field(['name' => 'email', 'label' => 'Work email', 'icon' => 'mail', 'type' => 'email', 'placeholder' => 'you@company.com', 'autocomplete' => 'email', 'value' => old('register', 'email')]) ?>
  <?= auth_field(['name' => 'password', 'label' => 'Password', 'icon' => 'key-round', 'type' => 'password', 'placeholder' => 'Create a password', 'autocomplete' => 'new-password', 'reveal' => true, 'strength' => true]) ?>
  <div class="pt-1"><?= auth_submit('Create workspace') ?></div>
  <ul class="grid gap-1.5 text-xs text-muted sm:grid-cols-2">
    <?php foreach (['You become the workspace admin', 'Invite your team any time'] as $t): ?>
      <li class="flex items-center gap-1.5"><?= icon('circle-check', 14, 'text-emerald-500') ?><?= e($t) ?></li>
    <?php endforeach; ?>
  </ul>
</form>

<div class="mt-7 border-t border-border/70 pt-6 text-center text-sm text-muted">
  Already have an account? <a href="/login" class="font-semibold text-brand hover:underline">Sign in</a>
</div>
