<?php
require_super_admin();
page_title('Platform');

$perPage = in_array((int) ($_GET['per'] ?? 0), [25, 50, 100], true) ? (int) $_GET['per'] : 25;
$tq = trim((string) ($_GET['q'] ?? ''));
$where = '1 = 1';
$params = [];
if ($tq !== '') {
    $where = "(LOWER(t.\"name\") LIKE ? ESCAPE '!' OR LOWER(t.\"slug\") LIKE ? ESCAPE '!')";
    $params = [like_arg(mb_strtolower($tq)), like_arg(mb_strtolower($tq))];
}
$tenantTotal = (int) q_val('SELECT COUNT(*) FROM "Tenant"');
$matchTotal = (int) q_val("SELECT COUNT(*) FROM \"Tenant\" t WHERE $where", $params);
$pages = max(1, (int) ceil($matchTotal / $perPage));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
// Per-tenant counts are computed only for the tenants on this page.
$tenants = q_all("SELECT t.*, (SELECT COUNT(*) FROM \"User\" u WHERE u.\"tenantId\" = t.\"id\") AS \"users\", (SELECT COUNT(*) FROM \"Ticket\" k WHERE k.\"tenantId\" = t.\"id\") AS \"tickets\"
    FROM (SELECT * FROM \"Tenant\" t WHERE $where ORDER BY t.\"createdAt\" DESC LIMIT $perPage OFFSET " . (($page - 1) * $perPage) . ') t ORDER BY t."createdAt" DESC', $params);

$totalUsers = (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" IS NOT NULL');
$totalTickets = (int) q_val('SELECT COUNT(*) FROM "Ticket"');
// "Open" = tickets in any non-closed status of their own tenant.
$openTickets = (int) q_val('SELECT COUNT(*) FROM "Ticket" k WHERE NOT EXISTS (SELECT 1 FROM "TicketStatus" s WHERE s."tenantId" = k."tenantId" AND s."key" = k."status" AND s."isClosed" = 1)');
$stats = [['Tenants', $tenantTotal, 'building-2'], ['Users', $totalUsers, 'users'], ['Tickets', $totalTickets, 'ticket'], ['Open / Pending', $openTickets, 'ticket']];
$purl = function (array $c) use ($tq, $page, $perPage): string {
    $p = array_merge(['q' => $tq, 'page' => $page, 'per' => $perPage], $c);
    if ((int) ($p['page'] ?? 1) <= 1) unset($p['page']);
    if ((int) ($p['per'] ?? 25) === 25) unset($p['per']);
    $p = array_filter($p, fn ($v) => $v !== '' && $v !== null);
    return '/platform' . ($p ? '?' . http_build_query($p) : '');
};
$pagerOpts = ['perPageOptions' => [25, 50, 100], 'noun' => ['tenant', 'tenants']];
?>
<div>
  <h1 class="text-2xl font-bold tracking-tight">Platform overview</h1>
  <p class="mt-1 text-sm text-muted">Every tenant on TicketingOS, isolated and self-serve.</p>

  <div class="mt-6 grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <?php foreach ($stats as [$label, $value, $ic]): ?>
      <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
        <div class="inline-flex rounded-lg bg-sky-500/10 p-2"><?= icon($ic, 20, 'text-sky-600 dark:text-sky-400') ?></div>
        <p class="mt-3 text-2xl font-bold tracking-tight tabular-nums sm:text-3xl"><?= number_format($value) ?></p>
        <p class="text-sm text-muted"><?= e($label) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-8 mb-3 flex flex-wrap items-center justify-between gap-3">
    <h2 class="text-sm font-semibold">Tenants <span class="font-normal text-muted">(<?= number_format($matchTotal) ?>)</span></h2>
    <form method="get" action="/platform" class="relative w-full sm:w-72" role="search">
      <?= icon('search', 16, 'pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted') ?>
      <input name="q" type="search" value="<?= e($tq) ?>" placeholder="Search tenants by name or slug…" class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40 sm:h-9">
      <?php if ($perPage !== 25): ?><input type="hidden" name="per" value="<?= $perPage ?>"><?php endif; ?>
    </form>
  </div>
  <div class="rounded-xl border border-border bg-surface">
    <?php if ($tenants): ?>
      <?= pager($page, $pages, $matchTotal, $perPage, $purl, $pagerOpts + ['class' => 'border-b border-border px-4 py-2.5 sm:px-5']) ?>
    <?php endif; ?>
    <div class="divide-y divide-border">
      <?php foreach ($tenants as $t): ?>
        <a href="/platform/tenants/<?= e($t['id']) ?>" class="flex items-center gap-3 px-4 py-3.5 hover:bg-surface-2 active:bg-surface-2 transition-colors sm:gap-4 sm:px-5 sm:py-4">
          <span class="grid h-10 w-10 shrink-0 place-items-center rounded-lg bg-brand/10 font-semibold text-brand sm:h-11 sm:w-11"><?= e(mb_strtoupper(mb_substr($t['name'], 0, 2))) ?></span>
          <div class="min-w-0 flex-1">
            <p class="truncate font-medium"><?= e($t['name']) ?></p>
            <p class="truncate text-xs text-muted">/<?= e($t['slug']) ?> · created <?= e(time_ago($t['createdAt'])) ?></p>
            <p class="mt-0.5 text-xs text-muted sm:hidden"><?= number_format((int) $t['users']) ?> users · <?= number_format((int) $t['tickets']) ?> tickets</p>
          </div>
          <div class="hidden gap-6 text-sm sm:flex">
            <div class="text-right"><p class="font-semibold tabular-nums"><?= number_format((int) $t['users']) ?></p><p class="text-xs text-muted">users</p></div>
            <div class="text-right"><p class="font-semibold tabular-nums"><?= number_format((int) $t['tickets']) ?></p><p class="text-xs text-muted">tickets</p></div>
          </div>
          <?= icon('chevron-right', 18, 'text-muted') ?>
        </a>
      <?php endforeach; ?>
      <?php if (!$tenants): ?><p class="px-5 py-12 text-center text-sm text-muted"><?= $tq ? 'No tenants match.' : 'No tenants yet.' ?></p><?php endif; ?>
    </div>
    <?php if ($pages > 1): ?>
      <?= pager($page, $pages, $matchTotal, $perPage, $purl, $pagerOpts + ['class' => 'border-t border-border px-4 py-3 sm:px-5']) ?>
    <?php endif; ?>
  </div>
</div>
