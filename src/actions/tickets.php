<?php
declare(strict_types=1);

/* Tickets: create, update, bulk update, replies, team (watchers). */

function log_event(string $tenantId, string $ticketId, ?string $actorId, string $type, string $summary): void
{
    db_insert('TicketEvent', [
        'id' => cuid(), 'tenantId' => $tenantId, 'ticketId' => $ticketId, 'actorId' => $actorId,
        'type' => $type, 'summary' => $summary, 'createdAt' => now_iso(),
    ]);
}

function find_ticket(string $id, string $tenantId): ?array
{
    return q_one('SELECT * FROM "Ticket" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]);
}

function watcher_of(string $ticketId, string $userId): ?array
{
    return q_one('SELECT * FROM "TicketWatcher" WHERE "ticketId" = ? AND "userId" = ?', [$ticketId, $userId]);
}

/** Staff member without a permission (customers are governed by ownership instead). */
function staff_lacks(array $user, string $perm): bool
{
    return is_staff($user['role']) && !can($user, $perm);
}

/** Staff, the requester, or a COLLABORATOR team member may manage a ticket. */
function can_manage_ticket(array $ticket, array $user): bool
{
    if ($ticket['requesterId'] === $user['id']) return true;
    // Staff manage the tickets they can see (all, or assigned / followed ones).
    if (is_staff($user['role'])) return ticket_visible($ticket, $user);
    return (watcher_of($ticket['id'], $user['id'])['role'] ?? null) === 'COLLABORATOR';
}

function ticket_ref(array $t, ?string $priority = null): array
{
    return ['id' => $t['id'], 'number' => (int) $t['number'], 'subject' => $t['subject'], 'priority' => $priority ?? $t['priority']];
}

/** Apply enabled TICKET_CREATED automation rules to a freshly created ticket. */
function apply_automations(string $tenantId, array $ticket, array $statuses, array $priorities, array $sla): void
{
    $rules = q_all('SELECT * FROM "AutomationRule" WHERE "tenantId" = ? AND "enabled" = 1 AND "trigger" = ? ORDER BY "order" ASC, "createdAt" ASC', [$tenantId, 'TICKET_CREATED']);
    if (!$rules) return;
    $cur = ['priority' => $ticket['priority'], 'categoryId' => $ticket['categoryId'], 'status' => $ticket['status'], 'assigneeId' => $ticket['assigneeId']];
    $num = (int) $ticket['number'];

    foreach ($rules as $r) {
        if ($r['conditionField'] === 'priority' && $cur['priority'] !== $r['conditionValue']) continue;
        if ($r['conditionField'] === 'category' && $cur['categoryId'] !== $r['conditionValue']) continue;

        switch ($r['actionType']) {
            case 'ASSIGN':
                $agent = q_one('SELECT "id","name" FROM "User" WHERE "id" = ? AND "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\')', [$r['actionValue'], $tenantId]);
                if (!$agent || $cur['assigneeId'] === $agent['id']) break;
                db_update('Ticket', ['assigneeId' => $agent['id'], 'updatedAt' => now_iso()], '"id" = ?', [$ticket['id']]);
                $cur['assigneeId'] = $agent['id'];
                log_event($tenantId, $ticket['id'], null, 'ASSIGNED', "auto-assigned the ticket to {$agent['name']} (rule \"{$r['name']}\")");
                dispatch([
                    'tenantId' => $tenantId, 'recipients' => [$agent['id']], 'category' => 'assigned',
                    'ticket' => ticket_ref($ticket, $cur['priority']),
                    'inAppType' => 'TICKET_ASSIGNED',
                    'inAppMessage' => "You were assigned ticket #$num: {$ticket['subject']}",
                    'pushBody' => 'You were auto-assigned this ticket',
                    'emailLine' => "You were assigned ticket #$num: {$ticket['subject']}.",
                ]);
                break;
            case 'SET_PRIORITY':
                if (!priority_exists($priorities, $r['actionValue']) || $cur['priority'] === $r['actionValue']) break;
                db_update('Ticket', ['priority' => $r['actionValue'], 'dueAt' => compute_due_at($ticket['createdAt'], $r['actionValue'], $sla), 'updatedAt' => now_iso()], '"id" = ?', [$ticket['id']]);
                $cur['priority'] = $r['actionValue'];
                log_event($tenantId, $ticket['id'], null, 'PRIORITY_CHANGED', "set priority to {$r['actionValue']} (rule \"{$r['name']}\")");
                break;
            case 'SET_STATUS':
                $st = null;
                foreach ($statuses as $s) if ($s['key'] === $r['actionValue']) $st = $s;
                if (!$st || $cur['status'] === $r['actionValue']) break;
                db_update('Ticket', ['status' => $r['actionValue'], 'closedAt' => $st['isClosed'] ? now_iso() : null, 'updatedAt' => now_iso()], '"id" = ?', [$ticket['id']]);
                $cur['status'] = $r['actionValue'];
                log_event($tenantId, $ticket['id'], null, 'STATUS_CHANGED', "set status to {$r['actionValue']} (rule \"{$r['name']}\")");
                break;
            case 'ADD_TAG':
                $tag = q_one('SELECT "id","name" FROM "Tag" WHERE "id" = ? AND "tenantId" = ?', [$r['actionValue'], $tenantId]);
                if (!$tag) break;
                if (!q_val('SELECT 1 FROM "TicketTag" WHERE "ticketId" = ? AND "tagId" = ?', [$ticket['id'], $tag['id']])) {
                    db_insert('TicketTag', ['ticketId' => $ticket['id'], 'tagId' => $tag['id']]);
                }
                log_event($tenantId, $ticket['id'], null, 'MEMBER_ADDED', "tagged the ticket #{$tag['name']} (rule \"{$r['name']}\")");
                break;
        }
    }
}

/** Mentioned user ids from sanitized editor HTML. */
function extract_mention_ids(string $html): array
{
    $ids = [];
    if (preg_match_all('/<span[^>]*data-type="mention"[^>]*>/', $html, $m)) {
        foreach ($m[0] as $tag) {
            if (preg_match('/data-id="([^"]+)"/', $tag, $id)) $ids[$id[1]] = true;
        }
    }
    return array_keys($ids);
}

/** Add @mentioned users to the ticket team (as viewers) and notify them. */
function process_mentions(array $ticket, string $tenantId, array $actor, string $html): void
{
    $ids = array_values(array_filter(extract_mention_ids($html), fn ($id) => $id !== $actor['id'] && $id !== $ticket['requesterId']));
    if (!$ids) return;
    $users = q_all('SELECT "id","name" FROM "User" WHERE "id" IN (' . in_ph($ids) . ') AND "tenantId" = ?', [...$ids, $tenantId]);

    $added = [];
    foreach ($users as $u) {
        if (!watcher_of($ticket['id'], $u['id'])) {
            db_insert('TicketWatcher', ['ticketId' => $ticket['id'], 'userId' => $u['id'], 'role' => 'VIEWER', 'createdAt' => now_iso()]);
            log_event($tenantId, $ticket['id'], $actor['id'], 'MEMBER_ADDED', "mentioned {$u['name']}");
        }
        $added[] = $u['id'];
    }
    $num = (int) $ticket['number'];
    dispatch([
        'tenantId' => $tenantId, 'recipients' => $added, 'category' => 'mention', 'ticket' => ticket_ref($ticket),
        'inAppType' => 'NEW_COMMENT',
        'inAppMessage' => "{$actor['name']} mentioned you on ticket #$num",
        'pushBody' => "{$actor['name']} mentioned you",
        'emailLine' => "{$actor['name']} mentioned you on ticket #$num.",
    ]);
}

/** Parse the team JSON submitted from the create form. */
function parse_team(mixed $raw): array
{
    if (!is_string($raw) || trim($raw) === '') return [];
    $parsed = json_decode($raw, true);
    if (!is_array($parsed)) return [];
    $seen = [];
    $out = [];
    foreach ($parsed as $item) {
        $userId = (string) ($item['userId'] ?? '');
        $role = is_watcher_role((string) ($item['role'] ?? '')) ? (string) $item['role'] : 'VIEWER';
        if ($userId !== '' && !isset($seen[$userId])) {
            $seen[$userId] = true;
            $out[] = ['userId' => $userId, 'role' => $role];
        }
    }
    return $out;
}

/**
 * Create a ticket for a requester (web form, REST API, support form, app).
 * $data: subject, description (HTML), priority?, categoryId?, groupId?, fields? (key => value), source?
 * $actor: who performs it (defaults to the requester). Returns the ticket row or an error string.
 */
function create_ticket(string $tenantId, array $requester, array $data, ?array $actor = null): array|string
{
    $actor ??= $requester;
    $subject = trim((string) ($data['subject'] ?? ''));
    $categoryId = ($data['categoryId'] ?? '') ?: null;
    if (mb_strlen($subject) < 3) return 'Subject must be at least 3 characters';
    if (mb_strlen($subject) > 140) return 'Subject is too long';
    $description = sanitize_rich_html((string) ($data['description'] ?? ''));
    if (mb_strlen(html_to_text($description)) < 5) return 'Please describe the issue';
    if ($categoryId && !q_val('SELECT 1 FROM "Category" WHERE "id" = ? AND "tenantId" = ?', [$categoryId, $tenantId])) return 'Invalid category';

    $priorities = tenant_priorities($tenantId);
    $priority = (string) ($data['priority'] ?? '') ?: default_priority_key($priorities);
    if (!priority_exists($priorities, $priority)) return 'Invalid priority';
    $statuses = tenant_statuses($tenantId);
    $sla = tenant_sla($tenantId);

    // Group: explicit, else routed from the category.
    $groupId = ($data['groupId'] ?? '') ?: null;
    if ($groupId && !q_val('SELECT 1 FROM "AgentGroup" WHERE "id" = ? AND "tenantId" = ?', [$groupId, $tenantId])) return 'Invalid group';
    if (!$groupId && $categoryId) $groupId = q_val('SELECT "groupId" FROM "Category" WHERE "id" = ?', [$categoryId]) ?: null;

    // Custom fields that apply to this category (customers only fill the visible ones).
    $fieldDefs = fields_for($tenantId, $categoryId, !is_staff($actor['role']));
    $values = validate_fields($fieldDefs, (array) ($data['fields'] ?? []));
    if (is_string($values)) return $values;
    $source = in_array($data['source'] ?? 'web', ['web', 'api', 'form', 'email'], true) ? ($data['source'] ?? 'web') : 'web';

    $now = now_iso();
    // Per-tenant number = MAX+1. Two simultaneous creates can race for the same
    // number; the UNIQUE(tenantId, number) key rejects one, so retry it.
    for ($attempt = 0; ; $attempt++) {
        try {
            $ticket = db_tx(function () use ($tenantId, $subject, $description, $priority, $categoryId, $groupId, $requester, $statuses, $sla, $now, $source) {
                $number = (int) q_val('SELECT COALESCE(MAX("number"), 0) FROM "Ticket" WHERE "tenantId" = ?', [$tenantId]) + 1;
                $t = [
                    'id' => cuid(), 'number' => $number, 'subject' => $subject, 'description' => $description,
                    'status' => initial_status_key($statuses), 'priority' => $priority,
                    'createdAt' => $now, 'updatedAt' => $now, 'closedAt' => null,
                    'dueAt' => compute_due_at($now, $priority, $sla),
                    'tenantId' => $tenantId, 'requesterId' => $requester['id'], 'assigneeId' => null, 'categoryId' => $categoryId,
                    'groupId' => $groupId, 'source' => $source,
                ];
                db_insert('Ticket', $t);
                return $t;
            });
            break;
        } catch (PDOException $e) {
            $dup = $e->getCode() === '23000' || str_contains($e->getMessage(), 'UNIQUE');
            if (!$dup || $attempt >= 4) throw $e;
            usleep(random_int(5_000, 40_000));
        }
    }
    $number = $ticket['number'];
    if ($values) save_field_values($ticket['id'], $tenantId, $values);

    // Notify all staff in the tenant about the new ticket.
    $staff = pluck(q_all('SELECT "id" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') AND "active" = 1 AND "id" <> ?', [$tenantId, $actor['id']]), 'id');
    if ($staff) {
        dispatch([
            'tenantId' => $tenantId, 'recipients' => $staff, 'category' => 'new_ticket', 'ticket' => ticket_ref($ticket),
            'inAppType' => 'NEW_TICKET',
            'inAppMessage' => "New ticket #$number: $subject",
            'pushBody' => "New ticket: $subject",
            'emailLine' => "New ticket #$number: $subject.",
        ]);
    }

    log_event($tenantId, $ticket['id'], $actor['id'], 'CREATED', $actor['id'] === $requester['id'] ? 'opened the ticket' . ($source !== 'web' ? " (via $source)" : '') : "opened the ticket for {$requester['name']} (via $source)");
    if ($groupId) {
        $g = q_one('SELECT "name" FROM "AgentGroup" WHERE "id" = ?', [$groupId]);
        log_event($tenantId, $ticket['id'], null, 'GROUP_CHANGED', 'routed the ticket to ' . ($g['name'] ?? 'a group'));
    }
    process_mentions($ticket, $tenantId, $actor, $description);
    apply_automations($tenantId, $ticket, $statuses, $priorities, $sla);
    if ($groupId) auto_assign_ticket($ticket['id'], $tenantId);
    reindex_ticket($ticket['id']);
    $fresh = find_ticket($ticket['id'], $tenantId) ?? $ticket;
    webhook_emit($tenantId, 'ticket.created', ['ticket' => api_ticket_brief($fresh) + ['fields' => $values ?: (object) []]]);
    return $fresh;
}

function act_ticket_create(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (str_in($in, 'priority') === '') return ['error' => 'Priority is required'];
    $ticket = create_ticket($tenantId, $user, [
        'subject' => str_in($in, 'subject'), 'description' => (string) ($in['description'] ?? ''),
        'priority' => str_in($in, 'priority'), 'categoryId' => str_in($in, 'categoryId'),
        'fields' => (array) ($in['cf'] ?? []), 'source' => 'web',
    ]);
    if (is_string($ticket)) return ['error' => $ticket];
    $number = $ticket['number'];
    $subject = $ticket['subject'];

    save_uploads(uploaded_files('files'), $tenantId, $user['id'], $ticket['id']);

    // Team members chosen on the create form.
    $team = parse_team($in['team'] ?? '');
    if ($team) {
        $ids = pluck($team, 'userId');
        $valid = array_flip(pluck(q_all('SELECT "id" FROM "User" WHERE "id" IN (' . in_ph($ids) . ') AND "tenantId" = ?', [...$ids, $tenantId]), 'id'));
        $rows = array_values(array_filter($team, fn ($t) => isset($valid[$t['userId']]) && $t['userId'] !== $user['id']));
        foreach ($rows as $r) {
            db_insert('TicketWatcher', ['ticketId' => $ticket['id'], 'userId' => $r['userId'], 'role' => $r['role'], 'createdAt' => now_iso()]);
        }
        if ($rows) {
            dispatch([
                'tenantId' => $tenantId, 'recipients' => pluck($rows, 'userId'), 'category' => 'team', 'ticket' => ticket_ref($ticket),
                'inAppType' => 'NEW_TICKET',
                'inAppMessage' => "You were added to ticket #$number: $subject",
                'pushBody' => "{$user['name']} added you to this ticket",
                'emailLine' => "{$user['name']} added you to ticket #$number: $subject.",
            ]);
        }
    }
    redirect('/tickets/' . $ticket['id']);
}

/**
 * Update ticket fields. $in may contain status, priority, assigneeId, categoryId
 * ("" for assigneeId/categoryId means clear). Returns false when nothing applied.
 */
function update_ticket(string $ticketId, array $in, array $user, string $tenantId): bool
{
    $ticket = find_ticket($ticketId, $tenantId);
    if (!$ticket || !can_manage_ticket($ticket, $user)) return false;

    $status = isset($in['status']) && $in['status'] !== '' ? (string) $in['status'] : null;
    $priority = isset($in['priority']) && $in['priority'] !== '' ? (string) $in['priority'] : null;
    $assigneeSet = array_key_exists('assigneeId', $in);
    $assigneeId = $assigneeSet ? ((string) $in['assigneeId'] ?: null) : null;
    $categorySet = array_key_exists('categoryId', $in);
    $categoryId = $categorySet ? ((string) $in['categoryId'] ?: null) : null;
    $groupSet = array_key_exists('groupId', $in);
    $groupId = $groupSet ? ((string) $in['groupId'] ?: null) : null;
    // Only staff route tickets between groups.
    if (!is_staff($user['role'])) { $groupSet = false; $groupId = null; }

    // Staff field-level permissions (customers keep their requester/collaborator rights).
    if (is_staff($user['role'])) {
        if (!can($user, 'tickets.status')) $status = null;
        if (!can($user, 'tickets.priority')) $priority = null;
        if ($categorySet && !can($user, 'tickets.category')) { $categorySet = false; $categoryId = null; }
        // Taking a ticket yourself is always allowed; assigning to someone else needs the permission.
        if ($assigneeSet && $assigneeId !== $user['id'] && !can($user, 'tickets.assign')) { $assigneeSet = false; $assigneeId = null; }
        if ($groupSet && !can($user, 'tickets.group')) { $groupSet = false; $groupId = null; }
        if (!$status && !$priority && !$assigneeSet && !$categorySet && !$groupSet) return false;
    }

    $assigneeName = null;
    if ($assigneeId) {
        $a = q_one('SELECT "name" FROM "User" WHERE "id" = ? AND "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\')', [$assigneeId, $tenantId]);
        if (!$a) return false;
        $assigneeName = $a['name'];
    }
    $groupName = null;
    if ($groupId) {
        $g = q_one('SELECT "name" FROM "AgentGroup" WHERE "id" = ? AND "tenantId" = ?', [$groupId, $tenantId]);
        if (!$g) return false;
        $groupName = $g['name'];
    }
    $categoryName = null;
    if ($categoryId) {
        $c = q_one('SELECT "name" FROM "Category" WHERE "id" = ? AND "tenantId" = ?', [$categoryId, $tenantId]);
        if (!$c) return false;
        $categoryName = $c['name'];
    }

    $targetClosed = false;
    $statusFrom = $ticket['status'];
    $statusTo = $status ?? '';
    if ($status) {
        $statuses = tenant_statuses($tenantId);
        $st = null;
        foreach ($statuses as $s) if ($s['key'] === $status) $st = $s;
        if (!$st) return false;
        $targetClosed = $st['isClosed'];
        $statusFrom = resolve_status($statuses, $ticket['status'])['label'];
        $statusTo = $st['label'];
    }

    $prioFrom = $ticket['priority'];
    $prioTo = $priority ?? '';
    if ($priority) {
        $priorities = tenant_priorities($tenantId);
        if (!priority_exists($priorities, $priority)) return false;
        $prioFrom = resolve_priority($priorities, $ticket['priority'])['label'];
        $prioTo = resolve_priority($priorities, $priority)['label'];
    }

    $statusChanged = $status && $status !== $ticket['status'];
    $priorityChanged = $priority && $priority !== $ticket['priority'];
    $assigneeChanged = $assigneeSet && $assigneeId !== $ticket['assigneeId'];
    $categoryChanged = $categorySet && $categoryId !== $ticket['categoryId'];
    $groupChanged = $groupSet && $groupId !== ($ticket['groupId'] ?? null);

    $data = ['updatedAt' => now_iso()];
    if ($status) {
        $data['status'] = $status;
        // Moving into a closed status stamps closedAt; staying closed keeps the original stamp.
        $data['closedAt'] = $targetClosed ? ($statusChanged || !$ticket['closedAt'] ? now_iso() : $ticket['closedAt']) : null;
    }
    if ($priority) {
        $data['priority'] = $priority;
        if ($priorityChanged) $data['dueAt'] = compute_due_at($ticket['createdAt'], $priority, tenant_sla($tenantId));
    }
    if ($assigneeSet) $data['assigneeId'] = $assigneeId;
    if ($categorySet) $data['categoryId'] = $categoryId;
    if ($groupSet) $data['groupId'] = $groupId;
    db_update('Ticket', $data, '"id" = ?', [$ticketId]);
    if ($groupChanged) log_event($tenantId, $ticketId, $user['id'], 'GROUP_CHANGED', $groupName ? "moved the ticket to the $groupName group" : 'removed the group');

    if ($statusChanged) log_event($tenantId, $ticketId, $user['id'], 'STATUS_CHANGED', "changed status from $statusFrom to $statusTo");
    if ($priorityChanged) log_event($tenantId, $ticketId, $user['id'], 'PRIORITY_CHANGED', "changed priority from $prioFrom to $prioTo");
    if ($assigneeChanged) log_event($tenantId, $ticketId, $user['id'], 'ASSIGNED', $assigneeName ? "assigned the ticket to $assigneeName" : 'unassigned the ticket');
    if ($assigneeChanged || $categoryChanged) reindex_ticket($ticketId);
    if ($categoryChanged) log_event($tenantId, $ticketId, $user['id'], 'CATEGORY_CHANGED', $categoryName ? "set the category to $categoryName" : 'removed the category');

    $eff = ticket_ref($ticket, $priority ?? $ticket['priority']);
    $num = (int) $ticket['number'];
    if ($statusChanged) {
        $recipients = [$ticket['requesterId'], ...pluck(q_all('SELECT "userId" FROM "TicketWatcher" WHERE "ticketId" = ?', [$ticketId]), 'userId')];
        $recipients = array_values(array_filter(array_unique($recipients), fn ($id) => $id !== $user['id']));
        dispatch([
            'tenantId' => $tenantId, 'recipients' => $recipients, 'category' => 'status', 'ticket' => $eff,
            'inAppType' => 'STATUS_CHANGED',
            'inAppMessage' => "Ticket #$num is now $statusTo",
            'pushBody' => "Status is now $statusTo",
            'emailLine' => "Ticket #$num is now $statusTo.",
        ]);
    }
    if ($assigneeChanged && $assigneeId && $assigneeId !== $user['id']) {
        dispatch([
            'tenantId' => $tenantId, 'recipients' => [$assigneeId], 'category' => 'assigned', 'ticket' => $eff,
            'inAppType' => 'TICKET_ASSIGNED',
            'inAppMessage' => "You were assigned ticket #$num: {$ticket['subject']}",
            'pushBody' => 'You were assigned this ticket',
            'emailLine' => "You were assigned ticket #$num: {$ticket['subject']}.",
        ]);
    }
    // A ticket moved into a group with automatic assignment (and nobody on it yet).
    if ($groupChanged && $groupId && !($data['assigneeId'] ?? $ticket['assigneeId'])) auto_assign_ticket($ticketId, $tenantId);

    if ($statusChanged || $priorityChanged || $assigneeChanged || $categoryChanged || $groupChanged) {
        $fresh = find_ticket($ticketId, $tenantId);
        $changes = [];
        if ($statusChanged) $changes['status'] = ['from' => $ticket['status'], 'to' => $status];
        if ($priorityChanged) $changes['priority'] = ['from' => $ticket['priority'], 'to' => $priority];
        if ($assigneeChanged) $changes['assignee'] = ['from' => $ticket['assigneeId'], 'to' => $fresh['assigneeId']];
        if ($categoryChanged) $changes['category'] = ['from' => $ticket['categoryId'], 'to' => $categoryId];
        if ($groupChanged) $changes['group'] = ['from' => $ticket['groupId'] ?? null, 'to' => $groupId];
        webhook_emit($tenantId, 'ticket.updated', ['ticket' => api_ticket_brief($fresh), 'changes' => $changes, 'actor' => ['id' => $user['id'], 'name' => $user['name']]]);
        if ($statusChanged && $targetClosed) {
            webhook_emit($tenantId, 'ticket.closed', ['ticket' => api_ticket_brief($fresh)]);
            if ($user['id'] !== $fresh['requesterId']) csat_on_closed($fresh, $tenantId);
        }
    }
    return true;
}

function act_ticket_update(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $fields = array_intersect_key($in, array_flip(['status', 'priority', 'assigneeId', 'categoryId', 'groupId']));
    if (update_ticket(str_in($in, 'ticketId'), $fields, $user, $tenantId) === false && $fields) {
        return ['error' => 'You don’t have permission to make that change'];
    }
    return ['ok' => true];
}

/** Apply the same change to many tickets at once (staff only). */
function act_ticket_bulk(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!is_staff($user['role']) || !can($user, 'tickets.bulk')) return ['error' => 'You don’t have permission to bulk edit'];
    $ids = json_decode((string) ($in['ids'] ?? '[]'), true);
    if (!is_array($ids)) return ['error' => 'Invalid selection'];

    $changes = [];
    if (($in['status'] ?? '') !== '') $changes['status'] = (string) $in['status'];
    if (($in['priority'] ?? '') !== '') $changes['priority'] = (string) $in['priority'];
    if (array_key_exists('assigneeId', $in) && $in['assigneeId'] !== '__nochange__') $changes['assigneeId'] = (string) $in['assigneeId'];
    if (!$changes) return ['ok' => true];

    foreach (array_slice($ids, 0, 100) as $id) {
        update_ticket((string) $id, $changes, $user, $tenantId);
    }
    return ['ok' => true];
}

function act_comment_add(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticketId = str_in($in, 'ticketId');
    $ticket = find_ticket($ticketId, $tenantId);
    if (!$ticket) return ['error' => 'Ticket not found'];

    // Only people who can see the ticket may reply.
    if (!ticket_visible($ticket, $user)) return ['error' => 'Not allowed'];

    $body = sanitize_rich_html((string) ($in['body'] ?? ''));
    $files = uploaded_files('files');
    $hasFiles = (bool) array_filter($files, 'file_ok');
    if (mb_strlen(html_to_text($body)) < 1 && !str_contains($body, '<img') && !$hasFiles) {
        return ['error' => 'Comment cannot be empty'];
    }

    $wantsInternal = ($in['isInternal'] ?? '') === 'on';
    // Never turn a requested internal note into a public reply.
    if ($wantsInternal && (!is_staff($user['role']) || !can($user, 'tickets.internal'))) {
        return ['error' => 'You don’t have permission to write internal notes'];
    }
    $isInternal = $wantsInternal;
    if (!$isInternal && staff_lacks($user, 'tickets.reply') && $user['id'] !== $ticket['requesterId']) {
        return ['error' => 'You can only add internal notes on tickets'];
    }
    add_comment($ticket, $user, $tenantId, $body, $isInternal, $hasFiles ? $files : []);
    return ['ok' => true];
}

/**
 * Store a reply/internal note and run every side effect (attachments, mentions,
 * reopen-on-customer-reply, first-response time, search index, notifications).
 * $body must already be sanitized. Shared by replies and macros.
 */
function add_comment(array $ticket, array $user, string $tenantId, string $body, bool $isInternal, array $files = []): string
{
    $ticketId = $ticket['id'];
    $now = now_iso();
    $commentId = cuid();
    db_insert('Comment', [
        'id' => $commentId, 'body' => $body, 'isInternal' => $isInternal ? 1 : 0, 'createdAt' => $now,
        'tenantId' => $tenantId, 'ticketId' => $ticketId, 'authorId' => $user['id'],
    ]);
    if ($files) save_uploads($files, $tenantId, $user['id'], $ticketId, $commentId);

    // First public staff reply = first response (SLA / reports).
    if (!$isInternal && is_staff($user['role']) && $user['id'] !== $ticket['requesterId'] && empty($ticket['firstResponseAt'])) {
        db_update('Ticket', ['firstResponseAt' => $now], '"id" = ? AND "firstResponseAt" IS NULL', [$ticketId]);
    }

    if (!$isInternal) process_mentions($ticket, $tenantId, $user, $body);

    // Reopen a resolved/closed ticket when the requester replies.
    if (!is_staff($user['role'])) {
        $statuses = tenant_statuses($tenantId);
        if (resolve_status($statuses, $ticket['status'])['isClosed']) {
            db_update('Ticket', ['status' => initial_status_key($statuses), 'closedAt' => null, 'updatedAt' => now_iso()], '"id" = ?', [$ticketId]);
            log_event($tenantId, $ticketId, $user['id'], 'REOPENED', 'reopened the ticket by replying');
        }
    }
    db_update('Ticket', ['updatedAt' => now_iso()], '"id" = ?', [$ticketId]);
    if (!$isInternal) reindex_ticket($ticketId);

    // Notify requester, assignee and watchers (internal notes → staff only).
    $watchers = q_all('SELECT u."id", u."role" FROM "TicketWatcher" w JOIN "User" u ON u."id" = w."userId" WHERE w."ticketId" = ?', [$ticketId]);
    $recipients = [];
    if (!$isInternal && $ticket['requesterId'] !== $user['id']) $recipients[] = $ticket['requesterId'];
    if ($ticket['assigneeId'] && $ticket['assigneeId'] !== $user['id']) $recipients[] = $ticket['assigneeId'];
    foreach ($watchers as $w) {
        if ($w['id'] === $user['id']) continue;
        if ($isInternal && !is_staff($w['role'])) continue;
        $recipients[] = $w['id'];
    }
    $num = (int) $ticket['number'];
    dispatch([
        'tenantId' => $tenantId, 'recipients' => $recipients, 'category' => 'reply', 'ticket' => ticket_ref($ticket),
        'inAppType' => 'NEW_COMMENT',
        'inAppMessage' => ($isInternal ? 'Internal note' : 'New reply') . " on ticket #$num",
        'pushBody' => $user['name'] . ($isInternal ? ' added an internal note' : ' replied'),
        'emailLine' => $user['name'] . ($isInternal ? ' added an internal note' : ' replied') . " on ticket #$num.",
    ]);
    webhook_emit($tenantId, 'comment.created', ['ticket' => api_ticket_brief(find_ticket($ticketId, $tenantId) ?? $ticket), 'comment' => ['id' => $commentId, 'internal' => $isInternal, 'body' => $body, 'text' => html_to_text($body), 'author' => ['id' => $user['id'], 'name' => $user['name'], 'role' => $user['role']], 'createdAt' => $now]]);
    return $commentId;
}

function act_watcher_add(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !can_manage_ticket($ticket, $user)) return ['error' => 'Not allowed'];
    if (staff_lacks($user, 'tickets.team')) return ['error' => 'You don’t have permission to manage followers'];

    $userId = str_in($in, 'userId');
    $role = is_watcher_role(str_in($in, 'role')) ? str_in($in, 'role') : 'VIEWER';
    if (!$userId || $userId === $ticket['requesterId']) return null;
    $target = q_one('SELECT * FROM "User" WHERE "id" = ? AND "tenantId" = ?', [$userId, $tenantId]);
    if (!$target) return null;

    if (watcher_of($ticket['id'], $userId)) {
        db_update('TicketWatcher', ['role' => $role], '"ticketId" = ? AND "userId" = ?', [$ticket['id'], $userId]);
    } else {
        db_insert('TicketWatcher', ['ticketId' => $ticket['id'], 'userId' => $userId, 'role' => $role, 'createdAt' => now_iso()]);
    }
    log_event($tenantId, $ticket['id'], $user['id'], 'MEMBER_ADDED', "added {$target['name']} to the team as " . WATCHER_ROLE_LABELS[$role]);

    if ($userId !== $user['id']) {
        $num = (int) $ticket['number'];
        dispatch([
            'tenantId' => $tenantId, 'recipients' => [$userId], 'category' => 'team', 'ticket' => ticket_ref($ticket),
            'inAppType' => 'NEW_TICKET',
            'inAppMessage' => "You were added to ticket #$num",
            'pushBody' => "{$user['name']} added you to this ticket",
            'emailLine' => "{$user['name']} added you to ticket #$num.",
        ]);
    }
    return ['ok' => true];
}

function act_watcher_role(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !can_manage_ticket($ticket, $user)) return ['error' => 'Not allowed'];
    if (staff_lacks($user, 'tickets.team')) return ['error' => 'You don’t have permission to manage followers'];
    $userId = str_in($in, 'userId');
    $role = str_in($in, 'role');
    if (!$userId || !is_watcher_role($role)) return null;

    db_update('TicketWatcher', ['role' => $role], '"ticketId" = ? AND "userId" = ?', [$ticket['id'], $userId]);
    $target = q_one('SELECT "name" FROM "User" WHERE "id" = ?', [$userId]);
    if ($target) log_event($tenantId, $ticket['id'], $user['id'], 'MEMBER_ROLE_CHANGED', "changed {$target['name']}'s role to " . WATCHER_ROLE_LABELS[$role]);
    return ['ok' => true];
}

function act_watcher_remove(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !can_manage_ticket($ticket, $user)) return ['error' => 'Not allowed'];
    if (staff_lacks($user, 'tickets.team')) return ['error' => 'You don’t have permission to manage followers'];
    $userId = str_in($in, 'userId');
    if (!$userId) return null;

    $target = q_one('SELECT "name" FROM "User" WHERE "id" = ?', [$userId]);
    $removed = q('DELETE FROM "TicketWatcher" WHERE "ticketId" = ? AND "userId" = ?', [$ticket['id'], $userId])->rowCount();
    if ($removed > 0 && $target) log_event($tenantId, $ticket['id'], $user['id'], 'MEMBER_REMOVED', "removed {$target['name']} from the team");
    return ['ok' => true];
}
