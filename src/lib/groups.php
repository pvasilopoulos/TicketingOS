<?php
declare(strict_types=1);

/*
 * Agent groups (teams), automatic assignment, and customer organizations.
 */

const GROUP_ASSIGN_MODES = [
    'none' => ['Manual', 'Tickets wait in the group until someone takes them.'],
    'round_robin' => ['Round robin', 'Each new ticket goes to the next member in turn.'],
    'least_busy' => ['Least busy', 'Each new ticket goes to the member with the fewest open tickets.'],
];

function tenant_groups(string $tenantId): array
{
    static $cache = [];
    return $cache[$tenantId] ??= q_all('SELECT * FROM "AgentGroup" WHERE "tenantId" = ? ORDER BY "name"', [$tenantId]);
}

/** Group ids a user belongs to. */
function user_group_ids(string $userId): array
{
    static $cache = [];
    return $cache[$userId] ??= pluck(q_all('SELECT "groupId" FROM "AgentGroupMember" WHERE "userId" = ?', [$userId]), 'groupId');
}

/** Active staff members of a group. */
function group_members(string $groupId): array
{
    return q_all('SELECT u."id", u."name", u."avatarColor" FROM "AgentGroupMember" m JOIN "User" u ON u."id" = m."userId"
                  WHERE m."groupId" = ? AND u."active" = 1 AND u."role" IN (\'ADMIN\',\'AGENT\') ORDER BY u."name", u."id"', [$groupId]);
}

/**
 * Pick an assignee for a ticket that just landed in a group (or null for manual groups).
 * Round robin remembers the last pick on the group; least busy counts open tickets.
 */
function pick_group_assignee(array $group, string $tenantId): ?array
{
    $mode = $group['assignMode'] ?? 'none';
    if ($mode === 'none') return null;
    $members = group_members($group['id']);
    if (!$members) return null;
    if ($mode === 'least_busy') {
        $closed = closed_status_keys(tenant_statuses($tenantId));
        $ids = pluck($members, 'id');
        $open = array_column(q_all('SELECT "assigneeId", COUNT(*) AS n FROM "Ticket" WHERE "tenantId" = ? AND "assigneeId" IN (' . in_ph($ids) . ')'
            . ($closed ? ' AND "status" NOT IN (' . in_ph($closed) . ')' : '') . ' GROUP BY "assigneeId"', [$tenantId, ...$ids, ...$closed]), 'n', 'assigneeId');
        usort($members, fn ($a, $b) => [(int) ($open[$a['id']] ?? 0), $a['name']] <=> [(int) ($open[$b['id']] ?? 0), $b['name']]);
        $pick = $members[0];
    } else {
        $ids = pluck($members, 'id');
        $i = array_search($group['lastAssignedId'], $ids, true);
        $pick = $members[$i === false ? 0 : ($i + 1) % count($members)];
    }
    db_update('AgentGroup', ['lastAssignedId' => $pick['id']], '"id" = ?', [$group['id']]);
    return $pick;
}

/** Assign an unassigned ticket according to its group's rule (no-op otherwise). */
function auto_assign_ticket(string $ticketId, string $tenantId): void
{
    $t = q_one('SELECT * FROM "Ticket" WHERE "id" = ? AND "tenantId" = ?', [$ticketId, $tenantId]);
    if (!$t || $t['assigneeId'] || !$t['groupId']) return;
    $group = q_one('SELECT * FROM "AgentGroup" WHERE "id" = ? AND "tenantId" = ?', [$t['groupId'], $tenantId]);
    if (!$group) return;
    $pick = pick_group_assignee($group, $tenantId);
    if (!$pick) return;
    db_update('Ticket', ['assigneeId' => $pick['id'], 'updatedAt' => now_iso()], '"id" = ?', [$ticketId]);
    log_event($tenantId, $ticketId, null, 'ASSIGNED', "assigned the ticket to {$pick['name']} ({$group['name']}, " . strtolower(GROUP_ASSIGN_MODES[$group['assignMode']][0]) . ')');
    dispatch([
        'tenantId' => $tenantId, 'recipients' => [$pick['id']], 'category' => 'assigned', 'ticket' => ticket_ref($t),
        'inAppType' => 'TICKET_ASSIGNED',
        'inAppMessage' => "You were assigned ticket #{$t['number']}: {$t['subject']}",
        'pushBody' => 'You were assigned this ticket',
        'emailLine' => "You were assigned ticket #{$t['number']}: {$t['subject']} (group {$group['name']}).",
    ]);
    reindex_ticket($ticketId);
}

/* ---------- Customer organizations ---------- */

function tenant_orgs(string $tenantId): array
{
    static $cache = [];
    return $cache[$tenantId] ??= q_all('SELECT * FROM "Organization" WHERE "tenantId" = ? ORDER BY "name"', [$tenantId]);
}

/** Lower-cased domain part of an email. */
function email_domain(string $email): string
{
    return strtolower(substr(strrchr($email, '@') ?: '', 1));
}

/** Organization whose domain list matches an email ("acme.com, acme.co.uk"). */
function org_for_email(string $tenantId, string $email): ?array
{
    $d = email_domain($email);
    if ($d === '') return null;
    foreach (tenant_orgs($tenantId) as $o) {
        foreach (org_domains($o['domain'] ?? '') as $od) if ($od === $d) return $o;
    }
    return null;
}

function org_domains(?string $raw): array
{
    return array_values(array_filter(array_map(fn ($d) => strtolower(trim($d, " \t@")), preg_split('/[\s,;]+/', (string) $raw))));
}
