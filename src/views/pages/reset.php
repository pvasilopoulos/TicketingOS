<?php
/** @var string $token */
$state = flash('reset');
if ($token === ''): ?>
<div>
  <h1 class="text-2xl font-bold">Invalid link</h1>
  <p class="mt-1 text-sm text-muted">This reset link is missing its token.</p>
  <p class="mt-6 text-sm"><a href="/forgot" class="font-medium text-brand hover:underline">Request a new link</a></p>
</div>
<?php else: ?>
<div>
  <h1 class="text-2xl font-bold">Set a new password</h1>
  <p class="mt-1 text-sm text-muted">Choose a strong password you&apos;ll remember.</p>

  <form method="post" action="/action/reset" class="mt-8 space-y-4" data-pending="Updating…">
    <?= form_meta('reset') ?>
    <?= banner($state) ?>
    <input type="hidden" name="token" value="<?= e($token) ?>">
    <div>
      <label for="password" class="<?= LABEL_CLS ?>">New password</label>
      <input id="password" name="password" type="password" placeholder="At least 6 characters" required autocomplete="new-password" class="<?= INPUT_CLS ?>">
    </div>
    <div>
      <label for="confirm" class="<?= LABEL_CLS ?>">Confirm password</label>
      <input id="confirm" name="confirm" type="password" placeholder="Repeat password" required autocomplete="new-password" class="<?= INPUT_CLS ?>">
    </div>
    <button type="submit" class="<?= btn_cls('primary', 'lg', 'w-full') ?>">Set new password</button>
  </form>

  <p class="mt-6 text-sm text-muted"><a href="/login" class="font-medium text-brand hover:underline">Back to sign in</a></p>
</div>
<?php endif; ?>
