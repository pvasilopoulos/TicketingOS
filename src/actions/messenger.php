<?php
declare(strict_types=1);

/*
 * In-app messenger for signed-in customers (the chat button at the bottom right).
 * They talk to their support team, the administrators or colleagues of the same
 * organization. Support chats show up on the agents' Live chat page; chats between
 * colleagues stay private to the two of them and never become tickets.
 * Endpoints: /api/messenger/(list|messages|start|send|upload|action)
 */

function msgr_ctx(): array
{
    [$user, $tenantId] = require_tenant_user();
    if ($user['role'] !== 'CUSTOMER') json_out(['error' => 'Not allowed'], 403);
    if (!chat_settings($tenantId)['enabled']) json_out(['error' => 'Chat is turned off'], 404);
    return [$user, $tenantId];
}

/** Which side of the conversation this person is on: starter, peer (the colleague asked) or null. */
function msgr_side(array $s, array $me): ?string
{
    if ($s['visitorUserId'] === $me['id']) return 'starter';
    if (($s['kind'] ?? null) === 'peer' && $s['requestedAgentId'] === $me['id']) return 'peer';
    return null;
}

function msgr_session(string $id, array $me, string $tenantId): array
{
    $s = q_one('SELECT * FROM "ChatSession" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]);
    if (!$s || !msgr_side($s, $me)) json_out(['error' => 'Conversation not found'], 404);
    return $s;
}

/** The other person in the conversation, as the customer sees them. */
function msgr_other(array $s, string $side): array
{
    $name = fn (?string $id) => $id ? q_one('SELECT "id","name","role","avatarColor" FROM "User" WHERE "id" = ?', [$id]) : null;
    if ($side === 'peer') $u = $name($s['visitorUserId']);
    elseif (($s['kind'] ?? null) === 'peer') $u = $name($s['requestedAgentId']);
    else $u = $name($s['agentId']) ?: $name($s['requestedAgentId']);
    if (!$u) return ['id' => null, 'name' => 'Support team', 'role' => 'Support', 'color' => null];
    $role = ['SUPER_ADMIN' => 'Super admin', 'ADMIN' => 'Admin', 'AGENT' => 'Support', 'CUSTOMER' => 'Colleague'][$u['role']] ?? '';
    return ['id' => $u['id'], 'name' => $u['name'], 'role' => $role, 'color' => $u['avatarColor']];
}

function msgr_unread(array $s, string $side): bool
{
    // The other side's last message is newer than when I last looked.
    [$theirs, $seen] = $side === 'starter' ? [$s['lastAgentAt'], $s['visitorSeenAt']] : [$s['lastVisitorAt'], $s['agentSeenAt']];
    return $theirs && (!$seen || $theirs > $seen);
}

function msgr_typing(array $s, string $side): bool
{
    [$at, $last] = $side === 'starter' ? [$s['agentTypingAt'], $s['lastAgentAt']] : [$s['visitorTypingAt'], $s['lastVisitorAt']];
    return $s['status'] === 'open' && $at && $at > now_iso(-6) && (string) $at > (string) $last;
}

function msgr_mark_seen(array $s, string $side): void
{
    db_update('ChatSession', [$side === 'starter' ? 'visitorSeenAt' : 'agentSeenAt' => now_iso()], '"id" = ?', [$s['id']]);
}

/** GET /api/messenger/list — my conversations and the people I can start one with. */
function api_messenger_list(): never
{
    [$me, $tenantId] = msgr_ctx();
    chat_maintenance($tenantId);
    $rows = q_all('SELECT * FROM "ChatSession" WHERE "tenantId" = ? AND ("visitorUserId" = ? OR ("kind" = ? AND "requestedAgentId" = ?)) AND ("status" = ? OR "closedAt" >= ?)
                   ORDER BY "status" DESC, COALESCE("lastAgentAt", "lastVisitorAt", "createdAt") DESC LIMIT 50', [$tenantId, $me['id'], 'peer', $me['id'], 'open', now_iso(-7 * 86400)]);
    $unread = 0;
    $lasts = chat_last_messages(array_column($rows, 'id'));
    $items = array_map(function ($s) use ($me, &$unread, $lasts) {
        $side = msgr_side($s, $me);
        $last = $lasts[$s['id']] ?? null;
        $u = msgr_unread($s, $side);
        if ($u && $s['status'] === 'open') $unread++;
        $mine = $last && (($last['sender'] === 'visitor' && $side === 'starter') || ($last['sender'] === 'agent' && $last['authorId'] === $me['id']));
        return [
            'id' => $s['id'], 'kind' => ($s['kind'] ?? null) === 'peer' ? 'peer' : 'support', 'status' => $s['status'], 'other' => msgr_other($s, $side),
            'unread' => $u, 'typing' => msgr_typing($s, $side), 'incoming' => $side === 'peer',
            'last' => $last ? ['mine' => $mine, 'text' => $last['attachmentId'] ? '📎 ' . $last['body'] : mb_substr($last['body'], 0, 90), 'at' => $last['createdAt']] : null,
        ];
    }, $rows);
    json_out(['items' => $items, 'unread' => $unread, 'groups' => chat_targets($tenantId, $me), 'greeting' => chat_settings($tenantId)['greeting']]);
}

/** GET /api/messenger/messages?id=…&after=… */
function api_messenger_messages(): never
{
    [$me, $tenantId] = msgr_ctx();
    $s = msgr_session((string) ($_GET['id'] ?? ''), $me, $tenantId);
    $side = msgr_side($s, $me);
    msgr_mark_seen($s, $side);
    $msgs = array_map(function ($m) use ($side, $me) {
        $m['mine'] = ($m['sender'] === 'visitor' && $side === 'starter') || ($m['sender'] === 'agent' && $m['authorId'] === $me['id']);
        unset($m['authorId']);
        return $m;
    }, chat_messages($s['id'], (string) ($_GET['after'] ?? '')));
    $peer = ($s['kind'] ?? null) === 'peer';
    json_out(['messages' => $msgs, 'status' => $s['status'], 'kind' => $peer ? 'peer' : 'support', 'other' => msgr_other($s, $side), 'typing' => msgr_typing($s, $side),
        'canRate' => !$peer && $side === 'starter' && $s['status'] === 'closed' && $s['rating'] === null, 'rating' => $s['rating'] !== null ? (int) $s['rating'] : null]);
}

/** POST /api/messenger/start {to, message} — to: '' for anyone in support, or a person from chat_targets(). */
function api_messenger_start(): never
{
    verify_csrf();
    [$me, $tenantId] = msgr_ctx();
    $b = json_body() ?? [];
    $msg = trim((string) ($b['message'] ?? ''));
    if (mb_strlen($msg) < 1) json_out(['error' => 'Write a message'], 422);
    if (!rate_allow('mchat:' . $me['id'], 20, 3600)) json_out(['error' => 'You’ve started many chats in the last hour. Please try again later.'], 429);
    $to = (string) ($b['to'] ?? '');
    $target = $to !== '' ? chat_target($tenantId, $me, $to) : null;
    if ($to !== '' && !$target) json_out(['error' => 'You can’t chat with this person'], 422);
    $tenant = q_one('SELECT * FROM "Tenant" WHERE "id" = ?', [$tenantId]);

    if ($target && $target['group'] === 'colleagues') {
        // A private conversation between two colleagues.
        $now = now_iso();
        $s = [
            'id' => cuid(), 'tenantId' => $tenantId, 'tokenHash' => hash('sha256', bin2hex(random_bytes(24))), 'visitorName' => mb_substr($me['name'], 0, 120), 'visitorEmail' => strtolower($me['email']),
            'status' => 'open', 'agentId' => null, 'requestedAgentId' => $target['id'], 'ticketId' => null, 'pageUrl' => null, 'kind' => 'peer', 'visitorUserId' => $me['id'],
            'lastVisitorAt' => $now, 'lastAgentAt' => null, 'agentSeenAt' => null, 'visitorSeenAt' => $now, 'createdAt' => $now, 'closedAt' => null,
        ];
        db_insert('ChatSession', $s);
        chat_add($s, 'visitor', $me['id'], $msg);
        db_insert('Notification', ['id' => cuid(), 'type' => 'CHAT', 'message' => first_name($me['name']) . ' sent you a message: ' . mb_substr($msg, 0, 120), 'read' => 0, 'createdAt' => $now, 'tenantId' => $tenantId, 'userId' => $target['id'], 'ticketId' => null]);
    } else {
        $r = chat_start($tenant, $me['name'], $me['email'], $msg, '', $target['id'] ?? null);
        if (is_string($r)) json_out(['error' => $r], 422);
        $s = $r[0];
        db_update('ChatSession', ['visitorUserId' => $me['id'], 'visitorSeenAt' => now_iso()], '"id" = ?', [$s['id']]);
    }
    json_out(['id' => $s['id']]);
}

/** POST /api/messenger/send {id, body} */
function api_messenger_send(): never
{
    verify_csrf();
    [$me, $tenantId] = msgr_ctx();
    $b = json_body() ?? [];
    $s = msgr_session((string) ($b['id'] ?? ''), $me, $tenantId);
    if ($s['status'] !== 'open') json_out(['error' => 'This conversation has ended'], 409);
    if (!rate_allow('mchatmsg:' . $s['id'] . ':' . $me['id'], 60, 300)) json_out(['error' => 'You’re sending messages too fast'], 429);
    $body = trim((string) ($b['body'] ?? ''));
    if ($body === '') json_out(['error' => 'Empty message'], 422);
    $side = msgr_side($s, $me);
    chat_add($s, $side === 'starter' ? 'visitor' : 'agent', $me['id'], $body);
    msgr_mark_seen($s, $side);
    json_out(['ok' => true]);
}

/** POST /api/messenger/upload (multipart: id, file) */
function api_messenger_upload(): never
{
    verify_csrf();
    [$me, $tenantId] = msgr_ctx();
    $s = msgr_session((string) ($_POST['id'] ?? ''), $me, $tenantId);
    if ($s['status'] !== 'open') json_out(['error' => 'This conversation has ended'], 409);
    if (!rate_allow('mchatfile:' . $me['id'], 30, 3600)) json_out(['error' => 'Too many files — please try again later'], 429);
    $f = uploaded_files('file')[0] ?? null;
    if (!$f) json_out(['error' => 'Pick a file'], 422);
    $a = chat_save_file($s, $f, $me['id']);
    if (is_string($a)) json_out(['error' => $a], 422);
    $side = msgr_side($s, $me);
    chat_add($s, $side === 'starter' ? 'visitor' : 'agent', $me['id'], $a['filename'], $a['id']);
    json_out(['ok' => true]);
}

/** POST /api/messenger/action {id, op: typing|close|rate, rating} */
function api_messenger_action(): never
{
    verify_csrf();
    [$me, $tenantId] = msgr_ctx();
    $b = json_body() ?? [];
    $s = msgr_session((string) ($b['id'] ?? ''), $me, $tenantId);
    $side = msgr_side($s, $me);
    $op = (string) ($b['op'] ?? '');
    if ($op === 'typing') {
        if ($s['status'] === 'open') db_update('ChatSession', [$side === 'starter' ? 'visitorTypingAt' : 'agentTypingAt' => now_iso()], '"id" = ?', [$s['id']]);
        json_out(['ok' => true]);
    }
    if ($op === 'close') {
        if ($s['status'] !== 'open') json_out(['ok' => true]);
        if (($s['kind'] ?? null) === 'peer') {
            chat_add($s, 'system', null, first_name($me['name']) . ' ended the conversation.');
            db_update('ChatSession', ['status' => 'closed', 'closedAt' => now_iso()], '"id" = ?', [$s['id']]);
        } else {
            chat_close($s, null, 'visitor');
        }
        json_out(['ok' => true]);
    }
    if ($op === 'rate') {
        $r = (int) ($b['rating'] ?? 0);
        if (($s['kind'] ?? null) === 'peer' || $side !== 'starter' || $s['status'] !== 'closed' || !in_array($r, [1, -1], true)) json_out(['error' => 'Not allowed'], 422);
        db_update('ChatSession', ['rating' => $r], '"id" = ?', [$s['id']]);
        json_out(['ok' => true]);
    }
    json_out(['error' => 'Unknown action'], 422);
}
