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

/**
 * WHERE clause for the ticket list/board/export from query-string filters:
 * q, status, priority, category, tag, overdue=1, assignee=me|none.
 */
function ticket_filter_sql(array $user, string $tenantId, array $sp, array &$params, array $closedKeys): string
{
    $where = [ticket_scope_sql($user, $tenantId, $params)];
    if (!empty($sp['status'])) { $where[] = 't."status" = ?'; $params[] = $sp['status']; }
    if (!empty($sp['priority'])) { $where[] = 't."priority" = ?'; $params[] = $sp['priority']; }
    if (!empty($sp['category'])) { $where[] = 't."categoryId" = ?'; $params[] = $sp['category']; }
    if (($sp['group'] ?? '') === 'mine') { $where[] = 't."groupId" IN (SELECT gm2."groupId" FROM "AgentGroupMember" gm2 WHERE gm2."userId" = ?)'; $params[] = $user['id']; }
    elseif (($sp['group'] ?? '') === 'none') { $where[] = 't."groupId" IS NULL'; }
    elseif (!empty($sp['group'])) { $where[] = 't."groupId" = ?'; $params[] = $sp['group']; }
    if (!empty($sp['org'])) { $where[] = REQUESTER_IN_ORG_SQL; $params[] = $sp['org']; }
    if (!empty($sp['source'])) { $where[] = 't."source" = ?'; $params[] = $sp['source']; }
    if (!empty($sp['tag'])) { $where[] = 'EXISTS (SELECT 1 FROM "TicketTag" tt WHERE tt."ticketId" = t."id" AND tt."tagId" = ?)'; $params[] = $sp['tag']; }
    if (($sp['assignee'] ?? '') === 'me') { $where[] = ASSIGNED_TO_SQL; array_push($params, $user['id'], $user['id']); }
    if (($sp['assignee'] ?? '') === 'none') { $where[] = 't."assigneeId" IS NULL'; }
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
