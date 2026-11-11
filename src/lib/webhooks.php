<?php
declare(strict_types=1);

/*
 * Outgoing webhooks. Events are queued in WebhookDelivery and delivered right
 * after the response (and retried by bin/cron.php with exponential backoff).
 *
 * JSON payloads are signed: X-TicketingOS-Signature: sha256=HMAC(secret, body).
 * "slack" and "teams" formats post a short text message to an incoming webhook.
 */

const WEBHOOK_EVENTS = [
    'ticket.created' => 'Ticket created',
    'ticket.updated' => 'Ticket updated (status, priority, assignee, group, fields)',
    'ticket.closed' => 'Ticket closed or resolved',
    'comment.created' => 'New reply or internal note',
    'csat.received' => 'Satisfaction rating received',
];
const WEBHOOK_FORMATS = ['json' => 'JSON (signed)', 'slack' => 'Slack', 'teams' => 'Microsoft Teams', 'viber' => 'Viber channel'];
const VIBER_POST_URL = 'https://chatapi.viber.com/pa/post';
const WEBHOOK_MAX_ATTEMPTS = 6;

/** Queue an event for every active webhook subscribed to it. */
function webhook_emit(string $tenantId, string $event, array $data): void
{
    static $hooks = [];
    $hooks[$tenantId] ??= q_all('SELECT "id","events","options" FROM "Webhook" WHERE "tenantId" = ? AND "active" = 1', [$tenantId]);
    $queued = false;
    foreach ($hooks[$tenantId] as $h) {
        $events = json_decode((string) $h['events'], true) ?: [];
        if (!in_array($event, $events, true)) continue;
        // "Only high and urgent tickets": the two highest priorities of the workspace.
        $opt = json_decode((string) ($h['options'] ?? ''), true) ?: [];
        if (!empty($opt['urgentOnly']) && isset($data['ticket']['priority']) && !in_array($data['ticket']['priority'], webhook_urgent_keys($tenantId), true)) continue;
        db_insert('WebhookDelivery', [
            'id' => cuid(), 'webhookId' => $h['id'], 'tenantId' => $tenantId, 'event' => $event,
            'payload' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => 'pending', 'attempts' => 0, 'responseCode' => null, 'error' => null,
            'nextAttemptAt' => now_iso(), 'createdAt' => now_iso(), 'deliveredAt' => null,
        ]);
        $queued = true;
    }
    if ($queued) webhook_flush_after_response();
}

/** Keys of the two highest priorities. */
function webhook_urgent_keys(string $tenantId): array
{
    $p = tenant_priorities($tenantId);
    usort($p, fn ($a, $b) => $b['order'] <=> $a['order']);
    return array_column(array_slice($p, 0, 2), 'key');
}

/** Deliver this request's queued events once the response is on its way. */
function webhook_flush_after_response(): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') return;
    $registered = true;
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
        try { webhook_deliver_due(10, 4); } catch (Throwable $e) { error_log('[webhook] ' . $e->getMessage()); }
    });
}

/** Build the HTTP request for a delivery: [headers, body]. */
function webhook_request(array $hook, array $d): array
{
    $data = json_decode((string) $d['payload'], true) ?: [];
    $J = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
    if ($hook['format'] === 'slack') {
        return [['Content-Type: application/json'], json_encode(['text' => webhook_text($d['event'], $data)], $J)];
    }
    if ($hook['format'] === 'teams') {
        // Adaptive Card: works with Teams Workflows ("When a Teams webhook request is received") and classic incoming webhooks.
        $t = $data['ticket'] ?? [];
        $facts = array_values(array_filter([
            isset($t['priorityLabel']) ? ['title' => 'Priority', 'value' => $t['priorityLabel']] : null,
            isset($t['statusLabel']) ? ['title' => 'Status', 'value' => $t['statusLabel']] : null,
            isset($t['requester']['name']) ? ['title' => 'Requester', 'value' => $t['requester']['name']] : null,
        ]));
        $card = ['type' => 'AdaptiveCard', '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json', 'version' => '1.4', 'body' => array_values(array_filter([
            ['type' => 'TextBlock', 'text' => (['ticket.created' => '🎫 New ticket', 'ticket.updated' => '✏️ Ticket updated', 'ticket.closed' => '✅ Ticket closed', 'comment.created' => '💬 New reply', 'csat.received' => '⭐ New rating'][$d['event']] ?? $d['event']), 'weight' => 'Bolder', 'wrap' => true],
            isset($t['number']) ? ['type' => 'TextBlock', 'text' => '#' . $t['number'] . ' ' . $t['subject'], 'wrap' => true, 'size' => 'Medium'] : null,
            $facts ? ['type' => 'FactSet', 'facts' => $facts] : null,
        ])), 'actions' => isset($t['url']) ? [['type' => 'Action.OpenUrl', 'title' => 'Open ticket', 'url' => $t['url']]] : []];
        return [['Content-Type: application/json'], json_encode(['type' => 'message', 'text' => webhook_text($d['event'], $data), 'attachments' => [['contentType' => 'application/vnd.microsoft.card.adaptive', 'contentUrl' => null, 'content' => $card]]], $J)];
    }
    if ($hook['format'] === 'viber') {
        $opt = json_decode((string) ($hook['options'] ?? ''), true) ?: [];
        return [['Content-Type: application/json', 'X-Viber-Auth-Token: ' . $hook['secret']],
            json_encode(['from' => (string) ($opt['viberFrom'] ?? ''), 'type' => 'text', 'text' => mb_substr(webhook_text($d['event'], $data), 0, 7000)], $J)];
    }
    $tenant = q_one('SELECT "id","slug","name" FROM "Tenant" WHERE "id" = ?', [$d['tenantId']]);
    $body = json_encode([
        'id' => $d['id'], 'event' => $d['event'], 'createdAt' => $d['createdAt'],
        'workspace' => ['id' => $tenant['id'] ?? null, 'slug' => $tenant['slug'] ?? null, 'name' => $tenant['name'] ?? null],
        'data' => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return [[
        'Content-Type: application/json',
        'User-Agent: TicketingOS-Webhooks/1.0',
        'X-TicketingOS-Event: ' . $d['event'],
        'X-TicketingOS-Delivery: ' . $d['id'],
        'X-TicketingOS-Signature: sha256=' . hash_hmac('sha256', $body, $hook['secret']),
    ], $body];
}

/** One-line message for chat apps. */
function webhook_text(string $event, array $data, bool $withLink = true): string
{
    $t = $data['ticket'] ?? [];
    $ref = isset($t['number']) ? "#{$t['number']} {$t['subject']}" : '';
    $link = $withLink && isset($t['url']) ? " — {$t['url']}" : '';
    return match ($event) {
        'ticket.created' => "🎫 New ticket $ref (" . ($t['priorityLabel'] ?? $t['priority'] ?? '') . ') from ' . ($t['requester']['name'] ?? 'someone') . $link,
        'ticket.closed' => "✅ Ticket closed: $ref$link",
        'ticket.updated' => "✏️ Ticket $ref updated: " . implode(', ', array_map(fn ($k, $v) => "$k → " . (is_scalar($v['to'] ?? null) ? $v['to'] : '—'), array_keys($data['changes'] ?? []), $data['changes'] ?? [])) . $link,
        'comment.created' => (($data['comment']['internal'] ?? false) ? '🔒 Internal note' : '💬 Reply') . " on $ref by " . ($data['comment']['author']['name'] ?? '') . $link,
        'csat.received' => '⭐ Rated ' . ($data['rating'] ?? '?') . "/5 on $ref" . (!empty($data['comment']) ? ': “' . $data['comment'] . '”' : '') . $link,
        default => "$event $ref$link",
    };
}

/** Send due deliveries; returns how many were attempted. */
function webhook_deliver_due(int $limit = 50, int $timeout = 8): int
{
    $due = q_all('SELECT d.*, w."url", w."secret", w."format", w."options", w."active", w."failCount" FROM "WebhookDelivery" d JOIN "Webhook" w ON w."id" = d."webhookId"
                   WHERE d."status" = \'pending\' AND d."nextAttemptAt" <= ? ORDER BY d."createdAt" LIMIT ' . (int) $limit, [now_iso()]);
    foreach ($due as $d) webhook_send($d, $timeout);
    return count($due);
}

/** Attempt one delivery and record the outcome. */
function webhook_send(array $d, int $timeout = 8): bool
{
    $hook = ['url' => $d['url'], 'secret' => $d['secret'], 'format' => $d['format'], 'options' => $d['options'] ?? null];
    [$headers, $body] = webhook_request($hook, $d);
    $code = 0;
    $err = null;
    if (!webhook_url_allowed($d['url'])) {
        $err = 'URL not allowed';
    } else {
        try {
            [$code, $resp] = http_request('POST', $d['url'], $headers, $body, $timeout);
            if ($code < 200 || $code >= 300) $err = "HTTP $code: " . mb_substr(trim(strip_tags($resp)), 0, 200);
            // Viber answers 200 with {"status": 0} on success and an error status otherwise.
            elseif ($d['format'] === 'viber' && (int) ((json_decode((string) $resp, true) ?: [])['status'] ?? 1) !== 0) $err = 'Viber: ' . mb_substr((string) ((json_decode((string) $resp, true) ?: [])['status_message'] ?? 'error'), 0, 200);
        } catch (Throwable $e) {
            $err = mb_substr($e->getMessage(), 0, 250);
        }
    }
    $attempts = (int) $d['attempts'] + 1;
    $ok = $err === null;
    db_update('WebhookDelivery', [
        'status' => $ok ? 'ok' : ($attempts >= WEBHOOK_MAX_ATTEMPTS ? 'failed' : 'pending'),
        'attempts' => $attempts, 'responseCode' => $code ?: null, 'error' => $err,
        'nextAttemptAt' => now_iso((int) (60 * 2 ** $attempts)), 'deliveredAt' => $ok ? now_iso() : null,
    ], '"id" = ?', [$d['id']]);
    $fails = $ok ? 0 : (int) $d['failCount'] + 1;
    // Stop calling an endpoint that keeps failing.
    db_update('Webhook', ['lastStatus' => $code ?: null, 'lastError' => $err, 'lastAt' => now_iso(), 'failCount' => $fails] + ($fails >= 25 ? ['active' => 0] : []), '"id" = ?', [$d['webhookId']]);
    return $ok;
}

/** Only http(s) to public hosts (no loopback / private ranges unless APP_DEBUG). */
function webhook_url_allowed(string $url): bool
{
    $p = parse_url($url);
    if (!in_array(strtolower($p['scheme'] ?? ''), ['http', 'https'], true) || empty($p['host'])) return false;
    if (env('APP_DEBUG', '0') === '1') return true;
    $ip = gethostbyname($p['host']);
    return (bool) filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
}

/** The channel's owner, used as the sender of Viber channel posts. */
function viber_channel_sender(string $token): array
{
    try {
        [$code, $resp] = http_request('POST', 'https://chatapi.viber.com/pa/get_account_info', ['Content-Type: application/json', 'X-Viber-Auth-Token: ' . $token], '{}', 10);
    } catch (Throwable $e) {
        return ['error' => 'Could not reach Viber: ' . $e->getMessage()];
    }
    $j = json_decode((string) $resp, true) ?: [];
    if ($code !== 200 || (int) ($j['status'] ?? 1) !== 0) return ['error' => 'Viber rejected the token' . (!empty($j['status_message']) ? ': ' . $j['status_message'] : '')];
    foreach ((array) ($j['members'] ?? []) as $m) if (($m['role'] ?? '') === 'superadmin') return ['from' => (string) $m['id'], 'name' => (string) ($j['name'] ?? '')];
    return ['error' => 'No channel owner (superadmin) found for this token'];
}

/* ---------- Shared JSON shapes (webhooks + REST API) ---------- */

function api_ticket_brief(array $t): array
{
    $s = resolve_status(tenant_statuses($t['tenantId']), $t['status']);
    $p = resolve_priority(tenant_priorities($t['tenantId']), $t['priority']);
    $r = q_one('SELECT "id","name","email" FROM "User" WHERE "id" = ?', [$t['requesterId']]);
    return [
        'id' => $t['id'], 'number' => (int) $t['number'], 'subject' => $t['subject'],
        'status' => $s['key'], 'statusLabel' => $s['label'], 'closed' => (bool) $s['isClosed'],
        'priority' => $p['key'], 'priorityLabel' => $p['label'],
        'requester' => $r, 'assigneeId' => $t['assigneeId'], 'groupId' => $t['groupId'] ?? null, 'categoryId' => $t['categoryId'],
        'source' => $t['source'] ?? 'web', 'createdAt' => $t['createdAt'], 'updatedAt' => $t['updatedAt'], 'closedAt' => $t['closedAt'],
        'dueAt' => $t['dueAt'], 'url' => app_url() . '/tickets/' . $t['id'],
    ];
}
