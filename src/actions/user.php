<?php
declare(strict_types=1);

/* Per-user actions: notifications, preferences, saved views, ticket tags,
   platform impersonation. */

function act_notif_read(array $in): ?array
{
    $user = require_user();
    db_update('Notification', ['read' => 1], '"id" = ? AND "userId" = ?', [str_in($in, 'id'), $user['id']]);
    return ['ok' => true];
}

function act_notif_read_all(array $in): ?array
{
    $user = require_user();
    db_update('Notification', ['read' => 1], '"userId" = ? AND "read" = 0', [$user['id']]);
    return ['ok' => true];
}

function act_prefs_save(array $in): ?array
{
    [$user] = require_tenant_user();
    $prefs = [];
    foreach (NOTIFY_CATEGORIES as $c) {
        foreach (CHANNELS as $ch) $prefs[$c][$ch] = ($in["ch_{$c}_{$ch}"] ?? '') === 'on';
    }
    $quiet = ($in['quietEnabled'] ?? '') === 'on';
    $scope = in_array($in['scope'] ?? '', ['all', 'assigned', 'watching'], true) ? $in['scope'] : 'all';
    $data = [
        'prefs' => json_encode($prefs),
        'quietStart' => $quiet ? hhmm_to_minutes(str_in($in, 'quietStart')) : null,
        'quietEnd' => $quiet ? hhmm_to_minutes(str_in($in, 'quietEnd')) : null,
        'priorityThreshold' => str_in($in, 'priorityThreshold'),
        'scope' => $scope,
        'updatedAt' => now_iso(),
    ];
    if (q_val('SELECT 1 FROM "NotificationPreference" WHERE "userId" = ?', [$user['id']])) {
        db_update('NotificationPreference', $data, '"userId" = ?', [$user['id']]);
    } else {
        db_insert('NotificationPreference', ['id' => cuid(), 'userId' => $user['id']] + $data);
    }
    return ['success' => 'Notification preferences saved'];
}

function act_device_remove(array $in): ?array
{
    [$user] = require_tenant_user();
    q('DELETE FROM "PushSubscription" WHERE "id" = ? AND "userId" = ?', [str_in($in, 'id'), $user['id']]);
    return ['ok' => true];
}

function act_view_create(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $name = mb_substr(str_in($in, 'name'), 0, 40);
    $query = ltrim(str_in($in, 'query'), '?');
    if ($name === '') return null;
    db_insert('SavedView', ['id' => cuid(), 'tenantId' => $tenantId, 'userId' => $user['id'], 'name' => $name, 'query' => $query, 'createdAt' => now_iso()]);
    return ['ok' => true];
}

function act_view_delete(array $in): ?array
{
    [$user] = require_tenant_user();
    q('DELETE FROM "SavedView" WHERE "id" = ? AND "userId" = ?', [str_in($in, 'id'), $user['id']]);
    return ['ok' => true];
}

/** Tagging a ticket (staff, requester or collaborator). */
function act_ticket_tag_add(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !can_manage_ticket($ticket, $user)) return ['error' => 'Not allowed'];
    if (staff_lacks($user, 'tickets.tags')) return ['error' => 'You don’t have permission to tag tickets'];

    $tagId = str_in($in, 'tagId');
    $newName = clean_tag((string) ($in['name'] ?? ''));
    if (!$tagId && $newName !== '') {
        $tagId = q_val('SELECT "id" FROM "Tag" WHERE "tenantId" = ? AND "name" = ?', [$tenantId, $newName]);
        if (!$tagId) {
            $tagId = cuid();
            db_insert('Tag', ['id' => $tagId, 'name' => $newName, 'tenantId' => $tenantId]);
        }
    }
    if (!$tagId || !q_val('SELECT 1 FROM "Tag" WHERE "id" = ? AND "tenantId" = ?', [$tagId, $tenantId])) return null;
    if (!q_val('SELECT 1 FROM "TicketTag" WHERE "ticketId" = ? AND "tagId" = ?', [$ticket['id'], $tagId])) {
        db_insert('TicketTag', ['ticketId' => $ticket['id'], 'tagId' => $tagId]);
        reindex_ticket($ticket['id']);
    }
    return ['ok' => true];
}

function act_ticket_tag_remove(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !can_manage_ticket($ticket, $user)) return ['error' => 'Not allowed'];
    if (staff_lacks($user, 'tickets.tags')) return ['error' => 'You don’t have permission to tag tickets'];
    q('DELETE FROM "TicketTag" WHERE "ticketId" = ? AND "tagId" = ?', [$ticket['id'], str_in($in, 'tagId')]);
    reindex_ticket($ticket['id']);
    return ['ok' => true];
}

/** Platform operator enters a tenant's board to view & manage it. */
function act_platform_enter(array $in): ?array
{
    require_super_admin();
    $tenantId = str_in($in, 'tenantId');
    if (!$tenantId || !q_val('SELECT 1 FROM "Tenant" WHERE "id" = ?', [$tenantId])) return null;
    set_active_tenant_id($tenantId);
    redirect('/dashboard');
}

function act_platform_exit(array $in): ?array
{
    require_super_admin();
    clear_active_tenant_id();
    redirect('/platform');
}
