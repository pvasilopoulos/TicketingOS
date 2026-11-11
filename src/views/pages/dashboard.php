<?php
[$user, $tenantId] = require_tenant_user();
page_title('Dashboard');
$staff = is_staff($user['role']);

$statuses = tenant_statuses($tenantId);
$priorities = tenant_priorities($tenantId);
$closedKeys = closed_status_keys($statuses);
$topKeys = top_priority_keys($priorities);

$sp = [];
$scope = ticket_scope_sql($user, $tenantId, $sp);

$total = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $scope", $sp);
$resolved = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $scope AND t.\"status\" IN (" . in_ph($closedKeys) . ')', [...$sp, ...$closedKeys]);
$urgent = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $scope AND t.\"status\" NOT IN (" . in_ph($closedKeys) . ') AND t."priority" IN (' . in_ph($topKeys) . ')', [...$sp, ...$closedKeys, ...$topKeys]);
if (!$closedKeys) {
    // NOT IN (NULL) is never true; with no closed statuses every ticket is open.
    $urgent = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $scope AND t.\"priority\" IN (" . in_ph($topKeys) . ')', [...$sp, ...$topKeys]);
}
$byStatus = [];
foreach (q_all("SELECT t.\"status\", COUNT(*) AS n FROM \"Ticket\" t WHERE $scope GROUP BY t.\"status\"", $sp) as $r) $byStatus[$r['status']] = (int) $r['n'];

$recent = ticket_rows_by_ids(pluck(q_all("SELECT t.\"id\" FROM \"Ticket\" t WHERE $scope ORDER BY t.\"createdAt\" DESC LIMIT 6", $sp), 'id'), $statuses, $priorities);

// 14-day trend (local days).
$tz = new DateTimeZone(date_default_timezone_get());
$today = new DateTimeImmutable('today', $tz);
$days = [];
$index = [];
for ($i = 13; $i >= 0; $i--) {
    $d = $today->modify("-$i days");
    $index[$d->format('Y-m-d')] = count($days);
    $days[] = ['date' => $d->format('M j'), 'count' => 0];
}
$from = iso_from_ts((float) $today->modify('-13 days')->format('U'));
foreach (q_all("SELECT t.\"createdAt\" FROM \"Ticket\" t WHERE $scope AND t.\"createdAt\" >= ?", [...$sp, $from]) as $r) {
    $k = local_dt($r['createdAt'])->format('Y-m-d');
    if (isset($index[$k])) $days[$index[$k]]['count']++;
}

$pie = [];
foreach ($statuses as $s) {
    $v = $byStatus[$s['key']] ?? 0;
    if ($v > 0) $pie[] = ['name' => $s['label'], 'value' => $v, 'color' => $s['color']];
}

$unresolved = $total - $resolved;
$stats = [
    ['Total tickets', $total, 'ticket', 'text-brand', 'bg-brand/10'],
    ['Unresolved', $unresolved, 'clock', 'text-blue-600 dark:text-blue-400', 'bg-blue-500/10'],
    ['Resolved', $resolved, 'circle-check', 'text-emerald-600 dark:text-emerald-400', 'bg-emerald-500/10'],
    ['Urgent open', $urgent, 'triangle-alert', 'text-red-600 dark:text-red-400', 'bg-red-500/10'],
];
?>
<div>
  <?= page_header('Hello, ' . first_name($user['name']) . ' 👋', $staff ? "Here's what's happening across your helpdesk." : 'Track the status of your support requests.') ?>

  <?php if ($urgent > 0 && $staff): ?>
    <div class="mb-6 flex items-center gap-2 rounded-xl border border-red-200 dark:border-red-500/30 bg-red-50 dark:bg-red-500/10 px-4 py-3 text-sm text-red-700 dark:text-red-300">
      <?= icon('triangle-alert', 18) ?>
      <?= number_format($urgent) ?> urgent <?= $urgent === 1 ? 'ticket' : 'tickets' ?> need attention.
      <a href="/tickets?priority=<?= e(rawurlencode($topKeys[0] ?? '')) ?>" class="ml-auto font-medium underline">View</a>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <?php foreach ($stats as [$label, $value, $ic, $color, $bg]): ?>
      <div class="rounded-xl border border-border bg-surface p-5">
        <div class="inline-flex rounded-lg p-2 <?= $bg ?>"><?= icon($ic, 20, $color) ?></div>
        <p class="mt-3 text-2xl font-bold tracking-tight tabular-nums sm:text-3xl"><?= number_format($value) ?></p>
        <p class="text-sm text-muted"><?= e($label) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-3">
    <div class="rounded-xl border border-border bg-surface lg:col-span-2">
      <?= card_header('Tickets created (last 14 days)') ?>
      <div class="h-64 w-full px-2 pb-2"><?= trend_chart($days) ?></div>
    </div>
    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('By status') ?>
      <?= $pie ? status_pie($pie) : '<p class="px-5 py-16 text-center text-sm text-muted">No data yet</p>' ?>
    </div>
  </div>

  <div class="mt-6 rounded-xl border border-border bg-surface">
    <?= card_header('Recent tickets', '<a href="/tickets" class="text-sm font-medium text-brand hover:underline">View all</a>') ?>
    <?php if ($recent): ?>
      <div class="divide-y divide-border">
        <?php foreach ($recent as $t) echo ticket_list_item($t); ?>
      </div>
    <?php else: ?>
      <p class="px-5 py-16 text-center text-sm text-muted">
        No tickets yet. <a href="/tickets/new" class="font-medium text-brand hover:underline">Create the first one</a>
      </p>
    <?php endif; ?>
  </div>
</div>
