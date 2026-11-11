<?php
declare(strict_types=1);

/*
 * Live chat from the website widget. Visitors talk to agents in real time
 * (short polling, no extra server software); a chat nobody answers in time,
 * or that ends, becomes a ticket so nothing is lost.
 * Visitors are identified by a random token kept in their browser — no cookies.
 */

function chat_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'chat', []);
    return [
        'enabled' => (bool) ($s['enabled'] ?? false),
        'title' => (string) ($s['title'] ?? 'Chat with us'),
        'greeting' => (string) ($s['greeting'] ?? 'Hi! How can we help? We usually answer within a few minutes.'),
        'ticketAfter' => max(1, min(120, (int) ($s['ticketAfter'] ?? 5))),
    ];
}

function chat_session_by_token(string $tenantId, string $token): ?array
{
    if (strlen($token) < 20) return null;
    return q_one('SELECT * FROM "ChatSession" WHERE "tenantId" = ? AND "tokenHash" = ?', [$tenantId, hash('sha256', $token)]);
}

function chat_add(array $session, string $sender, ?string $authorId, string $body): array
{
    $m = ['id' => cuid(), 'sessionId' => $session['id'], 'sender' => $sender, 'authorId' => $authorId, 'body' => mb_substr(trim($body), 0, 4000), 'createdAt' => now_iso()];
    db_insert('ChatMessage', $m);
    $upd = $sender === 'visitor' ? ['lastVisitorAt' => $m['createdAt']] : ($sender === 'agent' ? ['lastAgentAt' => $m['createdAt']] : []);
    if ($upd) db_update('ChatSession', $upd, '"id" = ?', [$session['id']]);
    return $m;
}

/** Messages after a moment, for the visitor or the agent screen. */
function chat_messages(string $sessionId, string $after = ''): array
{
    $rows = q_all('SELECT m."id", m."sender", m."body", m."createdAt", u."name" AS "agentName" FROM "ChatMessage" m LEFT JOIN "User" u ON u."id" = m."authorId"
                   WHERE m."sessionId" = ?' . ($after !== '' ? ' AND m."createdAt" > ?' : '') . ' ORDER BY m."createdAt" ASC LIMIT 300', $after !== '' ? [$sessionId, $after] : [$sessionId]);
    return array_map(fn ($r) => ['id' => $r['id'], 'sender' => $r['sender'], 'name' => $r['sender'] === 'agent' ? first_name((string) $r['agentName']) : null, 'body' => $r['body'], 'at' => $r['createdAt']], $rows);
}

/** Start a chat (visitor). Returns [session, token] or an error string. */
function chat_start(array $tenant, string $name, string $email, string $message, string $page): array|string
{
    $tenantId = $tenant['id'];
    $token = bin2hex(random_bytes(24));
    $now = now_iso();
    $s = [
        'id' => cuid(), 'tenantId' => $tenantId, 'tokenHash' => hash('sha256', $token), 'visitorName' => mb_substr($name, 0, 120), 'visitorEmail' => strtolower($email),
        'status' => 'open', 'agentId' => null, 'ticketId' => null, 'pageUrl' => mb_substr($page, 0, 500) ?: null,
        'lastVisitorAt' => $now, 'lastAgentAt' => null, 'agentSeenAt' => null, 'createdAt' => $now, 'closedAt' => null,
    ];
    db_insert('ChatSession', $s);
    chat_add($s, 'system', null, chat_settings($tenantId)['greeting']);
    chat_add($s, 'visitor', null, $message);
    // Tell the agents who answer chats (in-app; the live stream shows it as a toast).
    foreach (q_all('SELECT * FROM "User" WHERE "tenantId" = ? AND "active" = 1 AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\')', [$tenantId]) as $u) {
        if (!can($u, 'chat.answer')) continue;
        db_insert('Notification', ['id' => cuid(), 'type' => 'CHAT', 'message' => 'New chat from ' . $s['visitorName'] . ': ' . mb_substr($message, 0, 120), 'read' => 0, 'createdAt' => $now, 'tenantId' => $tenantId, 'userId' => $u['id'], 'ticketId' => null]);
    }
    return [$s, $token];
}

/** The chat as HTML for a ticket. */
function chat_transcript_html(array $session, string $after = ''): string
{
    $h = '';
    foreach (chat_messages($session['id'], $after) as $m) {
        if ($m['sender'] === 'system') continue;
        $who = $m['sender'] === 'visitor' ? $session['visitorName'] : ($m['name'] ?: 'Agent');
        $h .= '<p><strong>' . e($who) . '</strong> <span style="color:#94a3b8">' . e(date('H:i', (int) iso_sec($m['at']))) . '</span><br>' . nl2br(e($m['body']), false) . '</p>';
    }
    return $h;
}

/**
 * Turn a chat into a ticket (or add the newest messages to its ticket).
 * $close: also close the ticket when the chat ended answered.
 */
function chat_to_ticket(array $session, ?array $agent, bool $close = false): ?array
{
    $tenantId = $session['tenantId'];
    if ($session['ticketId'] && ($t = find_ticket($session['ticketId'], $tenantId))) {
        $since = (string) q_val('SELECT MAX("createdAt") FROM "TicketEvent" WHERE "ticketId" = ? AND "type" = ?', [$t['id'], 'CHAT_SYNC']) ?: $session['createdAt'];
        $html = chat_transcript_html($session, $since);
        if ($html !== '') {
            db_insert('Comment', ['id' => cuid(), 'body' => '<p><em>Chat messages</em></p>' . $html, 'isInternal' => 1, 'createdAt' => now_iso(), 'tenantId' => $tenantId, 'ticketId' => $t['id'], 'authorId' => $agent['id'] ?? $t['requesterId']]);
            log_event($tenantId, $t['id'], $agent['id'] ?? null, 'CHAT_SYNC', 'added the latest chat messages');
        }
        return $t;
    }
    $customer = find_or_create_customer($tenantId, $session['visitorEmail'], $session['visitorName']);
    if (is_string($customer)) return null;
    $first = q_val('SELECT "body" FROM "ChatMessage" WHERE "sessionId" = ? AND "sender" = ? ORDER BY "createdAt" ASC LIMIT 1', [$session['id'], 'visitor']);
    $subject = 'Chat: ' . mb_substr(preg_replace('/\s+/', ' ', (string) $first), 0, 80);
    $t = create_ticket($tenantId, $customer, [
        'subject' => $subject, 'description' => '<p><em>Conversation from the website chat' . ($session['pageUrl'] ? ' (page: ' . e($session['pageUrl']) . ')' : '') . '</em></p>' . chat_transcript_html($session),
        'source' => 'chat', 'skipNotify' => $close,
    ], $customer);
    if (is_string($t)) return null;
    db_update('ChatSession', ['ticketId' => $t['id']], '"id" = ?', [$session['id']]);
    log_event($tenantId, $t['id'], $agent['id'] ?? null, 'CHAT_SYNC', 'created from a live chat');
    if ($agent || $session['agentId']) {
        $aid = $agent['id'] ?? $session['agentId'];
        db_update('Ticket', ['assigneeId' => $aid, 'firstResponseAt' => $session['lastAgentAt'] ?: null], '"id" = ?', [$t['id']]);
    }
    if ($close) {
        foreach (tenant_statuses($tenantId) as $st) if ($st['isClosed']) { db_update('Ticket', ['status' => $st['key'], 'closedAt' => now_iso()], '"id" = ?', [$t['id']]); break; }
    }
    return find_ticket($t['id'], $tenantId);
}

function chat_close(array $session, ?array $agent, string $by): void
{
    if ($session['status'] === 'closed') return;
    chat_add($session, 'system', null, $by === 'visitor' ? 'The visitor left the chat.' : ($by === 'idle' ? 'The chat ended after a while without messages.' : 'The chat was closed. Thanks for talking to us!'));
    db_update('ChatSession', ['status' => 'closed', 'closedAt' => now_iso()], '"id" = ?', [$session['id']]);
    $answered = (bool) $session['lastAgentAt'];
    // Keep a record: answered chats become a closed ticket; unanswered ones an open ticket.
    chat_to_ticket(q_one('SELECT * FROM "ChatSession" WHERE "id" = ?', [$session['id']]), $agent, $answered && !$session['ticketId']);
}

/** Cron + each poll: unanswered chats become tickets; idle chats are closed. */
function chat_maintenance(?string $tenantId = null): int
{
    $n = 0;
    $rows = q_all('SELECT * FROM "ChatSession" WHERE "status" = ?' . ($tenantId ? ' AND "tenantId" = ?' : '') . ' LIMIT 200', $tenantId ? ['open', $tenantId] : ['open']);
    foreach ($rows as $s) {
        $cfg = chat_settings($s['tenantId']);
        $last = max((string) $s['lastVisitorAt'], (string) $s['lastAgentAt'], $s['createdAt']);
        if ($last < now_iso(-30 * 60)) { chat_close($s, null, 'idle'); $n++; continue; }
        if (!$s['ticketId'] && !$s['lastAgentAt'] && $s['createdAt'] < now_iso(-$cfg['ticketAfter'] * 60)) {
            $t = chat_to_ticket($s, null);
            if ($t) chat_add($s, 'system', null, 'Our team is busy right now — we opened ticket #' . (int) $t['number'] . ' and will reply to ' . $s['visitorEmail'] . '. You can keep writing here.');
            $n++;
        }
    }
    return $n;
}

/** Chats waiting for an agent (for the menu badge). */
function chat_waiting_count(string $tenantId): int
{
    return (int) q_val('SELECT COUNT(*) FROM "ChatSession" WHERE "tenantId" = ? AND "status" = ? AND ("lastAgentAt" IS NULL OR "lastVisitorAt" > "lastAgentAt")', [$tenantId, 'open']);
}
