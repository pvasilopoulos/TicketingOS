<?php
[$user, $tenantId] = require_tenant_user();
if (!can($user, 'reports.view')) redirect('/dashboard');
page_title('Reports');

$ranges = [7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last 12 months'];
$days = isset($ranges[(int) ($_GET['days'] ?? 0)]) ? (int) $_GET['days'] : 30;
$from = now_iso(-$days * 86400);
$prevFrom = now_iso(-2 * $days * 86400);

$statuses = tenant_statuses($tenantId);
$priorities = tenant_priorities($tenantId);
$closedKeys = closed_status_keys($statuses);

/** Human duration ("3h 20m", "2d 4h"). */
$dur = function (?float $sec): string {
    if ($sec === null) return '—';
    $m = (int) round($sec / 60);
    if ($m < 60) return $m . 'm';
    $h = intdiv($m, 60);
    if ($h < 48) return $h . 'h ' . ($m % 60) . 'm';
    return intdiv($h, 24) . 'd ' . ($h % 24) . 'h';
};
$median = function (array $v): ?float {
    if (!$v) return null;
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
};

// Fast parser for our fixed UTC format ("2026-01-31T09:15:00.123Z") — DateTime is too slow for 10k+ rows.
$sec = fn (string $s): float => gmmktime((int) substr($s, 11, 2), (int) substr($s, 14, 2), (int) substr($s, 17, 2), (int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4)) + (int) substr($s, 20, 3) / 1000;

// Tickets created in the period (and the previous one, for trends).
$created = q_all('SELECT t."id", t."createdAt", t."firstResponseAt", t."closedAt", t."dueAt", t."status", t."priority", t."assigneeId", t."categoryId", t."groupId", t."source", u."organizationId" AS "org"
                  FROM "Ticket" t JOIN "User" u ON u."id" = t."requesterId" WHERE t."tenantId" = ? AND t."createdAt" >= ?', [$tenantId, $from]);
$prevCreated = (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "createdAt" >= ? AND "createdAt" < ?', [$tenantId, $prevFrom, $from]);
// Tickets resolved in the period (whenever they were opened).
$resolved = q_all('SELECT "createdAt","closedAt","dueAt","assigneeId" FROM "Ticket" WHERE "tenantId" = ? AND "closedAt" >= ? AND "mergedIntoId" IS NULL', [$tenantId, $from]);
$prevResolved = (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "closedAt" >= ? AND "closedAt" < ? AND "mergedIntoId" IS NULL', [$tenantId, $prevFrom, $from]);
$backlog = (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "status" NOT IN (' . in_ph($closedKeys) . ')', [$tenantId, ...$closedKeys]);
$overdueNow = (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "dueAt" < ? AND "status" NOT IN (' . in_ph($closedKeys) . ')', [$tenantId, now_iso(), ...$closedKeys]);

$frt = [];
foreach ($created as $t) if ($t['firstResponseAt']) $frt[] = $sec($t['firstResponseAt']) - $sec($t['createdAt']);
$res = [];
$slaMet = 0;
$slaTotal = 0;
foreach ($resolved as $t) {
    $res[] = $sec($t['closedAt']) - $sec($t['createdAt']);
    if ($t['dueAt']) { $slaTotal++; if ($t['closedAt'] <= $t['dueAt']) $slaMet++; }
}
$responded = count($frt);
$slaPct = $slaTotal ? round($slaMet / $slaTotal * 100) : null;

$delta = function (int $now, int $prev): string {
    if ($prev === 0) return $now ? '<span class="text-xs text-muted">new</span>' : '';
    $p = round(($now - $prev) / $prev * 100);
    $cls = $p > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-emerald-600 dark:text-emerald-400';
    return '<span class="text-xs font-medium ' . $cls . '">' . ($p > 0 ? '▲' : ($p < 0 ? '▼' : '')) . abs($p) . '%</span>';
};
$kpis = [
    ['Created', number_format(count($created)), $delta(count($created), $prevCreated), 'ticket', 'vs previous period'],
    ['Resolved', number_format(count($resolved)), $delta(count($resolved), $prevResolved), 'circle-check', 'vs previous period'],
    ['Median first response', $dur($median($frt)), '', 'timer', $responded . ' of ' . count($created) . ' answered'],
    ['Median resolution', $dur($median($res)), '', 'clock', 'for tickets resolved in period'],
    ['SLA met', $slaPct === null ? '—' : $slaPct . '%', '', 'flag', $slaTotal ? "$slaMet of $slaTotal resolved on time" : 'no SLA targets hit yet'],
    ['Open backlog', number_format($backlog), '', 'inbox', number_format($overdueNow) . ' overdue right now'],
];
$cs = csat_stats($tenantId, $from);
$csPrev = csat_stats($tenantId, $prevFrom, $from);
$selfService = count(array_filter($created, fn ($t) => in_array($t['source'] ?? 'web', ['form', 'api', 'email'], true)));
$kpis[] = ['Satisfaction', $cs['pct'] === null ? '—' : $cs['pct'] . '%', $cs['pct'] !== null && $csPrev['pct'] !== null ? '<span class="text-xs font-medium ' . ($cs['pct'] >= $csPrev['pct'] ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400') . '">' . ($cs['pct'] >= $csPrev['pct'] ? '▲' : '▼') . abs($cs['pct'] - $csPrev['pct']) . 'pt</span>' : '', 'smile', $cs['count'] ? $cs['count'] . ' ratings · avg ' . $cs['avg'] . '/5' : 'no ratings in this period'];
$kpis[] = ['From form & API', number_format($selfService), '', 'globe', count($created) ? round($selfService / count($created) * 100) . '% of new tickets' : 'no new tickets'];
$csatByAgent = [];
foreach (q_all('SELECT "agentId", COUNT(*) AS n, AVG("rating") AS avg, SUM(CASE WHEN "rating" >= 4 THEN 1 ELSE 0 END) AS good FROM "CsatSurvey" WHERE "tenantId" = ? AND "answeredAt" >= ? AND "agentId" IS NOT NULL GROUP BY "agentId"', [$tenantId, $from]) as $r) {
    $csatByAgent[$r['agentId']] = ['n' => (int) $r['n'], 'pct' => (int) round($r['good'] / max(1, $r['n']) * 100)];
}
$recentComments = q_all('SELECT s."rating", s."comment", s."answeredAt", t."id" AS "tid", t."number", t."subject" FROM "CsatSurvey" s JOIN "Ticket" t ON t."id" = s."ticketId"
                         WHERE s."tenantId" = ? AND s."answeredAt" >= ? AND s."comment" IS NOT NULL ORDER BY s."answeredAt" DESC LIMIT 6', [$tenantId, $from]);
$groupNames = array_column(tenant_groups($tenantId), null, 'id');
$orgNames = array_column(tenant_orgs($tenantId), 'name', 'id');
$byGroup = [];
$byOrg = [];
foreach ($created as $t) {
    if (!empty($t['groupId'])) $byGroup[$t['groupId']] = ($byGroup[$t['groupId']] ?? 0) + 1;
    if (!empty($t['org'])) $byOrg[$t['org']] = ($byOrg[$t['org']] ?? 0) + 1;
}
arsort($byGroup);
arsort($byOrg);
$schedules = q_all('SELECT * FROM "ScheduledReport" WHERE "tenantId" = ? ORDER BY "createdAt"', [$tenantId]);
$reportStaff = array_values(array_filter(q_all('SELECT * FROM "User" WHERE "tenantId" = ? AND "active" = 1 AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') ORDER BY "name" LIMIT 300', [$tenantId]), fn ($u) => can($u, 'reports.view')));
$schedState = flash('schedules');
$emailReady = (email_settings($tenantId)['enabled'] ?? false);
$weekdays = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];

// Daily created vs resolved (bucket by local day; weeks for 90d+, months for a year).
$tz = new DateTimeZone(date_default_timezone_get());
$bucket = $days <= 30 ? 'day' : ($days <= 90 ? 'week' : 'month');
// date() uses the app timezone, so buckets follow local days.
$fmt = match ($bucket) { 'day' => 'Y-m-d', 'week' => 'o-\WW', 'month' => 'Y-m' };
$key = fn (string $iso): string => date($fmt, (int) $sec($iso));
$series = [];
$cursor = (new DateTimeImmutable('now', $tz))->modify("-{$days} days");
$end = new DateTimeImmutable('now', $tz);
while ($cursor <= $end) {
    $k = $cursor->format($fmt);
    $series[$k] ??= ['label' => match ($bucket) { 'day' => $cursor->format('M j'), 'week' => 'Wk ' . $cursor->format('W'), 'month' => $cursor->format('M y') }, 'c' => 0, 'r' => 0];
    $cursor = $cursor->modify('+1 day');
}
foreach ($created as $t) { $k = $key($t['createdAt']); if (isset($series[$k])) $series[$k]['c']++; }
foreach ($resolved as $t) { $k = $key($t['closedAt']); if (isset($series[$k])) $series[$k]['r']++; }
$series = array_values($series);
$peak = max(1, max(array_map(fn ($s) => max($s['c'], $s['r']), $series)));

// Breakdown by priority / category of tickets created in the period.
$byPrio = [];
foreach ($created as $t) $byPrio[$t['priority']] = ($byPrio[$t['priority']] ?? 0) + 1;
$cats = key_by(q_all('SELECT "id","name","color" FROM "Category" WHERE "tenantId" = ?', [$tenantId]), 'id');
$byCat = [];
foreach ($created as $t) { $k = $t['categoryId'] ?? ''; $byCat[$k] = ($byCat[$k] ?? 0) + 1; }
arsort($byCat);

// Agent performance.
$agents = key_by(q_all('SELECT "id","name","avatarColor" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') ORDER BY "name"', [$tenantId]), 'id');
$openByAgent = array_column(q_all('SELECT "assigneeId", COUNT(*) AS n FROM "Ticket" WHERE "tenantId" = ? AND "assigneeId" IS NOT NULL AND "status" NOT IN (' . in_ph($closedKeys) . ') GROUP BY "assigneeId"', [$tenantId, ...$closedKeys]), 'n', 'assigneeId');
$perAgent = [];
foreach ($agents as $id => $a) $perAgent[$id] = ['resolved' => 0, 'met' => 0, 'withSla' => 0, 'frt' => []];
foreach ($resolved as $t) {
    if (!isset($perAgent[$t['assigneeId'] ?? ''])) continue;
    $p = &$perAgent[$t['assigneeId']];
    $p['resolved']++;
    if ($t['dueAt']) { $p['withSla']++; if ($t['closedAt'] <= $t['dueAt']) $p['met']++; }
    unset($p);
}
foreach ($created as $t) {
    if ($t['firstResponseAt'] && isset($perAgent[$t['assigneeId'] ?? ''])) $perAgent[$t['assigneeId']]['frt'][] = $sec($t['firstResponseAt']) - $sec($t['createdAt']);
}
uasort($perAgent, fn ($a, $b) => $b['resolved'] <=> $a['resolved']);
$bar = fn (int $n, int $max, string $color) => '<span class="block h-2 rounded-full" style="width:' . max(2, round($n / max(1, $max) * 100)) . '%;background:' . safe_color($color) . '"></span>';
?>
<div>
  <?= page_header('Reports', 'Volume, response times and SLA performance for your helpdesk.',
      '<div class="inline-flex rounded-lg border border-border bg-surface p-0.5">' . implode('', array_map(fn ($d, $l) =>
          '<a href="/reports?days=' . $d . '" class="' . cx('rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors', $d === $days ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg') . '">' . ($d === 365 ? '12m' : $d . 'd') . '</a>', array_keys($ranges), $ranges)) . '</div>') ?>

  <div class="grid grid-cols-2 gap-3 sm:gap-4 lg:grid-cols-4">
    <?php foreach ($kpis as [$label, $value, $trend, $ic, $sub]): ?>
      <div class="rounded-xl border border-border bg-surface p-4 sm:p-5">
        <div class="flex items-center justify-between"><span class="text-sm text-muted"><?= e($label) ?></span><?= icon($ic, 17, 'text-muted') ?></div>
        <p class="mt-2 flex items-baseline gap-2 text-2xl font-bold tracking-tight tabular-nums sm:text-3xl"><?= e($value) ?> <?= $trend ?></p>
        <p class="mt-0.5 text-xs text-muted"><?= e($sub) ?></p>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="mt-6 rounded-xl border border-border bg-surface">
    <?= card_header('Created vs resolved · ' . e($ranges[$days]), '<span class="flex items-center gap-3 text-xs text-muted"><span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-brand"></span>Created</span><span class="inline-flex items-center gap-1"><span class="h-2.5 w-2.5 rounded-sm bg-emerald-500"></span>Resolved</span></span>') ?>
    <div class="overflow-x-auto px-4 pb-4 pt-5">
      <div class="flex h-48 min-w-full items-end gap-1" style="min-width:<?= count($series) * 14 ?>px">
        <?php foreach ($series as $i => $p): ?>
          <div class="group flex h-full min-w-0 flex-1 flex-col justify-end" title="<?= e($p['label']) ?>: <?= $p['c'] ?> created, <?= $p['r'] ?> resolved">
            <div class="flex h-full items-end justify-center gap-px">
              <span class="w-1/2 max-w-3 rounded-t bg-brand/85 group-hover:bg-brand" style="height:<?= round($p['c'] / $peak * 100, 1) ?>%"></span>
              <span class="w-1/2 max-w-3 rounded-t bg-emerald-500/85 group-hover:bg-emerald-500" style="height:<?= round($p['r'] / $peak * 100, 1) ?>%"></span>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
      <div class="mt-2 flex justify-between text-[11px] text-muted"><span><?= e($series[0]['label']) ?></span><span><?= e($series[intdiv(count($series), 2)]['label']) ?></span><span><?= e(end($series)['label']) ?></span></div>
    </div>
  </div>

  <div class="mt-6 grid gap-6 lg:grid-cols-2">
    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('New tickets by priority') ?>
      <div class="space-y-3 p-5">
        <?php $mx = max([1, ...array_values($byPrio)]); foreach (array_reverse($priorities) as $p): $n = $byPrio[$p['key']] ?? 0; ?>
          <div class="grid grid-cols-[6rem_1fr_3rem] items-center gap-3 text-sm">
            <span class="truncate"><?= e($p['label']) ?></span><span class="rounded-full bg-surface-2"><?= $bar($n, $mx, $p['color']) ?></span><span class="text-right tabular-nums text-muted"><?= number_format($n) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('New tickets by category') ?>
      <div class="space-y-3 p-5">
        <?php if (!$byCat): ?><p class="text-sm text-muted">No tickets in this period.</p><?php endif; ?>
        <?php $mx = max([1, ...array_values($byCat)]); foreach (array_slice($byCat, 0, 8, true) as $cid => $n): $c = $cats[$cid] ?? ['name' => 'No category', 'color' => '#94a3b8']; ?>
          <div class="grid grid-cols-[6rem_1fr_3rem] items-center gap-3 text-sm">
            <span class="truncate"><?= e($c['name']) ?></span><span class="rounded-full bg-surface-2"><?= $bar($n, $mx, $c['color']) ?></span><span class="text-right tabular-nums text-muted"><?= number_format($n) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="mt-6 rounded-xl border border-border bg-surface">
    <?= card_header('Agents · ' . e($ranges[$days])) ?>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[36rem] text-sm">
        <thead><tr class="border-b border-border text-left text-xs text-muted">
          <th class="px-5 py-2.5 font-medium">Agent</th><th class="px-3 py-2.5 text-right font-medium">Open now</th><th class="px-3 py-2.5 text-right font-medium">Resolved</th>
          <th class="px-3 py-2.5 text-right font-medium">Median 1st response</th><th class="px-3 py-2.5 text-right font-medium">SLA met</th><th class="px-5 py-2.5 text-right font-medium">Satisfaction</th>
        </tr></thead>
        <tbody class="divide-y divide-border">
          <?php foreach ($perAgent as $id => $p): $a = $agents[$id]; ?>
            <tr>
              <td class="px-5 py-2.5"><span class="flex items-center gap-2.5"><?= avatar($a['name'], $a['avatarColor'], 28) ?><a href="/tickets?q=<?= e(rawurlencode('assignee:"' . $a['name'] . '"')) ?>" class="truncate font-medium hover:text-brand"><?= e($a['name']) ?></a></span></td>
              <td class="px-3 py-2.5 text-right tabular-nums"><?= number_format((int) ($openByAgent[$id] ?? 0)) ?></td>
              <td class="px-3 py-2.5 text-right tabular-nums"><?= number_format($p['resolved']) ?></td>
              <td class="px-3 py-2.5 text-right tabular-nums"><?= e($dur($median($p['frt']))) ?></td>
              <td class="px-5 py-2.5 text-right tabular-nums"><?php if ($p['withSla']): $pc = round($p['met'] / $p['withSla'] * 100); ?>
                <span class="<?= $pc >= 90 ? 'text-emerald-600 dark:text-emerald-400' : ($pc >= 70 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') ?> font-medium"><?= $pc ?>%</span>
              <?php else: ?>—<?php endif; ?></td>
              <td class="px-5 py-2.5 text-right tabular-nums"><?php if (isset($csatByAgent[$id])): $c = $csatByAgent[$id]; ?><span class="font-medium <?= $c['pct'] >= 80 ? 'text-emerald-600 dark:text-emerald-400' : ($c['pct'] >= 60 ? 'text-amber-600 dark:text-amber-400' : 'text-red-600 dark:text-red-400') ?>"><?= $c['pct'] ?>%</span> <span class="text-xs text-muted">(<?= $c['n'] ?>)</span><?php else: ?>—<?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php if ($byGroup || $byOrg || $recentComments): ?>
  <div class="mt-6 grid gap-6 lg:grid-cols-2">
    <?php if ($byGroup): ?>
    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('New tickets by group') ?>
      <div class="space-y-3 p-5">
        <?php $mx = max($byGroup); foreach (array_slice($byGroup, 0, 8, true) as $gid => $n): $g = $groupNames[$gid] ?? ['name' => 'Deleted group', 'color' => '#94a3b8']; ?>
          <div class="grid grid-cols-[7rem_1fr_3rem] items-center gap-3 text-sm">
            <a href="/tickets?group=<?= e($gid) ?>" class="truncate hover:text-brand"><?= e($g['name']) ?></a><span class="rounded-full bg-surface-2"><?= $bar($n, $mx, $g['color']) ?></span><span class="text-right tabular-nums text-muted"><?= number_format($n) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($byOrg): ?>
    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('Top organizations') ?>
      <div class="space-y-3 p-5">
        <?php $mx = max($byOrg); foreach (array_slice($byOrg, 0, 8, true) as $oid => $n): ?>
          <div class="grid grid-cols-[7rem_1fr_3rem] items-center gap-3 text-sm">
            <a href="/tickets?org=<?= e($oid) ?>" class="truncate hover:text-brand"><?= e($orgNames[$oid] ?? '—') ?></a><span class="rounded-full bg-surface-2"><?= $bar($n, $mx, '#f59e0b') ?></span><span class="text-right tabular-nums text-muted"><?= number_format($n) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php if ($recentComments): ?>
    <div class="rounded-xl border border-border bg-surface lg:col-span-2">
      <?= card_header('What customers said') ?>
      <ul class="divide-y divide-border">
        <?php foreach ($recentComments as $c): ?>
          <li class="flex gap-3 px-5 py-3">
            <span class="text-2xl"><?= CSAT_FACES[(int) $c['rating']] ?? '' ?></span>
            <div class="min-w-0"><p class="text-sm">“<?= e($c['comment']) ?>”</p><p class="mt-0.5 text-xs text-muted"><a href="/tickets/<?= e($c['tid']) ?>" class="hover:text-brand">#<?= (int) $c['number'] ?> <?= e($c['subject']) ?></a> · <?= e(time_ago($c['answeredAt'])) ?></p></div>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php if (can($user, 'reports.schedule')): ?>
  <!-- Scheduled emails -->
  <div class="mt-6 rounded-xl border border-border bg-surface" id="schedules">
    <?= card_header(titled('calendar-clock', 'Scheduled emails'), '<button type="button" data-toggle-target="#sched-new" class="' . btn_cls('outline', 'sm') . '">' . icon('plus', 13) . ' New schedule</button>') ?>
    <div class="space-y-4 p-5">
      <?= banner($schedState, true) ?>
      <?php if (!$emailReady): ?>
        <p class="flex items-start gap-2 rounded-lg bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-300"><?= icon('triangle-alert', 15, 'mt-0.5 shrink-0') ?> Email is not set up yet, so schedules won’t send. Configure it in Settings → Notifications & email. You can still preview the email.</p>
      <?php endif; ?>
      <form method="post" action="/action/report-schedule-save" id="sched-new" class="space-y-4 rounded-xl border border-dashed border-border p-4" <?= $schedules ? 'hidden' : '' ?>>
        <?= form_meta('schedules', '/reports#schedules') ?>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <div class="lg:col-span-2"><label class="mb-1 block text-xs font-medium text-muted">Name</label><input name="name" required maxlength="80" value="Weekly helpdesk report" class="<?= INPUT_CLS ?>"></div>
          <div><label class="mb-1 block text-xs font-medium text-muted">How often</label><select name="frequency" class="<?= SELECT_CLS ?>"><?= select_options(['daily' => 'Every day', 'weekly' => 'Every week', 'monthly' => 'Every month (1st)'], 'weekly') ?></select></div>
          <div><label class="mb-1 block text-xs font-medium text-muted">Day (weekly)</label><select name="weekday" class="<?= SELECT_CLS ?>"><?= select_options(array_map('strval', $weekdays), '1') ?></select></div>
          <div><label class="mb-1 block text-xs font-medium text-muted">Time</label><select name="hour" class="<?= SELECT_CLS ?>"><?= select_options(array_combine(array_map('strval', range(0, 23)), array_map(fn ($h) => sprintf('%02d:00', $h), range(0, 23))), '8') ?></select></div>
        </div>
        <div><label class="mb-1 block text-xs font-medium text-muted">Covers</label><select name="days" class="<?= SELECT_CLS ?> sm:max-w-xs"><?= select_options(['1' => 'Last 24 hours', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'], '7') ?></select></div>
        <div><span class="mb-1.5 block text-xs font-medium text-muted">Recipients</span>
          <div class="grid max-h-48 gap-1 overflow-y-auto rounded-xl border border-border p-1.5 sm:grid-cols-2">
            <?php foreach ($reportStaff as $u): ?>
              <label class="flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm hover:bg-surface-2"><input type="checkbox" name="recipients[]" value="<?= e($u['id']) ?>" <?= $u['id'] === $user['id'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"><?= avatar($u['name'], $u['avatarColor'], 22) ?><span class="truncate"><?= e($u['name']) ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="flex gap-2"><button type="submit" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('check', 14) ?> Create schedule</button><a href="/reports/email-preview" target="_blank" class="<?= btn_cls('ghost', 'sm') ?>"><?= icon('eye', 14) ?> Preview</a></div>
      </form>
      <?php foreach ($schedules as $sc): $rc = count(json_decode($sc['recipients'], true) ?: []); ?>
        <div class="flex flex-wrap items-center gap-3 rounded-xl border border-border px-4 py-3">
          <span class="grid h-9 w-9 place-items-center rounded-lg <?= (int) $sc['active'] ? 'bg-brand/10 text-brand' : 'bg-surface-2 text-muted' ?>"><?= icon('calendar-clock', 16) ?></span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium"><?= e($sc['name']) ?><?= (int) $sc['active'] ? '' : ' <span class="text-xs font-normal text-muted">(paused)</span>' ?></p>
            <p class="text-xs text-muted"><?= e(['daily' => 'Daily', 'weekly' => 'Weekly on ' . ($weekdays[(int) $sc['weekday']] ?? ''), 'monthly' => 'Monthly'][$sc['frequency']] ?? '') ?> at <?= sprintf('%02d:00', (int) $sc['hour']) ?> · last <?= (int) $sc['days'] ?> days · <?= $rc ?> recipient<?= $rc === 1 ? '' : 's' ?> · next <?= e(format_date($sc['nextRunAt'])) ?><?= $sc['lastSentAt'] ? ' · sent ' . e(time_ago($sc['lastSentAt'])) : '' ?></p>
          </div>
          <a href="/reports/email-preview?id=<?= e($sc['id']) ?>" target="_blank" class="<?= btn_cls('ghost', 'sm') ?>"><?= icon('eye', 14) ?> Preview</a>
          <form method="post" action="/action/report-schedule-send" data-ajax><?= form_meta('schedules') ?><input type="hidden" name="id" value="<?= e($sc['id']) ?>"><button type="submit" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('send', 14) ?> Send now</button></form>
          <form method="post" action="/action/report-schedule-delete" data-ajax data-confirm="Delete “<?= e($sc['name']) ?>”?"><?= form_meta('schedules') ?><input type="hidden" name="id" value="<?= e($sc['id']) ?>"><button type="submit" aria-label="Delete schedule" class="grid h-8 w-8 place-items-center rounded-lg text-muted hover:bg-red-500/10 hover:text-red-600 cursor-pointer"><?= icon('trash-2', 15) ?></button></form>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <p class="mt-3 text-xs text-muted">First response = first public reply by staff. Resolution = time from opening until a closed status. Merged tickets are excluded from “Resolved”.</p>
</div>
