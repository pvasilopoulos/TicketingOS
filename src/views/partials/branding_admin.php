<?php
/**
 * Settings → Branding (super admins only): app name and logo for the whole platform.
 * @var array $me @var callable $s
 */
$b = branding();
$logoUrl = brand_logo_url();
$bs = $s('branding');
?>
<div class="rounded-xl border border-border bg-surface">
  <div class="flex flex-wrap items-start gap-4 border-b border-border p-5 sm:p-6">
    <span class="hidden h-11 w-11 shrink-0 place-items-center rounded-xl bg-amber-500/10 text-amber-600 sm:grid"><?= icon('sparkles', 22) ?></span>
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Branding</h2>
      <p class="mt-0.5 text-sm text-muted">The app’s name and logo, shown in the side menu and the phone header, on the sign-in pages, the browser tab, the installed app and emails. Applies to every workspace. Only super admins see this.</p>
    </div>
  </div>

  <form method="post" action="/action/branding-save" enctype="multipart/form-data" class="grid gap-6 p-5 sm:p-6 lg:grid-cols-[minmax(0,1fr)_18rem]" data-pending="Saving…">
    <?= form_meta('branding', '/settings#branding') ?>
    <div class="space-y-5">
      <?= banner($bs, true) ?>
      <div>
        <label for="appName" class="<?= LABEL_CLS ?>">App name</label>
        <input id="appName" name="appName" value="<?= e(old('branding', 'appName') ?: $b['name']) ?>" maxlength="40" required class="<?= INPUT_CLS ?>" data-brand-name>
        <p class="mt-1.5 text-xs text-muted">2–40 characters. The installed app on phones shows the first 12.</p>
      </div>

      <div>
        <p class="<?= LABEL_CLS ?>">Logo</p>
        <label class="flex cursor-pointer flex-col items-center justify-center gap-2 rounded-xl border-2 border-dashed border-border bg-surface-2/40 px-4 py-6 text-center transition-colors hover:border-brand/50 hover:bg-brand/5">
          <span class="grid h-10 w-10 place-items-center rounded-full bg-surface text-muted ring-1 ring-border"><?= icon('image', 18) ?></span>
          <span class="text-sm font-medium">Choose an image <span class="font-normal text-muted">or drop it here</span></span>
          <span class="text-xs text-muted">Square PNG, SVG, WebP or JPG · up to 1 MB · at least 256×256 px looks best</span>
          <input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/svg+xml" class="sr-only" data-brand-file>
        </label>
        <?php if ($logoUrl): ?>
          <label class="mt-3 flex items-center gap-2 text-sm text-muted"><input type="checkbox" name="removeLogo" value="1" class="h-4 w-4 rounded border-border accent-brand" data-brand-remove> Remove the logo and use the default icon</label>
        <?php endif; ?>
      </div>

      <button type="submit" class="<?= btn_cls('primary') ?>"><?= icon('check', 16) ?> Save branding</button>
    </div>

    <!-- Live preview -->
    <div class="space-y-3">
      <p class="text-xs font-semibold uppercase tracking-wide text-muted">Preview</p>
      <div class="overflow-hidden rounded-2xl border border-border bg-bg">
        <div class="flex items-center gap-2.5 border-b border-border bg-surface px-4 py-3">
          <span data-brand-preview-mark><?= brand_mark(32) ?></span>
          <span class="truncate text-sm font-semibold" data-brand-preview-name><?= e($b['name']) ?></span>
        </div>
        <div class="space-y-2 p-4">
          <div class="h-2.5 w-2/3 rounded-full bg-surface-2"></div>
          <div class="h-2.5 w-1/2 rounded-full bg-surface-2"></div>
          <div class="mt-4 h-9 rounded-lg bg-gradient-to-r from-brand to-violet-600 opacity-90"></div>
        </div>
      </div>
      <div class="flex items-center gap-2 rounded-lg border border-border bg-surface px-3 py-2 text-xs">
        <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="" class="h-4 w-4 rounded-sm object-contain" data-brand-preview-fav><?php else: ?><span class="h-4 w-4 rounded-sm bg-brand" data-brand-preview-fav></span><?php endif; ?>
        <span class="truncate" data-brand-preview-tab><?= e($b['name']) ?> — Helpdesk</span>
      </div>
      <?php if ($b['updatedAt']): ?><p class="text-xs text-muted">Last changed <?= e(time_ago($b['updatedAt'])) ?></p><?php endif; ?>
    </div>
  </form>
</div>
