<?php
declare(strict_types=1);

/*
 * Role-based permissions.
 *
 *  - Administrators (and the platform operator inside a tenant) can do everything.
 *  - Agents get the tenant's "Agent" permission set, or the set of the custom
 *    role assigned to them (Settings → Roles & permissions).
 *  - Customers have no staff permissions; what they can see is decided by
 *    ticket ownership / team membership as before.
 *
 * Managing roles themselves, creating administrators and changing
 * administrators is reserved for administrators (never grantable), so a role
 * can't be used to escalate privileges.
 */

/**
 * Catalog: group => [icon, description, [key => [label, hint, flags]]].
 * flags: 'sensitive' (shown with a warning), 'requires' => other key.
 */
const PERMISSIONS = [
    'Tickets' => ['ticket', 'What agents can see and do on tickets.', [
        'tickets.view_all' => ['See all tickets', 'Without it, agents only see tickets assigned to them, requested by them or that they follow.', []],
        'tickets.reply' => ['Reply to customers', 'Send public replies. Without it, agents can only write internal notes.', []],
        'tickets.internal' => ['Internal notes', 'Read and write notes that customers never see.', []],
        'tickets.edit' => ['Change status, priority & category', '', []],
        'tickets.assign' => ['Assign tickets to others', 'Agents can always take an unassigned ticket themselves.', []],
        'tickets.tags' => ['Tag tickets', 'Add and remove tags; can create new tags while tagging.', []],
        'tickets.team' => ['Manage ticket followers', 'Add viewers and collaborators to a ticket.', []],
        'macros.use' => ['Use macros', 'Apply saved replies that can also change status or assignee.', []],
    ]],
    'Bulk & data' => ['layers', 'Actions that touch many tickets at once.', [
        'tickets.bulk' => ['Bulk edit', 'Change many tickets at once from the list.', ['requires' => 'tickets.edit']],
        'tickets.merge' => ['Merge & link tickets', 'Merging closes the duplicate and moves its conversation.', []],
        'tickets.export' => ['Export to CSV', 'Download ticket data, including customer emails.', ['sensitive' => true]],
    ]],
    'Knowledge & insights' => ['book-open', 'Help center and reporting.', [
        'kb.manage' => ['Write & publish articles', 'Create, edit, publish and delete knowledge-base articles.', []],
        'reports.view' => ['View reports', 'Volumes, response times, SLA and per-agent performance.', []],
    ]],
    'Administration' => ['settings', 'Workspace configuration. Grant carefully.', [
        'users.manage' => ['Manage people', 'Invite, edit, deactivate and remove non-admin users; groups and customer organizations.', ['sensitive' => true]],
        'settings.general' => ['General settings', 'Workspace name, categories and the mobile bottom menu.', ['sensitive' => true]],
        'settings.workflow' => ['Workflow settings', 'Statuses, priorities, SLA targets, ticket fields and satisfaction surveys.', ['sensitive' => true]],
        'settings.replies' => ['Tags, canned replies & macros', 'Create and edit the shared reply library.', ['sensitive' => true]],
        'settings.automations' => ['Automations', 'Rules that change tickets automatically.', ['sensitive' => true]],
        'settings.email' => ['Notifications & email', 'Notification policy and outgoing email (SMTP / Microsoft 365).', ['sensitive' => true]],
        'settings.integrations' => ['Integrations', 'API keys, webhooks (Slack / Teams) and the public support form.', ['sensitive' => true]],
    ]],
];

/** Settings tab id => permission needed to see and change it. */
const SETTINGS_SECTIONS = [
    'general' => 'settings.general',
    'workflow' => 'settings.workflow',
    'replies' => 'settings.replies',
    'automations' => 'settings.automations',
    'notifications' => 'settings.email',
    'fields' => 'settings.workflow',
    'integrations' => 'settings.integrations',
];

/** Default permissions of the built-in Agent role (what agents could do before roles existed). */
const AGENT_DEFAULT_PERMISSIONS = [
    'tickets.view_all', 'tickets.reply', 'tickets.internal', 'tickets.edit', 'tickets.assign', 'tickets.tags', 'tickets.team',
    'macros.use', 'tickets.bulk', 'tickets.merge', 'tickets.export', 'kb.manage', 'reports.view',
];

/** Starting points offered when creating a role. */
const ROLE_TEMPLATES = [
    'blank' => ['Blank', 'Start with nothing and add what is needed.', []],
    'readonly' => ['Read-only', 'Sees every ticket and leaves internal notes.', ['tickets.view_all', 'tickets.internal', 'reports.view']],
    'junior' => ['Junior agent', 'Works on own tickets only, with macros.', ['tickets.reply', 'tickets.internal', 'tickets.edit', 'tickets.tags', 'macros.use']],
    'lead' => ['Team lead', 'Full ticket handling, reports and user management.', ['tickets.view_all', 'tickets.reply', 'tickets.internal', 'tickets.edit', 'tickets.assign', 'tickets.tags', 'tickets.team', 'macros.use', 'tickets.bulk', 'tickets.merge', 'tickets.export', 'kb.manage', 'reports.view', 'users.manage']],
    'kb' => ['Content editor', 'Maintains the help center and reply library.', ['tickets.view_all', 'tickets.internal', 'kb.manage', 'settings.replies']],
];

/** Flat map key => [label, hint, flags, group]. */
function permission_info(): array
{
    static $map = null;
    if ($map === null) {
        $map = [];
        foreach (PERMISSIONS as $group => [, , $perms]) {
            foreach ($perms as $k => [$label, $hint, $flags]) $map[$k] = [$label, $hint, $flags, $group];
        }
    }
    return $map;
}

function all_permissions(): array
{
    return array_keys(permission_info());
}

/** Keep known keys only; expand the old catch-all "settings.manage"; drop perms whose requirement is missing. */
function clean_permissions(array $perms): array
{
    if (in_array('settings.manage', $perms, true)) $perms = array_merge($perms, array_values(SETTINGS_SECTIONS));
    $perms = array_values(array_intersect(all_permissions(), $perms));
    $info = permission_info();
    return array_values(array_filter($perms, fn ($p) => empty($info[$p][2]['requires']) || in_array($info[$p][2]['requires'], $perms, true)));
}

/** Permission set of the built-in Agent role for a tenant (editable in settings). */
function agent_permissions(string $tenantId): array
{
    $saved = tenant_setting($tenantId, 'agentPermissions');
    return is_array($saved) ? clean_permissions($saved) : AGENT_DEFAULT_PERMISSIONS;
}

/** What administrators of a workspace may do (all, unless a super admin limited it). */
function admin_permissions(string $tenantId): array
{
    $saved = $tenantId !== '' ? tenant_setting($tenantId, 'adminPermissions') : null;
    return is_array($saved) ? clean_permissions($saved) : all_permissions();
}

/** Effective permissions of a user inside a tenant. */
function user_permissions(array $user, ?string $tenantId = null): array
{
    static $cache = [];
    $role = $user['role'];
    if ($role === 'SUPER_ADMIN') return all_permissions();
    // Administrators get everything unless a super admin limited them for this workspace.
    if ($role === 'ADMIN') return admin_permissions((string) ($tenantId ?? $user['tenantId']));
    if ($role !== 'AGENT') return [];
    $tenantId ??= $user['tenantId'];
    $ck = $user['id'] . ':' . ($user['customRoleId'] ?? '') . ':' . $tenantId;
    if (isset($cache[$ck])) return $cache[$ck];
    $perms = null;
    if (!empty($user['customRoleId'])) {
        $raw = q_val('SELECT "permissions" FROM "Role" WHERE "id" = ? AND "tenantId" = ?', [$user['customRoleId'], $tenantId]);
        if ($raw !== null) $perms = clean_permissions(json_decode((string) $raw, true) ?: []);
    }
    return $cache[$ck] = $perms ?? agent_permissions((string) $tenantId);
}

/** Does the user hold a permission? "settings.*" means any permission with that prefix. */
function can(array $user, string $perm): bool
{
    $have = user_permissions($user, effective_tenant_id($user) ?? $user['tenantId']);
    if (str_ends_with($perm, '.*')) {
        $prefix = substr($perm, 0, -1);
        foreach ($have as $p) if (str_starts_with($p, $prefix)) return true;
        return false;
    }
    return in_array($perm, $have, true);
}

/** Require a tenant user holding any of the permissions; otherwise send them to the dashboard. */
function require_perm(string ...$anyOf): array
{
    $ctx = require_tenant_user();
    foreach ($anyOf as $p) if (can($ctx[0], $p)) return $ctx;
    redirect('/dashboard');
}

/** Name of the role a user acts as (custom role name for agents that have one). */
function user_role_label(array $user, array $rolesById = []): string
{
    if ($user['role'] === 'AGENT' && !empty($user['customRoleId']) && isset($rolesById[$user['customRoleId']])) {
        return $rolesById[$user['customRoleId']]['name'];
    }
    return role_label($user['role']);
}

/** Tenant password policy. */
function password_min_length(?string $tenantId): int
{
    return $tenantId ? max(6, min(64, (int) tenant_setting($tenantId, 'passwordMinLength', 6))) : 6;
}

function password_problem(string $password, ?string $tenantId): ?string
{
    $min = password_min_length($tenantId);
    if (mb_strlen($password) < $min) return "Password must be at least $min characters";
    if ($tenantId && tenant_setting($tenantId, 'passwordRequireMix', false)
        && !(preg_match('/\p{L}/u', $password) && preg_match('/\d/', $password))) {
        return 'Password must contain both letters and numbers';
    }
    return null;
}
