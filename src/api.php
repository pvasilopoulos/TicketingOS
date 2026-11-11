<?php
declare(strict_types=1);

/* JSON / binary endpoints (ported from app/api/*). */

/** Serve an attachment with tenant isolation + visibility checks. */
function api_attachment(string $id): never
{
    $user = current_user();
    if (!$user) abort(401, 'Unauthorized');

    $a = q_one('SELECT a.*, t."tenantId" AS tTenant, t."requesterId" AS tRequester, c."isInternal" AS cInternal
                FROM "Attachment" a
                LEFT JOIN "Ticket" t ON t."id" = a."ticketId"
                LEFT JOIN "Comment" c ON c."id" = a."commentId"
                WHERE a."id" = ?', [$id]);
    if (!$a) abort(404);

    $super = $user['role'] === 'SUPER_ADMIN';
    $owner = $a['tTenant'] ?? $a['tenantId'];
    if (!$owner) abort(404);
    if (!$super && $owner !== $user['tenantId']) abort(403, 'Forbidden');

    // Only people who can see the ticket may download its files.
    if ($a['ticketId'] && !$super) {
        $t = q_one('SELECT "id","requesterId","assigneeId" FROM "Ticket" WHERE "id" = ?', [$a['ticketId']]);
        if (!$t || !ticket_visible($t, $user)) abort(403, 'Forbidden');
    }
    // Attachments on internal notes need the internal-notes permission.
    if ($a['cInternal'] && !$super && !(is_staff($user['role']) && can($user, 'tickets.internal'))) abort(403, 'Forbidden');

    $path = attachment_disk_path($owner, $a['id']);
    if (!is_file($path)) abort(404, 'File missing');

    // SVG is never rendered inline (it can carry script).
    $inline = (str_starts_with($a['mime'], 'image/') && $a['mime'] !== 'image/svg+xml') || $a['mime'] === 'application/pdf';
    header('Content-Type: ' . ($a['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($path));
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($a['filename']));
    header('Cache-Control: private, max-age=3600');
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox");
    readfile($path);
    throw new HttpExit();
}

function api_user_or_401(): array
{
    $user = current_user();
    if (!$user) json_out(['error' => 'Unauthorized'], 401);
    $hdr = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? '');
    if (!hash_equals($_COOKIE['tos_csrf'] ?? '-', (string) $hdr)) json_out(['error' => 'Invalid CSRF token'], 419);
    return $user;
}

function api_editor_image(): never
{
    $user = api_user_or_401();
    $tenantId = effective_tenant_id($user);
    if (!$tenantId) json_out(['error' => 'Unauthorized'], 401);
    $file = uploaded_files('file')[0] ?? null;
    if (!$file) json_out(['error' => 'No file'], 400);
    $url = save_inline_image($file, $tenantId, $user['id']);
    if (!$url) json_out(['error' => 'Invalid image (PNG/JPEG/GIF/WebP up to 5MB)'], 400);
    json_out(['url' => $url]);
}

function json_body(): ?array
{
    $d = json_decode((string) file_get_contents('php://input'), true);
    return is_array($d) ? $d : null;
}

function api_push_subscribe(): never
{
    $user = api_user_or_401();
    $b = json_body();
    $endpoint = $b['endpoint'] ?? null;
    $p256dh = $b['keys']['p256dh'] ?? null;
    $auth = $b['keys']['auth'] ?? null;
    if (!is_string($endpoint) || !is_string($p256dh) || !is_string($auth) || !preg_match('#^https://#', $endpoint)) {
        json_out(['error' => 'Invalid subscription'], 400);
    }
    $ua = mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200) ?: null;
    $existing = q_val('SELECT "id" FROM "PushSubscription" WHERE "endpoint" = ?', [$endpoint]);
    if ($existing) {
        db_update('PushSubscription', ['p256dh' => $p256dh, 'auth' => $auth, 'userAgent' => $ua, 'userId' => $user['id']], '"id" = ?', [$existing]);
    } else {
        db_insert('PushSubscription', ['id' => cuid(), 'userId' => $user['id'], 'endpoint' => $endpoint, 'p256dh' => $p256dh, 'auth' => $auth, 'userAgent' => $ua, 'createdAt' => now_iso()]);
    }
    json_out(['ok' => true]);
}

function api_push_unsubscribe(): never
{
    $user = api_user_or_401();
    $endpoint = json_body()['endpoint'] ?? null;
    if (!is_string($endpoint)) json_out(['error' => 'No endpoint'], 400);
    q('DELETE FROM "PushSubscription" WHERE "endpoint" = ? AND "userId" = ?', [$endpoint, $user['id']]);
    json_out(['ok' => true]);
}

function api_push_test(): never
{
    $user = api_user_or_401();
    send_push_to_users([$user['id']], [
        'title' => 'TicketingOS',
        'body' => '🔔 Push notifications are working on this device.',
        'url' => '/dashboard',
    ]);
    json_out(['ok' => true]);
}

/** Web App Manifest (installable PWA + home-screen shortcuts). */
function api_manifest(): never
{
    header('Content-Type: application/manifest+json; charset=utf-8');
    header('Cache-Control: public, max-age=3600');
    echo json_encode([
        'id' => '/',
        'name' => 'TicketingOS — Helpdesk',
        'short_name' => 'TicketingOS',
        'description' => 'Multi-tenant helpdesk: tickets, replies and live notifications.',
        'start_url' => '/dashboard?source=pwa',
        'scope' => '/',
        'display' => 'standalone',
        'display_override' => ['window-controls-overlay', 'standalone'],
        'orientation' => 'any',
        'background_color' => '#f6f7f9',
        'theme_color' => '#4f46e5',
        'categories' => ['business', 'productivity'],
        'icons' => [
            ['src' => '/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
            ['src' => '/icons/maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
        ],
        'shortcuts' => [
            ['name' => 'New ticket', 'url' => '/tickets/new', 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
            ['name' => 'My tickets', 'url' => '/tickets?assignee=me', 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
            ['name' => 'Overdue', 'url' => '/tickets?overdue=1', 'icons' => [['src' => '/icons/icon-192.png', 'sizes' => '192x192']]],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    throw new HttpExit();
}

/**
 * Collision detection. The ticket page posts a heartbeat every few seconds
 * with its state (viewing / typing / left) and gets back the other people on
 * the ticket plus the newest reply, so agents don't answer twice.
 */
function api_presence(): never
{
    $user = api_user_or_401();
    [, $tenantId] = require_tenant_user();
    $b = json_body() ?? [];
    $ticket = find_ticket((string) ($b['ticketId'] ?? ''), $tenantId);
    if (!$ticket) json_out(['error' => 'Not found'], 404);
    $staff = is_staff($user['role']);
    if (!ticket_visible($ticket, $user)) json_out(['error' => 'Forbidden'], 403);
    $seesInternal = $staff && can($user, 'tickets.internal');

    $state = in_array($b['state'] ?? '', ['viewing', 'typing', 'left'], true) ? $b['state'] : 'viewing';
    $now = now_iso();
    q('DELETE FROM "TicketPresence" WHERE "ticketId" = ? AND "userId" = ?', [$ticket['id'], $user['id']]);
    if ($state !== 'left') {
        db_insert('TicketPresence', ['ticketId' => $ticket['id'], 'userId' => $user['id'], 'state' => $state, 'updatedAt' => $now]);
    }
    if (random_int(1, 50) === 1) q('DELETE FROM "TicketPresence" WHERE "updatedAt" < ?', [now_iso(-3600)]);
    if ($state === 'left') json_out(['ok' => true]);

    // Customers only send heartbeats; the panel is an agent tool.
    $others = $staff ? q_all('SELECT u."name", u."avatarColor" AS "color", u."role", p."state" FROM "TicketPresence" p JOIN "User" u ON u."id" = p."userId"
        WHERE p."ticketId" = ? AND p."userId" <> ? AND p."updatedAt" > ? ORDER BY p."state" DESC, u."name" ASC', [$ticket['id'], $user['id'], now_iso(-25)]) : [];
    foreach ($others as &$o) { $o['initials'] = initials($o['name']); $o['role'] = role_label($o['role']); }

    $since = (string) ($b['since'] ?? '');
    $latest = null;
    if (preg_match('/^\d{4}-\d\d-\d\dT[\d:.]+Z$/', $since)) {
        $latest = q_one('SELECT u."name", c."isInternal", c."createdAt" FROM "Comment" c JOIN "User" u ON u."id" = c."authorId"
            WHERE c."ticketId" = ? AND c."authorId" <> ? AND c."createdAt" > ?' . ($seesInternal ? '' : ' AND c."isInternal" = 0') . ' ORDER BY c."createdAt" DESC LIMIT 1',
            [$ticket['id'], $user['id'], $since]);
    }
    json_out(['others' => $others, 'newReply' => $latest ? ['name' => $latest['name'], 'internal' => (bool) $latest['isInternal']] : null]);
}

/** Knowledge-base suggestions while a customer types a new ticket. */
function api_kb_suggest(): never
{
    [$user, $tenantId] = require_tenant_user();
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
    header('Cache-Control: no-store');
    if (mb_strlen($q) < 3) json_out(['items' => []]);
    $rows = kb_find($tenantId, $q, false, 4);
    json_out(['items' => array_map(fn ($r) => [
        'id' => $r['id'], 'title' => $r['title'], 'section' => $r['section'],
        'excerpt' => mb_substr(html_to_text($r['body']), 0, 140),
    ], array_values(array_filter($rows, fn ($r) => ($r['score'] ?? 1) > 0)))]);
}

/** Live search suggestions (top matches) for the search boxes. */
function api_search(): never
{
    [$user, $tenantId] = require_tenant_user();
    $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 200);
    header('Cache-Control: no-store');
    if ($q === '') json_out(['items' => [], 'total' => 0]);

    $statuses = tenant_statuses($tenantId);
    $priorities = tenant_priorities($tenantId);
    $params = [];
    $where = ticket_filter_sql($user, $tenantId, ['q' => $q], $params, closed_status_keys($statuses));
    $started = microtime(true);
    $rows = q_all('SELECT t."id", t."number", t."subject", t."status", t."priority", t."searchText" FROM "Ticket" t WHERE ' . $where . ' ORDER BY t."createdAt" DESC LIMIT 8', $params);
    $total = count($rows) < 8 ? count($rows) : (int) q_val('SELECT COUNT(*) FROM (SELECT 1 FROM "Ticket" t WHERE ' . $where . ' LIMIT 1000) x', $params);

    $items = array_map(function ($r) use ($statuses, $priorities, $q) {
        $s = resolve_status($statuses, $r['status']);
        $p = resolve_priority($priorities, $r['priority']);
        return [
            'id' => $r['id'], 'number' => (int) $r['number'], 'subject' => $r['subject'],
            'status' => ['label' => $s['label'], 'color' => $s['color']],
            'priority' => ['label' => $p['label'], 'color' => $p['color']],
            'snippet' => search_snippet($r['searchText'], $q),
        ];
    }, $rows);
    json_out(['items' => $items, 'total' => $total, 'ms' => (int) round((microtime(true) - $started) * 1000)]);
}

/** CSV export of the (filtered) ticket list — staff only. */
function export_tickets_csv(): never
{
    [$user, $tenantId] = require_tenant_user();
    if (!is_staff($user['role']) || !can($user, 'tickets.export')) abort(403, 'Forbidden');
    $statuses = tenant_statuses($tenantId);
    $priorities = tenant_priorities($tenantId);
    $params = [];
    $where = ticket_filter_sql($user, $tenantId, query_params(), $params, closed_status_keys($statuses));

    $tenant = q_one('SELECT "slug" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tickets-' . ($tenant['slug'] ?? 'export') . '-' . date('Y-m-d') . '.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens Greek text correctly
    $fields = tenant_fields($tenantId);
    $groups = array_column(tenant_groups($tenantId), 'name', 'id');
    $orgs = array_column(tenant_orgs($tenantId), 'name', 'id');
    fputcsv($out, ['Number', 'Subject', 'Status', 'Priority', 'Category', 'Group', 'Requester', 'Requester email', 'Organization', 'Assignee', 'Source',
        'Created', 'Due', 'Closed', 'Replies', 'Satisfaction', ...array_column($fields, 'label')], ',', '"', '\\');

    $guard = fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], '=+-@') !== false ? "'" . $v : $v; // CSV-injection guard
    $stmt = q(TICKET_ROW_SELECT . ' WHERE ' . $where . ' ORDER BY t."createdAt" DESC LIMIT 50000', $params);
    // Rows are written in batches so field values / ratings are fetched with one query per batch.
    $flush = function (array $batch) use ($out, $guard, $statuses, $priorities, $fields, $groups, $orgs) {
        if (!$batch) return;
        $ids = array_column($batch, 'id');
        $vals = [];
        if ($fields) foreach (q_all('SELECT "ticketId","fieldId","value" FROM "TicketFieldValue" WHERE "ticketId" IN (' . in_ph($ids) . ')', $ids) as $v) $vals[$v['ticketId']][$v['fieldId']] = $v['value'];
        $csat = array_column(q_all('SELECT "ticketId", MAX("rating") AS r FROM "CsatSurvey" WHERE "answeredAt" IS NOT NULL AND "ticketId" IN (' . in_ph($ids) . ') GROUP BY "ticketId"', $ids), 'r', 'ticketId');
        foreach ($batch as $t) {
            $row = [
                $t['number'], $t['subject'],
                resolve_status($statuses, $t['status'])['label'], resolve_priority($priorities, $t['priority'])['label'],
                $t['cName'] ?? '', $groups[$t['groupId'] ?? ''] ?? '', $t['rName'], $t['rEmail'] ?? '', $orgs[$t['rOrg'] ?? ''] ?? '', $t['aName'] ?? '', $t['source'] ?? 'web',
                local_dt($t['createdAt'])?->format('Y-m-d H:i'), local_dt($t['dueAt'])?->format('Y-m-d H:i') ?? '',
                local_dt($t['closedAt'])?->format('Y-m-d H:i') ?? '', $t['commentCount'], $csat[$t['id']] ?? '',
            ];
            foreach ($fields as $f) $row[] = $vals[$t['id']][$f['id']] ?? '';
            fputcsv($out, array_map($guard, $row), ',', '"', '\\');
        }
    };
    $batch = [];
    while ($t = $stmt->fetch()) {
        $batch[] = $t;
        if (count($batch) === 500) { $flush($batch); $batch = []; }
    }
    $flush($batch);
    fclose($out);
    audit($tenantId, $user, 'ticket-export', 'Exported tickets to CSV', 'ticket', null, array_filter(query_params()));
    throw new HttpExit();
}

/**
 * Server-Sent Events. PHP workers are not long-lived, so each request
 * delivers any notifications created since Last-Event-ID and then closes;
 * the browser's EventSource reconnects after `retry` ms. This keeps the
 * stream cheap and works on every SAPI (incl. the single-threaded dev server).
 */
function api_stream(): never
{
    $user = current_user();
    if (!$user) abort(401, 'Unauthorized');

    $since = $_SERVER['HTTP_LAST_EVENT_ID'] ?? ($_GET['since'] ?? '');
    if (!preg_match('/^\d{4}-\d\d-\d\dT[\d:.]+Z$/', (string) $since)) $since = null;

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-transform');
    header('X-Accel-Buffering: no');

    echo "retry: 4000\n";
    if ($since === null) {
        // First connect: start the cursor at "now".
        echo 'id: ' . now_iso() . "\n\n";
        throw new HttpExit();
    }

    $rows = q_all('SELECT "id","message","ticketId","createdAt" FROM "Notification" WHERE "userId" = ? AND "createdAt" > ? ORDER BY "createdAt" ASC LIMIT 20', [$user['id'], $since]);
    foreach ($rows as $n) {
        echo 'id: ' . $n['createdAt'] . "\n";
        echo 'data: ' . json_encode([
            'kind' => 'notification',
            'message' => $n['message'],
            'ticketId' => $n['ticketId'],
            'url' => $n['ticketId'] ? '/tickets/' . $n['ticketId'] : '/dashboard',
            'at' => (int) (ts($n['createdAt']) * 1000),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    }
    if (!$rows) echo ": ping\n\n";
    throw new HttpExit();
}
