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
                  WHERE m."groupId" = ? AND u."active" = 1 AND u."role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') ORDER BY u."name", u."id"', [$groupId]);
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

/* ---------- Who someone may add to a ticket's team ---------- */

/**
 * People the user may add as ticket followers (or @mention into a ticket).
 * Staff: anyone in the workspace. Customers: only active colleagues of their
 * own organization — never other customers or the staff list.
 */
function addable_people(array $user, string $tenantId): array
{
    if (is_staff($user['role'])) {
        return q_all('SELECT "id","name","role" FROM "User" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
    }
    return q_all('SELECT DISTINCT u."id", u."name", u."role" FROM "User" u JOIN "UserOrganization" uo ON uo."userId" = u."id"
        WHERE u."tenantId" = ? AND u."role" = \'CUSTOMER\' AND u."active" = 1 AND u."id" <> ?
          AND uo."organizationId" IN (SELECT "organizationId" FROM "UserOrganization" WHERE "userId" = ?) ORDER BY u."name" ASC',
        [$tenantId, $user['id'], $user['id']]);
}

function can_add_person(array $user, array $target): bool
{
    if (is_staff($user['role'])) return true;
    if ($target['role'] !== 'CUSTOMER' || (int) ($target['active'] ?? 1) !== 1) return false;
    return (bool) q_val('SELECT 1 FROM "UserOrganization" a JOIN "UserOrganization" b ON b."organizationId" = a."organizationId" WHERE a."userId" = ? AND b."userId" = ?', [$user['id'], $target['id']]);
}

/* ---------- Organization membership (a person can belong to several) ----------
 * UserOrganization holds every membership, with manager rights per organization.
 * User.organizationId / orgManager mirror the *primary* (first) membership, used
 * for display, reports and CSV. Always change memberships through these helpers. */

/** SQL: requester of ticket alias t is a member of organization "?". */
const REQUESTER_IN_ORG_SQL = 'EXISTS (SELECT 1 FROM "UserOrganization" ruo WHERE ruo."userId" = t."requesterId" AND ruo."organizationId" = ?)';
/** SQL: requester of ticket alias t is in an organization that (staff) user "?" belongs to. */
const REQUESTER_IN_MY_ORGS_SQL = 'EXISTS (SELECT 1 FROM "UserOrganization" ruo JOIN "UserOrganization" auo ON auo."organizationId" = ruo."organizationId"
    WHERE ruo."userId" = t."requesterId" AND auo."userId" = ?)';
/** SQL: requester of ticket alias t is in an organization that user "?" manages. */
const REQUESTER_IN_MANAGED_ORG_SQL = 'EXISTS (SELECT 1 FROM "UserOrganization" ruo JOIN "UserOrganization" muo ON muo."organizationId" = ruo."organizationId"
    WHERE ruo."userId" = t."requesterId" AND muo."userId" = ? AND muo."isManager" = 1)';

/** [organizationId => ['id','name','isManager'], ...] for a user, primary first. */
function user_orgs(string $userId): array
{
    $rows = q_all('SELECT o."id", o."name", uo."isManager", (CASE WHEN u."organizationId" = o."id" THEN 0 ELSE 1 END) AS "notPrimary"
        FROM "UserOrganization" uo JOIN "Organization" o ON o."id" = uo."organizationId" JOIN "User" u ON u."id" = uo."userId"
        WHERE uo."userId" = ? ORDER BY "notPrimary", o."name"', [$userId]);
    $out = [];
    foreach ($rows as $r) $out[$r['id']] = ['id' => $r['id'], 'name' => $r['name'], 'isManager' => (int) $r['isManager'] === 1];
    return $out;
}

function user_org_ids(string $userId): array
{
    return pluck(q_all('SELECT "organizationId" FROM "UserOrganization" WHERE "userId" = ?', [$userId]), 'organizationId');
}

function user_manages_any_org(string $userId): bool
{
    return (bool) q_val('SELECT 1 FROM "UserOrganization" WHERE "userId" = ? AND "isManager" = 1', [$userId]);
}

/** Point User.organizationId / orgManager at the primary membership (or clear them). */
function sync_primary_org(string $userId, ?string $preferOrgId = null): void
{
    $rows = q_all('SELECT "organizationId", "isManager" FROM "UserOrganization" WHERE "userId" = ? ORDER BY "createdAt", "organizationId"', [$userId]);
    $byOrg = array_column($rows, 'isManager', 'organizationId');
    $cur = q_val('SELECT "organizationId" FROM "User" WHERE "id" = ?', [$userId]);
    if (q_val('SELECT "role" FROM "User" WHERE "id" = ?', [$userId]) !== 'CUSTOMER') {
        db_update('User', ['organizationId' => null, 'orgManager' => 0], '"id" = ?', [$userId]);
        return;
    }
    $primary = $preferOrgId && isset($byOrg[$preferOrgId]) ? $preferOrgId : ($cur && isset($byOrg[$cur]) ? $cur : ($rows[0]['organizationId'] ?? null));
    db_update('User', ['organizationId' => $primary, 'orgManager' => $primary ? (int) $byOrg[$primary] : 0], '"id" = ?', [$userId]);
}

function add_user_org(string $userId, string $orgId, bool $manager = false): void
{
    if (q_val('SELECT 1 FROM "UserOrganization" WHERE "userId" = ? AND "organizationId" = ?', [$userId, $orgId])) {
        q('UPDATE "UserOrganization" SET "isManager" = ? WHERE "userId" = ? AND "organizationId" = ?', [$manager ? 1 : 0, $userId, $orgId]);
    } else {
        db_insert('UserOrganization', ['userId' => $userId, 'organizationId' => $orgId, 'isManager' => $manager ? 1 : 0, 'createdAt' => now_iso()]);
    }
    sync_primary_org($userId);
}

function remove_user_org(string $userId, string $orgId): void
{
    q('DELETE FROM "UserOrganization" WHERE "userId" = ? AND "organizationId" = ?', [$userId, $orgId]);
    sync_primary_org($userId);
}

/** Replace all memberships: [orgId => isManager(bool)]. The first key becomes primary unless the current one stays. */
function set_user_orgs(string $userId, array $orgs): void
{
    q('DELETE FROM "UserOrganization" WHERE "userId" = ?' . ($orgs ? ' AND "organizationId" NOT IN (' . in_ph(array_keys($orgs)) . ')' : ''), [$userId, ...array_keys($orgs)]);
    foreach ($orgs as $orgId => $mgr) {
        if (q_val('SELECT 1 FROM "UserOrganization" WHERE "userId" = ? AND "organizationId" = ?', [$userId, $orgId])) {
            q('UPDATE "UserOrganization" SET "isManager" = ? WHERE "userId" = ? AND "organizationId" = ?', [$mgr ? 1 : 0, $userId, $orgId]);
        } else {
            db_insert('UserOrganization', ['userId' => $userId, 'organizationId' => $orgId, 'isManager' => $mgr ? 1 : 0, 'createdAt' => now_iso()]);
        }
    }
    sync_primary_org($userId);
}
