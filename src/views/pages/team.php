<?php
[$user, $tenantId] = require_tenant_admin();
page_title('Team');
$state = flash('member-new');
$roleOpts = array_combine(TENANT_ROLES, array_map('role_label', TENANT_ROLES));

// Search + role filter + paging (workspaces can have thousands of customers).
$perPage = in_array((int) ($_GET['per'] ?? 0), [25, 50, 100], true) ? (int) $_GET['per'] : 50;
$mq = trim((string) ($_GET['q'] ?? ''));
$mrole = in_array($_GET['role'] ?? '', TENANT_ROLES, true) ? $_GET['role'] : '';
$where = '"tenantId" = ?';
$params = [$tenantId];
if ($mq !== '') {
    $where .= " AND (LOWER(\"name\") LIKE ? ESCAPE '!' OR LOWER(\"email\") LIKE ? ESCAPE '!')";
    array_push($params, like_arg(mb_strtolower($mq)), like_arg(mb_strtolower($mq)));
}
if ($mrole) { $where .= ' AND "role" = ?'; $params[] = $mrole; }
$memberTotal = (int) q_val("SELECT COUNT(*) FROM \"User\" WHERE $where", $params);
$roleCounts = array_column(q_all('SELECT "role", COUNT(*) AS n FROM "User" WHERE "tenantId" = ? GROUP BY "role"', [$tenantId]), 'n', 'role');
$mpages = max(1, (int) ceil($memberTotal / $perPage));
$mpage = min(max(1, (int) ($_GET['page'] ?? 1)), $mpages);
$ids = pluck(q_all("SELECT \"id\" FROM \"User\" WHERE $where ORDER BY \"role\" ASC, \"name\" ASC LIMIT $perPage OFFSET " . (($mpage - 1) * $perPage), $params), 'id');
$members = $ids ? q_all('SELECT u.*, (SELECT COUNT(*) FROM "Ticket" t WHERE t."assigneeId" = u."id") AS "ticketCount" FROM "User" u WHERE u."id" IN (' . in_ph($ids) . ') ORDER BY u."role" ASC, u."name" ASC', $ids) : [];
$murl = function (array $c) use ($mq, $mrole, $mpage, $perPage): string {
    $p = array_merge(['q' => $mq, 'role' => $mrole, 'page' => $mpage, 'per' => $perPage], $c);
    if ((int) ($p['page'] ?? 1) <= 1) unset($p['page']);
    if ((int) ($p['per'] ?? 50) === 50) unset($p['per']);
    $p = array_filter($p, fn ($v) => $v !== '' && $v !== null);
    return '/team' . ($p ? '?' . http_build_query($p) : '');
};
$pagerOpts = ['perPageOptions' => [25, 50, 100], 'noun' => ['member', 'members']];
?>
<div>
  <?= page_header('Team', 'Manage who can access your workspace and what they can do.') ?>
  <div class="space-y-6">
    <div class="rounded-xl border border-border bg-surface p-6">
      <h3 class="mb-4 text-sm font-semibold">Invite a team member</h3>
      <form method="post" action="/action/member-create" class="space-y-4" data-pending="Adding…">
        <?= form_meta('member-new') ?>
        <?= banner($state) ?>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="name" class="<?= LABEL_CLS ?>">Name</label>
            <input id="name" name="name" placeholder="Jane Doe" required value="<?= e(old('member-new', 'name')) ?>" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="email" class="<?= LABEL_CLS ?>">Email</label>
            <input id="email" name="email" type="email" placeholder="jane@company.com" required value="<?= e(old('member-new', 'email')) ?>" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="password" class="<?= LABEL_CLS ?>">Temporary password</label>
            <input id="password" name="password" type="text" placeholder="At least 6 characters" required autocomplete="off" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="role" class="<?= LABEL_CLS ?>">Role</label>
            <select id="role" name="role" class="<?= SELECT_CLS ?>"><?= select_options($roleOpts, old('member-new', 'role', 'AGENT')) ?></select>
          </div>
        </div>
        <button type="submit" class="<?= btn_cls() ?>"><?= icon('user-plus', 16) ?> Add member</button>
      </form>
    </div>

    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('Members (' . number_format(array_sum($roleCounts)) . ')') ?>
      <form method="get" action="/team" class="flex flex-wrap items-center gap-2 border-b border-border px-4 py-3 sm:px-5">
        <div class="relative min-w-0 flex-1 sm:max-w-xs">
          <?= icon('search', 16, 'pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted') ?>
          <input name="q" type="search" value="<?= e($mq) ?>" placeholder="Search name or email…" class="h-9 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40">
        </div>
        <div class="-mx-1 flex gap-1 overflow-x-auto px-1">
          <?php foreach (['' => 'All'] + $roleOpts as $r => $label):
              $n = $r === '' ? array_sum($roleCounts) : (int) ($roleCounts[$r] ?? 0); ?>
            <a href="<?= e($murl(['role' => $r, 'page' => 1])) ?>" class="<?= cx('inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-sm transition-colors', $mrole === $r ? 'bg-brand/10 font-medium text-brand' : 'text-muted hover:bg-surface-2 hover:text-fg') ?>"><?= e($label) ?> <span class="text-xs opacity-70"><?= number_format($n) ?></span></a>
          <?php endforeach; ?>
        </div>
        <?php if ($mrole): ?><input type="hidden" name="role" value="<?= e($mrole) ?>"><?php endif; ?>
        <?php if ($perPage !== 50): ?><input type="hidden" name="per" value="<?= $perPage ?>"><?php endif; ?>
      </form>
      <?php if ($members): ?>
        <?= pager($mpage, $mpages, $memberTotal, $perPage, $murl, $pagerOpts + ['class' => 'border-b border-border px-4 py-2.5 sm:px-5']) ?>
      <?php endif; ?>
      <?php if (!$members): ?><p class="px-5 py-10 text-center text-sm text-muted">No members match.</p><?php endif; ?>
      <div class="divide-y divide-border">
        <?php foreach ($members as $m): $isSelf = $m['id'] === $user['id']; ?>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3.5 sm:px-5">
            <?= avatar($m['name'], $m['avatarColor'], 40) ?>
            <div class="min-w-0 flex-1 max-sm:basis-[calc(100%-3.25rem)]">
              <p class="truncate font-medium"><?= e($m['name']) ?><?php if ($isSelf): ?><span class="ml-1.5 text-xs text-muted">(you)</span><?php endif; ?></p>
              <p class="truncate text-xs text-muted"><?= e($m['email']) ?></p>
            </div>
            <span class="hidden text-xs text-muted sm:block"><?= (int) $m['ticketCount'] ?> assigned</span>
            <form method="post" action="/action/member-role" data-ajax data-autosave class="max-sm:ml-[3.25rem] max-sm:flex-1">
              <?= form_meta() ?><input type="hidden" name="userId" value="<?= e($m['id']) ?>">
              <select name="role" class="<?= str_replace('w-full ', '', INPUT_BASE) ?> h-9 w-32 cursor-pointer max-sm:w-full"><?= select_options($roleOpts, $m['role']) ?></select>
            </form>
            <?php if (!$isSelf): ?>
              <form method="post" action="/action/member-remove" data-ajax data-confirm-two-step>
                <?= form_meta() ?><input type="hidden" name="userId" value="<?= e($m['id']) ?>">
                <button type="button" data-confirm-start class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer" aria-label="Remove member"><?= icon('trash-2', 16) ?></button>
                <button type="submit" data-confirm-final hidden class="<?= btn_cls('danger', 'sm') ?>">Confirm</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if ($mpages > 1): ?>
        <?= pager($mpage, $mpages, $memberTotal, $perPage, $murl, $pagerOpts + ['class' => 'border-t border-border px-4 py-3 sm:px-5']) ?>
      <?php endif; ?>
    </div>
  </div>
</div>
