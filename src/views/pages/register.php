<?php $state = flash('register'); ?>
<div>
  <h1 class="text-2xl font-bold">Create your workspace</h1>
  <p class="mt-1 text-sm text-muted">Spin up an isolated helpdesk for your organization.</p>

  <form method="post" action="/action/register" class="mt-8 space-y-4" data-pending="Creating workspace…">
    <?= form_meta('register', '/register') ?>
    <?= banner($state) ?>
    <div>
      <label for="organizationName" class="<?= LABEL_CLS ?>">Organization name</label>
      <input id="organizationName" name="organizationName" placeholder="Acme Inc" required value="<?= e(old('register', 'organizationName')) ?>" class="<?= INPUT_CLS ?>">
    </div>
    <div>
      <label for="name" class="<?= LABEL_CLS ?>">Your name</label>
      <input id="name" name="name" placeholder="Jane Doe" required autocomplete="name" value="<?= e(old('register', 'name')) ?>" class="<?= INPUT_CLS ?>">
    </div>
    <div>
      <label for="email" class="<?= LABEL_CLS ?>">Work email</label>
      <input id="email" name="email" type="email" placeholder="you@company.com" required autocomplete="email" value="<?= e(old('register', 'email')) ?>" class="<?= INPUT_CLS ?>">
    </div>
    <div>
      <label for="password" class="<?= LABEL_CLS ?>">Password</label>
      <input id="password" name="password" type="password" placeholder="At least 6 characters" required autocomplete="new-password" class="<?= INPUT_CLS ?>">
    </div>
    <button type="submit" class="<?= btn_cls('primary', 'lg', 'w-full') ?>">Create workspace</button>
  </form>

  <p class="mt-6 text-sm text-muted">
    Already have an account?
    <a href="/login" class="font-medium text-brand hover:underline">Sign in</a>
  </p>
</div>
