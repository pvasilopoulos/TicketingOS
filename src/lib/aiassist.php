<?php
declare(strict_types=1);

/*
 * AI assistant for customers (Claude). It answers in the in-app Messages and,
 * if turned on, first in the website chat — about anything the customer asks,
 * using the workspace's own instructions, matching help-center articles and
 * (for signed-in customers) their recent tickets. The customer can ask for a
 * person at any time: the conversation then goes to the Live chat queue with the
 * whole history, like any other chat.
 */

function ai_assist_settings(string $tenantId): array
{
    $ai = ai_settings($tenantId);
    $s = tenant_setting($tenantId, 'aiAssist', []);
    $model = (string) ($s['model'] ?? '');
    return [
        'ready' => $ai['enabled'] && $ai['hasKey'],
        'enabled' => (bool) ($s['enabled'] ?? false),
        'inMessages' => (bool) ($s['inMessages'] ?? true),
        'onWebsite' => (bool) ($s['onWebsite'] ?? false),
        'name' => mb_substr(trim((string) ($s['name'] ?? '')), 0, 40) ?: 'AI assistant',
        'model' => isset(AI_MODELS[$model]) ? $model : '',
        'instructions' => (string) ($s['instructions'] ?? ''),
        'dailyLimit' => max(10, min(20000, (int) ($s['dailyLimit'] ?? 500))),
    ];
}

/** Is the assistant on for this place ('messages' in the app, 'website' chat)? */
function ai_assist_on(string $tenantId, string $where): bool
{
    $s = ai_assist_settings($tenantId);
    return $s['ready'] && $s['enabled'] && ($where === 'messages' ? $s['inMessages'] : $s['onWebsite']);
}

/** Help-center articles that match the question (published only). */
function ai_assist_articles(string $tenantId, string $question): array
{
    if (mb_strlen(trim($question)) < 3) return [];
    try { $rows = kb_find($tenantId, mb_substr($question, 0, 200), false, 3); } catch (Throwable) { return []; }
    return array_values(array_filter($rows, fn ($r) => ($r['score'] ?? 1) > 0 && (int) ($r['published'] ?? 1) === 1));
}

/** System prompt: who the assistant is, the workspace's own instructions and what it knows. */
function ai_assist_system(array $session, string $question): string
{
    $tenantId = $session['tenantId'];
    $cfg = ai_assist_settings($tenantId);
    $tenant = q_one('SELECT "name","slug" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    $inApp = !empty($session['visitorUserId']);
    $p = "You are {$cfg['name']}, the customer support assistant of “{$tenant['name']}”. You chat with a customer"
        . ($inApp ? ' inside the support app.' : ' on the company website.') . " Today is " . date('l j F Y') . ".\n\n"
        . "How to help:\n"
        . "- Help with whatever the customer asks: explain, troubleshoot step by step, answer general questions. Use your own knowledge; the help-center articles below are extra context, not a limit.\n"
        . "- Reply in the customer’s language (Greek if they write Greek). Be warm, short and clear: plain text, short paragraphs or numbered steps, no Markdown headings or tables.\n"
        . "- Never invent company facts (prices, contract terms, opening hours, ticket progress, promises). If you don’t know something about the company or their account, say so and suggest talking to a person.\n"
        . "- When the problem needs someone from the team (an account change, a refund, on-site work, something you can’t solve, or the customer asks for a human), say they can tap “Talk to a person”.\n"
        . "- Never ask for passwords, card numbers or other secrets.\n";
    if (trim($cfg['instructions']) !== '') $p .= "\nInstructions from the company (follow them):\n" . mb_substr(trim($cfg['instructions']), 0, 6000) . "\n";
    if ($inApp && ($u = q_one('SELECT "id","name","email" FROM "User" WHERE "id" = ?', [$session['visitorUserId']]))) {
        $orgs = array_column(q_all('SELECT o."name" FROM "Organization" o JOIN "UserOrganization" uo ON uo."organizationId" = o."id" WHERE uo."userId" = ?', [$u['id']]), 'name');
        $p .= "\nThe customer: {$u['name']}" . ($orgs ? ' (' . implode(', ', $orgs) . ')' : '') . ".\n";
        $statuses = tenant_statuses($tenantId);
        $tickets = q_all('SELECT "number","subject","status","updatedAt" FROM "Ticket" WHERE "tenantId" = ? AND "requesterId" = ? ORDER BY "updatedAt" DESC LIMIT 5', [$tenantId, $u['id']]);
        if ($tickets) {
            $p .= "Their latest tickets (you may mention them; you can’t change them):\n";
            foreach ($tickets as $t) $p .= "- #{$t['number']} “{$t['subject']}” — " . resolve_status($statuses, $t['status'])['label'] . ', updated ' . date('j M Y', (int) iso_sec($t['updatedAt'])) . "\n";
        }
    }
    $arts = ai_assist_articles($tenantId, $question);
    if ($arts) {
        $p .= "\nHelp-center articles that may be relevant (link them when useful):\n";
        foreach ($arts as $a) {
            $url = $inApp ? '/kb/' . $a['id'] : app_url() . '/support/' . $tenant['slug'] . '/kb';
            $p .= "### {$a['title']} ({$url})\n" . mb_substr(preg_replace('/\s+/', ' ', html_to_text((string) $a['body'])), 0, 1500) . "\n";
        }
    }
    return $p;
}

/** The chat so far as API messages (customer = user, assistant = assistant), alternating and starting with the customer. */
function ai_assist_messages(array $session): array
{
    $out = [];
    foreach (chat_messages($session['id']) as $m) {
        if ($m['sender'] === 'system') continue;
        $role = $m['sender'] === 'visitor' ? 'user' : 'assistant';
        $text = $m['file'] ? '[The customer sent a file: ' . $m['file']['name'] . ']' : $m['body'];
        if ($role === 'assistant' && $m['sender'] === 'agent') $text = '[' . ($m['name'] ?: 'Agent') . ' from the team] ' . $text;
        if ($out && $out[count($out) - 1]['role'] === $role) $out[count($out) - 1]['content'] .= "\n\n" . $text;
        else $out[] = ['role' => $role, 'content' => $text];
    }
    while ($out && $out[0]['role'] !== 'user') array_shift($out);
    return array_slice($out, -30);
}

/**
 * Answer the customer's latest message. Writes the reply (sender "bot") or a short
 * note when the assistant can't answer. Returns true when an answer was written.
 */
function ai_assist_reply(array $session): bool
{
    $tenantId = $session['tenantId'];
    $cfg = ai_assist_settings($tenantId);
    if (!rate_allow('aiassist:' . $tenantId, $cfg['dailyLimit'], 86400) || !rate_allow('aiassist-s:' . $session['id'], 40, 3600)) {
        chat_add($session, 'system', null, 'The assistant has answered a lot today. Tap “Talk to a person” and our team will help you.');
        return false;
    }
    $msgs = ai_assist_messages($session);
    if (!$msgs) return false;
    $last = '';
    for ($i = count($msgs) - 1; $i >= 0; $i--) if ($msgs[$i]['role'] === 'user') { $last = $msgs[$i]['content']; break; }
    db_update('ChatSession', ['agentTypingAt' => now_iso()], '"id" = ?', [$session['id']]);
    $r = ai_complete($tenantId, ai_assist_system($session, $last), '', 'low', 2000, null, $msgs, $cfg['model'] ?: null);
    if (!$r['ok']) {
        error_log('[ai-assist] ' . $r['error']);
        chat_add($session, 'system', null, 'The assistant can’t answer right now. Tap “Talk to a person” and our team will help you.');
        return false;
    }
    chat_add($session, 'bot', null, mb_substr($r['text'], 0, 4000));
    db_update('ChatSession', ['agentTypingAt' => null], '"id" = ?', [$session['id']]);
    return true;
}

/** Hand the conversation to the team: it joins the Live chat queue with its history. */
function ai_assist_handoff(array $session): void
{
    if (!(int) ($session['aiActive'] ?? 0) || $session['status'] !== 'open') return;
    $now = now_iso();
    db_update('ChatSession', ['aiActive' => 0, 'handoffAt' => $now, 'lastVisitorAt' => $now], '"id" = ?', [$session['id']]);
    chat_add($session, 'system', null, 'Connecting you with a person from our team. They’ll see this conversation.');
    $first = (string) q_val('SELECT "body" FROM "ChatMessage" WHERE "sessionId" = ? AND "sender" = ? ORDER BY "createdAt" DESC LIMIT 1', [$session['id'], 'visitor']);
    chat_notify_agents($session, $first, $session['requestedAgentId'] ?? null);
}
