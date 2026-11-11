<?php
declare(strict_types=1);

/* Ticket templates, sub-tickets and CC addresses. */

/* ---------- Templates (Settings → Tags & replies) ---------- */

function act_template_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.templates'))) return NOT_ALLOWED;
    [$user, $tenantId] = $ctx;
    $id = str_in($in, 'id');
    $name = mb_substr(str_in($in, 'name'), 0, 80);
    if (mb_strlen($name) < 2) return ['error' => 'Give the template a name'];
    $categoryId = str_in($in, 'categoryId') ?: null;
    if ($categoryId && !q_val('SELECT 1 FROM "Category" WHERE "id" = ? AND "tenantId" = ?', [$categoryId, $tenantId])) return ['error' => 'Invalid category'];
    $priority = str_in($in, 'priority') ?: null;
    if ($priority && !priority_exists(tenant_priorities($tenantId), $priority)) return ['error' => 'Invalid priority'];
    $agents = array_column(q_all('SELECT "id" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\')', [$tenantId]), 'id');
    $assignees = array_values(array_intersect(array_map('strval', (array) ($in['assigneeIds'] ?? [])), $agents));
    $tags = array_values(array_unique(array_filter(array_map('clean_tag', preg_split('/[,\n]+/', str_in($in, 'tags')) ?: []))));
    $subtasks = array_values(array_filter(array_map(fn ($l) => mb_substr(trim($l), 0, 140), preg_split('/\r?\n/', (string) ($in['subtasks'] ?? '')) ?: []), fn ($l) => mb_strlen($l) >= 3));
    $data = [
        'name' => $name, 'audience' => isset(TEMPLATE_AUDIENCES[$in['audience'] ?? '']) ? $in['audience'] : 'everyone',
        'categoryId' => $categoryId, 'priority' => $priority, 'subject' => mb_substr(str_in($in, 'subject'), 0, 140) ?: null,
        'body' => sanitize_rich_html((string) ($in['body'] ?? '')) ?: null,
        'tags' => json_encode($tags), 'assigneeIds' => json_encode($assignees), 'subtasks' => json_encode(array_slice($subtasks, 0, 20)),
        'updatedAt' => now_iso(),
    ];
    if ($id) {
        if (!q_val('SELECT 1 FROM "TicketTemplate" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId])) return ['error' => 'Template not found'];
        db_update('TicketTemplate', $data, '"id" = ? AND "tenantId" = ?', [$id, $tenantId]);
        return ['success' => "Saved “{$name}”"];
    }
    $order = (int) q_val('SELECT COALESCE(MAX("order"), 0) FROM "TicketTemplate" WHERE "tenantId" = ?', [$tenantId]) + 1;
    db_insert('TicketTemplate', ['id' => cuid(), 'tenantId' => $tenantId, 'order' => $order, 'createdAt' => now_iso()] + $data);
    return ['success' => "Added “{$name}”"];
}

function act_template_delete(array $in): ?array
{
    if (!($ctx = ctx_with('settings.templates'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    q('DELETE FROM "TicketTemplate" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['success' => 'Template deleted'];
}

/* ---------- Sub-tickets ---------- */

function act_subticket_create(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $parent = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$parent || !is_staff($user['role']) || !can_manage_ticket($parent, $user)) return ['error' => 'Not allowed'];
    if (!empty($parent['parentId'])) return ['error' => 'Sub-tickets can’t have their own sub-tickets'];
    $subject = mb_substr(str_in($in, 'subject'), 0, 140);
    if (mb_strlen($subject) < 3) return ['error' => 'Write what needs to be done (3+ characters)'];
    $assignee = str_in($in, 'assigneeId') ?: null;
    if ($assignee && $assignee !== $user['id'] && !can($user, 'tickets.assign')) return ['error' => 'You can only assign sub-tickets to yourself'];
    $t = create_subticket($parent, $user, $tenantId, $subject, $assignee);
    if (is_string($t)) return ['error' => $t];
    return ['success' => 'Sub-ticket #' . (int) $t['number'] . ' created'];
}

/* ---------- CC ---------- */

/** Who may change a ticket's CC list: staff who can manage it, and its requester. */
function cc_allowed(array $ticket, array $user): bool
{
    if (!ticket_form_parts($ticket['tenantId'])['cc']) return false;
    return ($ticket['requesterId'] === $user['id']) || (is_staff($user['role']) && can_manage_ticket($ticket, $user));
}

function act_cc_add(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !cc_allowed($ticket, $user)) return ['error' => 'Not allowed'];
    $list = parse_cc_list(str_in($in, 'emails'));
    if (is_string($list)) return ['error' => $list];
    if (!$list) return ['error' => 'Enter at least one email address'];
    $added = add_ticket_cc($ticket['id'], $list, $user['id']);
    if ($added) log_event($tenantId, $ticket['id'], $user['id'], 'MEMBER_ADDED', 'copied ' . human_list($added) . ' by email');
    return ['success' => $added ? 'Added to CC: ' . implode(', ', $added) : 'Already on the CC list'];
}

function act_cc_remove(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$ticket || !cc_allowed($ticket, $user)) return ['error' => 'Not allowed'];
    $email = strtolower(str_in($in, 'email'));
    q('DELETE FROM "TicketCc" WHERE "ticketId" = ? AND "email" = ?', [$ticket['id'], $email]);
    log_event($tenantId, $ticket['id'], $user['id'], 'MEMBER_REMOVED', "removed $email from CC");
    return ['ok' => true];
}

/* ---------- JSON helpers for the new-ticket form ---------- */

/** GET /api/customers?q= — staff picking the customer a ticket is for. */
function api_customers_search(): never
{
    [$user, $tenantId] = require_tenant_user();
    if (!is_staff($user['role'])) json_out(['error' => 'Forbidden'], 403);
    json_out(['data' => search_customers($tenantId, (string) ($_GET['q'] ?? ''))]);
}

/** GET /api/tickets/similar?q=&requesterId= — open tickets that may be the same request. */
function api_similar_tickets(): never
{
    [$user, $tenantId] = require_tenant_user();
    json_out(['data' => similar_open_tickets($user, $tenantId, (string) ($_GET['q'] ?? ''), ($_GET['requesterId'] ?? '') ?: null)]);
}

/**
 * GET /api/people?q=&kind=all|staff|customer&ticketId=&exclude=id,id
 * People that may be added to a ticket's team. With ticketId, the requester and
 * current followers are left out (and the ticket must be manageable).
 */
function api_people_search(): never
{
    [$user, $tenantId] = require_tenant_user();
    $exclude = array_values(array_filter(explode(',', (string) ($_GET['exclude'] ?? ''))));
    if (($tid = (string) ($_GET['ticketId'] ?? '')) !== '') {
        $t = find_ticket($tid, $tenantId);
        if (!$t || !can_manage_ticket($t, $user)) json_out(['error' => 'Not allowed'], 403);
        $exclude = array_merge($exclude, [$t['requesterId']], pluck(q_all('SELECT "userId" FROM "TicketWatcher" WHERE "ticketId" = ?', [$t['id']]), 'userId'));
    }
    $kind = in_array($_GET['kind'] ?? '', ['staff', 'customer'], true) ? $_GET['kind'] : 'all';
    json_out(search_people($user, $tenantId, (string) ($_GET['q'] ?? ''), $kind, array_slice(array_unique($exclude), 0, 200)));
}

/** Settings → General → New-ticket form: show or hide optional parts. */
function act_ticket_form_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.general'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $parts = [];
    foreach (TICKET_FORM_PARTS as $k => $_) $parts[$k] = ($in['part'][$k] ?? '') === 'on';
    tenant_setting_set($tenantId, 'ticketForm', $parts);
    return ['success' => 'New-ticket form saved'];
}
