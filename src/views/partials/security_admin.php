<?php
/**
 * Settings → Security (administrators only).
 * @var array $me @var string $tenantId @var callable $s
 */
$ss = $s('security');
$min = password_min_length($tenantId);
$mix = (bool) tenant_setting($tenantId, 'passwordRequireMix', false);
$stat = q_one('SELECT
    SUM(CASE WHEN "role" = \'ADMIN\' AND "active" = 1 THEN 1 ELSE 0 END) AS "admins",
    SUM(CASE WHEN "active" = 0 THEN 1 ELSE 0 END) AS "inactive",
    SUM(CASE WHEN "role" <> \'CUSTOMER\' AND "lastLoginAt" IS NULL THEN 1 ELSE 0 END) AS "neverStaff"
  FROM "User" WHERE "tenantId" = ?', [$tenantId]);
$tile = function (string $ic, string $label, int $n, string $hint, ?string $href, string $tone) {
    $tag = $href ? 'a href="' . e($href) . '"' : 'div';
    return '<' . $tag . ' class="flex items-start gap-3 rounded-xl border border-border bg-surface p-4' . ($href ? ' transition-colors hover:bg-surface-2/50' : '') . '">'
        . '<span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg ' . $tone . '">' . icon($ic, 17) . '</span>'
        . '<span class="min-w-0"><span class="block text-xl font-semibold tabular-nums">' . number_format($n) . '</span><span class="block text-sm font-medium">' . e($label) . '</span><span class="block text-xs text-muted">' . e($hint) . '</span></span>'
        . '</' . ($href ? 'a' : 'div') . '>';
};
?>
<div class="grid gap-3 sm:grid-cols-3">
  <?= $tile('crown', 'Active administrators', (int) $stat['admins'], (int) $stat['admins'] < 2 ? 'Consider a second admin as a backup' : 'Full access to everything', '/settings?urole=ADMIN#users', (int) $stat['admins'] < 2 ? 'bg-amber-500/10 text-amber-600' : 'bg-brand/10 text-brand') ?>
  <?= $tile('user-x', 'Deactivated accounts', (int) $stat['inactive'], 'Can’t sign in until reactivated', '/settings?ustatus=inactive#users', 'bg-red-500/10 text-red-600') ?>
  <?= $tile('clock', 'Staff never signed in', (int) $stat['neverStaff'], 'Pending invitations or unused accounts', '/settings?ustatus=never#users', 'bg-sky-500/10 text-sky-600') ?>
</div>

<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('key-round', 'Password rules')) ?>
  <form method="post" action="/action/security-save" class="space-y-5 p-5 sm:p-6" data-dirty>
    <?= form_meta('security', '/settings#security') ?>
    <?= banner($ss, true) ?>
    <div>
      <div class="flex items-center justify-between gap-4">
        <label class="text-sm font-medium" for="pw-min">Minimum length</label>
        <span class="rounded-lg bg-surface-2 px-2.5 py-1 text-sm font-semibold tabular-nums"><output data-range-out for="pw-min"><?= $min ?></output> characters</span>
      </div>
      <input id="pw-min" name="passwordMinLength" type="range" min="6" max="32" step="1" value="<?= $min ?>" data-range class="mt-3 w-full cursor-pointer accent-brand">
      <div class="mt-1 flex justify-between text-[11px] text-muted"><span>6 · basic</span><span>12 · recommended</span><span>32</span></div>
    </div>
    <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-border p-4">
      <span><span class="block text-sm font-medium">Require letters and numbers</span><span class="block text-xs text-muted">Passwords must mix at least one letter and one digit.</span></span>
      <span class="relative mt-0.5 inline-flex shrink-0 items-center">
        <input type="checkbox" name="passwordRequireMix" <?= $mix ? 'checked' : '' ?> class="peer sr-only">
        <span class="h-5 w-9 rounded-full bg-border transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40"></span>
        <span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span>
      </span>
    </label>
    <p class="flex items-start gap-2 rounded-lg bg-surface-2/60 p-3 text-xs text-muted"><?= icon('info', 14, 'mt-px shrink-0') ?> Rules apply to new passwords: invitations, reset links and passwords set by an admin. Existing passwords keep working.</p>
    <button type="submit" data-dirty-save disabled class="<?= btn_cls() ?>"><?= icon('check', 16) ?> Save rules</button>
  </form>
</div>

<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('shield', 'Built-in protections')) ?>
  <ul class="grid gap-3 p-5 text-sm sm:grid-cols-2 sm:p-6">
    <?php foreach ([
        ['lock', 'Deactivating a user signs them out everywhere and revokes their reset links and push devices.'],
        ['crown', 'A workspace always keeps at least one active administrator.'],
        ['shield-check', 'Only administrators can manage roles, administrators and these rules.'],
        ['mail', 'Reset and invitation links are single-use and expire (1 hour / 72 hours).'],
    ] as [$ic, $t]): ?>
      <li class="flex items-start gap-3"><span class="grid h-7 w-7 shrink-0 place-items-center rounded-lg bg-emerald-500/10 text-emerald-600"><?= icon($ic, 14) ?></span><span class="text-muted"><?= e($t) ?></span></li>
    <?php endforeach; ?>
  </ul>
</div>
