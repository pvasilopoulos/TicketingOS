<?php
declare(strict_types=1);

/*
 * Advanced ticket creation: tickets opened for a customer, channels, templates,
 * sub-tickets, CC email addresses and the "similar open tickets" check.
 */

/** Where a ticket came from. web/api/form are set by the app; the rest are picked by staff. */
const TICKET_CHANNELS = ['web' => 'Web', 'phone' => 'Phone', 'email' => 'Email', 'walkin' => 'In person', 'chat' => 'Chat'];
const TICKET_SOURCES = ['web', 'api', 'form', 'email', 'phone', 'walkin', 'chat'];

/* ---------- Customers ---------- */

/**
 * Find a customer of the workspace by email, or create one (joins the organization
 * matching the email domain). Returns the user row or an error string.
 */
function find_or_create_customer(string $tenantId, string $email, string $name): array|string
{
    $email = strtolower(trim($email));
    if (!is_valid_email($email)) return 'Enter a valid email for the customer';
    $u = q_one('SELECT * FROM "User" WHERE "email" = ?', [$email]);
    if ($u) return $u['tenantId'] === $tenantId ? $u : 'This email belongs to another workspace';
    $name = trim($name) !== '' ? mb_substr(trim($name), 0, 80) : ucfirst((string) strstr($email, '@', true));
    $count = (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" = ?', [$tenantId]);
    $now = now_iso();
    $u = [
        'id' => cuid(), 'email' => $email, 'name' => $name, 'passwordHash' => hash_password(bin2hex(random_bytes(24))),
        'role' => 'CUSTOMER', 'active' => 1, 'organizationId' => org_for_email($tenantId, $email)['id'] ?? null, 'orgManager' => 0,
        'avatarColor' => AVATAR_COLORS[$count % count(AVATAR_COLORS)], 'createdAt' => $now, 'updatedAt' => $now, 'tenantId' => $tenantId,
    ];
    db_insert('User', $u);
    if ($u['organizationId']) add_user_org($u['id'], $u['organizationId']);
    return $u;
}

/** Customers matching a search (staff picker on the new-ticket form). */
function search_customers(string $tenantId, string $q, int $limit = 12): array
{
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $like = like_arg(mb_strtolower($q));
    return q_all("SELECT u.\"id\", u.\"name\", u.\"email\", o.\"name\" AS \"org\" FROM \"User\" u LEFT JOIN \"Organization\" o ON o.\"id\" = u.\"organizationId\"
        WHERE u.\"tenantId\" = ? AND u.\"role\" = 'CUSTOMER' AND u.\"active\" = 1 AND (LOWER(u.\"name\") LIKE ? ESCAPE '!' OR LOWER(u.\"email\") LIKE ? ESCAPE '!')
        ORDER BY u.\"name\" LIMIT $limit", [$tenantId, $like, $like]);
}

/* ---------- Similar open tickets ---------- */

/**
 * Open tickets whose subject shares words with $text, among those the user can
 * see; for staff opening a ticket for a customer, that customer's tickets.
 */
function similar_open_tickets(array $user, string $tenantId, string $text, ?string $requesterId = null, int $limit = 5): array
{
    $words = array_values(array_unique(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [], fn ($w) => mb_strlen($w) >= 4)));
    if (!$words) return [];
    $words = array_slice($words, 0, 6);
    $closed = closed_status_keys(tenant_statuses($tenantId));
    $params = [];
    $where = ticket_scope_sql($user, $tenantId, $params);
    if ($closed) { $where .= ' AND t."status" NOT IN (' . in_ph($closed) . ')'; array_push($params, ...$closed); }
    $where .= ' AND t."mergedIntoId" IS NULL';
    if ($requesterId && is_staff($user['role'])) { $where .= ' AND t."requesterId" = ?'; $params[] = $requesterId; }
    elseif (!is_staff($user['role']) && !user_manages_any_org($user['id'])) { $where .= ' AND t."requesterId" = ?'; $params[] = $user['id']; }
    $score = [];
    $or = [];
    foreach ($words as $w) {
        $or[] = 'LOWER(t."subject") LIKE ? ESCAPE \'!\'';
        $params[] = like_arg($w);
    }
    $rows = q_all('SELECT t."id", t."number", t."subject", t."status", t."createdAt", r."name" AS "rName" FROM "Ticket" t JOIN "User" r ON r."id" = t."requesterId"
        WHERE ' . $where . ' AND (' . implode(' OR ', $or) . ') ORDER BY t."updatedAt" DESC LIMIT 40', $params);
    foreach ($rows as $r) {
        $s = mb_strtolower($r['subject']);
        $score[$r['id']] = count(array_filter($words, fn ($w) => str_contains($s, $w)));
    }
    usort($rows, fn ($a, $b) => [$score[$b['id']], $b['createdAt']] <=> [$score[$a['id']], $a['createdAt']]);
    $statuses = tenant_statuses($tenantId);
    return array_map(fn ($r) => [
        'id' => $r['id'], 'number' => (int) $r['number'], 'subject' => $r['subject'], 'requester' => $r['rName'],
        'status' => resolve_status($statuses, $r['status'])['label'], 'url' => '/tickets/' . $r['id'], 'ago' => time_ago($r['createdAt']),
    ], array_slice($rows, 0, $limit));
}

/* ---------- Sub-tickets ---------- */

function ticket_children(string $ticketId): array
{
    return q_all('SELECT t.*, a."name" AS "aName", a."avatarColor" AS "aColor" FROM "Ticket" t LEFT JOIN "User" a ON a."id" = t."assigneeId"
        WHERE t."parentId" = ? ORDER BY t."number"', [$ticketId]);
}

/**
 * Create a sub-ticket of $parent. Sub-tickets are internal work items: the staff
 * member who creates them is the requester, so customers don't see them.
 */
function create_subticket(array $parent, array $actor, string $tenantId, string $subject, ?string $assigneeId = null): array|string
{
    $t = create_ticket($tenantId, $actor, [
        'subject' => $subject,
        'description' => '<p>Sub-ticket of <a href="/tickets/' . e($parent['id']) . '">#' . (int) $parent['number'] . ' ' . e($parent['subject']) . '</a>.</p>',
        'priority' => $parent['priority'], 'categoryId' => $parent['categoryId'] ?? '', 'groupId' => $parent['groupId'] ?? '',
        'source' => 'web', 'parentId' => $parent['id'], 'skipNotify' => true,
    ], $actor);
    if (is_string($t)) return $t;
    if ($assigneeId) update_ticket($t['id'], ['assigneeId' => $assigneeId], $actor, $tenantId);
    log_event($tenantId, $parent['id'], $actor['id'], 'LINKED', 'added sub-ticket #' . (int) $t['number'] . ': ' . $t['subject']);
    return $t;
}

/* ---------- CC email addresses ---------- */

function ticket_cc(string $ticketId): array
{
    return pluck(q_all('SELECT "email" FROM "TicketCc" WHERE "ticketId" = ? ORDER BY "createdAt", "email"', [$ticketId]), 'email');
}

/** Parse "a@x.com, b@y.com; c@z.com" into valid unique addresses (max 10). */
function parse_cc_list(string $raw): array|string
{
    $out = [];
    foreach (preg_split('/[\s,;]+/', strtolower(trim($raw))) ?: [] as $e) {
        if ($e === '') continue;
        if (!is_valid_email($e)) return "“$e” is not a valid email address";
        $out[$e] = true;
    }
    if (count($out) > 10) return 'Up to 10 CC addresses per ticket';
    return array_keys($out);
}

function add_ticket_cc(string $ticketId, array $emails, ?string $actorId): array
{
    $have = array_flip(ticket_cc($ticketId));
    $added = [];
    foreach ($emails as $e) {
        if (isset($have[$e]) || count($have) + count($added) >= 10) continue;
        db_insert('TicketCc', ['ticketId' => $ticketId, 'email' => $e, 'addedById' => $actorId, 'createdAt' => now_iso()]);
        $added[] = $e;
    }
    return $added;
}

/** Email the CC list about a public update (reply or status change). Best-effort. */
function notify_ticket_cc(array $ticket, string $tenantId, string $headline, string $bodyHtml = ''): void
{
    $cc = ticket_cc($ticket['id']);
    if (!$cc) return;
    $s = email_settings($tenantId);
    if (!$s || !$s['enabled']) return;
    $num = (int) $ticket['number'];
    $html = '<div style="font-family:system-ui,sans-serif;font-size:14px;color:#0f172a">'
        . '<p style="margin:0 0 12px"><strong>' . e($headline) . '</strong></p>'
        . ($bodyHtml !== '' ? '<div style="border-left:3px solid #e2e8f0;padding-left:12px;margin:0 0 16px">' . $bodyHtml . '</div>' : '')
        . '<p style="margin:0;color:#64748b;font-size:12px">You’re receiving this because you were copied (CC) on ticket #' . $num . ' “' . e($ticket['subject']) . '” at ' . e(app_name()) . '. Reply to the person who added you if you have something to add.</p></div>';
    foreach ($cc as $to) {
        send_tenant_email_safe($tenantId, ['to' => $to, 'subject' => "[#$num] " . $ticket['subject'], 'html' => $html]);
    }
}

/* ---------- Templates ---------- */

const TEMPLATE_AUDIENCES = ['everyone' => 'Everyone', 'staff' => 'Staff only'];

function ticket_templates(string $tenantId, bool $staff): array
{
    $rows = q_all('SELECT * FROM "TicketTemplate" WHERE "tenantId" = ? ORDER BY "order", "name"', [$tenantId]);
    return array_values(array_filter($rows, fn ($t) => $staff || $t['audience'] === 'everyone'));
}

/** Template as sent to the new-ticket form (customers never see staff-only parts). */
function template_payload(array $t, bool $staff): array
{
    $dec = fn ($v) => json_decode((string) $v, true) ?: [];
    return [
        'id' => $t['id'], 'name' => $t['name'], 'subject' => (string) $t['subject'], 'body' => (string) $t['body'],
        'priority' => (string) $t['priority'], 'categoryId' => (string) $t['categoryId'],
        'tags' => $staff ? $dec($t['tags']) : [], 'assigneeIds' => $staff ? $dec($t['assigneeIds']) : [], 'subtasks' => $staff ? $dec($t['subtasks']) : [],
    ];
}

/** Default resolution target (hours) per priority key, for the form's SLA preview. */
function sla_hours_map(string $tenantId): array
{
    $map = [];
    $sla = tenant_sla($tenantId);
    foreach (tenant_priorities($tenantId) as $p) $map[$p['key']] = (int) ($sla[$p['key']] ?? 0);
    return $map;
}
