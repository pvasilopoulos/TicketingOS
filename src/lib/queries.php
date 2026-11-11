<?php
declare(strict_types=1);

/* Shared read queries for ticket lists. */

const TICKET_ROW_SELECT = 'SELECT t.*, r."name" AS "rName", r."avatarColor" AS "rColor", r."email" AS "rEmail", r."organizationId" AS "rOrg",
    a."name" AS "aName", a."avatarColor" AS "aColor", c."name" AS "cName", c."color" AS "cColor",
    (SELECT COUNT(*) FROM "Comment" cm WHERE cm."ticketId" = t."id") AS "commentCount"
  FROM "Ticket" t
  JOIN "User" r ON r."id" = t."requesterId"
  LEFT JOIN "User" a ON a."id" = t."assigneeId"
  LEFT JOIN "Category" c ON c."id" = t."categoryId"';

/** Visibility clause: staff see the whole board, others what they requested or watch. */
function ticket_scope_sql(array $user, string $tenantId, array &$params): string
{
    $params[] = $tenantId;
    if (is_staff($user['role']) && can($user, 'tickets.view_all')) return 't."tenantId" = ?';
    array_push($params, $user['id'], $user['id'], $user['id']);
    // Customers and restricted agents: requested, assigned to them, or followed…
    $or = ['t."requesterId" = ?', 't."assigneeId" = ?', 'EXISTS (SELECT 1 FROM "TicketWatcher" w WHERE w."ticketId" = t."id" AND w."userId" = ?)'];
    // …plus, for agents, tickets of their groups…
    if (is_staff($user['role'])) {
        $or[] = 't."groupId" IN (SELECT gm."groupId" FROM "AgentGroupMember" gm WHERE gm."userId" = ?)';
        $params[] = $user['id'];
        // …and the shared queue of unassigned tickets, when allowed.
        if (can($user, 'tickets.view_unassigned')) $or[] = 't."assigneeId" IS NULL';
    }
    // …and, for organization managers, every ticket of their organization.
    if (!is_staff($user['role']) && !empty($user['organizationId']) && (int) ($user['orgManager'] ?? 0)) {
        $or[] = 'EXISTS (SELECT 1 FROM "User" ro WHERE ro."id" = t."requesterId" AND ro."organizationId" = ?)';
        $params[] = $user['organizationId'];
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
    if (!empty($sp['org'])) { $where[] = 'EXISTS (SELECT 1 FROM "User" ro2 WHERE ro2."id" = t."requesterId" AND ro2."organizationId" = ?)'; $params[] = $sp['org']; }
    if (!empty($sp['source'])) { $where[] = 't."source" = ?'; $params[] = $sp['source']; }
    if (!empty($sp['tag'])) { $where[] = 'EXISTS (SELECT 1 FROM "TicketTag" tt WHERE tt."ticketId" = t."id" AND tt."tagId" = ?)'; $params[] = $sp['tag']; }
    if (($sp['assignee'] ?? '') === 'me') { $where[] = 't."assigneeId" = ?'; $params[] = $user['id']; }
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
        'requester' => ['name' => $t['rName'], 'color' => $t['rColor']],
        'assignee' => $t['aName'] !== null ? ['name' => $t['aName'], 'color' => $t['aColor']] : null,
        'category' => $t['cName'] !== null ? ['name' => $t['cName'], 'color' => $t['cColor']] : null,
    ];
}
