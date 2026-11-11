<?php
declare(strict_types=1);

// Cookie that lets a SUPER_ADMIN "enter" a tenant and operate inside its board.
const ACT_COOKIE = 'tos_act_tenant';

function hash_password(string $password): string
{
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 10]);
}

function verify_password(string $password, string $hash): bool
{
    return password_verify($password, $hash);
}

/** The full User row for the current session, or null. */
function current_user(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $s = get_session();
    if (!$s) return $cache = null;
    $u = q_one('SELECT * FROM "User" WHERE "id" = ?', [$s['userId'] ?? '']);
    // A deactivated account loses access immediately, even with a valid session cookie.
    if ($u && isset($u['active']) && !(int) $u['active']) $u = null;
    return $cache = $u;
}

function require_user(): array
{
    $u = current_user();
    if (!$u) redirect('/login');
    return $u;
}

// A super admin acting inside a tenant is treated as staff/admin there.
function is_staff(string $role): bool
{
    return in_array($role, ['ADMIN', 'AGENT', 'SUPER_ADMIN'], true);
}

/** Super admin: everything in every workspace plus the platform console. */
function is_super_admin(array $user): bool
{
    return $user['role'] === 'SUPER_ADMIN';
}

function is_tenant_admin(string $role): bool
{
    return $role === 'ADMIN' || $role === 'SUPER_ADMIN';
}

function active_tenant_id(): ?string
{
    $v = $_COOKIE[ACT_COOKIE] ?? '';
    return $v !== '' ? $v : null;
}

function set_active_tenant_id(string $id): void
{
    set_cookie(ACT_COOKIE, $id);
}

function clear_active_tenant_id(): void
{
    set_cookie(ACT_COOKIE, '', -1);
}

/** The tenant a user operates in: their own, or (super admin) the impersonated one. */
function effective_tenant_id(array $user): ?string
{
    // A super admin works in the workspace they entered, else their own (if any).
    if ($user['role'] === 'SUPER_ADMIN') return active_tenant_id() ?? ($user['tenantId'] ?: null);
    return $user['tenantId'] ?: null;
}

/**
 * Requires an authenticated user operating inside a tenant.
 * Returns [user, tenantId, impersonating].
 */
function require_tenant_user(): array
{
    $user = require_user();
    if ($user['role'] === 'SUPER_ADMIN') {
        $tid = active_tenant_id() ?? ($user['tenantId'] ?: null);
        if (!$tid) redirect('/platform');
        if (!q_val('SELECT 1 FROM "Tenant" WHERE "id" = ?', [$tid])) {
            clear_active_tenant_id();
            redirect(home_for($user));
        }
        // "Impersonating" only when visiting someone else's workspace.
        return [$user, $tid, $tid !== ($user['tenantId'] ?? null)];
    }
    if (!$user['tenantId']) redirect('/platform');
    return [$user, $user['tenantId'], false];
}

/** Workspace configuration (statuses, SLA, tags, macros, automations, email…). */
function require_tenant_admin(string $perm = 'settings.*'): array
{
    $ctx = require_tenant_user();
    if (!can($ctx[0], $perm)) redirect('/dashboard');
    return $ctx;
}

/**
 * Can this user see the ticket at all? Staff with "See all tickets" see
 * everything in the tenant; everyone else only their own / assigned /
 * followed tickets.
 */
function ticket_visible(array $ticket, array $user): bool
{
    if (is_staff($user['role']) && can($user, 'tickets.view_all')) return true;
    if ($ticket['requesterId'] === $user['id'] || ($ticket['assigneeId'] ?? null) === $user['id']) return true;
    if (is_staff($user['role']) && !empty($ticket['id']) && is_ticket_assignee($ticket, $user['id'])) return true;
    if (q_val('SELECT 1 FROM "TicketWatcher" WHERE "ticketId" = ? AND "userId" = ?', [$ticket['id'], $user['id']])) return true;
    // Agents allowed to see the unassigned queue.
    if (is_staff($user['role']) && empty($ticket['assigneeId']) && can($user, 'tickets.view_unassigned')) return true;
    // Agents see the tickets of organizations they handle.
    if (is_staff($user['role']) && q_val('SELECT 1 FROM "UserOrganization" ruo JOIN "UserOrganization" auo ON auo."organizationId" = ruo."organizationId" WHERE ruo."userId" = ? AND auo."userId" = ?', [$ticket['requesterId'], $user['id']])) return true;
    // Agents see their groups' tickets.
    if (is_staff($user['role']) && !empty($ticket['groupId']) && in_array($ticket['groupId'], user_group_ids($user['id']), true)) return true;
    // Organization managers see their organization's tickets.
    if (!is_staff($user['role'])) {
        return (bool) q_val('SELECT 1 FROM "UserOrganization" ruo JOIN "UserOrganization" muo ON muo."organizationId" = ruo."organizationId"
            WHERE ruo."userId" = ? AND muo."userId" = ? AND muo."isManager" = 1', [$ticket['requesterId'], $user['id']]);
    }
    return false;
}

function require_super_admin(): array
{
    $user = require_user();
    if ($user['role'] !== 'SUPER_ADMIN') redirect('/dashboard');
    return $user;
}

function home_for(array $user): string
{
    return $user['role'] === 'SUPER_ADMIN' && !$user['tenantId'] ? '/platform' : '/dashboard';
}
