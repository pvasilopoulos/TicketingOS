<?php
declare(strict_types=1);

/*
 * Role-based permissions.
 *
 *  - Super admins can do everything everywhere.
 *  - Administrators can do everything in their workspace, unless a super admin
 *    limited them (Roles → Administrator).
 *  - Agents get the tenant's "Agent" permission set, or the set of the custom
 *    role assigned to them (Settings → Roles & permissions).
 *  - Customers have no staff permissions; what they can see is decided by
 *    ticket ownership / team membership / organization manager.
 *
 * Managing roles themselves, security rules and administrator accounts is
 * reserved for administrators (never grantable), so a role can't be used to
 * escalate privileges.
 */

/**
 * Catalog: group => [icon, description, [key => [label, hint, flags]]].
 * flags: 'sensitive' (shown with a warning), 'requires' => other key.
 */
const PERMISSIONS = [
    'Ticket access' => ['eye', 'Which tickets someone sees and how they talk to customers.', [
        'tickets.view_all' => ['See all tickets', 'Every ticket in the workspace. Without it: tickets they requested, are assigned to, follow, or that belong to their groups.', []],
        'tickets.view_unassigned' => ['See unassigned tickets', 'The shared queue of tickets nobody has taken yet, so they can pick work up.', []],
        'tickets.reply' => ['Reply to customers', 'Send public replies. Without it they can only write internal notes.', []],
        'tickets.internal' => ['Internal notes', 'Read and write notes that customers never see.', []],
    ]],
    'Ticket details' => ['sliders-horizontal', 'What can be changed on a ticket.', [
        'tickets.status' => ['Change status', 'Move tickets through the workflow, resolve, close and reopen them.', []],
        'tickets.priority' => ['Change priority', '', []],
        'tickets.category' => ['Change category', 'May also move the ticket to the category’s group.', []],
        'tickets.fields' => ['Edit custom fields', 'Order number, device and the other ticket fields.', []],
        'tickets.assign' => ['Assign to others', 'Anyone can always take an unassigned ticket themselves.', []],
        'tickets.group' => ['Move between groups', 'Route a ticket to another team such as Billing or Technical.', []],
        'tickets.tags' => ['Tag tickets', 'Add and remove tags; new tags can be created while tagging.', []],
        'tickets.team' => ['Manage followers', 'Add viewers and collaborators to a ticket.', []],
        'macros.use' => ['Use macros', 'Apply saved replies that can also change status or assignee.', []],
    ]],
    'Bulk & data' => ['layers', 'Actions that touch many tickets or take data out.', [
        'tickets.bulk' => ['Bulk edit', 'Change many tickets at once from the list (each change still needs its own permission).', []],
        'tickets.merge' => ['Merge tickets', 'Closes the duplicate and moves its conversation into another ticket.', []],
        'tickets.link' => ['Link tickets', 'Mark tickets as related, without merging them.', []],
        'tickets.export' => ['Export to CSV', 'Download ticket data, including customer emails.', ['sensitive' => true]],
    ]],
    'Knowledge base' => ['book-open', 'Help-center articles.', [
        'kb.edit' => ['Write & edit articles', 'Create drafts and change existing articles; see drafts and article stats.', []],
        'kb.publish' => ['Publish articles', 'Make articles visible to customers, or take them down.', ['requires' => 'kb.edit']],
        'kb.delete' => ['Delete articles', '', ['requires' => 'kb.edit']],
    ]],
    'Reports' => ['chart-column', 'Performance and satisfaction data.', [
        'reports.view' => ['View reports', 'Volumes, response times, SLA, satisfaction and per-agent performance.', []],
        'reports.schedule' => ['Schedule report emails', 'Create daily, weekly or monthly report emails for the team.', ['requires' => 'reports.view']],
    ]],
    'People' => ['users', 'Users, teams and customer companies. Administrator accounts always stay with administrators.', [
        'users.invite' => ['Invite users', 'Add agents and customers to the workspace.', ['sensitive' => true]],
        'users.edit' => ['Edit users', 'Change names, emails, roles and organizations of non-admin users.', ['sensitive' => true]],
        'users.access' => ['Sign-in access', 'Deactivate or reactivate accounts and reset passwords.', ['sensitive' => true]],
        'users.remove' => ['Remove users', 'Permanently delete accounts with their tickets and replies.', ['sensitive' => true]],
        'groups.manage' => ['Manage groups', 'Create agent groups, choose members, auto-assignment and category routing.', ['sensitive' => true]],
        'orgs.manage' => ['Manage organizations', 'Customer companies, their email domains, members and managers.', ['sensitive' => true]],
    ]],
    'Workspace settings' => ['settings', 'Configuration of the workspace. Grant carefully.', [
        'settings.general' => ['Workspace name & menu', 'Rename the workspace and arrange the mobile bottom menu.', ['sensitive' => true]],
        'settings.categories' => ['Categories', 'Add and remove ticket categories.', ['sensitive' => true]],
        'settings.workflow' => ['Statuses, priorities & SLA', 'The ticket workflow and response-time targets.', ['sensitive' => true]],
        'settings.fields' => ['Ticket fields', 'Custom fields on tickets and forms.', ['sensitive' => true]],
        'settings.csat' => ['Satisfaction surveys', 'Turn surveys on or off and change the question.', ['sensitive' => true]],
        'settings.tags' => ['Tags', 'Create, rename and delete tags.', ['sensitive' => true]],
        'settings.canned' => ['Canned replies', 'The shared library of saved replies.', ['sensitive' => true]],
        'settings.macros' => ['Macros', 'Create and delete macros.', ['sensitive' => true]],
        'settings.templates' => ['Ticket templates', 'Ready-made tickets people can start from on the new-ticket form.', ['sensitive' => true]],
        'settings.automations' => ['Automations', 'Rules that change tickets automatically.', ['sensitive' => true]],
        'settings.notifications' => ['Notification policy', 'Which notifications people can receive and the defaults.', ['sensitive' => true]],
        'settings.email' => ['Email server', 'Outgoing email (SMTP / Microsoft 365) and its passwords.', ['sensitive' => true]],
        'settings.api' => ['API keys & webhooks', 'REST API keys and Slack / Teams / JSON webhooks.', ['sensitive' => true]],
        'settings.webform' => ['Support form & widget', 'The public support form and website widget.', ['sensitive' => true]],
    ]],
    'Oversight' => ['scroll-text', 'Seeing who did what.', [
        'audit.view' => ['View the audit log', 'Every admin change, sign-in and export, with IP addresses; can export it.', ['sensitive' => true]],
    ]],
];

/**
 * Abilities only the Administrator role can ever hold (a super admin can switch
 * them off per workspace). Never offered to agents or custom roles, so no role
 * can be used to escalate privileges.
 */
const ADMIN_PERMISSIONS = [
    'Administration' => ['crown', 'Only for the Administrator role — never available to agents or custom roles.', [
        'admin.roles' => ['Roles & permissions', 'Create, edit and delete roles and change the Agent role.', ['sensitive' => true]],
        'admin.security' => ['Security rules', 'Minimum password length and the letters + numbers rule.', ['sensitive' => true]],
        'admin.admins' => ['Manage administrators', 'Make people administrators and change, deactivate or remove other administrators.', ['sensitive' => true]],
    ]],
];
/** Marks an Administrator set saved after the admin-only switches existed. */
const PERMS_ADMIN_MARKER = '_ax';

/** Settings tab id => permissions that open it (any of them). */
const SETTINGS_SECTIONS = [
    'general' => ['settings.general', 'settings.categories'],
    'workflow' => ['settings.workflow'],
    'replies' => ['settings.tags', 'settings.canned', 'settings.macros', 'settings.templates'],
    'automations' => ['settings.automations'],
    'notifications' => ['settings.notifications', 'settings.email'],
    'fields' => ['settings.fields', 'settings.csat'],
    'integrations' => ['settings.api', 'settings.webform'],
];

/** Can the user open this Settings tab? */
function can_settings_tab(array $user, string $tab): bool
{
    foreach (SETTINGS_SECTIONS[$tab] ?? [] as $p) if (can($user, $p)) return true;
    return false;
}

/**
 * Permission sets saved before the detailed catalog used fewer, broader keys.
 * Sets stored from now on carry this marker; sets without it are upgraded on read
 * so every role keeps exactly what it could do before.
 */
const PERMS_MARKER = '_v3';
const PERMS_LEGACY = [
    // Old keys that no longer exist.
    'tickets.edit' => ['tickets.status', 'tickets.priority', 'tickets.category', 'tickets.fields'],
    'kb.manage' => ['kb.edit', 'kb.publish', 'kb.delete'],
    'users.manage' => ['users.invite', 'users.edit', 'users.access', 'users.remove', 'groups.manage', 'orgs.manage'],
    'settings.replies' => ['settings.tags', 'settings.canned', 'settings.macros', 'settings.templates'],
    'settings.integrations' => ['settings.api', 'settings.webform'],
    // Keys that were split: the old meaning included these too.
    'tickets.assign' => ['tickets.group'],
    'tickets.merge' => ['tickets.link'],
    'reports.view' => ['reports.schedule'],
    'settings.general' => ['settings.categories'],
    'settings.workflow' => ['settings.fields', 'settings.csat'],
    'settings.email' => ['settings.notifications'],
];

/** Default permissions of the built-in Agent role. */
const AGENT_DEFAULT_PERMISSIONS = [
    PERMS_MARKER, 'tickets.view_all', 'tickets.view_unassigned', 'tickets.reply', 'tickets.internal',
    'tickets.status', 'tickets.priority', 'tickets.category', 'tickets.fields', 'tickets.assign', 'tickets.group', 'tickets.tags', 'tickets.team',
    'macros.use', 'tickets.bulk', 'tickets.merge', 'tickets.link', 'tickets.export', 'kb.edit', 'kb.publish', 'kb.delete', 'reports.view', 'reports.schedule',
];

/** Starting points offered when creating a role. */
const ROLE_TEMPLATES = [
    'blank' => ['Blank', 'Start with nothing and add what is needed.', [PERMS_MARKER]],
    'readonly' => ['Read-only', 'Sees every ticket and leaves internal notes.', [PERMS_MARKER, 'tickets.view_all', 'tickets.internal', 'reports.view']],
    'junior' => ['Junior agent', 'Works on own and unassigned tickets, with macros.', [PERMS_MARKER, 'tickets.view_unassigned', 'tickets.reply', 'tickets.internal', 'tickets.status', 'tickets.priority', 'tickets.tags', 'macros.use']],
    'lead' => ['Team lead', 'Full ticket handling, reports, groups and user management.', [PERMS_MARKER, 'tickets.view_all', 'tickets.view_unassigned', 'tickets.reply', 'tickets.internal',
        'tickets.status', 'tickets.priority', 'tickets.category', 'tickets.fields', 'tickets.assign', 'tickets.group', 'tickets.tags', 'tickets.team', 'macros.use',
        'tickets.bulk', 'tickets.merge', 'tickets.link', 'tickets.export', 'kb.edit', 'kb.publish', 'reports.view', 'reports.schedule',
        'users.invite', 'users.edit', 'users.access', 'groups.manage', 'orgs.manage']],
    'kb' => ['Content editor', 'Maintains the help center and reply library.', [PERMS_MARKER, 'tickets.view_all', 'tickets.internal', 'kb.edit', 'kb.publish', 'kb.delete', 'settings.tags', 'settings.canned', 'settings.macros']],
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

/** Keys of the Administrator-only abilities. */
function admin_only_permissions(): array
{
    $keys = [];
    foreach (ADMIN_PERMISSIONS as [, , $perms]) $keys = array_merge($keys, array_keys($perms));
    return $keys;
}

/** Everything an unrestricted administrator (or super admin) holds. */
function admin_all_permissions(): array
{
    return array_merge(all_permissions(), admin_only_permissions());
}

/**
 * Keep known keys only; upgrade sets saved by older versions (no marker);
 * drop perms whose requirement is missing. Admin-only keys survive only for
 * the Administrator role ($forAdmin).
 */
function clean_permissions(array $perms, bool $forAdmin = false): array
{
    $adminKeys = $forAdmin ? array_values(array_intersect(admin_only_permissions(), $perms)) : [];
    $perms = clean_catalog_permissions($perms);
    return array_merge($perms, $adminKeys);
}

function clean_catalog_permissions(array $perms): array
{
    if (!in_array(PERMS_MARKER, $perms, true)) {
        if (in_array('settings.manage', $perms, true)) $perms = array_merge($perms, ['settings.general', 'settings.workflow', 'settings.replies', 'settings.automations', 'settings.email', 'settings.integrations']);
        foreach (PERMS_LEGACY as $old => $new) if (in_array($old, $perms, true)) $perms = array_merge($perms, $new);
        // Before, every agent could see unassigned tickets only with "see all"; keep that.
        if (in_array('tickets.view_all', $perms, true)) $perms[] = 'tickets.view_unassigned';
    }
    $perms = array_values(array_intersect(all_permissions(), $perms));
    $info = permission_info();
    return array_values(array_filter($perms, fn ($p) => empty($info[$p][2]['requires']) || in_array($info[$p][2]['requires'], $perms, true)));
}

/** A permission set ready to be saved (cleaned and marked as current). */
function perms_for_storage(array $perms, bool $forAdmin = false): array
{
    return [PERMS_MARKER, ...($forAdmin ? [PERMS_ADMIN_MARKER] : []), ...clean_permissions([PERMS_MARKER, ...$perms], $forAdmin)];
}

/** Permission set of the built-in Agent role for a tenant (editable in settings). */
function agent_permissions(string $tenantId): array
{
    $saved = tenant_setting($tenantId, 'agentPermissions');
    return clean_permissions(is_array($saved) ? $saved : AGENT_DEFAULT_PERMISSIONS);
}

/** What administrators of a workspace may do (all, unless a super admin limited it). */
function admin_permissions(string $tenantId): array
{
    $saved = $tenantId !== '' ? tenant_setting($tenantId, 'adminPermissions') : null;
    if (!is_array($saved)) return admin_all_permissions();
    // Sets saved before the admin-only switches existed keep those abilities.
    if (!in_array(PERMS_ADMIN_MARKER, $saved, true)) $saved = array_merge($saved, admin_only_permissions());
    return clean_permissions($saved, true);
}

/** Effective permissions of a user inside a tenant. */
function user_permissions(array $user, ?string $tenantId = null): array
{
    static $cache = [];
    $role = $user['role'];
    if ($role === 'SUPER_ADMIN') return admin_all_permissions();
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

/** Holds at least one of the permissions. */
function can_any(array $user, string ...$perms): bool
{
    foreach ($perms as $p) if (can($user, $p)) return true;
    return false;
}

/** Any of the people-management permissions (Users tab, team page). */
const PEOPLE_PERMS = ['users.invite', 'users.edit', 'users.access', 'users.remove'];

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
