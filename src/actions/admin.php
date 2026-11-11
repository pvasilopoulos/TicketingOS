<?php
declare(strict_types=1);

/* Tenant-admin actions: workspace, categories, statuses, priorities, SLA,
   tags, canned responses, automations, notification policy, email, team. */

/* ---------- Workspace & categories ---------- */

function act_workspace_rename(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.general');
    $name = str_in($in, 'name');
    if (mb_strlen($name) < 2) return ['error' => 'Name is too short'];
    db_update('Tenant', ['name' => $name, 'updatedAt' => now_iso()], '"id" = ?', [$tenantId]);
    return ['success' => 'Workspace name updated'];
}

function act_category_create(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.categories');
    $name = str_in($in, 'name');
    $color = safe_color(str_in($in, 'color'), '#64748b');
    if ($name === '') return ['error' => 'Category name is required'];
    if (q_val('SELECT 1 FROM "Category" WHERE "tenantId" = ? AND "name" = ?', [$tenantId, $name])) {
        return ['error' => 'A category with that name already exists'];
    }
    db_insert('Category', ['id' => cuid(), 'name' => $name, 'color' => $color, 'tenantId' => $tenantId]);
    return ['success' => "Added \"$name\""];
}

function act_category_delete(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.categories');
    q('DELETE FROM "Category" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['ok' => true];
}

/* ---------- Mobile bottom menu ---------- */

function act_nav_save(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.general');
    $cfg = [];
    foreach (['staff', 'customer'] as $menu) {
        if (($in['reset'] ?? '') === '1') { $cfg[$menu] = NAV_DEFAULTS[$menu]; continue; }
        $allowed = nav_allowed($menu);
        $items = [];
        for ($i = 1; $i <= NAV_MAX_ITEMS; $i++) {
            $k = str_in($in, "{$menu}_slot$i");
            if ($k !== '' && in_array($k, $allowed, true) && !in_array($k, $items, true)) $items[] = $k;
        }
        if (!$items) return ['error' => ($menu === 'staff' ? 'Agents' : 'Customers') . ' menu needs at least one item'];
        $cfg[$menu] = ['items' => $items, 'center' => ($in["{$menu}_center"] ?? '') === 'on'];
    }
    tenant_setting_set($tenantId, 'bottomNav', $cfg);
    return ['success' => ($in['reset'] ?? '') === '1' ? 'Bottom menu reset to the defaults' : 'Bottom menu saved — open the app on a phone to see it'];
}

/* ---------- Statuses & priorities (shared ordering logic) ---------- */

function unique_key(string $table, string $tenantId, string $base): string
{
    $key = $base;
    $n = 1;
    while (q_val('SELECT 1 FROM ' . qi($table) . ' WHERE "tenantId" = ? AND "key" = ?', [$tenantId, $key])) $key = $base . '_' . $n++;
    return $key;
}

function swap_order(string $table, string $tenantId, string $id, string $dir): void
{
    if ($dir !== 'up' && $dir !== 'down') return;
    $list = q_all('SELECT "id","order" FROM ' . qi($table) . ' WHERE "tenantId" = ? ORDER BY "order" ASC', [$tenantId]);
    $idx = array_search($id, array_column($list, 'id'), true);
    if ($idx === false) return;
    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($swap < 0 || $swap >= count($list)) return;
    [$a, $b] = [$list[$idx], $list[$swap]];
    // Orders may collide after deletions; fall back to positional indexes.
    $oa = (int) $a['order'] === (int) $b['order'] ? $swap : (int) $b['order'];
    $ob = (int) $a['order'] === (int) $b['order'] ? $idx : (int) $a['order'];
    db_tx(function () use ($table, $a, $b, $oa, $ob) {
        db_update($table, ['order' => $oa], '"id" = ?', [$a['id']]);
        db_update($table, ['order' => $ob], '"id" = ?', [$b['id']]);
    });
}

function act_status_create(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    $label = str_in($in, 'label');
    $color = safe_color(str_in($in, 'color'), '#64748b');
    if (mb_strlen($label) < 2) return ['error' => 'Status name is too short'];
    $key = unique_key('TicketStatus', $tenantId, key_from_label($label, 'STATUS'));
    $count = (int) q_val('SELECT COALESCE(MAX("order"), -1) + 1 FROM "TicketStatus" WHERE "tenantId" = ?', [$tenantId]);
    db_insert('TicketStatus', [
        'id' => cuid(), 'tenantId' => $tenantId, 'key' => $key, 'label' => $label, 'color' => $color,
        'isClosed' => ($in['isClosed'] ?? '') === 'on' ? 1 : 0, 'order' => $count, 'createdAt' => now_iso(),
    ]);
    return ['success' => "Added \"$label\""];
}

function act_status_update(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    $label = str_in($in, 'label');
    if (mb_strlen($label) < 2) return ['error' => 'Status name is too short'];
    db_update('TicketStatus', [
        'label' => $label, 'color' => safe_color(str_in($in, 'color'), '#64748b'),
        'isClosed' => ($in['isClosed'] ?? '') === 'on' ? 1 : 0,
    ], '"id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['ok' => true];
}

function act_status_delete(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    $s = q_one('SELECT * FROM "TicketStatus" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$s) return null;
    // Keep at least one status, and don't orphan tickets.
    if ((int) q_val('SELECT COUNT(*) FROM "TicketStatus" WHERE "tenantId" = ?', [$tenantId]) <= 1) return ['error' => 'Keep at least one status'];
    if ((int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "status" = ?', [$tenantId, $s['key']]) > 0) return ['error' => 'Status is in use'];
    q('DELETE FROM "TicketStatus" WHERE "id" = ?', [$s['id']]);
    return ['ok' => true];
}

function act_status_move(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    swap_order('TicketStatus', $tenantId, str_in($in, 'id'), str_in($in, 'dir'));
    return ['ok' => true];
}

function act_priority_create(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    $label = str_in($in, 'label');
    $color = safe_color(str_in($in, 'color'), '#64748b');
    if (mb_strlen($label) < 2) return ['error' => 'Priority name is too short'];
    $key = unique_key('TicketPriority', $tenantId, key_from_label($label, 'PRIORITY'));
    $count = (int) q_val('SELECT COALESCE(MAX("order"), -1) + 1 FROM "TicketPriority" WHERE "tenantId" = ?', [$tenantId]);
    db_insert('TicketPriority', ['id' => cuid(), 'tenantId' => $tenantId, 'key' => $key, 'label' => $label, 'color' => $color, 'order' => $count]);
    return ['success' => "Added \"$label\""];
}

function act_priority_update(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    $label = str_in($in, 'label');
    if (mb_strlen($label) < 2) return ['error' => 'Priority name is too short'];
    db_update('TicketPriority', ['label' => $label, 'color' => safe_color(str_in($in, 'color'), '#64748b')], '"id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['ok' => true];
}

function act_priority_delete(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    $p = q_one('SELECT * FROM "TicketPriority" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    if (!$p) return null;
    if ((int) q_val('SELECT COUNT(*) FROM "TicketPriority" WHERE "tenantId" = ?', [$tenantId]) <= 1) return ['error' => 'Keep at least one priority'];
    if ((int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "priority" = ?', [$tenantId, $p['key']]) > 0) return ['error' => 'Priority is in use'];
    q('DELETE FROM "TicketPriority" WHERE "id" = ?', [$p['id']]);
    q('DELETE FROM "SlaTarget" WHERE "tenantId" = ? AND "priority" = ?', [$tenantId, $p['key']]);
    return ['ok' => true];
}

function act_priority_move(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    swap_order('TicketPriority', $tenantId, str_in($in, 'id'), str_in($in, 'dir'));
    return ['ok' => true];
}

/* ---------- SLA ---------- */

function act_sla_save(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.workflow');
    foreach (tenant_priorities($tenantId) as $p) {
        $raw = $in['sla_' . $p['key']] ?? '';
        $hours = is_numeric($raw) && (float) $raw > 0 ? (int) round((float) $raw) : 0;
        if ($hours <= 0) continue;
        $exists = q_val('SELECT "id" FROM "SlaTarget" WHERE "tenantId" = ? AND "priority" = ?', [$tenantId, $p['key']]);
        if ($exists) db_update('SlaTarget', ['resolveHours' => $hours], '"id" = ?', [$exists]);
        else db_insert('SlaTarget', ['id' => cuid(), 'tenantId' => $tenantId, 'priority' => $p['key'], 'resolveHours' => $hours]);
    }
    // Recompute deadlines for open tickets so changes take effect immediately.
    $map = [];
    foreach (q_all('SELECT "priority","resolveHours" FROM "SlaTarget" WHERE "tenantId" = ?', [$tenantId]) as $t) $map[$t['priority']] = (int) $t['resolveHours'];
    db_tx(function () use ($tenantId, $map) {
        foreach (q_all('SELECT "id","createdAt","priority" FROM "Ticket" WHERE "tenantId" = ? AND "closedAt" IS NULL', [$tenantId]) as $t) {
            if (empty($map[$t['priority']])) continue;
            db_update('Ticket', ['dueAt' => iso_from_ts(ts($t['createdAt']) + $map[$t['priority']] * 3600)], '"id" = ?', [$t['id']]);
        }
    });
    return ['success' => 'SLA targets saved'];
}

/* ---------- Tags ---------- */

function clean_tag(string $name): string
{
    return mb_substr(trim(preg_replace('/\s+/u', ' ', $name)), 0, 40);
}

function act_tag_create(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.tags');
    $name = clean_tag((string) ($in['name'] ?? ''));
    if ($name === '') return ['error' => 'Tag name is required'];
    if (q_val('SELECT 1 FROM "Tag" WHERE "tenantId" = ? AND "name" = ?', [$tenantId, $name])) return ['error' => 'That tag already exists'];
    db_insert('Tag', ['id' => cuid(), 'name' => $name, 'tenantId' => $tenantId]);
    return ['success' => "Added \"$name\""];
}

function act_tag_rename(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.tags');
    $id = str_in($in, 'id');
    $name = clean_tag((string) ($in['name'] ?? ''));
    if (!$id || $name === '') return null;
    if (q_val('SELECT 1 FROM "Tag" WHERE "tenantId" = ? AND "name" = ? AND "id" <> ?', [$tenantId, $name, $id])) return ['error' => 'That tag already exists'];
    db_update('Tag', ['name' => $name], '"id" = ? AND "tenantId" = ?', [$id, $tenantId]);
    foreach (pluck(q_all('SELECT "ticketId" FROM "TicketTag" WHERE "tagId" = ?', [$id]), 'ticketId') as $tid) reindex_ticket($tid);
    return ['ok' => true];
}

function act_tag_delete(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.tags');
    q('DELETE FROM "Tag" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['ok' => true];
}

/* ---------- Canned responses ---------- */

function act_canned_create(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.canned');
    $title = mb_substr(str_in($in, 'title'), 0, 80);
    $body = sanitize_rich_html((string) ($in['body'] ?? ''));
    if (mb_strlen($title) < 2) return ['error' => 'Title is too short'];
    if (mb_strlen(html_to_text($body)) < 2) return ['error' => 'Body is required'];
    $now = now_iso();
    db_insert('CannedResponse', ['id' => cuid(), 'tenantId' => $tenantId, 'title' => $title, 'body' => $body, 'createdAt' => $now, 'updatedAt' => $now]);
    return ['success' => "Saved \"$title\""];
}

function act_canned_update(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.canned');
    $id = str_in($in, 'id');
    $title = mb_substr(str_in($in, 'title'), 0, 80);
    $body = sanitize_rich_html((string) ($in['body'] ?? ''));
    if (!$id || mb_strlen($title) < 2) return ['error' => 'Title is too short'];
    if (mb_strlen(html_to_text($body)) < 2) return ['error' => 'Body is required'];
    db_update('CannedResponse', ['title' => $title, 'body' => $body, 'updatedAt' => now_iso()], '"id" = ? AND "tenantId" = ?', [$id, $tenantId]);
    return ['success' => 'Updated'];
}

function act_canned_delete(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.canned');
    q('DELETE FROM "CannedResponse" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['ok' => true];
}

/* ---------- Automations ---------- */

function act_rule_create(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.automations');
    $name = mb_substr(str_in($in, 'name'), 0, 60);
    $condField = str_in($in, 'conditionField');
    $condValue = str_in($in, 'conditionValue');
    $actionType = str_in($in, 'actionType');
    $actionValue = str_in($in, 'actionValue');

    if (mb_strlen($name) < 2) return ['error' => 'Rule name is too short'];
    if (!in_array($actionType, ['ASSIGN', 'SET_PRIORITY', 'SET_STATUS', 'ADD_TAG'], true)) return ['error' => 'Choose an action'];
    if ($actionValue === '') return ['error' => 'Choose an action target'];
    if (!in_array($condField, ['', 'priority', 'category'], true)) return ['error' => 'Invalid condition'];
    if ($condField && $condValue === '') return ['error' => 'Choose a condition value'];

    $count = (int) q_val('SELECT COUNT(*) FROM "AutomationRule" WHERE "tenantId" = ?', [$tenantId]);
    db_insert('AutomationRule', [
        'id' => cuid(), 'tenantId' => $tenantId, 'name' => $name, 'enabled' => 1, 'trigger' => 'TICKET_CREATED',
        'conditionField' => $condField ?: null, 'conditionValue' => $condField ? $condValue : null,
        'actionType' => $actionType, 'actionValue' => $actionValue, 'order' => $count, 'createdAt' => now_iso(),
    ]);
    return ['success' => "Added rule \"$name\""];
}

function act_rule_toggle(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.automations');
    db_update('AutomationRule', ['enabled' => ($in['enabled'] ?? '') === 'true' ? 1 : 0], '"id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['ok' => true];
}

function act_rule_delete(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.automations');
    q('DELETE FROM "AutomationRule" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['ok' => true];
}

/* ---------- Notification policy ---------- */

function act_policy_save(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.notifications');
    $defaults = [];
    foreach (NOTIFY_CATEGORIES as $c) {
        foreach (CHANNELS as $ch) $defaults[$c][$ch] = ($in["def_{$c}_{$ch}"] ?? '') === 'on';
    }
    $data = [
        'allowInApp' => ($in['allowInApp'] ?? '') === 'on' ? 1 : 0,
        'allowPush' => ($in['allowPush'] ?? '') === 'on' ? 1 : 0,
        'allowEmail' => ($in['allowEmail'] ?? '') === 'on' ? 1 : 0,
        'defaults' => json_encode($defaults),
        'updatedAt' => now_iso(),
    ];
    if (q_val('SELECT 1 FROM "TenantNotificationPolicy" WHERE "tenantId" = ?', [$tenantId])) {
        db_update('TenantNotificationPolicy', $data, '"tenantId" = ?', [$tenantId]);
    } else {
        db_insert('TenantNotificationPolicy', ['id' => cuid(), 'tenantId' => $tenantId] + $data);
    }
    return ['success' => 'Notification policy saved'];
}

/* ---------- Email settings ---------- */

function act_email_save(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.email');
    $enabled = ($in['enabled'] ?? '') === 'on';
    $provider = str_in($in, 'provider') ?: 'SMTP';
    $fromName = mb_substr(str_in($in, 'fromName'), 0, 80) ?: null;
    $fromEmail = str_in($in, 'fromEmail');
    if (!in_array($provider, ['SMTP', 'MICROSOFT365'], true)) return ['error' => 'Invalid provider'];
    if (!is_valid_email($fromEmail)) return ['error' => 'A valid From email is required'];

    $existing = email_settings($tenantId);
    $smtpPw = str_in($in, 'smtpPassword');
    $msSecret = str_in($in, 'msClientSecret');

    $data = [
        'enabled' => $enabled ? 1 : 0,
        'provider' => $provider,
        'fromName' => $fromName,
        'fromEmail' => $fromEmail,
        'smtpHost' => str_in($in, 'smtpHost') ?: null,
        'smtpPort' => (int) str_in($in, 'smtpPort') ?: null,
        'smtpSecure' => ($in['smtpSecure'] ?? '') === 'on' ? 1 : 0,
        'smtpUser' => str_in($in, 'smtpUser') ?: null,
        // Secret fields: keep the stored (encrypted) value when left blank.
        'smtpPassword' => $smtpPw !== '' ? encrypt_secret($smtpPw) : ($existing['smtpPassword'] ?? null),
        'msTenantId' => str_in($in, 'msTenantId') ?: null,
        'msClientId' => str_in($in, 'msClientId') ?: null,
        'msClientSecret' => $msSecret !== '' ? encrypt_secret($msSecret) : ($existing['msClientSecret'] ?? null),
        'msSender' => str_in($in, 'msSender') ?: null,
        'updatedAt' => now_iso(),
    ];

    if ($enabled && $provider === 'SMTP' && (!$data['smtpHost'] || !$data['smtpPort'])) {
        return ['error' => 'SMTP host and port are required to enable email'];
    }
    if ($enabled && $provider === 'MICROSOFT365' && (!$data['msTenantId'] || !$data['msClientId'] || !$data['msSender'] || !$data['msClientSecret'])) {
        return ['error' => 'Microsoft 365 tenant, client ID, secret and sender are required'];
    }

    if ($existing) db_update('EmailSetting', $data, '"tenantId" = ?', [$tenantId]);
    else db_insert('EmailSetting', ['id' => cuid(), 'tenantId' => $tenantId] + $data);
    return ['success' => 'Email settings saved'];
}

function act_email_test(array $in): ?array
{
    [$user, $tenantId] = require_tenant_admin('settings.email');
    $s = email_settings($tenantId);
    if (!$s) return ['error' => 'Save your settings first'];
    if (!$s['enabled']) return ['error' => 'Enable email notifications first'];
    $r = send_tenant_email($tenantId, [
        'to' => $user['email'],
        'subject' => app_name() . ' — test email',
        'html' => '<p>Hi ' . e($user['name']) . ',</p><p>This is a test email from <strong>' . e(app_name()) . '</strong>. If you received it, your '
            . ($s['provider'] === 'MICROSOFT365' ? 'Microsoft 365' : 'SMTP') . ' email notifications are working. ✅</p>',
    ]);
    return $r['ok'] ? ['success' => "Test email sent to {$user['email']}"] : ['error' => $r['error'] ?? 'Could not send test email'];
}

/* ---------- Users (Settings → Users & roles) ---------- */

/**
 * Load a user of the tenant that the current manager may change.
 * Only administrators may touch administrator accounts.
 */
function managed_user(array $manager, string $tenantId, string $userId): array|string
{
    $target = q_one('SELECT * FROM "User" WHERE "id" = ? AND "tenantId" = ?', [$userId, $tenantId]);
    if (!$target) return 'User not found';
    if ($target['role'] === 'SUPER_ADMIN' && $manager['role'] !== 'SUPER_ADMIN') return 'Only a super admin can change a super admin account';
    if ($target['role'] === 'ADMIN' && !can_manage_admins($manager)) return 'You can’t change administrator accounts';
    return $target;
}

/** Organization id from a form (validated), or null. */
function pick_org(string $tenantId, array $in): ?string
{
    $id = str_in($in, 'organizationId');
    return $id !== '' && q_val('SELECT 1 FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]) ? $id : null;
}

function active_admin_count(string $tenantId, ?string $exceptId = null): int
{
    return (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\') AND "active" = 1' . ($exceptId ? ' AND "id" <> ?' : ''), $exceptId ? [$tenantId, $exceptId] : [$tenantId]);
}

/** Active super admins on the whole platform (optionally not counting one). */
function super_admin_count(?string $exceptId = null): int
{
    return (int) q_val('SELECT COUNT(*) FROM "User" WHERE "role" = \'SUPER_ADMIN\' AND "active" = 1' . ($exceptId ? ' AND "id" <> ?' : ''), $exceptId ? [$exceptId] : []);
}

/** Validate role + custom role from a form for the current manager. */
function pick_role(array $manager, string $tenantId, array $in): array|string
{
    $role = str_in($in, 'role');
    if ($role === 'SUPER_ADMIN') {
        if ($manager['role'] !== 'SUPER_ADMIN') return 'Only a super admin can grant the super admin role';
    } elseif (!in_array($role, TENANT_ROLES, true)) {
        return 'Invalid role';
    }
    if ($role === 'ADMIN' && !can_manage_admins($manager)) return 'You can’t make people administrators';
    $custom = $role === 'AGENT' ? str_in($in, 'customRoleId') : '';
    if ($custom !== '' && !q_val('SELECT 1 FROM "Role" WHERE "id" = ? AND "tenantId" = ?', [$custom, $tenantId])) return 'Invalid role';
    return ['role' => $role, 'customRoleId' => $custom ?: null];
}

function act_member_create(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'users.invite')) return ['error' => 'You don’t have permission to add users'];
    $name = str_in($in, 'name');
    $email = strtolower(str_in($in, 'email'));
    $invite = ($in['invite'] ?? '') === 'on';
    $password = (string) ($in['password'] ?? '');
    if (mb_strlen($name) < 2) return ['error' => 'Name must be at least 2 characters'];
    if (!is_valid_email($email)) return ['error' => 'Invalid email address'];
    $roles = pick_role($user, $tenantId, $in);
    if (is_string($roles)) return ['error' => $roles];
    if (!$invite && ($problem = password_problem($password, $tenantId))) return ['error' => $problem];
    if (q_val('SELECT 1 FROM "User" WHERE "email" = ?', [$email])) return ['error' => 'Email already in use'];

    $count = (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" = ?', [$tenantId]);
    $now = now_iso();
    $new = [
        'id' => cuid(), 'email' => $email, 'name' => $name,
        // Invited users get an unusable random password until they pick their own.
        'passwordHash' => hash_password($invite ? bin2hex(random_bytes(24)) : $password),
        'role' => $roles['role'], 'customRoleId' => $roles['customRoleId'], 'active' => 1,
        // Customers join the organization picked on the form, or the one matching their email domain.
        'organizationId' => $roles['role'] === 'CUSTOMER' ? (pick_org($tenantId, $in) ?? (org_for_email($tenantId, $email)['id'] ?? null)) : null,
        'orgManager' => $roles['role'] === 'CUSTOMER' && ($in['orgManager'] ?? '') === '1' ? 1 : 0,
        'avatarColor' => AVATAR_COLORS[$count % count(AVATAR_COLORS)], 'createdAt' => $now, 'updatedAt' => $now, 'tenantId' => $tenantId,
    ];
    db_insert('User', $new);
    if ($invite) {
        $r = send_reset_link($new, 72 * 3600, true);
        return ['success' => $r['emailed'] ? "Invitation sent to $email" : "$name added. Email is not set up, so share this link to let them choose a password (valid 72h): {$r['link']}"];
    }
    return ['success' => "$name added to the workspace"];
}

/** Edit name, email, role and custom role. */
function act_member_update(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'users.edit')) return ['error' => 'Not allowed'];
    $target = managed_user($user, $tenantId, str_in($in, 'userId'));
    if (is_string($target)) return ['error' => $target];

    $data = ['updatedAt' => now_iso()];
    if (array_key_exists('name', $in)) {
        $name = str_in($in, 'name');
        if (mb_strlen($name) < 2) return ['error' => 'Name must be at least 2 characters'];
        $data['name'] = $name;
    }
    if (array_key_exists('email', $in)) {
        $email = strtolower(str_in($in, 'email'));
        if (!is_valid_email($email)) return ['error' => 'Invalid email address'];
        if (q_val('SELECT 1 FROM "User" WHERE "email" = ? AND "id" <> ?', [$email, $target['id']])) return ['error' => 'Email already in use'];
        $data['email'] = $email;
    }
    if (array_key_exists('role', $in)) {
        $roles = pick_role($user, $tenantId, $in);
        if (is_string($roles)) return ['error' => $roles];
        if (is_tenant_admin($target['role']) && !is_tenant_admin($roles['role']) && active_admin_count($tenantId, $target['id']) < 1) {
            return ['error' => 'A workspace needs at least one active administrator'];
        }
        if ($target['role'] === 'SUPER_ADMIN' && $roles['role'] !== 'SUPER_ADMIN' && super_admin_count($target['id']) < 1) {
            return ['error' => 'The platform needs at least one active super admin'];
        }
        $data += $roles;
    }
    $finalRole = $data['role'] ?? $target['role'];
    if ($finalRole !== 'CUSTOMER') {
        $data['organizationId'] = null;
        $data['orgManager'] = 0;
    } elseif (array_key_exists('organizationId', $in)) {
        $data['organizationId'] = pick_org($tenantId, $in);
        $data['orgManager'] = $data['organizationId'] && ($in['orgManager'] ?? '') === '1' ? 1 : 0;
    }
    db_update('User', $data, '"id" = ?', [$target['id']]);
    return ['success' => 'Saved ' . ($data['name'] ?? $target['name'])];
}

/** Kept for the inline role dropdown. */
function act_member_role(array $in): ?array
{
    return act_member_update(['userId' => $in['userId'] ?? '', 'role' => $in['role'] ?? '', 'customRoleId' => $in['customRoleId'] ?? '']);
}

function act_member_status(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'users.access')) return ['error' => 'Not allowed'];
    $target = managed_user($user, $tenantId, str_in($in, 'userId'));
    if (is_string($target)) return ['error' => $target];
    $active = ($in['active'] ?? '') === '1';
    if (!$active && $target['id'] === $user['id']) return ['error' => 'You can’t deactivate your own account'];
    if (!$active && $target['role'] === 'SUPER_ADMIN' && super_admin_count($target['id']) < 1) {
        return ['error' => 'The platform needs at least one active super admin'];
    }
    if (!$active && is_tenant_admin($target['role']) && active_admin_count($tenantId, $target['id']) < 1) {
        return ['error' => 'A workspace needs at least one active administrator'];
    }
    db_update('User', ['active' => $active ? 1 : 0, 'updatedAt' => now_iso()], '"id" = ?', [$target['id']]);
    if (!$active) {
        // Signed-out everywhere: no push to their devices, pending reset links revoked.
        q('DELETE FROM "PushSubscription" WHERE "userId" = ?', [$target['id']]);
        q('DELETE FROM "PasswordResetToken" WHERE "userId" = ?', [$target['id']]);
    }
    return ['success' => $target['name'] . ($active ? ' can sign in again' : ' was deactivated and signed out')];
}

/** Set a temporary password, or email a reset link. */
function act_member_password(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'users.access')) return ['error' => 'Not allowed'];
    $target = managed_user($user, $tenantId, str_in($in, 'userId'));
    if (is_string($target)) return ['error' => $target];
    if (($in['mode'] ?? '') === 'set') {
        $pw = (string) ($in['password'] ?? '');
        if ($problem = password_problem($pw, $tenantId)) return ['error' => $problem];
        db_update('User', ['passwordHash' => hash_password($pw), 'updatedAt' => now_iso()], '"id" = ?', [$target['id']]);
        return ['success' => "New password set for {$target['name']}. Share it with them securely."];
    }
    $r = send_reset_link($target);
    return ['success' => $r['emailed'] ? "Password reset link emailed to {$target['email']}" : "Email is not set up — share this one-time link (valid 1h): {$r['link']}"];
}

function act_member_remove(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'users.remove')) return ['error' => 'Not allowed'];
    $userId = str_in($in, 'userId');
    if (!$userId || $userId === $user['id']) return ['error' => 'You can’t remove yourself'];
    $target = managed_user($user, $tenantId, $userId);
    if (is_string($target)) return ['error' => $target];
    if ($target['role'] === 'SUPER_ADMIN' && super_admin_count($target['id']) < 1) {
        return ['error' => 'The platform needs at least one active super admin'];
    }
    if (is_tenant_admin($target['role']) && active_admin_count($tenantId, $target['id']) < 1) {
        return ['error' => 'A workspace needs at least one active administrator'];
    }
    q('DELETE FROM "User" WHERE "id" = ?', [$userId]);
    return ['ok' => true];
}

/* ---------- Roles & permissions (administrators only) ---------- */

/** Administrators allowed to manage other administrators (a super admin may switch this off). */
function can_manage_admins(array $user): bool
{
    return is_tenant_admin($user['role']) && can($user, 'admin.admins');
}

function require_roles_admin(string $perm = 'admin.roles'): array
{
    $ctx = require_tenant_user();
    if (!is_tenant_admin($ctx[0]['role']) || !can($ctx[0], $perm)) redirect('/dashboard');
    return $ctx;
}

function act_role_save(array $in): ?array
{
    [, $tenantId] = require_roles_admin();
    $id = str_in($in, 'id');
    $name = mb_substr(str_in($in, 'name'), 0, 40);
    if (mb_strlen($name) < 2) return ['error' => 'Role name is too short'];
    if (in_array(mb_strtolower($name), ['administrator', 'admin', 'agent', 'customer'], true)) return ['error' => 'That name is reserved for a built-in role'];
    if (q_val('SELECT 1 FROM "Role" WHERE "tenantId" = ? AND "name" = ?' . ($id ? ' AND "id" <> ?' : ''), $id ? [$tenantId, $name, $id] : [$tenantId, $name])) {
        return ['error' => 'A role with that name already exists'];
    }
    $perms = perms_for_storage(array_keys(array_filter($in['perm'] ?? [], fn ($v) => $v === 'on')));
    $data = [
        'name' => $name,
        'description' => mb_substr(str_in($in, 'description'), 0, 160) ?: null,
        'color' => safe_color(str_in($in, 'color'), '#6366f1'),
        'permissions' => json_encode($perms),
    ];
    if ($id) {
        if (!q_val('SELECT 1 FROM "Role" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId])) return ['error' => 'Role not found'];
        db_update('Role', $data, '"id" = ? AND "tenantId" = ?', [$id, $tenantId]);
        tenant_setting_set($tenantId, 'permissionsVersion', 2);
        return ['success' => "Role \"$name\" saved"];
    }
    $newId = cuid();
    db_insert('Role', ['id' => $newId, 'tenantId' => $tenantId, 'createdAt' => now_iso()] + $data);
    tenant_setting_set($tenantId, 'permissionsVersion', 2);
    return ['success' => "Role \"$name\" created. Assign it to agents from the Users tab.", 'back' => '/settings?role=' . $newId . '#roles'];
}

function act_role_delete(array $in): ?array
{
    [, $tenantId] = require_roles_admin();
    $id = str_in($in, 'id');
    $role = q_one('SELECT "name" FROM "Role" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]);
    if (!$role) return ['error' => 'Role not found'];
    $moved = q('UPDATE "User" SET "customRoleId" = NULL WHERE "tenantId" = ? AND "customRoleId" = ?', [$tenantId, $id])->rowCount();
    q('DELETE FROM "Role" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]);
    return ['success' => "Deleted \"{$role['name']}\"" . ($moved ? " — $moved user(s) moved back to the Agent role" : ''), 'back' => '/settings#roles'];
}

/** Permissions of the built-in Agent role. */
function act_agent_perms_save(array $in): ?array
{
    [, $tenantId] = require_roles_admin();
    if (($in['reset'] ?? '') === '1') {
        tenant_setting_set($tenantId, 'agentPermissions', perms_for_storage(AGENT_DEFAULT_PERMISSIONS));
        return ['success' => 'Agent permissions reset to the defaults'];
    }
    tenant_setting_set($tenantId, 'agentPermissions', perms_for_storage(array_keys(array_filter($in['perm'] ?? [], fn ($v) => $v === 'on'))));
    return ['success' => 'Agent permissions saved'];
}

/** Super admins only: limit what administrators of this workspace can do. */
function act_admin_perms_save(array $in): ?array
{
    [$me, $tenantId] = require_roles_admin();
    if ($me['role'] !== 'SUPER_ADMIN') return ['error' => 'Only a super admin can change Administrator permissions'];
    if (($in['reset'] ?? '') === '1') {
        tenant_setting_set($tenantId, 'adminPermissions', perms_for_storage(admin_all_permissions(), true));
        return ['success' => 'Administrators have full access again'];
    }
    tenant_setting_set($tenantId, 'adminPermissions', perms_for_storage(array_keys(array_filter($in['perm'] ?? [], fn ($v) => $v === 'on')), true));
    return ['success' => 'Administrator permissions saved'];
}

function act_security_save(array $in): ?array
{
    [, $tenantId] = require_roles_admin('admin.security');
    $min = (int) ($in['passwordMinLength'] ?? 6);
    if ($min < 6 || $min > 64) return ['error' => 'Minimum length must be between 6 and 64'];
    tenant_setting_set($tenantId, 'passwordMinLength', $min);
    tenant_setting_set($tenantId, 'passwordRequireMix', ($in['passwordRequireMix'] ?? '') === 'on');
    return ['success' => 'Security settings saved — they apply to new passwords'];
}
