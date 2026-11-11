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

/** Agents a signed-in customer can pick in the chat: active staff who answer chats. */
function chat_agents(string $tenantId): array
{
    $out = [];
    foreach (q_all('SELECT * FROM "User" WHERE "tenantId" = ? AND "active" = 1 AND "role" IN (\'ADMIN\',\'AGENT\') ORDER BY "name"', [$tenantId]) as $u) {
        if (can($u, 'chat.answer')) $out[] = ['id' => $u['id'], 'name' => $u['name']];
    }
    return $out;
}

function chat_session_by_token(string $tenantId, string $token): ?array
{
    if (strlen($token) < 20) return null;
    return q_one('SELECT * FROM "ChatSession" WHERE "tenantId" = ? AND "tokenHash" = ?', [$tenantId, hash('sha256', $token)]);
}

function chat_add(array $session, string $sender, ?string $authorId, string $body, ?string $attachmentId = null): array
{
    $m = ['id' => cuid(), 'sessionId' => $session['id'], 'sender' => $sender, 'authorId' => $authorId, 'body' => mb_substr(trim($body), 0, 4000), 'createdAt' => now_iso()];
    if ($attachmentId) $m['attachmentId'] = $attachmentId;
    db_insert('ChatMessage', $m);
    $upd = $sender === 'visitor' ? ['lastVisitorAt' => $m['createdAt']] : ($sender === 'agent' ? ['lastAgentAt' => $m['createdAt']] : []);
    if ($upd) db_update('ChatSession', $upd, '"id" = ?', [$session['id']]);
    return $m;
}

/** Messages after a moment, for the visitor or the agent screen. */
function chat_messages(string $sessionId, string $after = ''): array
{
    $rows = q_all('SELECT m."id", m."sender", m."body", m."createdAt", m."authorId", m."attachmentId", u."name" AS "agentName", a."filename", a."size", a."mime"
                   FROM "ChatMessage" m LEFT JOIN "User" u ON u."id" = m."authorId" LEFT JOIN "Attachment" a ON a."id" = m."attachmentId"
                   WHERE m."sessionId" = ?' . ($after !== '' ? ' AND m."createdAt" > ?' : '') . ' ORDER BY m."createdAt" ASC LIMIT 300', $after !== '' ? [$sessionId, $after] : [$sessionId]);
    return array_map(fn ($r) => ['id' => $r['id'], 'sender' => $r['sender'], 'authorId' => $r['authorId'], 'name' => $r['sender'] === 'agent' ? first_name((string) $r['agentName']) : null, 'body' => $r['body'], 'at' => $r['createdAt'],
        'file' => $r['attachmentId'] && $r['filename'] !== null ? ['id' => $r['attachmentId'], 'name' => $r['filename'], 'size' => (int) $r['size'], 'mime' => $r['mime'], 'image' => in_array($r['mime'], IMAGE_MIME, true)] : null], $rows);
}

/** Start a chat (visitor). Returns [session, token] or an error string. */
function chat_start(array $tenant, string $name, string $email, string $message, string $page, ?string $requestedAgentId = null): array|string
{
    $tenantId = $tenant['id'];
    $token = bin2hex(random_bytes(24));
    $now = now_iso();
    $s = [
        'id' => cuid(), 'tenantId' => $tenantId, 'tokenHash' => hash('sha256', $token), 'visitorName' => mb_substr($name, 0, 120), 'visitorEmail' => strtolower($email),
        'status' => 'open', 'agentId' => null, 'requestedAgentId' => $requestedAgentId, 'ticketId' => null, 'pageUrl' => mb_substr($page, 0, 500) ?: null,
        'lastVisitorAt' => $now, 'lastAgentAt' => null, 'agentSeenAt' => null, 'createdAt' => $now, 'closedAt' => null,
    ];
    db_insert('ChatSession', $s);
    chat_add($s, 'system', null, chat_settings($tenantId)['greeting']);
    $wanted = $requestedAgentId ? q_one('SELECT "name" FROM "User" WHERE "id" = ?', [$requestedAgentId]) : null;
    if ($wanted) chat_add($s, 'system', null, 'You asked to talk to ' . first_name($wanted['name']) . '. They’ll be with you shortly.');
    chat_add($s, 'visitor', null, $message);
    // Tell the agents who answer chats (in-app; the live stream shows it as a toast).
    // A chat for one agent goes to that agent only; the others still see it on the Live chat page.
    foreach (q_all('SELECT * FROM "User" WHERE "active" = 1 AND (("tenantId" = ? AND "role" IN (\'ADMIN\',\'AGENT\')) OR "role" = \'SUPER_ADMIN\')', [$tenantId]) as $u) {
        if (!can($u, 'chat.answer') || ($wanted && $u['id'] !== $requestedAgentId)) continue;
        if ($u['role'] === 'SUPER_ADMIN' && $u['id'] !== $requestedAgentId) continue;
        db_insert('Notification', ['id' => cuid(), 'type' => 'CHAT', 'message' => ($wanted ? $s['visitorName'] . ' wants to chat with you: ' : 'New chat from ' . $s['visitorName'] . ': ') . mb_substr($message, 0, 120), 'read' => 0, 'createdAt' => $now, 'tenantId' => $tenantId, 'userId' => $u['id'], 'ticketId' => null]);
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
        $text = $m['file'] ? '📎 <a href="/api/attachments/' . e($m['file']['id']) . '">' . e($m['file']['name']) . '</a>' : nl2br(e($m['body']), false);
        $h .= '<p><strong>' . e($who) . '</strong> <span style="color:#94a3b8">' . e(date('H:i', (int) iso_sec($m['at']))) . '</span><br>' . $text . '</p>';
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
    if (($session['kind'] ?? null) === 'peer') return null; // conversations between colleagues stay private
    if ($session['ticketId'] && ($t = find_ticket($session['ticketId'], $tenantId))) {
        db_update('Attachment', ['ticketId' => $t['id']], '"chatSessionId" = ? AND "ticketId" IS NULL', [$session['id']]);
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
    db_update('Attachment', ['ticketId' => $t['id']], '"chatSessionId" = ? AND "ticketId" IS NULL', [$session['id']]);
    log_event($tenantId, $t['id'], $agent['id'] ?? null, 'CHAT_SYNC', 'created from a live chat');
    if ($agent || $session['agentId'] || !empty($session['requestedAgentId'])) {
        $aid = $agent['id'] ?? $session['agentId'] ?? $session['requestedAgentId'];
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
    if (($session['kind'] ?? null) === 'peer') return;
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
        if (($s['kind'] ?? null) !== 'peer' && !$s['ticketId'] && !$s['lastAgentAt'] && $s['createdAt'] < now_iso(-$cfg['ticketAfter'] * 60)) {
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
    return (int) q_val('SELECT COUNT(*) FROM "ChatSession" WHERE "tenantId" = ? AND "status" = ? AND ("kind" IS NULL OR "kind" <> ?) AND ("lastAgentAt" IS NULL OR "lastVisitorAt" > "lastAgentAt")', [$tenantId, 'open', 'peer']);
}

/**
 * People a signed-in customer can chat with, in groups:
 * their organization's support team, the administrators and their colleagues.
 */
function chat_targets(string $tenantId, array $customer): array
{
    $orgs = q_all('SELECT o."id", o."name" FROM "Organization" o JOIN "UserOrganization" uo ON uo."organizationId" = o."id" WHERE uo."userId" = ? AND o."tenantId" = ? ORDER BY o."name"', [$customer['id'], $tenantId]);
    $orgIds = array_column($orgs, 'id');
    $in = $orgIds ? implode(',', array_fill(0, count($orgIds), '?')) : '';
    $person = fn (array $u, string $role) => ['id' => $u['id'], 'name' => $u['name'], 'role' => $role, 'color' => $u['avatarColor'] ?? null];
    $seen = [$customer['id'] => true];
    $team = [];
    if ($in) {
        foreach (q_all('SELECT DISTINCT u.* FROM "User" u JOIN "UserOrganization" uo ON uo."userId" = u."id" WHERE u."tenantId" = ? AND u."active" = 1 AND u."role" IN (\'ADMIN\',\'AGENT\') AND uo."organizationId" IN (' . $in . ') ORDER BY u."name"', array_merge([$tenantId], $orgIds)) as $u) {
            if (!can($u, 'chat.answer') || isset($seen[$u['id']])) continue;
            $seen[$u['id']] = true;
            $team[] = $person($u, $u['role'] === 'ADMIN' ? 'Admin' : 'Agent');
        }
    }
    $admins = [];
    foreach (q_all('SELECT * FROM "User" WHERE "active" = 1 AND (("tenantId" = ? AND "role" = \'ADMIN\') OR "role" = \'SUPER_ADMIN\') ORDER BY "role" DESC, "name"', [$tenantId]) as $u) {
        if (isset($seen[$u['id']])) continue;
        $seen[$u['id']] = true;
        $admins[] = $person($u, $u['role'] === 'SUPER_ADMIN' ? 'Super admin' : 'Admin');
    }
    $colleagues = [];
    if ($in) {
        foreach (q_all('SELECT DISTINCT u.* FROM "User" u JOIN "UserOrganization" uo ON uo."userId" = u."id" WHERE u."tenantId" = ? AND u."active" = 1 AND u."role" = \'CUSTOMER\' AND uo."organizationId" IN (' . $in . ') ORDER BY u."name"', array_merge([$tenantId], $orgIds)) as $u) {
            if (isset($seen[$u['id']])) continue;
            $seen[$u['id']] = true;
            $colleagues[] = $person($u, 'Colleague');
        }
    }
    $orgName = $orgs ? implode(', ', array_column($orgs, 'name')) : '';
    return array_values(array_filter([
        ['key' => 'team', 'label' => 'Your support team', 'people' => $team],
        ['key' => 'admins', 'label' => 'Administrators', 'people' => $admins],
        ['key' => 'colleagues', 'label' => 'Colleagues' . ($orgName ? ' at ' . $orgName : ''), 'people' => $colleagues],
    ], fn ($g) => $g['people']));
}

/** Find one person in chat_targets(); null when the customer may not chat with them. */
function chat_target(string $tenantId, array $customer, string $id): ?array
{
    foreach (chat_targets($tenantId, $customer) as $g) foreach ($g['people'] as $p) if ($p['id'] === $id) return $p + ['group' => $g['key']];
    return null;
}

/** Save one file sent in a chat. Returns the Attachment row or an error message. */
function chat_save_file(array $session, array $f, string $uploaderId): array|string
{
    $tenantId = $session['tenantId'];
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return 'Pick a file';
    if ($p = upload_problems([$f], $tenantId)) return $p[0];
    if (!file_ok($f)) return 'The file didn’t upload completely';
    $map = attachment_ext_map($tenantId);
    $id = uuid4();
    $path = attachment_disk_path($tenantId, $id);
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
    if (!move_uploaded_file($f['tmp_name'], $path)) return 'The file could not be saved';
    $row = [
        'id' => $id, 'filename' => mb_substr((string) $f['name'], 0, 200) ?: 'file', 'url' => "/api/attachments/$id", 'size' => (int) $f['size'],
        'mime' => $map[file_ext((string) $f['name'])], 'createdAt' => now_iso(), 'tenantId' => $tenantId, 'ticketId' => $session['ticketId'] ?: null,
        'commentId' => null, 'uploaderId' => $uploaderId, 'internal' => 0, 'chatSessionId' => $session['id'],
    ];
    db_insert('Attachment', $row);
    return $row;
}

/** May this signed-in person open files and messages of the chat? */
function chat_user_can_see(array $user, array $session): bool
{
    if ($session['visitorUserId'] && $session['visitorUserId'] === $user['id']) return true;
    if (($session['kind'] ?? null) === 'peer') return $session['requestedAgentId'] === $user['id'];
    return is_staff($user['role']) && can($user, 'chat.answer');
}
