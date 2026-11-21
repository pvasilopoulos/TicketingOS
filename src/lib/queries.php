<?php
declare(strict_types=1);

/* Shared read queries for ticket lists. */

const TICKET_ROW_SELECT = 'SELECT t.*, r."name" AS "rName", r."avatarColor" AS "rColor", r."email" AS "rEmail", r."organizationId" AS "rOrg",
    a."name" AS "aName", a."avatarColor" AS "aColor", c."name" AS "cName", c."color" AS "cColor",
    (SELECT COUNT(*) FROM "Comment" cm WHERE cm."ticketId" = t."id") AS "commentCount",
    (SELECT COUNT(*) FROM "TicketAssignee" tx WHERE tx."ticketId" = t."id") AS "coCount"
  FROM "Ticket" t
  JOIN "User" r ON r."id" = t."requesterId"
  LEFT JOIN "User" a ON a."id" = t."assigneeId"
  LEFT JOIN "Category" c ON c."id" = t."categoryId"';

/** Visibility clause: staff see the whole board, others what they requested or watch. */
function ticket_scope_sql(array $user, string $tenantId, array &$params): string
{
    $params[] = $tenantId;
    if (is_staff($user['role']) && can($user, 'tickets.view_all')) return 't."tenantId" = ?';
    if (!is_staff($user['role']) && !user_manages_any_org($user['id'])) {
        // Customers see a handful of tickets (requested, assigned or followed): build that short id
        // list from indexes instead of checking every ticket of the board. Organization managers can
        // see thousands, where the plain OR below is faster, so they keep it.
        array_push($params, $tenantId, $user['id'], $tenantId, $user['id'], $user['id'], $user['id']);
        // Wrapped in a derived table so MySQL builds the id list once instead of once per row.
        return 't."tenantId" = ? AND t."id" IN (SELECT v."id" FROM (
            SELECT t1."id" FROM "Ticket" t1 WHERE t1."tenantId" = ? AND t1."requesterId" = ?
            UNION SELECT t2."id" FROM "Ticket" t2 WHERE t2."tenantId" = ? AND t2."assigneeId" = ?
            UNION SELECT ta."ticketId" FROM "TicketAssignee" ta WHERE ta."userId" = ?
            UNION SELECT w."ticketId" FROM "TicketWatcher" w WHERE w."userId" = ?) v)';
    }
    array_push($params, $user['id'], $user['id'], $user['id'], $user['id']);
    // Customers and restricted agents: requested, assigned to them (lead or co-assignee), or followed…
    $or = ['t."requesterId" = ?', ASSIGNED_TO_SQL, 'EXISTS (SELECT 1 FROM "TicketWatcher" w WHERE w."ticketId" = t."id" AND w."userId" = ?)'];
    // …plus, for agents, tickets of their groups…
    if (is_staff($user['role'])) {
        $or[] = 't."groupId" IN (SELECT gm."groupId" FROM "AgentGroupMember" gm WHERE gm."userId" = ?)';
        $params[] = $user['id'];
        // …the organizations they handle…
        $or[] = REQUESTER_IN_MY_ORGS_SQL;
        $params[] = $user['id'];
        // …and the shared queue of unassigned tickets, when allowed.
        if (can($user, 'tickets.view_unassigned')) $or[] = 't."assigneeId" IS NULL';
    }
    // …and, for organization managers, every ticket of their organization.
    if (!is_staff($user['role']) && user_manages_any_org($user['id'])) {
        $or[] = REQUESTER_IN_MANAGED_ORG_SQL;
        $params[] = $user['id'];
    }
    return 't."tenantId" = ? AND (' . implode(' OR ', $or) . ')';
}

const TICKETS_PER_PAGE = 50;
const BOARD_COLUMN_LIMIT = 50;
/** Board grouped into swimlanes: at most this many lanes, and cards per lane and status. */
const BOARD_LANE_LIMIT = 30;
const BOARD_LANE_CELL_LIMIT = 10;

/** Values of a multi-value filter: "OPEN,PENDING" (or an array) => ['OPEN', 'PENDING']. */
function filter_values(mixed $v): array
{
    $list = is_array($v) ? $v : explode(',', (string) $v);
    return array_slice(array_values(array_unique(array_filter(array_map(fn ($x) => trim((string) $x), $list), fn ($x) => $x !== ''))), 0, 30);
}

/** "t.col = ?" or "t.col IN (?, ?)" for a multi-value filter. */
function filter_in_sql(string $col, array $vals, array &$params): string
{
    array_push($params, ...$vals);
    return count($vals) === 1 ? "$col = ?" : "$col IN (" . in_ph($vals) . ')';
}

/**
 * WHERE clause for the ticket list/board/export from query-string filters:
 * q and, each with one or several comma-separated values, status, priority, category, tag, overdue=1, assignee=me|none.
 */
function ticket_filter_sql(array $user, string $tenantId, array $sp, array &$params, array $closedKeys): string
{
    $where = [ticket_scope_sql($user, $tenantId, $params)];
    if ($v = filter_values($sp['status'] ?? '')) $where[] = filter_in_sql('t."status"', $v, $params);
    if ($v = filter_values($sp['priority'] ?? '')) $where[] = filter_in_sql('t."priority"', $v, $params);
    if ($v = filter_values($sp['category'] ?? '')) $where[] = filter_in_sql('t."categoryId"', $v, $params);
    if ($v = filter_values($sp['source'] ?? '')) $where[] = filter_in_sql('t."source"', $v, $params);
    // Several values of one filter mean "any of them".
    if ($v = filter_values($sp['group'] ?? '')) {
        $or = [];
        if (in_array('mine', $v, true)) { $or[] = 't."groupId" IN (SELECT gm2."groupId" FROM "AgentGroupMember" gm2 WHERE gm2."userId" = ?)'; $params[] = $user['id']; }
        if (in_array('none', $v, true)) $or[] = 't."groupId" IS NULL';
        if ($ids = array_values(array_diff($v, ['mine', 'none']))) $or[] = filter_in_sql('t."groupId"', $ids, $params);
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if ($v = filter_values($sp['org'] ?? '')) {
        $where[] = 'EXISTS (SELECT 1 FROM "UserOrganization" ruo WHERE ruo."userId" = t."requesterId" AND ' . filter_in_sql('ruo."organizationId"', $v, $params) . ')';
    }
    if ($v = filter_values($sp['tag'] ?? '')) {
        $where[] = 'EXISTS (SELECT 1 FROM "TicketTag" tt WHERE tt."ticketId" = t."id" AND ' . filter_in_sql('tt."tagId"', $v, $params) . ')';
    }
    if ($v = filter_values($sp['assignee'] ?? '')) {
        $or = [];
        if (in_array('none', $v, true)) $or[] = 't."assigneeId" IS NULL';
        $ids = array_values(array_unique(array_map(fn ($x) => $x === 'me' ? $user['id'] : $x, array_diff($v, ['none']))));
        if ($ids) {
            $or[] = filter_in_sql('t."assigneeId"', $ids, $params);
            $or[] = 'EXISTS (SELECT 1 FROM "TicketAssignee" ta WHERE ta."ticketId" = t."id" AND ' . filter_in_sql('ta."userId"', $ids, $params) . ')';
        }
        $where[] = '(' . implode(' OR ', $or) . ')';
    }
    if (($sp['overdue'] ?? '') === '1') {
        $where[] = 't."dueAt" < ?';
        $params[] = now_iso();
        if ($closedKeys) { $where[] = 't."status" NOT IN (' . in_ph($closedKeys) . ')'; array_push($params, ...$closedKeys); }
    }
    if (!empty($sp['q'])) {
        search_sql(mb_substr(trim($sp['q']), 0, 200), [
            'statuses' => tenant_statuses($tenantId),
            'priorities' => tenant_priorities($tenantId),
            'closedKeys' => $closedKeys,
            'user' => $user,
            'tenantId' => $tenantId,
        ], $where, $params);
    }
    return implode(' AND ', $where);
}

/**
 * Fetch full rows for a page of ticket ids. Paging on ids first keeps the
 * per-row joins / comment counts limited to the rows actually shown.
 */
function ticket_rows_by_ids(array $ids, array $statuses, array $priorities): array
{
    if (!$ids) return [];
    $rows = key_by(q_all(TICKET_ROW_SELECT . ' WHERE t."id" IN (' . in_ph($ids) . ')', $ids), 'id');
    $out = [];
    foreach ($ids as $id) if (isset($rows[$id])) $out[] = ticket_view_row($rows[$id], $statuses, $priorities);
    return $out;
}

/** Turn a joined DB row into the view model used by list/board components. */
function ticket_view_row(array $t, array $statuses, array $priorities): array
{
    $s = resolve_status($statuses, $t['status']);
    $p = resolve_priority($priorities, $t['priority']);
    return [
        'id' => $t['id'],
        'number' => (int) $t['number'],
        'subject' => $t['subject'],
        'status' => ['key' => $s['key'], 'label' => $s['label'], 'color' => $s['color'], 'isClosed' => $s['isClosed']],
        'priority' => ['key' => $p['key'], 'label' => $p['label'], 'color' => $p['color']],
        'createdAt' => $t['createdAt'],
        'dueAt' => $t['dueAt'],
        'commentCount' => (int) $t['commentCount'],
        'sentiment' => $t['sentiment'] ?? null,
        'requester' => ['name' => $t['rName'], 'color' => $t['rColor']],
        'assignee' => $t['aName'] !== null ? ['name' => $t['aName'], 'color' => $t['aColor'], 'more' => (int) ($t['coCount'] ?? 0)] : null,
        'category' => $t['cName'] !== null ? ['name' => $t['cName'], 'color' => $t['cColor']] : null,
    ];
}

/* ---------- Several assignees per ticket ----------
 * Ticket.assigneeId is the lead (reports, SLA, CSAT, auto-assign use it);
 * TicketAssignee holds everyone else who works on the ticket. */

/** SQL: is user "?" one of the ticket's assignees (lead or co-assignee)? Alias t = Ticket. */
const ASSIGNED_TO_SQL = '(t."assigneeId" = ? OR EXISTS (SELECT 1 FROM "TicketAssignee" ta WHERE ta."ticketId" = t."id" AND ta."userId" = ?))';

function ticket_co_assignee_ids(string $ticketId): array
{
    return pluck(q_all('SELECT "userId" FROM "TicketAssignee" WHERE "ticketId" = ? ORDER BY "createdAt", "userId"', [$ticketId]), 'userId');
}

/** Lead first, then co-assignees (no duplicates). */
function ticket_assignee_ids(array $ticket): array
{
    $ids = $ticket['assigneeId'] ? [$ticket['assigneeId']] : [];
    return array_values(array_unique(array_merge($ids, ticket_co_assignee_ids($ticket['id']))));
}

/** Rows (id, name, avatarColor) of all assignees, lead first. */
function ticket_assignees(array $ticket): array
{
    $ids = ticket_assignee_ids($ticket);
    if (!$ids) return [];
    $rows = key_by(q_all('SELECT "id","name","avatarColor" FROM "User" WHERE "id" IN (' . in_ph($ids) . ')', $ids), 'id');
    return array_values(array_filter(array_map(fn ($id) => $rows[$id] ?? null, $ids)));
}

function is_ticket_assignee(array $ticket, string $userId): bool
{
    if (($ticket['assigneeId'] ?? null) === $userId) return true;
    return (bool) q_val('SELECT 1 FROM "TicketAssignee" WHERE "ticketId" = ? AND "userId" = ?', [$ticket['id'], $userId]);
}

/** Replace the co-assignees (everyone but the lead). */
function set_ticket_co_assignees(string $ticketId, ?string $leadId, array $ids): void
{
    q('DELETE FROM "TicketAssignee" WHERE "ticketId" = ?', [$ticketId]);
    $now = now_iso();
    foreach (array_unique($ids) as $id) {
        if ($id === $leadId) continue;
        db_insert('TicketAssignee', ['ticketId' => $ticketId, 'userId' => $id, 'createdAt' => $now]);
    }
}

/* ---------- Grouping the ticket list / board (?groupby=) ---------- */

/** What tickets can be grouped by; the board can't group by status (its columns already are). */
function ticket_groupings(bool $board): array
{
    $o = ['org' => 'Organization', 'assignee' => 'Assignee', 'group' => 'Group', 'priority' => 'Priority', 'status' => 'Status'];
    if ($board) unset($o['status']);
    return $o;
}

/**
 * SQL for a ticket's group key ('' = none). A ticket's organization is its
 * requester's main organization (else the first one they belong to).
 */
function ticket_group_expr(string $by): string
{
    return match ($by) {
        'org' => 'COALESCE((SELECT COALESCE(gu."organizationId", (SELECT MIN(guo."organizationId") FROM "UserOrganization" guo WHERE guo."userId" = gu."id")) FROM "User" gu WHERE gu."id" = t."requesterId"), \'\')',
        'assignee' => 'COALESCE(t."assigneeId", \'\')',
        'group' => 'COALESCE(t."groupId", \'\')',
        'priority' => 't."priority"',
        'status' => 't."status"',
    };
}

/**
 * The groups of the filtered tickets, in display order, each with
 * key, label, color, total, open and overdue counts and (organizations) a dashboard link.
 * Organizations, agents and groups: most overdue first, then most open, by name; "none" last.
 * Priorities: highest first. Statuses: workflow order.
 */
function ticket_groups(string $by, string $where, array $params, string $tenantId, array $statuses, array $priorities, array $closedKeys): array
{
    $expr = ticket_group_expr($by);
    $p = $params;
    $open = $closedKeys ? 't."status" NOT IN (' . in_ph($closedKeys) . ')' : '1 = 1';
    $sel = "SELECT $expr AS \"gk\", COUNT(*) AS \"total\",
        SUM(CASE WHEN $open THEN 1 ELSE 0 END) AS \"open\",
        SUM(CASE WHEN $open AND t.\"dueAt\" IS NOT NULL AND t.\"dueAt\" < ? THEN 1 ELSE 0 END) AS \"overdue\"
      FROM \"Ticket\" t WHERE $where GROUP BY $expr";
    $args = array_merge($closedKeys, $closedKeys, [now_iso()], $p);
    $rows = q_all($sel, $args);

    $names = [];
    $colors = [];
    switch ($by) {
        case 'org': $names = array_column(tenant_orgs($tenantId), 'name', 'id'); $none = 'No organization'; break;
        case 'group': $names = array_column(tenant_groups($tenantId), 'name', 'id'); $none = 'No group'; break;
        case 'assignee':
            $ids = array_values(array_filter(array_column($rows, 'gk')));
            foreach ($ids ? q_all('SELECT "id", "name", "avatarColor" FROM "User" WHERE "id" IN (' . in_ph($ids) . ')', $ids) : [] as $u) { $names[$u['id']] = $u['name']; $colors[$u['id']] = $u['avatarColor']; }
            $none = 'Unassigned';
            break;
        case 'priority': $names = array_column($priorities, 'label', 'key'); $colors = array_column($priorities, 'color', 'key'); $none = 'No priority'; break;
        default: $names = array_column($statuses, 'label', 'key'); $colors = array_column($statuses, 'color', 'key'); $none = 'No status';
    }
    $out = [];
    foreach ($rows as $r) {
        $k = (string) $r['gk'];
        $out[$k] = [
            'key' => $k, 'label' => $k === '' ? $none : ($names[$k] ?? $k), 'color' => $colors[$k] ?? null,
            'total' => (int) $r['total'], 'open' => (int) $r['open'], 'overdue' => (int) $r['overdue'],
            'link' => $by === 'org' && $k !== '' ? '/organization?id=' . rawurlencode($k) : null,
        ];
    }
    if ($by === 'priority' || $by === 'status') {
        $order = $by === 'priority' ? array_reverse(array_column($priorities, 'key')) : array_column($statuses, 'key');
        $pos = array_flip($order);
        uasort($out, fn ($a, $b) => ($pos[$a['key']] ?? 999) <=> ($pos[$b['key']] ?? 999));
    } else {
        uasort($out, fn ($a, $b) => [$a['key'] === '', -$a['overdue'], -$a['open'], mb_strtolower($a['label'])] <=> [$b['key'] === '', -$b['overdue'], -$b['open'], mb_strtolower($b['label'])]);
    }
    return $out;
}

/** ORDER BY piece that keeps the groups in display order (params appended to $params). */
function ticket_group_order_sql(string $by, array $groups, array &$params): string
{
    $keys = array_keys($groups);
    if (!$keys) return '0';
    $sql = 'CASE ' . ticket_group_expr($by);
    foreach ($keys as $i => $k) { $sql .= ' WHEN ? THEN ' . $i; $params[] = (string) $k; }
    return $sql . ' ELSE ' . count($keys) . ' END';
}

/** The list filter that shows one group's tickets (for "open in list" links), or []. */
function ticket_group_filter(string $by, string $key): array
{
    if ($by === 'org') return $key === '' ? [] : ['org' => $key];
    if ($by === 'assignee' || $by === 'group') return [$by => $key === '' ? 'none' : $key];
    return [$by => $key];
}

/** Counts per (group, subgroup) pair for a two-level grouped list: [k1][k2] => total, open, overdue. */
function ticket_group_pairs(string $by, string $by2, string $where, array $params, array $closedKeys): array
{
    $e1 = ticket_group_expr($by);
    $e2 = ticket_group_expr($by2);
    $open = $closedKeys ? 't."status" NOT IN (' . in_ph($closedKeys) . ')' : '1 = 1';
    $out = [];
    foreach (q_all("SELECT $e1 AS \"k1\", $e2 AS \"k2\", COUNT(*) AS \"total\",
            SUM(CASE WHEN $open THEN 1 ELSE 0 END) AS \"open\",
            SUM(CASE WHEN $open AND t.\"dueAt\" IS NOT NULL AND t.\"dueAt\" < ? THEN 1 ELSE 0 END) AS \"overdue\"
          FROM \"Ticket\" t WHERE $where GROUP BY $e1, $e2", array_merge($closedKeys, $closedKeys, [now_iso()], $params)) as $r) {
        $out[(string) $r['k1']][(string) $r['k2']] = ['total' => (int) $r['total'], 'open' => (int) $r['open'], 'overdue' => (int) $r['overdue']];
    }
    return $out;
}
