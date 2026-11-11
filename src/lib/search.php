<?php
declare(strict_types=1);

/*
 * Smart ticket search.
 *
 * Each ticket keeps a denormalised, lower-cased "searchText" (subject,
 * description, public replies, requester, assignee, category, tags) so a search
 * is a single indexed lookup instead of joins across five tables. On MariaDB /
 * MySQL a FULLTEXT index answers word queries; SQLite (and very short words)
 * fall back to LIKE inside the tenant.
 *
 * Query language (combine freely, everything is AND-ed):
 *   printer jam          words anywhere            "paper jam"   exact phrase
 *   #42 / 42             ticket number             status:open   priority:high
 *   assignee:me|none|dana   requester:jamie        tag:bug       category:billing
 *   is:open|closed|overdue|unassigned|mine         created:today|7d|30d|2026-01-31
 */

const SEARCH_TEXT_MAX = 60000;

function search_norm(string $s): string
{
    $s = mb_strtolower(html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // Fold Greek accents so "επιλυση" matches "επίλυση" (and Latin diacritics).
    if (class_exists('Normalizer')) {
        $d = Normalizer::normalize($s, Normalizer::FORM_D);
        if ($d !== false) $s = preg_replace('/\p{Mn}+/u', '', $d);
    } else {
        $s = strtr($s, ['ά' => 'α', 'έ' => 'ε', 'ή' => 'η', 'ί' => 'ι', 'ϊ' => 'ι', 'ΐ' => 'ι', 'ό' => 'ο', 'ύ' => 'υ', 'ϋ' => 'υ', 'ΰ' => 'υ', 'ώ' => 'ω']);
    }
    return trim(preg_replace('/\s+/u', ' ', $s));
}

/** Rebuild one ticket's search document. */
function reindex_ticket(string $ticketId): void
{
    $t = q_one('SELECT t."subject", t."description", t."number", r."name" AS rn, r."email" AS re, a."name" AS an, c."name" AS cn
                FROM "Ticket" t JOIN "User" r ON r."id" = t."requesterId"
                LEFT JOIN "User" a ON a."id" = t."assigneeId" LEFT JOIN "Category" c ON c."id" = t."categoryId"
                WHERE t."id" = ?', [$ticketId]);
    if (!$t) return;
    $parts = [$t['subject'], html_to_text($t['description']), $t['rn'], $t['re'], $t['an'] ?? '', $t['cn'] ?? ''];
    foreach (q_all('SELECT g."name" FROM "TicketTag" tt JOIN "Tag" g ON g."id" = tt."tagId" WHERE tt."ticketId" = ?', [$ticketId]) as $g) $parts[] = $g['name'];
    foreach (q_all('SELECT v."value" FROM "TicketFieldValue" v JOIN "CustomField" f ON f."id" = v."fieldId" WHERE v."ticketId" = ? AND f."customerVisible" = 1 AND f."type" <> \'checkbox\'', [$ticketId]) as $fv) $parts[] = $fv['value'];
    // Internal notes are excluded: customers can search their own tickets.
    foreach (q_all('SELECT "body" FROM "Comment" WHERE "ticketId" = ? AND "isInternal" = 0 ORDER BY "createdAt" ASC', [$ticketId]) as $c) $parts[] = html_to_text($c['body']);
    $doc = mb_substr(search_norm(implode(' · ', array_filter($parts))), 0, SEARCH_TEXT_MAX);
    db_update('Ticket', ['searchText' => $doc], '"id" = ?', [$ticketId]);
}

/** Split a query into free-text terms and key:value filters. */
function parse_search(string $q): array
{
    $out = ['terms' => [], 'phrases' => [], 'number' => null, 'strict' => false, 'ops' => []];
    preg_match_all('/(\w+):"([^"]*)"|(\w+):(\S+)|"([^"]+)"|(\S+)/u', trim($q), $m, PREG_SET_ORDER);
    foreach ($m as $t) {
        if (($t[1] ?? '') !== '') { $out['ops'][] = [strtolower($t[1]), $t[2]]; continue; }
        if (($t[3] ?? '') !== '' && in_array(strtolower($t[3]), ['status', 'priority', 'assignee', 'requester', 'tag', 'category', 'is', 'created', 'group', 'org', 'source', 'rating'], true)) {
            $out['ops'][] = [strtolower($t[3]), $t[4]];
            continue;
        }
        // Possibly a custom field (key:value); decided in search_sql where the tenant's fields are known.
        if (($t[3] ?? '') !== '') {
            $out['ops'][] = ['?' . strtolower($t[3]), $t[4], $t[0]];
            continue;
        }
        if (($t[5] ?? '') !== '') { $out['phrases'][] = search_norm($t[5]); continue; }
        $word = $t[0];
        if (preg_match('/^#?(\d{1,9})$/', $word, $n) && $out['number'] === null) { $out['number'] = (int) $n[1]; $out['strict'] = $word[0] === '#'; continue; }
        $w = search_norm($word);
        if ($w !== '') $out['terms'][] = $w;
    }
    return $out;
}

function like_arg(string $s): string
{
    return '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $s) . '%';
}

/**
 * Append WHERE fragments for a search query.
 * @param array $ctx statuses, priorities, closedKeys, tenantId, user
 */
function search_sql(string $q, array $ctx, array &$where, array &$params): void
{
    $p = parse_search($q);
    $mysql = db_driver() === 'mysql';

    foreach ($p['ops'] as [$k, $v]) {
        $vn = search_norm($v);
        switch ($k) {
            case 'status':
            case 'priority':
                $set = $k === 'status' ? $ctx['statuses'] : $ctx['priorities'];
                $keys = [];
                foreach ($set as $o) {
                    if (search_norm($o['key']) === $vn || search_norm($o['label']) === $vn || str_starts_with(search_norm($o['label']), $vn)) $keys[] = $o['key'];
                }
                $where[] = 't."' . $k . '" IN (' . in_ph($keys) . ')';
                array_push($params, ...$keys);
                break;
            case 'assignee':
                if ($vn === 'me') { $where[] = 't."assigneeId" = ?'; $params[] = $ctx['user']['id']; }
                elseif ($vn === 'none' || $vn === 'nobody') { $where[] = 't."assigneeId" IS NULL'; }
                else { $where[] = "EXISTS (SELECT 1 FROM \"User\" ua WHERE ua.\"id\" = t.\"assigneeId\" AND LOWER(ua.\"name\") LIKE ? ESCAPE '!')"; $params[] = like_arg($vn); }
                break;
            case 'requester':
                if ($vn === 'me') { $where[] = 't."requesterId" = ?'; $params[] = $ctx['user']['id']; }
                else { $where[] = "EXISTS (SELECT 1 FROM \"User\" ur WHERE ur.\"id\" = t.\"requesterId\" AND (LOWER(ur.\"name\") LIKE ? ESCAPE '!' OR LOWER(ur.\"email\") LIKE ? ESCAPE '!'))"; array_push($params, like_arg($vn), like_arg($vn)); }
                break;
            case 'tag':
                $where[] = "EXISTS (SELECT 1 FROM \"TicketTag\" tt JOIN \"Tag\" g ON g.\"id\" = tt.\"tagId\" WHERE tt.\"ticketId\" = t.\"id\" AND LOWER(g.\"name\") = ?)";
                $params[] = ltrim($vn, '#');
                break;
            case 'category':
                $where[] = "EXISTS (SELECT 1 FROM \"Category\" cc WHERE cc.\"id\" = t.\"categoryId\" AND LOWER(cc.\"name\") LIKE ? ESCAPE '!')";
                $params[] = like_arg($vn);
                break;
            case 'is':
                $closed = $ctx['closedKeys'];
                if ($vn === 'open') { if ($closed) { $where[] = 't."status" NOT IN (' . in_ph($closed) . ')'; array_push($params, ...$closed); } }
                elseif ($vn === 'closed' || $vn === 'resolved') { $where[] = 't."status" IN (' . in_ph($closed) . ')'; array_push($params, ...$closed); }
                elseif ($vn === 'unassigned') { $where[] = 't."assigneeId" IS NULL'; }
                elseif ($vn === 'mine') { $where[] = 't."assigneeId" = ?'; $params[] = $ctx['user']['id']; }
                elseif ($vn === 'overdue') {
                    $where[] = 't."dueAt" < ?';
                    $params[] = now_iso();
                    if ($closed) { $where[] = 't."status" NOT IN (' . in_ph($closed) . ')'; array_push($params, ...$closed); }
                }
                break;
            case 'group':
                if ($vn === 'none') { $where[] = 't."groupId" IS NULL'; break; }
                if ($vn === 'mine') { $where[] = 't."groupId" IN (SELECT gm3."groupId" FROM "AgentGroupMember" gm3 WHERE gm3."userId" = ?)'; $params[] = $ctx['user']['id']; break; }
                $where[] = "EXISTS (SELECT 1 FROM \"AgentGroup\" ag WHERE ag.\"id\" = t.\"groupId\" AND LOWER(ag.\"name\") LIKE ? ESCAPE '!')";
                $params[] = like_arg($vn);
                break;
            case 'org':
                $where[] = "EXISTS (SELECT 1 FROM \"User\" uo JOIN \"Organization\" o ON o.\"id\" = uo.\"organizationId\" WHERE uo.\"id\" = t.\"requesterId\" AND LOWER(o.\"name\") LIKE ? ESCAPE '!')";
                $params[] = like_arg($vn);
                break;
            case 'source':
                $where[] = 't."source" = ?';
                $params[] = $vn;
                break;
            case 'rating':
                $where[] = 'EXISTS (SELECT 1 FROM "CsatSurvey" cs WHERE cs."ticketId" = t."id" AND cs."rating" ' . (str_starts_with($vn, '<') ? '<=' : (str_starts_with($vn, '>') ? '>=' : '=')) . ' ?)';
                $params[] = (int) ltrim($vn, '<>=');
                break;
            case 'created':
                $since = match (true) {
                    $vn === 'today' => (new DateTimeImmutable('today'))->getTimestamp(),
                    (bool) preg_match('/^(\d+)d$/', $vn, $d) => time() - (int) $d[1] * 86400,
                    (bool) preg_match('/^\d{4}-\d\d-\d\d$/', $vn) => strtotime($vn),
                    default => null,
                };
                if ($since) { $where[] = 't."createdAt" >= ?'; $params[] = iso_from_ts((float) $since); }
                break;
        }
    }

    // Unknown key:value — a custom field when the key exists, plain words otherwise.
    $fieldKeys = isset($ctx['tenantId']) ? array_column(tenant_fields($ctx['tenantId']), 'id', 'key') : [];
    foreach ($p['ops'] as $op) {
        if ($op[0][0] !== '?') continue;
        $key = substr($op[0], 1);
        if (isset($fieldKeys[$key])) {
            $where[] = "EXISTS (SELECT 1 FROM \"TicketFieldValue\" fv WHERE fv.\"ticketId\" = t.\"id\" AND fv.\"fieldId\" = ? AND LOWER(fv.\"value\") LIKE ? ESCAPE '!')";
            array_push($params, $fieldKeys[$key], like_arg(mb_strtolower($op[1])));
        } else {
            foreach (preg_split('/\s+/', search_norm($op[2])) as $w) if ($w !== '') $p['terms'][] = $w;
        }
    }

    // Free text: number OR all words/phrases in the search document.
    $text = [];
    $textParams = [];
    $ft = [];
    foreach ($p['terms'] as $w) {
        // FULLTEXT (InnoDB min token 3) handles longer words as prefix matches;
        // short words and symbols use LIKE.
        if ($mysql && mb_strlen($w) >= 3 && preg_match('/^[\p{L}\p{N}]+$/u', $w)) {
            $ft[] = '+' . $w . '*';
        } else {
            $text[] = "t.\"searchText\" LIKE ? ESCAPE '!'";
            $textParams[] = like_arg($w);
        }
    }
    foreach ($p['phrases'] as $ph) {
        $text[] = "t.\"searchText\" LIKE ? ESCAPE '!'";
        $textParams[] = like_arg($ph);
    }
    if ($ft) {
        $text[] = 'MATCH(t."searchText") AGAINST (? IN BOOLEAN MODE)';
        $textParams[] = implode(' ', $ft);
    }

    if ($p['number'] !== null && $p['strict']) {
        // "#42" means exactly ticket 42.
        $where[] = 't."number" = ?';
        $params[] = $p['number'];
        if ($text) { $where[] = implode(' AND ', $text); array_push($params, ...$textParams); }
    } elseif ($p['number'] !== null && !$text) {
        // Only a number: the ticket with that number, or text containing it.
        $where[] = "(t.\"number\" = ? OR t.\"searchText\" LIKE ? ESCAPE '!')";
        array_push($params, $p['number'], like_arg((string) $p['number']));
    } elseif ($p['number'] !== null) {
        $where[] = '(t."number" = ? OR (' . implode(' AND ', $text) . '))';
        array_push($params, $p['number'], ...$textParams);
    } elseif ($text) {
        $where[] = implode(' AND ', $text);
        array_push($params, ...$textParams);
    }
}

/** Short excerpt around the first matched word, for suggestions. */
function search_snippet(?string $doc, string $q): string
{
    $doc = (string) $doc;
    $p = parse_search($q);
    $needles = array_merge($p['phrases'], $p['terms']);
    foreach ($needles as $n) {
        $pos = mb_strpos($doc, $n);
        if ($pos !== false) {
            $start = max(0, $pos - 40);
            return ($start > 0 ? '…' : '') . mb_substr($doc, $start, 120) . (mb_strlen($doc) > $start + 120 ? '…' : '');
        }
    }
    return '';
}
