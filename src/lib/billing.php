<?php
declare(strict_types=1);

/*
 * Time tracking, support contracts (hour banks + SLA per organization),
 * extra report data (agents, organizations, heatmap, billing) and Excel export.
 */

/* ---------- Time ---------- */

/** "90", "1:30", "1h 30m", "1.5h", "45m", "2h" → minutes (null if unreadable). */
function parse_minutes(string $s): ?int
{
    $s = strtolower(trim(str_replace(',', '.', $s)));
    if ($s === '') return null;
    if (preg_match('/^(\d+):([0-5]?\d)$/', $s, $m)) return (int) $m[1] * 60 + (int) $m[2];
    if (preg_match('/^\d+$/', $s)) return (int) $s;
    if (preg_match('/^(?:(\d+(?:\.\d+)?)\s*(?:h|hr|hrs|hours?|ώ|ω|ώρες?)\s*)?(?:(\d+)\s*(?:m|min|mins|minutes?|λ|λεπτά?)?)?$/u', $s, $m) && ($m[1] ?? '') . ($m[2] ?? '') !== '') {
        return (int) round((float) ($m[1] ?? 0) * 60) + (int) ($m[2] ?? 0);
    }
    return null;
}

function fmt_minutes(int $min): string
{
    if ($min < 60) return $min . 'm';
    return intdiv($min, 60) . 'h' . ($min % 60 ? ' ' . ($min % 60) . 'm' : '');
}

/** Hours with one decimal for spreadsheets / invoices. */
function hours_dec(int $min): float
{
    return round($min / 60, 2);
}

function ticket_time(string $ticketId): array
{
    $rows = q_all('SELECT te.*, u."name" AS "uName", u."avatarColor" AS "uColor" FROM "TimeEntry" te JOIN "User" u ON u."id" = te."userId" WHERE te."ticketId" = ? ORDER BY te."workedAt" DESC, te."createdAt" DESC', [$ticketId]);
    $billable = $other = 0;
    foreach ($rows as $r) { if ((int) $r['billable']) $billable += (int) $r['minutes']; else $other += (int) $r['minutes']; }
    return ['entries' => $rows, 'billable' => $billable, 'other' => $other, 'total' => $billable + $other];
}

function add_time_entry(string $tenantId, string $ticketId, string $userId, int $minutes, bool $billable, string $note = '', ?string $workedOn = null): void
{
    $day = $workedOn && preg_match('/^\d{4}-\d{2}-\d{2}$/', $workedOn) ? $workedOn : date('Y-m-d');
    db_insert('TimeEntry', [
        'id' => cuid(), 'tenantId' => $tenantId, 'ticketId' => $ticketId, 'userId' => $userId,
        'minutes' => max(1, min(24 * 60, $minutes)), 'billable' => $billable ? 1 : 0, 'note' => mb_substr(trim($note), 0, 500) ?: null,
        'workedAt' => $day, 'createdAt' => now_iso(),
    ]);
}

/* ---------- Hourly rates ---------- */

const CURRENCIES = ['EUR' => '€', 'USD' => '$', 'GBP' => '£', 'CHF' => 'CHF', 'RON' => 'lei', 'BGN' => 'лв'];

/** Workspace currency and the rate used when an organization has none. */
function billing_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'billing', []);
    $cur = isset(CURRENCIES[$s['currency'] ?? '']) ? $s['currency'] : 'EUR';
    $rate = isset($s['defaultRate']) && $s['defaultRate'] !== null && $s['defaultRate'] !== '' ? (float) $s['defaultRate'] : null;
    return ['currency' => $cur, 'defaultRate' => $rate];
}

/** Hourly rate of an organization (its own, else the workspace default; null = none set). */
function org_hourly_rate(string $tenantId, ?string $orgId): ?float
{
    if ($orgId) {
        $r = q_val('SELECT "hourlyRate" FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [$orgId, $tenantId]);
        if ($r !== null && $r !== '') return (float) $r;
    }
    return billing_settings($tenantId)['defaultRate'];
}

/** "1.234,50 €" (Greek style for the euro, symbol first otherwise). */
function money(float $amount, string $currency): string
{
    $sym = CURRENCIES[$currency] ?? $currency;
    $n = number_format($amount, 2, ',', '.');
    return in_array($currency, ['USD', 'GBP'], true) ? $sym . $n : $n . ' ' . $sym;
}

/** Amount for minutes at a rate. */
function minutes_amount(int $minutes, ?float $rate): ?float
{
    return $rate === null ? null : round($minutes / 60 * $rate, 2);
}

/** The organization a ticket is billed to (the requester's first one). */
function ticket_billing_org(array $ticket): ?string
{
    return q_val('SELECT "organizationId" FROM "UserOrganization" WHERE "userId" = ? ORDER BY "organizationId" LIMIT 1', [$ticket['requesterId']]) ?: null;
}

/* ---------- Contracts ---------- */

function org_contracts(string $orgId): array
{
    return q_all('SELECT * FROM "Contract" WHERE "organizationId" = ? ORDER BY "active" DESC, "startsOn" DESC', [$orgId]);
}

function contract_is_current(array $c, ?string $day = null): bool
{
    $day ??= date('Y-m-d');
    return (int) $c['active'] && $c['startsOn'] <= $day && (!$c['endsOn'] || $c['endsOn'] >= $day);
}

/** Billable minutes logged on the organization's tickets during the contract. */
function contract_used_minutes(array $c): int
{
    $end = $c['endsOn'] ?: '9999-12-31';
    return (int) q_val('SELECT COALESCE(SUM(te."minutes"), 0) FROM "TimeEntry" te JOIN "Ticket" t ON t."id" = te."ticketId"
        WHERE te."tenantId" = ? AND te."billable" = 1 AND te."workedAt" >= ? AND te."workedAt" <= ?
          AND t."requesterId" IN (SELECT "userId" FROM "UserOrganization" WHERE "organizationId" = ?)', [$c['tenantId'], $c['startsOn'], $end, $c['organizationId']]);
}

/** State of a contract for badges: used, left, percent, warning level. */
function contract_state(array $c): array
{
    $used = contract_used_minutes($c);
    $incl = $c['minutesIncluded'] !== null ? (int) $c['minutesIncluded'] : null;
    $left = $incl !== null ? $incl - $used : null;
    $daysLeft = $c['endsOn'] ? (int) floor((strtotime($c['endsOn'] . ' 23:59:59') - time()) / 86400) : null;
    $level = 'ok';
    if (!contract_is_current($c)) $level = 'expired';
    elseif (($left !== null && $left <= 0)) $level = 'over';
    elseif (($left !== null && $incl > 0 && $left / $incl < 0.15) || ($daysLeft !== null && $daysLeft <= 14)) $level = 'low';
    return ['used' => $used, 'included' => $incl, 'left' => $left, 'daysLeft' => $daysLeft, 'level' => $level, 'pct' => $incl ? min(100, (int) round($used / max(1, $incl) * 100)) : null];
}

/** The requester's current contract (via any of their organizations). */
function requester_contract(string $tenantId, string $userId): ?array
{
    $day = date('Y-m-d');
    $rows = q_all('SELECT c.*, o."name" AS "orgName" FROM "Contract" c JOIN "Organization" o ON o."id" = c."organizationId"
        WHERE c."tenantId" = ? AND c."active" = 1 AND c."startsOn" <= ? AND (c."endsOn" IS NULL OR c."endsOn" >= ?)
          AND c."organizationId" IN (SELECT "organizationId" FROM "UserOrganization" WHERE "userId" = ?)
        ORDER BY c."resolutionHours" IS NULL, c."resolutionHours" ASC, c."startsOn" DESC', [$tenantId, $day, $day, $userId]);
    return $rows[0] ?? null;
}

/** Deadline from the requester's contract (overrides the workspace SLA), or null. */
function contract_due_at(string $tenantId, string $requesterId, string $createdIso): ?string
{
    $c = requester_contract($tenantId, $requesterId);
    if (!$c || !$c['resolutionHours']) return null;
    return iso_from_ts(ts($createdIso) + (int) $c['resolutionHours'] * 3600);
}

/* ---------- Report data ---------- */

/** Tickets created per weekday (1=Mon) × hour in the app timezone. */
function report_heatmap(string $tenantId, string $fromIso): array
{
    $grid = array_fill(1, 7, array_fill(0, 24, 0));
    foreach (q_all('SELECT "createdAt" FROM "Ticket" WHERE "tenantId" = ? AND "createdAt" >= ?', [$tenantId, $fromIso]) as $r) {
        $t = (int) iso_sec($r['createdAt']);
        $grid[(int) date('N', $t)][(int) date('G', $t)]++;
    }
    return $grid;
}

/** Per organization: created, resolved, open, median first response, SLA met, satisfaction, time. */
function report_orgs(string $tenantId, string $fromIso, array $closedKeys): array
{
    $orgs = array_column(tenant_orgs($tenantId), 'name', 'id');
    $out = [];
    foreach ($orgs as $id => $name) $out[$id] = ['name' => $name, 'created' => 0, 'resolved' => 0, 'open' => 0, 'frt' => [], 'met' => 0, 'withSla' => 0, 'billable' => 0, 'other' => 0, 'csat' => null];
    $map = [];
    foreach (q_all('SELECT "userId","organizationId" FROM "UserOrganization" uo WHERE uo."organizationId" IN (SELECT "id" FROM "Organization" WHERE "tenantId" = ?)', [$tenantId]) as $r) $map[$r['userId']][] = $r['organizationId'];
    $each = function (string $requester, callable $fn) use ($map, &$out) { foreach ($map[$requester] ?? [] as $o) if (isset($out[$o])) $fn($out[$o]); };
    foreach (q_all('SELECT "requesterId","createdAt","firstResponseAt" FROM "Ticket" WHERE "tenantId" = ? AND "createdAt" >= ?', [$tenantId, $fromIso]) as $t) {
        $each($t['requesterId'], function (&$o) use ($t) { $o['created']++; if ($t['firstResponseAt']) $o['frt'][] = iso_sec($t['firstResponseAt']) - iso_sec($t['createdAt']); });
    }
    foreach (q_all('SELECT "requesterId","closedAt","dueAt" FROM "Ticket" WHERE "tenantId" = ? AND "closedAt" >= ? AND "mergedIntoId" IS NULL', [$tenantId, $fromIso]) as $t) {
        $each($t['requesterId'], function (&$o) use ($t) { $o['resolved']++; if ($t['dueAt']) { $o['withSla']++; if ($t['closedAt'] <= $t['dueAt']) $o['met']++; } });
    }
    if ($closedKeys) foreach (q_all('SELECT "requesterId" FROM "Ticket" WHERE "tenantId" = ? AND "status" NOT IN (' . in_ph($closedKeys) . ')', [$tenantId, ...$closedKeys]) as $t) {
        $each($t['requesterId'], function (&$o) { $o['open']++; });
    }
    $fromDay = substr(date('Y-m-d', (int) iso_sec($fromIso)), 0, 10);
    foreach (q_all('SELECT t."requesterId", te."minutes", te."billable" FROM "TimeEntry" te JOIN "Ticket" t ON t."id" = te."ticketId" WHERE te."tenantId" = ? AND te."workedAt" >= ?', [$tenantId, $fromDay]) as $r) {
        $each($r['requesterId'], function (&$o) use ($r) { if ((int) $r['billable']) $o['billable'] += (int) $r['minutes']; else $o['other'] += (int) $r['minutes']; });
    }
    $csat = [];
    foreach (q_all('SELECT uo."organizationId" AS org, COUNT(*) AS n, SUM(CASE WHEN cs."rating" >= 4 THEN 1 ELSE 0 END) AS good
                    FROM "CsatSurvey" cs JOIN "UserOrganization" uo ON uo."userId" = cs."requesterId"
                    WHERE cs."tenantId" = ? AND cs."answeredAt" >= ? GROUP BY uo."organizationId"', [$tenantId, $fromIso]) as $r) {
        $csat[$r['org']] = (int) $r['n'] ? (int) round($r['good'] / $r['n'] * 100) : null;
    }
    foreach ($out as $id => &$o) {
        $o['frtMedian'] = median_of($o['frt']);
        $o['slaPct'] = $o['withSla'] ? (int) round($o['met'] / $o['withSla'] * 100) : null;
        $o['csat'] = $csat[$id] ?? null;
        unset($o['frt']);
    }
    unset($o);
    $out = array_filter($out, fn ($o) => $o['created'] || $o['resolved'] || $o['open'] || $o['billable'] || $o['other']);
    uasort($out, fn ($a, $b) => [$b['created'], $b['open']] <=> [$a['created'], $a['open']]);
    return $out;
}

/** Minutes logged per agent since a day. */
function report_agent_minutes(string $tenantId, string $fromDay): array
{
    $out = [];
    foreach (q_all('SELECT "userId", SUM(CASE WHEN "billable" = 1 THEN "minutes" ELSE 0 END) AS b, SUM("minutes") AS t FROM "TimeEntry" WHERE "tenantId" = ? AND "workedAt" >= ? GROUP BY "userId"', [$tenantId, $fromDay]) as $r) {
        $out[$r['userId']] = ['billable' => (int) $r['b'], 'total' => (int) $r['t']];
    }
    return $out;
}

/**
 * Billing for a month ("2026-11"): per organization billable / other minutes,
 * tickets worked on and the current contract balance; plus the entries for detail sheets.
 */
function billing_report(string $tenantId, string $month): array
{
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $orgs = array_column(tenant_orgs($tenantId), 'name', 'id');
    $entries = q_all('SELECT te.*, t."number", t."subject", t."requesterId", u."name" AS "agent", r."name" AS "requester"
        FROM "TimeEntry" te JOIN "Ticket" t ON t."id" = te."ticketId" JOIN "User" u ON u."id" = te."userId" JOIN "User" r ON r."id" = t."requesterId"
        WHERE te."tenantId" = ? AND te."workedAt" >= ? AND te."workedAt" <= ? ORDER BY te."workedAt" ASC, t."number" ASC', [$tenantId, $from, $to]);
    $userOrgs = [];
    foreach (q_all('SELECT "userId","organizationId" FROM "UserOrganization" WHERE "organizationId" IN (SELECT "id" FROM "Organization" WHERE "tenantId" = ?) ORDER BY "organizationId"', [$tenantId]) as $r) $userOrgs[$r['userId']][] = $r['organizationId'];
    $rows = [];
    foreach ($entries as &$e) {
        // A ticket belongs to the requester's first organization for billing.
        $oid = ($userOrgs[$e['requesterId']] ?? [''])[0];
        $e['orgId'] = $oid;
        $e['orgName'] = $orgs[$oid] ?? 'No organization';
        $rows[$oid] ??= ['id' => $oid, 'name' => $orgs[$oid] ?? 'No organization', 'billable' => 0, 'other' => 0, 'tickets' => [], 'contract' => null];
        $rows[$oid][(int) $e['billable'] ? 'billable' : 'other'] += (int) $e['minutes'];
        $rows[$oid]['tickets'][$e['ticketId']] = true;
    }
    unset($e);
    $cfg = billing_settings($tenantId);
    $rates = [];
    foreach ($entries as &$e) {
        $rates[$e['orgId']] ??= org_hourly_rate($tenantId, $e['orgId'] ?: null);
        $e['amount'] = (int) $e['billable'] ? minutes_amount((int) $e['minutes'], $rates[$e['orgId']]) : null;
    }
    unset($e);
    foreach ($rows as $oid => &$r) {
        $r['tickets'] = count($r['tickets']);
        $r['rate'] = $rates[$oid] ?? org_hourly_rate($tenantId, $oid ?: null);
        $r['amount'] = minutes_amount($r['billable'], $r['rate']);
        if ($oid !== '') {
            foreach (org_contracts($oid) as $c) if (contract_is_current($c)) { $r['contract'] = ['name' => $c['name']] + contract_state($c); break; }
        }
    }
    unset($r);
    uasort($rows, fn ($a, $b) => $b['billable'] <=> $a['billable']);
    return ['from' => $from, 'to' => $to, 'rows' => $rows, 'entries' => $entries, 'currency' => $cfg['currency'],
        'billable' => array_sum(array_column($rows, 'billable')), 'other' => array_sum(array_column($rows, 'other')),
        'amount' => array_sum(array_map(fn ($r) => (float) ($r['amount'] ?? 0), $rows))];
}

/* ---------- Excel (.xlsx) without libraries ---------- */

/** Send a workbook: [sheet name => rows of cells]; the first row of each sheet is bold. */
function xlsx_download(string $filename, array $sheets): never
{
    if (!class_exists('ZipArchive')) abort(500, 'ZIP support is not installed on this server');
    $x = fn ($s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
    $tmp = tempnam(sys_get_temp_dir(), 'xl');
    $z = new ZipArchive();
    $z->open($tmp, ZipArchive::OVERWRITE);
    $names = [];
    $i = 0;
    foreach ($sheets as $name => $rows) {
        $i++;
        $names[$i] = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', ' ', (string) $name), 0, 31);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        foreach (array_values($rows) as $r => $cells) {
            $xml .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($cells) as $c => $v) {
                $ref = $col($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                if ($v === null || $v === '') continue;
                if (is_int($v) || is_float($v)) $xml .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                else $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $x($v) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $z->addFromString("xl/worksheets/sheet$i.xml", $xml . '</sheetData></worksheet>');
    }
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . implode('', array_map(fn ($n) => '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>', array_keys($names))) . '</Types>');
    $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>'
        . implode('', array_map(fn ($n, $nm) => '<sheet name="' . $x($nm) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>', array_keys($names), $names)) . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . implode('', array_map(fn ($n) => '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>', array_keys($names)))
        . '<Relationship Id="rId' . (count($names) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf fontId="0"/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
    $z->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
    header('Content-Length: ' . filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    throw new HttpExit();
}

/** Per agent: open now, resolved, median first response, SLA met, satisfaction, time. */
function report_agents(string $tenantId, string $fromIso, array $closedKeys): array
{
    $agents = q_all('SELECT "id","name" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') ORDER BY "name"', [$tenantId]);
    $out = [];
    foreach ($agents as $a) $out[$a['id']] = ['name' => $a['name'], 'open' => 0, 'resolved' => 0, 'frt' => [], 'met' => 0, 'withSla' => 0, 'csat' => null, 'csatN' => 0, 'billable' => 0, 'total' => 0];
    if ($closedKeys) foreach (q_all('SELECT "assigneeId", COUNT(*) AS n FROM "Ticket" WHERE "tenantId" = ? AND "assigneeId" IS NOT NULL AND "status" NOT IN (' . in_ph($closedKeys) . ') GROUP BY "assigneeId"', [$tenantId, ...$closedKeys]) as $r) if (isset($out[$r['assigneeId']])) $out[$r['assigneeId']]['open'] = (int) $r['n'];
    foreach (q_all('SELECT "assigneeId","closedAt","dueAt" FROM "Ticket" WHERE "tenantId" = ? AND "closedAt" >= ? AND "mergedIntoId" IS NULL', [$tenantId, $fromIso]) as $t) {
        if (!isset($out[$t['assigneeId'] ?? ''])) continue;
        $out[$t['assigneeId']]['resolved']++;
        if ($t['dueAt']) { $out[$t['assigneeId']]['withSla']++; if ($t['closedAt'] <= $t['dueAt']) $out[$t['assigneeId']]['met']++; }
    }
    foreach (q_all('SELECT "assigneeId","createdAt","firstResponseAt" FROM "Ticket" WHERE "tenantId" = ? AND "createdAt" >= ? AND "firstResponseAt" IS NOT NULL', [$tenantId, $fromIso]) as $t) {
        if (isset($out[$t['assigneeId'] ?? ''])) $out[$t['assigneeId']]['frt'][] = iso_sec($t['firstResponseAt']) - iso_sec($t['createdAt']);
    }
    foreach (q_all('SELECT "agentId", COUNT(*) AS n, SUM(CASE WHEN "rating" >= 4 THEN 1 ELSE 0 END) AS good FROM "CsatSurvey" WHERE "tenantId" = ? AND "answeredAt" >= ? AND "agentId" IS NOT NULL GROUP BY "agentId"', [$tenantId, $fromIso]) as $r) {
        if (isset($out[$r['agentId']])) { $out[$r['agentId']]['csatN'] = (int) $r['n']; $out[$r['agentId']]['csat'] = (int) round($r['good'] / max(1, $r['n']) * 100); }
    }
    foreach (report_agent_minutes($tenantId, date('Y-m-d', (int) iso_sec($fromIso))) as $id => $m) if (isset($out[$id])) { $out[$id]['billable'] = $m['billable']; $out[$id]['total'] = $m['total']; }
    foreach ($out as &$o) { $o['frtMedian'] = median_of($o['frt']); $o['slaPct'] = $o['withSla'] ? (int) round($o['met'] / $o['withSla'] * 100) : null; unset($o['frt']); }
    unset($o);
    uasort($out, fn ($a, $b) => [$b['resolved'], $b['open']] <=> [$a['resolved'], $a['open']]);
    return $out;
}
