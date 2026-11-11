<?php
declare(strict_types=1);

/* Live chat: public endpoints for the website visitor and JSON endpoints for agents. */

/** /support/{slug}/chat[/start|/send|/poll|/close] */
function public_chat(array $tenant, string $op): never
{
    $cfg = chat_settings($tenant['id']);
    if ($op === '') {
        // The widget always opens the chat; without chat it shows the support form.
        if (!$cfg['enabled']) redirect('/support/' . $tenant['slug'] . (isset($_GET['embed']) ? '?embed=1' : ''));
        header_remove('X-Frame-Options');
        header("Content-Security-Policy: frame-ancestors *");
        page_title($cfg['title']);
        view('pages/support_chat', ['tenant' => $tenant, 'cfg' => $cfg, 'embed' => isset($_GET['embed'])], 'base');
    }
    header('Cache-Control: no-store');
    if (!$cfg['enabled']) json_out(['error' => 'Chat is not available right now'], 404);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' && $op !== 'poll') json_out(['error' => 'Method not allowed'], 405);
    $b = json_body() ?? [];
    $ip = client_ip();

    if ($op === 'start') {
        if (trim((string) ($b['website'] ?? '')) !== '') json_out(['error' => 'Please try again'], 422);
        if (!rate_allow('chat:' . $tenant['id'] . ':' . $ip, 6, 3600)) json_out(['error' => 'Too many chats from your network. Please try again later.'], 429);
        $name = mb_substr(trim((string) ($b['name'] ?? '')), 0, 80);
        $email = strtolower(trim((string) ($b['email'] ?? '')));
        $msg = trim((string) ($b['message'] ?? ''));
        if (mb_strlen($name) < 2) json_out(['error' => 'Please enter your name'], 422);
        if (!is_valid_email($email)) json_out(['error' => 'Please enter a valid email'], 422);
        if (mb_strlen($msg) < 2) json_out(['error' => 'Please write a message'], 422);
        $u = q_one('SELECT "tenantId","active" FROM "User" WHERE "email" = ?', [$email]);
        if ($u && ($u['tenantId'] !== $tenant['id'] || !(int) $u['active'])) json_out(['error' => 'This email can’t be used here. Please use another address.'], 422);
        [$s, $token] = chat_start($tenant, $name, $email, $msg, (string) ($b['page'] ?? ''));
        json_out(['token' => $token, 'messages' => chat_messages($s['id']), 'status' => 'open']);
    }

    $s = chat_session_by_token($tenant['id'], (string) ($b['t'] ?? $_GET['t'] ?? ''));
    if (!$s) json_out(['error' => 'This chat has ended'], 404);
    if ($op === 'poll') {
        chat_maintenance($tenant['id']);
        $s = q_one('SELECT * FROM "ChatSession" WHERE "id" = ?', [$s['id']]);
        $agentTyping = $s['agentId'] && $s['lastAgentAt'] && $s['lastAgentAt'] > now_iso(-8);
        json_out(['messages' => chat_messages($s['id'], (string) ($_GET['after'] ?? '')), 'status' => $s['status'], 'agent' => $s['agentId'] ? first_name((string) q_val('SELECT "name" FROM "User" WHERE "id" = ?', [$s['agentId']])) : null]);
    }
    if ($op === 'send') {
        if ($s['status'] !== 'open') json_out(['error' => 'This chat has ended'], 409);
        if (!rate_allow('chatmsg:' . $s['id'], 40, 300)) json_out(['error' => 'You’re sending messages too fast'], 429);
        $body = trim((string) ($b['body'] ?? ''));
        if ($body === '') json_out(['error' => 'Empty message'], 422);
        $m = chat_add($s, 'visitor', null, $body);
        json_out(['ok' => true, 'id' => $m['id']]);
    }
    if ($op === 'close') {
        chat_close($s, null, 'visitor');
        json_out(['ok' => true]);
    }
    json_out(['error' => 'Not found'], 404);
}

/* ---------- Agents ---------- */

function chat_agent_ctx(): array
{
    [$user, $tenantId] = require_tenant_user();
    if (!is_staff($user['role']) || !can($user, 'chat.answer')) json_out(['error' => 'Not allowed'], 403);
    return [$user, $tenantId];
}

/** GET /api/chat/list — open chats and the ones closed today. */
function api_chat_list(): never
{
    [, $tenantId] = chat_agent_ctx();
    chat_maintenance($tenantId);
    $rows = q_all('SELECT s.*, u."name" AS "agentName" FROM "ChatSession" s LEFT JOIN "User" u ON u."id" = s."agentId"
                   WHERE s."tenantId" = ? AND (s."status" = ? OR s."closedAt" >= ?) ORDER BY s."status" DESC, COALESCE(s."lastVisitorAt", s."createdAt") DESC LIMIT 60', [$tenantId, 'open', now_iso(-86400)]);
    json_out(['items' => array_map(function ($s) {
        $last = q_one('SELECT "sender","body","createdAt" FROM "ChatMessage" WHERE "sessionId" = ? AND "sender" <> ? ORDER BY "createdAt" DESC LIMIT 1', [$s['id'], 'system']);
        return [
            'id' => $s['id'], 'name' => $s['visitorName'], 'email' => $s['visitorEmail'], 'status' => $s['status'], 'agent' => $s['agentName'],
            'ticketId' => $s['ticketId'], 'page' => $s['pageUrl'], 'createdAt' => $s['createdAt'],
            'waiting' => $s['status'] === 'open' && (!$s['lastAgentAt'] || $s['lastVisitorAt'] > $s['lastAgentAt']),
            'unread' => $s['lastVisitorAt'] && (!$s['agentSeenAt'] || $s['lastVisitorAt'] > $s['agentSeenAt']),
            'last' => $last ? ['from' => $last['sender'], 'text' => mb_substr($last['body'], 0, 90), 'at' => $last['createdAt']] : null,
        ];
    }, $rows), 'waiting' => chat_waiting_count($tenantId)]);
}

/** GET /api/chat/messages?id=…&after=… */
function api_chat_messages(): never
{
    [, $tenantId] = chat_agent_ctx();
    $s = q_one('SELECT * FROM "ChatSession" WHERE "id" = ? AND "tenantId" = ?', [(string) ($_GET['id'] ?? ''), $tenantId]);
    if (!$s) json_out(['error' => 'Chat not found'], 404);
    db_update('ChatSession', ['agentSeenAt' => now_iso()], '"id" = ?', [$s['id']]);
    $ticket = $s['ticketId'] ? q_one('SELECT "id","number" FROM "Ticket" WHERE "id" = ?', [$s['ticketId']]) : null;
    $known = q_one('SELECT "id","name" FROM "User" WHERE "tenantId" = ? AND "email" = ?', [$tenantId, $s['visitorEmail']]);
    $open = $known ? (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "requesterId" = ?', [$known['id']]) : 0;
    json_out(['messages' => chat_messages($s['id'], (string) ($_GET['after'] ?? '')), 'status' => $s['status'],
        'session' => ['id' => $s['id'], 'name' => $s['visitorName'], 'email' => $s['visitorEmail'], 'page' => $s['pageUrl'], 'agentId' => $s['agentId'],
            'ticket' => $ticket ? ['id' => $ticket['id'], 'number' => (int) $ticket['number']] : null, 'knownTickets' => $open]]);
}

/** POST /api/chat/reply {id, body} */
function api_chat_reply(): never
{
    verify_csrf();
    [$user, $tenantId] = chat_agent_ctx();
    $b = json_body() ?? [];
    $s = q_one('SELECT * FROM "ChatSession" WHERE "id" = ? AND "tenantId" = ?', [(string) ($b['id'] ?? ''), $tenantId]);
    if (!$s) json_out(['error' => 'Chat not found'], 404);
    if ($s['status'] !== 'open') json_out(['error' => 'This chat has ended'], 409);
    $body = trim((string) ($b['body'] ?? ''));
    if ($body === '') json_out(['error' => 'Empty message'], 422);
    if (!$s['agentId']) {
        db_update('ChatSession', ['agentId' => $user['id']], '"id" = ?', [$s['id']]);
        chat_add($s, 'system', null, first_name($user['name']) . ' joined the chat.');
    }
    chat_add($s, 'agent', $user['id'], $body);
    json_out(['ok' => true]);
}

/** POST /api/chat/action {id, op: ticket|close} */
function api_chat_action(): never
{
    verify_csrf();
    [$user, $tenantId] = chat_agent_ctx();
    $b = json_body() ?? [];
    $s = q_one('SELECT * FROM "ChatSession" WHERE "id" = ? AND "tenantId" = ?', [(string) ($b['id'] ?? ''), $tenantId]);
    if (!$s) json_out(['error' => 'Chat not found'], 404);
    $op = (string) ($b['op'] ?? '');
    if ($op === 'ticket') {
        $t = chat_to_ticket($s, $user);
        if (!$t) json_out(['error' => 'Could not create the ticket for this email'], 422);
        if ($s['status'] === 'open' && !$s['ticketId']) chat_add($s, 'system', null, 'We opened ticket #' . (int) $t['number'] . ' for you — you’ll get updates by email.');
        json_out(['ok' => true, 'ticket' => ['id' => $t['id'], 'number' => (int) $t['number']]]);
    }
    if ($op === 'close') {
        chat_close($s, $user, 'agent');
        json_out(['ok' => true]);
    }
    json_out(['error' => 'Unknown action'], 422);
}

function act_chat_settings_save(array $in): ?array
{
    if (!($ctx = ctx_with('settings.chat'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    tenant_setting_set($tenantId, 'chat', [
        'enabled' => ($in['enabled'] ?? '') === 'on',
        'title' => mb_substr(str_in($in, 'title'), 0, 60) ?: 'Chat with us',
        'greeting' => mb_substr(str_in($in, 'greeting'), 0, 300) ?: 'Hi! How can we help?',
        'ticketAfter' => max(1, min(120, (int) ($in['ticketAfter'] ?? 5))),
    ]);
    return ['success' => ($in['enabled'] ?? '') === 'on' ? 'Live chat is on — the website widget now opens the chat' : 'Live chat is off'];
}
