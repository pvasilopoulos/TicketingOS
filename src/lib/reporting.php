<?php
declare(strict_types=1);

/*
 * Report summaries shared by the Reports page and scheduled report emails.
 */

/** Fast parser for our fixed UTC ISO format ("2026-01-31T09:15:00.123Z"). */
function iso_sec(string $s): float
{
    return gmmktime((int) substr($s, 11, 2), (int) substr($s, 14, 2), (int) substr($s, 17, 2), (int) substr($s, 5, 2), (int) substr($s, 8, 2), (int) substr($s, 0, 4)) + (int) substr($s, 20, 3) / 1000;
}

function median_of(array $v): ?float
{
    if (!$v) return null;
    sort($v);
    $n = count($v);
    return $n % 2 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
}

/** Human duration ("3h 20m", "2d 4h"). */
function human_duration(?float $sec): string
{
    if ($sec === null) return '—';
    $m = (int) round($sec / 60);
    if ($m < 60) return $m . 'm';
    $h = intdiv($m, 60);
    if ($h < 48) return $h . 'h ' . ($m % 60) . 'm';
    return intdiv($h, 24) . 'd ' . ($h % 24) . 'h';
}

/** Headline numbers for the last $days days (with the previous period for trends). */
function report_summary(string $tenantId, int $days): array
{
    $from = now_iso(-$days * 86400);
    $prevFrom = now_iso(-2 * $days * 86400);
    $closed = closed_status_keys(tenant_statuses($tenantId));
    $created = q_all('SELECT "createdAt","firstResponseAt","assigneeId" FROM "Ticket" WHERE "tenantId" = ? AND "createdAt" >= ?', [$tenantId, $from]);
    $resolved = q_all('SELECT "createdAt","closedAt","dueAt","assigneeId" FROM "Ticket" WHERE "tenantId" = ? AND "closedAt" >= ? AND "mergedIntoId" IS NULL', [$tenantId, $from]);
    $frt = $res = [];
    $met = $withSla = 0;
    $agents = [];
    foreach ($created as $t) if ($t['firstResponseAt']) $frt[] = iso_sec($t['firstResponseAt']) - iso_sec($t['createdAt']);
    foreach ($resolved as $t) {
        $res[] = iso_sec($t['closedAt']) - iso_sec($t['createdAt']);
        if ($t['dueAt']) { $withSla++; if ($t['closedAt'] <= $t['dueAt']) $met++; }
        if ($t['assigneeId']) $agents[$t['assigneeId']] = ($agents[$t['assigneeId']] ?? 0) + 1;
    }
    arsort($agents);
    $names = $agents ? array_column(q_all('SELECT "id","name" FROM "User" WHERE "id" IN (' . in_ph(array_keys($agents)) . ')', array_keys($agents)), 'name', 'id') : [];
    $top = [];
    foreach (array_slice($agents, 0, 5, true) as $id => $n) $top[] = ['name' => $names[$id] ?? 'Unknown', 'resolved' => $n];
    return [
        'days' => $days,
        'created' => count($created),
        'prevCreated' => (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "createdAt" >= ? AND "createdAt" < ?', [$tenantId, $prevFrom, $from]),
        'resolved' => count($resolved),
        'prevResolved' => (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "closedAt" >= ? AND "closedAt" < ? AND "mergedIntoId" IS NULL', [$tenantId, $prevFrom, $from]),
        'medianFirstResponse' => median_of($frt),
        'medianResolution' => median_of($res),
        'slaPct' => $withSla ? (int) round($met / $withSla * 100) : null,
        'backlog' => (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ?' . ($closed ? ' AND "status" NOT IN (' . in_ph($closed) . ')' : ''), [$tenantId, ...$closed]),
        'overdue' => (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "dueAt" < ?' . ($closed ? ' AND "status" NOT IN (' . in_ph($closed) . ')' : ''), [$tenantId, now_iso(), ...$closed]),
        'csat' => csat_stats($tenantId, $from),
        'topAgents' => $top,
    ];
}

/** HTML email body for a scheduled report. */
function report_email_html(string $tenantId, array $sched): string
{
    $s = report_summary($tenantId, (int) $sched['days']);
    $tenant = q_one('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    $trend = function (int $now, int $prev): string {
        if (!$prev) return '';
        $p = (int) round(($now - $prev) / $prev * 100);
        return ' <span style="color:#64748b;font-size:12px">(' . ($p > 0 ? '+' : '') . $p . '%)</span>';
    };
    $cell = fn (string $label, string $value, string $sub = '') => '<td style="padding:12px;border:1px solid #e2e8f0;border-radius:8px;width:33%;vertical-align:top">'
        . '<div style="color:#64748b;font-size:12px">' . e($label) . '</div><div style="font-size:22px;font-weight:700;margin-top:4px">' . $value . '</div>'
        . ($sub ? '<div style="color:#64748b;font-size:11px;margin-top:2px">' . e($sub) . '</div>' : '') . '</td>';
    $h = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;max-width:620px;color:#0f172a">'
        . '<h2 style="margin:0 0 4px">' . e($sched['name']) . '</h2>'
        . '<p style="margin:0 0 16px;color:#64748b">' . e($tenant['name'] ?? '') . ' · last ' . (int) $sched['days'] . ' days</p>'
        . '<table style="width:100%;border-collapse:separate;border-spacing:6px"><tr>'
        . $cell('Created', number_format($s['created']) . $trend($s['created'], $s['prevCreated']))
        . $cell('Resolved', number_format($s['resolved']) . $trend($s['resolved'], $s['prevResolved']))
        . $cell('Open backlog', number_format($s['backlog']), number_format($s['overdue']) . ' overdue')
        . '</tr><tr>'
        . $cell('Median first response', human_duration($s['medianFirstResponse']))
        . $cell('Median resolution', human_duration($s['medianResolution']))
        . $cell('SLA met', $s['slaPct'] === null ? '—' : $s['slaPct'] . '%')
        . '</tr><tr>'
        . $cell('Satisfaction', $s['csat']['pct'] === null ? '—' : $s['csat']['pct'] . '%', $s['csat']['count'] ? $s['csat']['count'] . ' ratings · avg ' . $s['csat']['avg'] . '/5' : 'no ratings yet')
        . '<td></td><td></td></tr></table>';
    if ($s['topAgents']) {
        $h .= '<h3 style="margin:20px 0 8px;font-size:14px">Most resolved</h3><table style="width:100%;border-collapse:collapse;font-size:14px">';
        foreach ($s['topAgents'] as $a) $h .= '<tr><td style="padding:6px 0;border-bottom:1px solid #f1f5f9">' . e($a['name']) . '</td><td style="padding:6px 0;border-bottom:1px solid #f1f5f9;text-align:right">' . $a['resolved'] . '</td></tr>';
        $h .= '</table>';
    }
    $h .= '<p style="margin-top:20px"><a href="' . e(app_url() . '/reports?days=' . min(365, max(7, (int) $sched['days']))) . '" style="background:#4f46e5;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:600">Open full report</a></p>'
        . '<p style="color:#94a3b8;font-size:11px">You get this because you are a recipient of “' . e($sched['name']) . '”. Change it under Reports → Scheduled emails.</p></div>';
    return $h;
}

/** Next run (UTC ISO) after a moment, in the app timezone. */
function report_next_run(array $sched, ?DateTimeImmutable $after = null): string
{
    $tz = new DateTimeZone(date_default_timezone_get());
    $after ??= new DateTimeImmutable('now', $tz);
    $hour = max(0, min(23, (int) $sched['hour']));
    $c = $after->setTimezone($tz)->setTime($hour, 0);
    for ($i = 0; $i < 400; $i++) {
        $ok = match ($sched['frequency']) {
            'daily' => true,
            'weekly' => (int) $c->format('N') === max(1, min(7, (int) $sched['weekday'])),
            'monthly' => (int) $c->format('j') === 1,
            default => false,
        };
        if ($ok && $c > $after) return iso_from_ts((float) $c->getTimestamp());
        $c = $c->modify('+1 day');
    }
    return iso_from_ts((float) ($after->getTimestamp() + 86400));
}

/** Send one scheduled report now. Returns ['sent' => n, 'skipped' => reason|null]. */
function report_send(array $sched): array
{
    $ids = json_decode((string) $sched['recipients'], true) ?: [];
    $users = $ids ? q_all('SELECT * FROM "User" WHERE "id" IN (' . in_ph($ids) . ') AND "tenantId" = ? AND "active" = 1', [...$ids, $sched['tenantId']]) : [];
    // Recipients must still be allowed to see reports.
    $users = array_values(array_filter($users, fn ($u) => can($u, 'reports.view')));
    $email = email_settings($sched['tenantId']);
    if (!$email || !$email['enabled']) return ['sent' => 0, 'skipped' => 'Email is not set up'];
    if (!$users) return ['sent' => 0, 'skipped' => 'No recipients'];
    $html = report_email_html($sched['tenantId'], $sched);
    $n = 0;
    foreach ($users as $u) {
        $r = send_tenant_email($sched['tenantId'], ['to' => $u['email'], 'subject' => $sched['name'], 'html' => $html]);
        if ($r['ok'] ?? false) $n++;
    }
    return ['sent' => $n, 'skipped' => null];
}

/** Cron: send every report that is due. */
function run_due_reports(): int
{
    $due = q_all('SELECT * FROM "ScheduledReport" WHERE "active" = 1 AND "nextRunAt" <= ?', [now_iso()]);
    foreach ($due as $s) {
        report_send($s);
        db_update('ScheduledReport', ['lastSentAt' => now_iso(), 'nextRunAt' => report_next_run($s)], '"id" = ?', [$s['id']]);
    }
    return count($due);
}

/** Everything periodic (bin/cron.php and GET /cron?key=…). */
function run_cron(): array
{
    $out = ['reports' => run_due_reports(), 'webhooks' => webhook_deliver_due(100)];
    q('DELETE FROM "RateHit" WHERE "createdAt" < ?', [now_iso(-86400)]);
    q('DELETE FROM "WebhookDelivery" WHERE "status" <> \'pending\' AND "createdAt" < ?', [now_iso(-30 * 86400)]);
    return $out;
}
