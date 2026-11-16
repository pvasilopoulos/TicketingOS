<?php
/**
 * Organization dashboard: support contract, tickets, response times, time and
 * invoices of one customer organization. For the organization's managers, and
 * for staff (Settings → Organizations → Dashboard).
 */
[$user, $tenantId] = require_tenant_user();
$staff = is_staff($user['role']);

// Which organizations this person may open here.
if ($staff) {
    if (!can_any($user, 'tickets.view_all', 'orgs.manage', 'reports.view')) redirect('/dashboard');
    $choices = [];
} else {
    $choices = array_values(array_filter(user_orgs($user['id']), fn ($o) => $o['isManager']));
    if (!$choices) redirect('/dashboard');
}
$id = (string) ($_GET['id'] ?? '') ?: ($choices[0]['id'] ?? '');
$org = q_one('SELECT * FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]);
if (!$org || (!$staff && !in_array($org['id'], array_column($choices, 'id'), true))) abort(404);
page_title($org['name'] . ' · Organization');

$statuses = tenant_statuses($tenantId);
$priorities = tenant_priorities($tenantId);
$closed = closed_status_keys($statuses);
$inOrg = 't."tenantId" = ? AND ' . REQUESTER_IN_ORG_SQL;
$p = [$tenantId, $org['id']];
$openSql = $closed ? ' AND t."status" NOT IN (' . in_ph($closed) . ')' : '';
$now = now_iso();
$d30 = now_iso(-30 * 86400);
$d90 = now_iso(-90 * 86400);

$open = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $inOrg$openSql", [...$p, ...$closed]);
$overdue = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $inOrg$openSql AND t.\"dueAt\" < ?", [...$p, ...$closed, $now]);
$new30 = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $inOrg AND t.\"createdAt\" >= ?", [...$p, $d30]);
$resolved30 = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $inOrg AND t.\"closedAt\" >= ?", [...$p, $d30]);

// Service level over the last 90 days.
$slaRow = q_one("SELECT COUNT(*) AS n, SUM(CASE WHEN t.\"closedAt\" <= t.\"dueAt\" THEN 1 ELSE 0 END) AS ok FROM \"Ticket\" t
    WHERE $inOrg AND t.\"closedAt\" >= ? AND t.\"dueAt\" IS NOT NULL", [...$p, $d90]);
$slaPct = (int) $slaRow['n'] ? (int) round((int) $slaRow['ok'] / (int) $slaRow['n'] * 100) : null;
$firsts = q_all("SELECT t.\"createdAt\", t.\"firstResponseAt\" FROM \"Ticket\" t WHERE $inOrg AND t.\"createdAt\" >= ? AND t.\"firstResponseAt\" IS NOT NULL", [...$p, $d90]);
$avgFirst = $firsts ? (int) round(array_sum(array_map(fn ($r) => max(0, ts($r['firstResponseAt']) - ts($r['createdAt'])), $firsts)) / count($firsts) / 60) : null;
$fmtDur = fn (int $min) => $min < 60 ? $min . ' min' : ($min < 1440 ? round($min / 60, 1) . ' h' : round($min / 1440, 1) . ' days');

// Support contracts and time.
$contracts = array_values(array_filter(org_contracts($org['id']), fn ($c) => contract_is_current($c)));
$monthStart = date('Y-m-01');
$prevStart = date('Y-m-01', strtotime('first day of last month'));
$timeSql = 'SELECT COALESCE(SUM(te."minutes"), 0) FROM "TimeEntry" te JOIN "Ticket" t ON t."id" = te."ticketId" WHERE ' . $inOrg . ' AND te."billable" = 1 AND te."workedAt" >= ? AND te."workedAt" < ?';
$minThis = (int) q_val($timeSql, [...$p, $monthStart, date('Y-m-d', strtotime('+1 day'))]);
$minPrev = (int) q_val($timeSql, [...$p, $prevStart, $monthStart]);

// Invoices (issued, paid; drafts and voided ones stay with staff).
$invoices = q_all('SELECT * FROM "Invoice" WHERE "organizationId" = ? AND "status" IN (\'issued\', \'paid\') ORDER BY "issueDate" DESC, "seq" DESC LIMIT 5', [$org['id']]);
$unpaid = (float) q_val('SELECT COALESCE(SUM("total"), 0) FROM "Invoice" WHERE "organizationId" = ? AND "status" = \'issued\'', [$org['id']]);
$canInvoices = $staff ? can($user, 'invoices.manage') : true;

// Open tickets (overdue first, then the most urgent) and their priorities.
$prioOrder = array_column($priorities, 'order', 'key');
$openRows = ticket_rows_by_ids(pluck(q_all("SELECT t.\"id\" FROM \"Ticket\" t WHERE $inOrg$openSql
    ORDER BY CASE WHEN t.\"dueAt\" IS NOT NULL AND t.\"dueAt\" < ? THEN 0 ELSE 1 END, t.\"createdAt\" DESC LIMIT 8", [...$p, ...$closed, $now]), 'id'), $statuses, $priorities);
$byPrio = array_column(q_all("SELECT t.\"priority\", COUNT(*) AS n FROM \"Ticket\" t WHERE $inOrg$openSql GROUP BY t.\"priority\"", [...$p, ...$closed]), 'n', 'priority');
$people = (int) q_val('SELECT COUNT(*) FROM "UserOrganization" uo JOIN "User" u ON u."id" = uo."userId" WHERE uo."organizationId" = ? AND u."role" = \'CUSTOMER\' AND u."active" = 1', [$org['id']]);
$managers = pluck(q_all('SELECT u."name" FROM "UserOrganization" uo JOIN "User" u ON u."id" = uo."userId" WHERE uo."organizationId" = ? AND uo."isManager" = 1 ORDER BY u."name"', [$org['id']]), 'name');

$stats = [
    ['Open tickets', number_format($open), 'ticket', 'text-brand', 'bg-brand/10', $overdue ? "$overdue overdue" : 'none overdue'],
    ['New · 30 days', number_format($new30), 'circle-plus', 'text-blue-600 dark:text-blue-400', 'bg-blue-500/10', number_format($resolved30) . ' resolved'],
    ['Resolved on time', $slaPct === null ? '—' : $slaPct . '%', 'circle-check', 'text-emerald-600 dark:text-emerald-400', 'bg-emerald-500/10', 'last 90 days' . ((int) $slaRow['n'] ? ' · ' . (int) $slaRow['n'] . ' tickets' : '')],
    ['First response', $avgFirst === null ? '—' : $fmtDur($avgFirst), 'clock', 'text-amber-600 dark:text-amber-400', 'bg-amber-500/10', 'average · last 90 days'],
];
$ticketsUrl = '/tickets?org=' . rawurlencode($org['id']);
?>
<div>
  <?php if ($staff): ?><a href="/settings?org=<?= e($org['id']) ?>#orgs" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><?= icon('arrow-left', 15) ?> Organizations</a><?php endif; ?>
  <?= page_header($org['name'], ($people === 1 ? '1 person' : number_format($people) . ' people') . ($managers ? ' · managed by ' . human_list($managers) : ''),
      ($staff || $open ? '<a href="' . e($ticketsUrl) . '" class="' . btn_cls('outline') . '">' . icon('ticket', 16) . ' All tickets</a>' : '')) ?>

  <?php if (count($choices) > 1): ?>
    <div class="-mt-2 mb-5 flex flex-wrap gap-2">
      <?php foreach ($choices as $c): ?>
        <a href="/organization?id=<?= e($c['id']) ?>" class="<?= cx('rounded-full border px-3 py-1 text-sm', $c['id'] === $org['id'] ? 'border-brand/50 bg-brand/10 text-brand' : 'border-border text-muted hover:text-fg') ?>"><?= e($c['name']) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
    <?php foreach ($stats as [$label, $value, $ic, $color, $bg, $sub]): ?>
      <div class="rounded-xl border border-border bg-surface p-5">
        <div class="inline-flex rounded-lg p-2 <?= $bg ?>"><?= icon($ic, 20, $color) ?></div>
        <p class="mt-3 text-2xl font-bold tracking-tight tabular-nums sm:text-3xl"><?= e($value) ?></p>
        <p class="text-sm text-muted"><?= e($label) ?></p>
        <p class="mt-0.5 text-xs text-muted/80"><?= e($sub) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-3">
    <!-- Open tickets -->
    <div class="rounded-xl border border-border bg-surface lg:col-span-2">
      <?= card_header('Open tickets', $open > count($openRows) ? '<a href="' . e($ticketsUrl . ($closed ? '&status=' . rawurlencode(implode(',', array_diff(array_column($statuses, 'key'), $closed))) : '')) . '" class="text-sm font-medium text-brand hover:underline">View all ' . number_format($open) . '</a>' : '') ?>
      <?php if ($byPrio): ?>
        <div class="flex flex-wrap gap-2 px-5 pb-3">
          <?php foreach ($priorities as $pr): if (empty($byPrio[$pr['key']])) continue; ?>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-border px-2.5 py-0.5 text-xs"><span class="h-2 w-2 rounded-full" style="background-color:<?= safe_color($pr['color']) ?>"></span><?= e($pr['label']) ?> <strong class="tabular-nums"><?= (int) $byPrio[$pr['key']] ?></strong></span>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if (!$openRows): ?>
        <p class="px-5 pb-8 pt-4 text-center text-sm text-muted"><?= icon('circle-check', 28, 'mx-auto mb-2 text-emerald-500') ?>No open tickets — all caught up.</p>
      <?php else: ?>
        <div class="divide-y divide-border border-t border-border"><?php foreach ($openRows as $t) echo ticket_list_item($t); ?></div>
      <?php endif; ?>
    </div>

    <div class="space-y-6">
      <!-- Support contract -->
      <div class="rounded-xl border border-border bg-surface p-5">
        <h3 class="flex items-center gap-2 text-sm font-semibold"><?= icon('file-text', 16, 'text-muted') ?> Support contract</h3>
        <?php if (!$contracts): ?>
          <p class="mt-3 text-sm text-muted">No active support contract.</p>
        <?php endif; ?>
        <?php foreach ($contracts as $c): $st = contract_state($c);
            $bar = ['ok' => 'bg-emerald-500', 'low' => 'bg-amber-500', 'over' => 'bg-red-500', 'expired' => 'bg-slate-400'][$st['level']]; ?>
          <div class="mt-3">
            <p class="text-sm font-medium"><?= e($c['name'] ?? 'Contract') ?></p>
            <?php if ($st['included'] !== null): ?>
              <div class="mt-2 h-2 overflow-hidden rounded-full bg-surface-2"><div class="h-full rounded-full <?= $bar ?>" style="width:<?= (int) $st['pct'] ?>%"></div></div>
              <p class="mt-1.5 flex justify-between text-xs text-muted"><span><?= e(fmt_minutes($st['used'])) ?> used of <?= e(fmt_minutes($st['included'])) ?></span>
                <span class="<?= $st['left'] < 0 ? 'font-medium text-red-600' : '' ?>"><?= $st['left'] >= 0 ? e(fmt_minutes($st['left'])) . ' left' : e(fmt_minutes(-$st['left'])) . ' over' ?></span></p>
            <?php else: ?>
              <p class="mt-1 text-xs text-muted"><?= e(fmt_minutes($st['used'])) ?> used · no hour limit</p>
            <?php endif; ?>
            <p class="mt-1 text-xs text-muted">
              <?= $c['endsOn'] ? 'Until ' . e(date('j M Y', strtotime($c['endsOn']))) . ($st['daysLeft'] !== null && $st['daysLeft'] <= 30 ? ' · ' . max(0, $st['daysLeft']) . ' days left' : '') : 'No end date' ?>
              <?= !empty($c['resolutionHours']) ? ' · resolution within ' . (int) $c['resolutionHours'] . ' h' : '' ?>
            </p>
          </div>
        <?php endforeach; ?>
        <div class="mt-4 grid grid-cols-2 gap-3 border-t border-border pt-3 text-sm">
          <div><p class="text-xs text-muted">Billable time this month</p><p class="font-semibold tabular-nums"><?= e(fmt_minutes($minThis)) ?></p></div>
          <div><p class="text-xs text-muted">Last month</p><p class="font-semibold tabular-nums"><?= e(fmt_minutes($minPrev)) ?></p></div>
        </div>
      </div>

      <!-- Invoices -->
      <?php if ($canInvoices): ?>
        <div class="rounded-xl border border-border bg-surface p-5">
          <div class="flex items-center justify-between">
            <h3 class="flex items-center gap-2 text-sm font-semibold"><?= icon('receipt', 16, 'text-muted') ?> Invoices</h3>
            <?php if ($invoices): ?><a href="/invoices" class="text-xs font-medium text-brand hover:underline">All</a><?php endif; ?>
          </div>
          <?php if (!$invoices): ?>
            <p class="mt-3 text-sm text-muted">No invoices yet.</p>
          <?php else: ?>
            <?php if ($unpaid > 0): ?><p class="mt-2 rounded-lg bg-amber-500/10 px-3 py-2 text-sm text-amber-700 dark:text-amber-300">Unpaid: <strong><?= e(money($unpaid, $invoices[0]['currency'])) ?></strong></p><?php endif; ?>
            <ul class="mt-2 divide-y divide-border">
              <?php foreach ($invoices as $inv): ?>
                <li><a href="/invoices/<?= e($inv['id']) ?>" class="flex items-center gap-2 py-2 text-sm hover:text-brand">
                  <span class="min-w-0 flex-1"><span class="block truncate font-medium"><?= e($inv['number']) ?></span><span class="block text-xs text-muted"><?= e(date('j M Y', strtotime($inv['issueDate']))) ?></span></span>
                  <span class="shrink-0 tabular-nums"><?= e(money((float) $inv['total'], $inv['currency'])) ?></span>
                  <span class="<?= cx('rounded-full px-2 py-0.5 text-[11px] font-medium', $inv['status'] === 'paid' ? 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'bg-amber-500/10 text-amber-700 dark:text-amber-300') ?>"><?= INVOICE_STATUSES[$inv['status']] ?></span>
                </a></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
