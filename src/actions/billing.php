<?php
declare(strict_types=1);

/* Time entries, support contracts and shared saved views. */

function act_time_add(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!is_staff($user['role']) || !can($user, 'time.log')) return NOT_ALLOWED;
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !ticket_visible($ticket, $user)) return ['error' => 'Ticket not found'];
    $min = parse_minutes(str_in($in, 'minutes'));
    if (!$min || $min < 1) return ['error' => 'Enter the time, e.g. 45, 1:30 or 1h 30m'];
    if ($min > 24 * 60) return ['error' => 'One entry can be at most 24 hours'];
    $day = str_in($in, 'workedOn');
    if ($day !== '' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || $day > date('Y-m-d'))) return ['error' => 'Pick a date that isn’t in the future'];
    add_time_entry($tenantId, $ticket['id'], $user['id'], $min, ($in['billable'] ?? '') === 'on', str_in($in, 'note'), $day ?: null);
    return ['success' => 'Logged ' . fmt_minutes($min) . ' on #' . (int) $ticket['number']];
}

function act_time_delete(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $e = q_one('SELECT * FROM "TimeEntry" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$e) return ['error' => 'Entry not found'];
    if (!(is_staff($user['role']) && ($e['userId'] === $user['id'] || can($user, 'time.manage')))) return NOT_ALLOWED;
    q('DELETE FROM "TimeEntry" WHERE "id" = ?', [$e['id']]);
    return ['success' => 'Time entry deleted'];
}

function act_contract_save(array $in): ?array
{
    if (!($ctx = ctx_with('contracts.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $orgId = str_in($in, 'organizationId');
    if (!q_val('SELECT 1 FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [$orgId, $tenantId])) return ['error' => 'Organization not found'];
    $name = mb_substr(str_in($in, 'name'), 0, 120);
    if ($name === '') return ['error' => 'Give the contract a name, e.g. “Gold support 2027”'];
    $start = str_in($in, 'startsOn') ?: date('Y-m-d');
    $end = str_in($in, 'endsOn');
    foreach ([$start, $end] as $d) if ($d !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return ['error' => 'Dates must be valid'];
    if ($end !== '' && $end < $start) return ['error' => 'The end date is before the start date'];
    $hoursRaw = str_in($in, 'hours');
    $minutes = null;
    if ($hoursRaw !== '') {
        $minutes = parse_minutes(str_contains($hoursRaw, ':') || preg_match('/[a-zα-ω]/iu', $hoursRaw) ? $hoursRaw : $hoursRaw . 'h');
        if ($minutes === null || $minutes < 1) return ['error' => 'Included hours must be a number, e.g. 20 or 7.5'];
    }
    $hrs = fn (string $k) => ($v = str_in($in, $k)) === '' ? null : max(1, min(2000, (int) $v));
    $data = [
        'name' => $name, 'minutesIncluded' => $minutes, 'startsOn' => $start, 'endsOn' => $end ?: null,
        'firstResponseHours' => $hrs('firstResponseHours'), 'resolutionHours' => $hrs('resolutionHours'),
        'notes' => mb_substr(str_in($in, 'notes'), 0, 2000) ?: null, 'active' => ($in['active'] ?? 'on') === 'on' ? 1 : 0,
    ];
    $id = str_in($in, 'id');
    if ($id) {
        if (!q_val('SELECT 1 FROM "Contract" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId])) return ['error' => 'Contract not found'];
        db_update('Contract', $data, '"id" = ?', [$id]);
    } else {
        db_insert('Contract', ['id' => cuid(), 'tenantId' => $tenantId, 'organizationId' => $orgId, 'createdAt' => now_iso()] + $data);
    }
    return ['success' => "Contract “{$name}” saved", 'back' => '/settings?org=' . $orgId . '#orgs'];
}

function act_contract_delete(array $in): ?array
{
    if (!($ctx = ctx_with('contracts.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $n = q('DELETE FROM "Contract" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId])->rowCount();
    return $n ? ['success' => 'Contract deleted'] : ['error' => 'Contract not found'];
}

/** Hourly rate of one organization (empty = use the workspace default). */
function act_org_rate_save(array $in): ?array
{
    if (!($ctx = ctx_with('contracts.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $org = q_one('SELECT "id","name" FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'organizationId'), $tenantId]);
    if (!$org) return ['error' => 'Organization not found'];
    $raw = str_replace(',', '.', str_in($in, 'hourlyRate'));
    if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > 100000)) return ['error' => 'Enter the rate as a number, e.g. 45 or 47.50'];
    db_update('Organization', ['hourlyRate' => $raw === '' ? null : round((float) $raw, 2)], '"id" = ?', [$org['id']]);
    $c = billing_settings($tenantId)['currency'];
    return ['success' => $raw === '' ? "{$org['name']} uses the workspace rate" : "{$org['name']}: " . money((float) $raw, $c) . ' per hour'];
}

/** Workspace currency and default hourly rate. */
function act_billing_settings_save(array $in): ?array
{
    if (!($ctx = ctx_with('time.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $cur = isset(CURRENCIES[$in['currency'] ?? '']) ? $in['currency'] : 'EUR';
    $raw = str_replace(',', '.', str_in($in, 'defaultRate'));
    if ($raw !== '' && (!is_numeric($raw) || (float) $raw < 0)) return ['error' => 'Enter the default rate as a number'];
    tenant_setting_set($tenantId, 'billing', ['currency' => $cur, 'defaultRate' => $raw === '' ? null : round((float) $raw, 2)]);
    return ['success' => 'Billing settings saved'];
}

/** Share a saved view with the team (or make it personal again). */
function act_view_share(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!is_staff($user['role']) || !can($user, 'tickets.views_share')) return NOT_ALLOWED;
    $v = q_one('SELECT * FROM "SavedView" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$v || ($v['userId'] !== $user['id'] && !(int) $v['shared'])) return ['error' => 'View not found'];
    $on = ($in['shared'] ?? '1') === '1';
    db_update('SavedView', ['shared' => $on ? 1 : 0], '"id" = ?', [$v['id']]);
    return ['success' => $on ? "“{$v['name']}” is now shared with the team" : "“{$v['name']}” is personal again"];
}

/* ---------- Saved views in the menu ---------- */

/** The person's views plus views shared with the team, with live counts. */
function menu_views(array $user, string $tenantId): array
{
    $staff = is_staff($user['role']);
    $rows = q_all('SELECT * FROM "SavedView" WHERE "tenantId" = ? AND ("userId" = ?' . ($staff ? ' OR "shared" = 1' : '') . ') ORDER BY "shared" DESC, "createdAt" ASC LIMIT 12', [$tenantId, $user['id']]);
    if (!$rows) return [];
    $closed = closed_status_keys(tenant_statuses($tenantId));
    foreach ($rows as &$v) {
        parse_str((string) $v['query'], $sp);
        $params = [];
        try {
            $where = ticket_filter_sql($user, $tenantId, array_map(fn ($x) => is_array($x) ? $x : (string) $x, $sp), $params, $closed);
            $v['count'] = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $where", $params);
        } catch (Throwable $e) {
            $v['count'] = null;
        }
    }
    unset($v);
    return $rows;
}

/* ---------- Report downloads (GET) ---------- */

/** /reports/export.xlsx?days=30 — summary, agents, organizations and the busy-hours grid. */
function report_export_xlsx(): never
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'reports.view')) abort(403);
    $days = in_array((int) ($_GET['days'] ?? 30), [7, 30, 90, 365], true) ? (int) $_GET['days'] : 30;
    $from = now_iso(-$days * 86400);
    $closed = closed_status_keys(tenant_statuses($tenantId));
    $s = report_summary($tenantId, $days);
    $h = fn (?float $sec) => $sec === null ? '' : round($sec / 3600, 1);
    $sheets = [];
    $sheets['Summary'] = [['Metric', 'Value'], ['Period (days)', $days], ['Created', $s['created']], ['Created (previous period)', $s['prevCreated']], ['Resolved', $s['resolved']],
        ['Resolved (previous period)', $s['prevResolved']], ['Median first response (hours)', $h($s['medianFirstResponse'])], ['Median resolution (hours)', $h($s['medianResolution'])],
        ['SLA met (%)', $s['slaPct'] ?? ''], ['Open backlog', $s['backlog']], ['Overdue now', $s['overdue']], ['Satisfaction (%)', $s['csat']['pct'] ?? ''], ['Ratings', $s['csat']['count']]];
    $rows = [['Agent', 'Open now', 'Resolved', 'Median first response (h)', 'SLA met (%)', 'Satisfaction (%)', 'Ratings', 'Time logged (h)', 'Billable (h)']];
    foreach (report_agents($tenantId, $from, $closed) as $a) $rows[] = [$a['name'], $a['open'], $a['resolved'], $h($a['frtMedian']), $a['slaPct'] ?? '', $a['csat'] ?? '', $a['csatN'], hours_dec($a['total']), hours_dec($a['billable'])];
    $sheets['Agents'] = $rows;
    $rows = [['Organization', 'Created', 'Resolved', 'Open now', 'Median first response (h)', 'SLA met (%)', 'Satisfaction (%)', 'Billable (h)', 'Not billable (h)']];
    foreach (report_orgs($tenantId, $from, $closed) as $o) $rows[] = [$o['name'], $o['created'], $o['resolved'], $o['open'], $h($o['frtMedian']), $o['slaPct'] ?? '', $o['csat'] ?? '', hours_dec($o['billable']), hours_dec($o['other'])];
    $sheets['Organizations'] = $rows;
    $grid = report_heatmap($tenantId, $from);
    $rows = [array_merge(['Day'], array_map(fn ($x) => sprintf('%02d:00', $x), range(0, 23)))];
    foreach ([1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as $d => $name) $rows[] = array_merge([$name], array_values($grid[$d]));
    $sheets['Busy hours'] = $rows;
    audit($tenantId, $user, 'report-export', "Exported the report ($days days) to Excel", 'report', null);
    xlsx_download('helpdesk-report-' . $days . 'd-' . date('Y-m-d') . '.xlsx', $sheets);
}

/** /reports/billing.xlsx?month=2026-11 — totals per organization and every time entry. */
function billing_export_xlsx(): never
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'time.manage')) abort(403);
    $month = preg_match('/^\d{4}-\d{2}$/', (string) ($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
    $b = billing_report($tenantId, $month);
    $cur = $b['currency'];
    $rows = [['Organization', 'Tickets', 'Billable (h)', 'Not billable (h)', "Rate ($cur/h)", "Amount ($cur)", 'Contract', 'Contract hours', 'Used in contract (h)', 'Left (h)']];
    foreach ($b['rows'] as $r) {
        $c = $r['contract'];
        $rows[] = [$r['name'], $r['tickets'], hours_dec($r['billable']), hours_dec($r['other']), $r['rate'] ?? '', $r['amount'] ?? '', $c['name'] ?? '',
            $c && $c['included'] !== null ? hours_dec($c['included']) : '', $c ? hours_dec($c['used']) : '', $c && $c['left'] !== null ? hours_dec($c['left']) : ''];
    }
    $rows[] = ['Total', null, hours_dec($b['billable']), hours_dec($b['other']), null, round($b['amount'], 2)];
    $detail = [['Date', 'Organization', 'Ticket', 'Subject', 'Requester', 'Agent', 'Minutes', 'Hours', 'Billable', "Amount ($cur)", 'Note']];
    foreach ($b['entries'] as $e) {
        $detail[] = [$e['workedAt'], $e['orgName'], '#' . (int) $e['number'], $e['subject'], $e['requester'], $e['agent'], (int) $e['minutes'], hours_dec((int) $e['minutes']), (int) $e['billable'] ? 'Yes' : 'No', $e['amount'] ?? '', (string) $e['note']];
    }
    audit($tenantId, $user, 'billing-export', "Exported billing for $month", 'report', null);
    xlsx_download('billing-' . $month . '.xlsx', ['Per organization' => $rows, 'Time entries' => $detail]);
}
