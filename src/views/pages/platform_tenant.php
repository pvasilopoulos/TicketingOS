<?php
/** @var string $id */
$user = require_super_admin();
$tenant = q_one('SELECT * FROM "Tenant" WHERE "id" = ?', [$id]);
if (!$tenant) abort(404);
page_title($tenant['name']);

$userCount = (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" = ?', [$id]);
// Staff first; large workspaces only show the first 50 here.
$users = q_all("SELECT * FROM \"User\" WHERE \"tenantId\" = ? ORDER BY CASE \"role\" WHEN 'SUPER_ADMIN' THEN 0 WHEN 'ADMIN' THEN 0 WHEN 'AGENT' THEN 1 ELSE 2 END, \"name\" ASC LIMIT 50", [$id]);
$statuses = tenant_statuses($id);
$priorities = tenant_priorities($id);
$tickets = q_all('SELECT t.*, r."name" AS "rName", r."avatarColor" AS "rColor" FROM "Ticket" t JOIN "User" r ON r."id" = t."requesterId" WHERE t."tenantId" = ? ORDER BY t."createdAt" DESC LIMIT 15', [$id]);
$ticketCount = (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ?', [$id]);
?>
<div>
  <a href="/platform" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><?= icon('arrow-left', 16) ?> Back to platform</a>

  <div class="flex flex-wrap items-center gap-4">
    <span class="grid h-14 w-14 place-items-center rounded-xl bg-brand/10 text-lg font-bold text-brand"><?= e(mb_strtoupper(mb_substr($tenant['name'], 0, 2))) ?></span>
    <div>
      <h1 class="text-2xl font-bold tracking-tight"><?= e($tenant['name']) ?></h1>
      <p class="text-sm text-muted">/<?= e($tenant['slug']) ?> · <?= number_format($userCount) ?> users · <?= number_format($ticketCount) ?> tickets</p>
    </div>
    <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
      <?php if (($user['tenantId'] ?? null) === $tenant['id']): ?>
        <span class="inline-flex h-10 items-center gap-1.5 rounded-lg bg-emerald-500/10 px-3 text-sm font-medium text-emerald-700 dark:text-emerald-400"><?= icon('house', 15) ?> Your workspace — you work here as an agent</span>
      <?php else: ?>
        <form method="post" action="/action/platform-home" data-confirm="Make <?= e($tenant['name']) ?> your own workspace? You’ll appear there as an agent (assignee lists, notifications) and land there after signing in.">
          <?= form_meta() ?><input type="hidden" name="tenantId" value="<?= e($tenant['id']) ?>">
          <button type="submit" class="<?= btn_cls('outline', 'md') ?>"><?= icon('house', 16) ?> Make this my workspace</button>
        </form>
      <?php endif; ?>
      <form method="post" action="/action/platform-enter" class="flex-1 sm:flex-none">
        <?= form_meta() ?>
        <input type="hidden" name="tenantId" value="<?= e($tenant['id']) ?>">
        <button type="submit" class="<?= btn_cls('primary', 'md', 'w-full sm:w-auto') ?>"><?= icon('log-in', 16) ?> Enter board</button>
      </form>
    </div>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-[minmax(0,1fr)_340px] xl:grid-cols-[minmax(0,1fr)_400px]">
    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('Recent tickets') ?>
      <div class="divide-y divide-border">
        <?php foreach ($tickets as $t):
            $s = resolve_status($statuses, $t['status']);
            $p = resolve_priority($priorities, $t['priority']); ?>
          <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5 px-4 py-3 sm:flex-nowrap sm:px-5">
            <?= avatar($t['rName'], $t['rColor'], 32) ?>
            <div class="min-w-0 flex-1 max-sm:basis-[calc(100%-2.75rem)]">
              <p class="truncate text-sm font-medium"><?= e($t['subject']) ?></p>
              <p class="text-xs text-muted">#<?= (int) $t['number'] ?> · <?= e(time_ago($t['createdAt'])) ?></p>
            </div>
            <span class="flex items-center gap-1.5 max-sm:ml-11"><?= priority_badge($p['label'], $p['color']) ?><?= status_badge($s['label'], $s['color']) ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$tickets): ?><p class="px-5 py-10 text-center text-sm text-muted">No tickets.</p><?php endif; ?>
      </div>
    </div>

    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('Users', $userCount > 50 ? '<span class="text-xs text-muted">first 50 of ' . number_format($userCount) . '</span>' : '') ?>
      <div class="divide-y divide-border">
        <?php foreach ($users as $u): ?>
          <div class="flex items-center gap-3 px-4 py-3 sm:px-5">
            <?= avatar($u['name'], $u['avatarColor'], 36) ?>
            <div class="min-w-0 flex-1">
              <p class="truncate text-sm font-medium" title="<?= e($u['name']) ?>"><?= e($u['name']) ?></p>
              <p class="truncate text-xs text-muted" title="<?= e($u['email']) ?>"><?= e($u['email']) ?></p>
              <span class="mt-1 inline-flex items-center rounded-full bg-surface-2 px-2 py-0.5 text-[11px] font-medium text-muted"><?= e(role_label($u['role'])) ?></span>
            </div>
            <?php if ($u['role'] !== 'SUPER_ADMIN' && (int) $u['active']): ?>
              <form method="post" action="/action/impersonate" class="shrink-0"><?= form_meta() ?><input type="hidden" name="userId" value="<?= e($u['id']) ?>">
                <button type="submit" title="Sign in as <?= e($u['name']) ?>" aria-label="Sign in as <?= e($u['name']) ?>" class="<?= btn_cls('outline', 'sm', 'max-xl:w-8 max-xl:px-0') ?>"><?= icon('log-in', 14) ?><span class="max-xl:hidden">Sign in as</span></button></form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <p class="mt-4 text-xs text-muted">Tenant data is fully isolated from other tenants. “Sign in as” opens the app exactly as that person sees it; every use is in the workspace’s audit log.</p>
</div>
