<?php
declare(strict_types=1);

/* Macros, merging / linking tickets, knowledge base. */

/* ---------- Macros ---------- */

const MACRO_PLACEHOLDERS = [
    '{{requester.name}}' => 'Requester full name',
    '{{requester.first_name}}' => 'Requester first name',
    '{{agent.name}}' => 'Your name',
    '{{ticket.number}}' => 'Ticket number',
    '{{ticket.subject}}' => 'Ticket subject',
];

function act_macro_create(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.macros');
    $name = mb_substr(str_in($in, 'name'), 0, 60);
    if (mb_strlen($name) < 2) return ['error' => 'Macro name is too short'];
    $body = sanitize_rich_html((string) ($in['body'] ?? ''));
    if (html_to_text($body) === '' && !str_contains($body, '<img')) $body = '';

    $statuses = tenant_statuses($tenantId);
    $priorities = tenant_priorities($tenantId);
    $setStatus = str_in($in, 'setStatus');
    $setPriority = str_in($in, 'setPriority');
    $setAssignee = str_in($in, 'setAssignee');
    $addTagId = str_in($in, 'addTagId');
    if ($setStatus && !in_array($setStatus, array_column($statuses, 'key'), true)) return ['error' => 'Invalid status'];
    if ($setPriority && !priority_exists($priorities, $setPriority)) return ['error' => 'Invalid priority'];
    if ($setAssignee && !in_array($setAssignee, ['me', 'none'], true)
        && !q_val('SELECT 1 FROM "User" WHERE "id" = ? AND "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\')', [$setAssignee, $tenantId])) {
        return ['error' => 'Invalid assignee'];
    }
    if ($addTagId && !q_val('SELECT 1 FROM "Tag" WHERE "id" = ? AND "tenantId" = ?', [$addTagId, $tenantId])) return ['error' => 'Invalid tag'];
    if ($body === '' && !$setStatus && !$setPriority && !$setAssignee && !$addTagId) {
        return ['error' => 'A macro needs a reply or at least one change'];
    }

    db_insert('Macro', [
        'id' => cuid(), 'tenantId' => $tenantId, 'name' => $name, 'body' => $body ?: null,
        'isInternal' => ($in['isInternal'] ?? '') === 'on' ? 1 : 0,
        'setStatus' => $setStatus ?: null, 'setPriority' => $setPriority ?: null,
        'setAssignee' => $setAssignee ?: null, 'addTagId' => $addTagId ?: null, 'createdAt' => now_iso(),
    ]);
    return ['success' => "Added macro \"$name\""];
}

function act_macro_delete(array $in): ?array
{
    [, $tenantId] = require_tenant_admin('settings.macros');
    q('DELETE FROM "Macro" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return ['ok' => true];
}

function macro_fill(string $html, array $ticket, array $user): string
{
    // Same placeholders as canned replies ({customer.name}, {ticket.number} …) plus the older {{…}} ones.
    return fill_reply_vars($html, reply_vars($ticket, $user, $ticket['tenantId']));
}

/** Apply a macro: optional reply/note, then field changes — all through the normal paths. */
function act_macro_apply(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!is_staff($user['role']) || !can($user, 'macros.use')) return ['error' => 'You don’t have permission to use macros'];
    $ticket = find_ticket(str_in($in, 'ticketId'), $tenantId);
    $macro = q_one('SELECT * FROM "Macro" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'macroId'), $tenantId]);
    if (!$ticket || !$macro) return ['error' => 'Not found'];

    if ($macro['body'] && !$macro['isInternal'] && staff_lacks($user, 'tickets.reply')) return ['error' => 'This macro sends a reply, and your role can’t reply to customers'];
    if ($macro['body'] && $macro['isInternal'] && staff_lacks($user, 'tickets.internal')) return ['error' => 'This macro adds an internal note, and your role can’t write them'];
    if ($macro['body']) {
        add_comment($ticket, $user, $tenantId, sanitize_rich_html(macro_fill($macro['body'], $ticket, $user)), (bool) $macro['isInternal']);
    }
    $changes = [];
    if ($macro['setStatus']) $changes['status'] = $macro['setStatus'];
    if ($macro['setPriority']) $changes['priority'] = $macro['setPriority'];
    if ($macro['setAssignee']) {
        $changes['assigneeId'] = match ($macro['setAssignee']) {
            'me' => $user['role'] === 'SUPER_ADMIN' && $user['tenantId'] !== $ticket['tenantId'] ? $ticket['assigneeId'] ?? '' : $user['id'],
            'none' => '',
            default => $macro['setAssignee'],
        };
    }
    if ($changes) update_ticket($ticket['id'], $changes, $user, $tenantId);
    if ($macro['addTagId'] && q_val('SELECT 1 FROM "Tag" WHERE "id" = ? AND "tenantId" = ?', [$macro['addTagId'], $tenantId])
        && !q_val('SELECT 1 FROM "TicketTag" WHERE "ticketId" = ? AND "tagId" = ?', [$ticket['id'], $macro['addTagId']])) {
        db_insert('TicketTag', ['ticketId' => $ticket['id'], 'tagId' => $macro['addTagId']]);
        reindex_ticket($ticket['id']);
    }
    log_event($tenantId, $ticket['id'], $user['id'], 'MACRO', "applied macro \"{$macro['name']}\"");
    return ['ok' => true];
}

/* ---------- Merge & link ---------- */

/** Find a ticket of the tenant by "#12", "12" or id. */
function find_ticket_ref(string $ref, string $tenantId): ?array
{
    $ref = trim($ref);
    if (preg_match('/^#?(\d{1,9})$/', $ref, $m)) {
        return q_one('SELECT * FROM "Ticket" WHERE "tenantId" = ? AND "number" = ?', [$tenantId, (int) $m[1]]);
    }
    return find_ticket($ref, $tenantId);
}

/**
 * Merge the source ticket into a target: its description, replies, attachments,
 * team and tags move to the target; the source is closed and points to it.
 */
function act_ticket_merge(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!is_staff($user['role']) || !can($user, 'tickets.merge')) return ['error' => 'You don’t have permission to merge tickets'];
    $src = find_ticket(str_in($in, 'ticketId'), $tenantId);
    $dst = find_ticket_ref(str_in($in, 'target'), $tenantId);
    if (!$src) return ['error' => 'Ticket not found'];
    if (!$dst) return ['error' => 'Target ticket not found — use its number, e.g. #42'];
    if ($dst['id'] === $src['id']) return ['error' => 'A ticket cannot be merged into itself'];
    if ($src['mergedIntoId']) return ['error' => 'This ticket was already merged'];
    if ($dst['mergedIntoId']) return ['error' => "Ticket #{$dst['number']} was itself merged — pick the ticket it was merged into"];

    $statuses = tenant_statuses($tenantId);
    $closedKey = closed_status_keys($statuses)[0] ?? $src['status'];
    db_tx(function () use ($src, $dst, $user, $tenantId, $closedKey) {
        // The original request becomes a reply on the target, at its original time.
        db_insert('Comment', [
            'id' => cuid(), 'tenantId' => $tenantId, 'ticketId' => $dst['id'], 'authorId' => $src['requesterId'], 'isInternal' => 0,
            'createdAt' => $src['createdAt'],
            'body' => '<p><strong>Merged from #' . (int) $src['number'] . ': ' . e($src['subject']) . '</strong></p>' . $src['description'],
        ]);
        q('UPDATE "Comment" SET "ticketId" = ? WHERE "ticketId" = ?', [$dst['id'], $src['id']]);
        q('UPDATE "Attachment" SET "ticketId" = ? WHERE "ticketId" = ?', [$dst['id'], $src['id']]);

        // Team: everyone on the source (and its requester) can follow the target.
        $people = q_all('SELECT "userId", "role" FROM "TicketWatcher" WHERE "ticketId" = ?', [$src['id']]);
        $people[] = ['userId' => $src['requesterId'], 'role' => 'VIEWER'];
        foreach ($people as $p) {
            if ($p['userId'] === $dst['requesterId'] || watcher_of($dst['id'], $p['userId'])) continue;
            db_insert('TicketWatcher', ['ticketId' => $dst['id'], 'userId' => $p['userId'], 'role' => $p['role'], 'createdAt' => now_iso()]);
        }
        foreach (pluck(q_all('SELECT "tagId" FROM "TicketTag" WHERE "ticketId" = ?', [$src['id']]), 'tagId') as $tagId) {
            if (!q_val('SELECT 1 FROM "TicketTag" WHERE "ticketId" = ? AND "tagId" = ?', [$dst['id'], $tagId])) {
                db_insert('TicketTag', ['ticketId' => $dst['id'], 'tagId' => $tagId]);
            }
        }
        db_update('Ticket', ['status' => $closedKey, 'closedAt' => now_iso(), 'mergedIntoId' => $dst['id'], 'updatedAt' => now_iso()], '"id" = ?', [$src['id']]);
        db_update('Ticket', ['updatedAt' => now_iso()], '"id" = ?', [$dst['id']]);
        log_event($tenantId, $src['id'], $user['id'], 'MERGED', 'merged this ticket into #' . (int) $dst['number']);
        log_event($tenantId, $dst['id'], $user['id'], 'MERGED', 'merged ticket #' . (int) $src['number'] . ' into this one');
    });
    reindex_ticket($dst['id']);
    reindex_ticket($src['id']);

    dispatch([
        'tenantId' => $tenantId, 'recipients' => [$src['requesterId']], 'category' => 'status', 'ticket' => ticket_ref($dst), 'actor' => $user,
        'inAppType' => 'STATUS_CHANGED',
        'inAppMessage' => "Ticket #{$src['number']} was merged into #{$dst['number']}",
        'pushBody' => "Your request continues in ticket #{$dst['number']}",
        'emailLine' => "Your ticket #{$src['number']} was merged into #{$dst['number']}: {$dst['subject']}. Replies continue there.",
    ]);
    redirect('/tickets/' . $dst['id']);
}

function act_ticket_link(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $a = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$a || !can_manage_ticket($a, $user)) return ['error' => 'Not allowed'];
    // Linking reveals other tickets, so it is a staff permission.
    if (!is_staff($user['role']) || !can($user, 'tickets.link')) return ['error' => 'You don’t have permission to link tickets'];
    $b = find_ticket_ref(str_in($in, 'target'), $tenantId);
    if (!$b) return ['error' => 'Ticket not found — use its number, e.g. #42'];
    if ($b['id'] === $a['id']) return ['error' => 'A ticket cannot be linked to itself'];
    [$x, $y] = strcmp($a['id'], $b['id']) < 0 ? [$a['id'], $b['id']] : [$b['id'], $a['id']];
    if (!q_val('SELECT 1 FROM "TicketLink" WHERE "ticketId" = ? AND "linkedId" = ?', [$x, $y])) {
        db_insert('TicketLink', ['id' => cuid(), 'tenantId' => $tenantId, 'ticketId' => $x, 'linkedId' => $y, 'createdAt' => now_iso()]);
        log_event($tenantId, $a['id'], $user['id'], 'LINKED', 'linked ticket #' . (int) $b['number']);
        log_event($tenantId, $b['id'], $user['id'], 'LINKED', 'linked ticket #' . (int) $a['number']);
    }
    return ['ok' => true];
}

function act_ticket_unlink(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    $a = find_ticket(str_in($in, 'ticketId'), $tenantId);
    if (!$a || !can_manage_ticket($a, $user)) return ['error' => 'Not allowed'];
    // Linking reveals other tickets, so it is a staff permission.
    if (!is_staff($user['role']) || !can($user, 'tickets.link')) return ['error' => 'You don’t have permission to link tickets'];
    $other = str_in($in, 'otherId');
    q('DELETE FROM "TicketLink" WHERE "tenantId" = ? AND (("ticketId" = ? AND "linkedId" = ?) OR ("ticketId" = ? AND "linkedId" = ?))', [$tenantId, $a['id'], $other, $other, $a['id']]);
    return ['ok' => true];
}

/** Tickets linked to a ticket (either direction). */
function ticket_links(string $ticketId, array $statuses): array
{
    $rows = q_all('SELECT t."id", t."number", t."subject", t."status" FROM "TicketLink" l
        JOIN "Ticket" t ON t."id" = CASE WHEN l."ticketId" = ? THEN l."linkedId" ELSE l."ticketId" END
        WHERE l."ticketId" = ? OR l."linkedId" = ? ORDER BY t."number" ASC', [$ticketId, $ticketId, $ticketId]);
    foreach ($rows as &$r) $r['statusDef'] = resolve_status($statuses, $r['status']);
    return $rows;
}

/* ---------- Knowledge base ---------- */

function kb_search_text(string $title, string $section, string $body): string
{
    return mb_substr(search_norm($title . ' · ' . $section . ' · ' . html_to_text($body)), 0, SEARCH_TEXT_MAX);
}

function act_kb_save(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'kb.edit')) return ['error' => 'You don’t have permission to edit articles'];
    $id = str_in($in, 'id');
    $title = mb_substr(str_in($in, 'title'), 0, 160);
    $section = mb_substr(str_in($in, 'section'), 0, 60);
    $body = sanitize_rich_html((string) ($in['body'] ?? ''));
    if (mb_strlen($title) < 3) return ['error' => 'Title must be at least 3 characters'];
    if (mb_strlen(html_to_text($body)) < 10) return ['error' => 'Write the article (at least a sentence)'];
    $data = [
        'title' => $title, 'section' => $section ?: null, 'body' => $body,
        'published' => ($in['published'] ?? '') === 'on' ? 1 : 0,
        'searchText' => kb_search_text($title, $section, $body), 'updatedAt' => now_iso(),
    ];
    // Publishing (or unpublishing) needs its own permission; otherwise the article keeps its state (new ones stay drafts).
    $current = $id ? q_one('SELECT "published" FROM "KbArticle" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]) : null;
    if (!can($user, 'kb.publish')) $data['published'] = (int) ($current['published'] ?? 0);
    if ($id) {
        if (!$current) return ['error' => 'Article not found'];
        db_update('KbArticle', $data, '"id" = ? AND "tenantId" = ?', [$id, $tenantId]);
    } else {
        $id = cuid();
        db_insert('KbArticle', ['id' => $id, 'tenantId' => $tenantId, 'authorId' => $user['id'], 'createdAt' => now_iso()] + $data);
    }
    redirect('/kb/' . $id);
}

function act_kb_delete(array $in): ?array
{
    [$user, $tenantId] = require_tenant_user();
    if (!can($user, 'kb.delete')) return ['error' => 'You don’t have permission to delete articles'];
    q('DELETE FROM "KbArticle" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    redirect('/kb');
}

function act_kb_helpful(array $in): ?array
{
    [, $tenantId] = require_tenant_user();
    q('UPDATE "KbArticle" SET "helpful" = "helpful" + 1 WHERE "id" = ? AND "tenantId" = ? AND "published" = 1', [str_in($in, 'id'), $tenantId]);
    return ['success' => 'Thanks for the feedback!'];
}

/** Published articles matching free text (for /kb search and ticket suggestions). */
function kb_find(string $tenantId, string $q, bool $includeDrafts, int $limit = 50): array
{
    $where = ['"tenantId" = ?'];
    $params = [$tenantId];
    if (!$includeDrafts) $where[] = '"published" = 1';
    // Common words carry no meaning for matching ("I cannot reset my password" → reset, password).
    static $stop = ['the', 'and', 'for', 'you', 'your', 'can', 'cannot', 'cant', 'not', 'how', 'what', 'why', 'with', 'this', 'that', 'are', 'was', 'have',
        'has', 'from', 'when', 'does', 'doesnt', 'dont', 'get', 'got', 'my', 'me', 'is', 'it', 'to', 'of', 'in', 'on', 'an', 'or', 'do', 'i', 'a', 'we', 'our',
        'help', 'please', 'issue', 'problem', 'το', 'τα', 'της', 'του', 'των', 'και', 'να', 'δεν', 'μου', 'με', 'για', 'σε', 'στο', 'στη', 'στην', 'θα', 'πως',
        'πώς', 'τι', 'ειναι', 'είναι', 'μπορω', 'μπορώ', 'ενα', 'ένα', 'μια', 'μία', 'απο', 'από'];
    $terms = array_values(array_filter(array_map('search_norm', preg_split('/[\s,.;:!?()"\']+/u', trim($q)) ?: []),
        fn ($t) => mb_strlen($t) >= 3 && !in_array($t, $stop, true)));
    if ($terms) {
        // Any term matches; rows are ranked by how many terms (and title hits) they contain.
        $ors = [];
        $score = [];
        foreach (array_slice($terms, 0, 8) as $t) {
            $ors[] = "\"searchText\" LIKE ? ESCAPE '!'";
            $params[] = like_arg($t);
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
        $rows = q_all('SELECT "id","title","section","body","views","helpful","published","updatedAt","searchText" FROM "KbArticle" WHERE ' . implode(' AND ', $where) . ' LIMIT 200', $params);
        foreach ($rows as &$r) {
            $s = 0;
            $title = search_norm($r['title']);
            foreach ($terms as $t) {
                if (str_contains($r['searchText'], $t)) $s += 1;
                if (str_contains($title, $t)) $s += 2;
            }
            $r['score'] = $s;
        }
        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score'] ?: $b['helpful'] <=> $a['helpful']);
        return array_slice($rows, 0, $limit);
    }
    return q_all('SELECT "id","title","section","body","views","helpful","published","updatedAt" FROM "KbArticle" WHERE ' . implode(' AND ', $where) . ' ORDER BY "section" ASC, "title" ASC LIMIT ' . $limit, $params);
}
