<?php
declare(strict_types=1);

/*
 * Ticket page extras: attachment rules, canned-reply variables, signatures,
 * editable / pinned messages, customer history, ticket ZIP and the AI assistant.
 */

/* ---------- Attachments ---------- */

/** File type groups an admin can allow: key => [label, [extension => MIME served]]. */
const ATTACHMENT_TYPES = [
    'images' => ['Images', ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml', 'heic' => 'image/heic', 'bmp' => 'image/bmp']],
    'pdf' => ['PDF', ['pdf' => 'application/pdf']],
    'office' => ['Office documents', [
        'doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text', 'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation', 'rtf' => 'application/rtf',
    ]],
    'text' => ['Text, CSV & logs', ['txt' => 'text/plain', 'csv' => 'text/csv', 'log' => 'text/plain', 'md' => 'text/plain', 'json' => 'application/json', 'xml' => 'application/xml']],
    'archives' => ['Archives', ['zip' => 'application/zip', '7z' => 'application/x-7z-compressed', 'rar' => 'application/vnd.rar', 'gz' => 'application/gzip', 'tar' => 'application/x-tar']],
    'email' => ['Email files', ['eml' => 'message/rfc822', 'msg' => 'application/vnd.ms-outlook']],
    'media' => ['Audio & video', ['mp3' => 'audio/mpeg', 'wav' => 'audio/wav', 'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm']],
];
const ATTACHMENT_DEFAULT_TYPES = ['images', 'pdf', 'office', 'text', 'archives'];

/** Largest upload (MB) PHP on this server accepts. */
function server_upload_limit_mb(): int
{
    $bytes = function (string $v): int {
        $v = trim($v);
        $n = (int) $v;
        return match (strtolower(substr($v, -1))) { 'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n };
    };
    $lim = min(array_filter([$bytes((string) ini_get('upload_max_filesize')), $bytes((string) ini_get('post_max_size'))]) ?: [MAX_FILE_SIZE]);
    return max(1, intdiv($lim, 1024 * 1024));
}

function attachment_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'attachments', []);
    $types = array_values(array_intersect((array) ($s['types'] ?? ATTACHMENT_DEFAULT_TYPES), array_keys(ATTACHMENT_TYPES)));
    return [
        'maxMB' => max(1, min(100, (int) ($s['maxMB'] ?? 10))),
        'maxFiles' => max(1, min(20, (int) ($s['maxFiles'] ?? 5))),
        'types' => $types,
        'staffOnly' => (bool) ($s['staffOnly'] ?? true),
        'customerUploads' => (bool) ($s['customerUploads'] ?? true),
    ];
}

/** Allowed extension => MIME for the workspace. */
function attachment_ext_map(string $tenantId): array
{
    $map = [];
    foreach (attachment_settings($tenantId)['types'] as $g) $map += ATTACHMENT_TYPES[$g][1];
    return $map;
}

/** What the browser needs to check files before uploading. */
function attachment_rules_js(string $tenantId): array
{
    $s = attachment_settings($tenantId);
    return ['maxBytes' => min($s['maxMB'], server_upload_limit_mb()) * 1024 * 1024, 'maxFiles' => $s['maxFiles'], 'exts' => array_keys(attachment_ext_map($tenantId))];
}

function file_ext(string $name): string
{
    return strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
}

/** Reasons the uploaded files can't be accepted (empty = all fine). */
function upload_problems(array $files, string $tenantId): array
{
    $s = attachment_settings($tenantId);
    $map = attachment_ext_map($tenantId);
    $max = min($s['maxMB'], server_upload_limit_mb()) * 1024 * 1024;
    $out = [];
    $n = 0;
    foreach ($files as $f) {
        $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
        if ($err === UPLOAD_ERR_NO_FILE) continue;
        $n++;
        $name = (string) ($f['name'] ?? 'file');
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE || ($f['size'] ?? 0) > $max) $out[] = "“{$name}” is larger than " . intdiv($max, 1024 * 1024) . ' MB';
        elseif ($err !== UPLOAD_ERR_OK) $out[] = "“{$name}” didn’t upload completely";
        elseif (!isset($map[file_ext($name)])) $out[] = "“{$name}”: this file type isn’t allowed";
    }
    if ($n > $s['maxFiles']) $out[] = 'Up to ' . $s['maxFiles'] . ' files per message';
    return $out;
}

/** Visible attachments of a ticket for this person (internal notes, staff-only files and deleted messages respected). */
function visible_attachments(array $ticket, array $user, array $comments): array
{
    $staff = is_staff($user['role']);
    $seesInternal = $staff && can($user, 'tickets.internal');
    $byId = array_column($comments, null, 'id');
    $rows = q_all('SELECT a.*, u."name" AS "uName" FROM "Attachment" a LEFT JOIN "User" u ON u."id" = a."uploaderId" WHERE a."ticketId" = ? ORDER BY a."createdAt" ASC', [$ticket['id']]);
    return array_values(array_filter($rows, function ($a) use ($byId, $staff, $seesInternal) {
        if ((int) ($a['internal'] ?? 0) && !$staff) return false;
        if ($a['commentId']) {
            $c = $byId[$a['commentId']] ?? null;
            if (!$c || !empty($c['deletedAt'])) return false;
            if ($c['isInternal'] && !$seesInternal) return false;
        }
        return true;
    }));
}

function attachment_kind(array $a): string
{
    $m = (string) $a['mime'];
    return match (true) {
        str_starts_with($m, 'image/') => 'image',
        $m === 'application/pdf' => 'pdf',
        str_starts_with($m, 'audio/') || str_starts_with($m, 'video/') => 'media',
        in_array(file_ext($a['filename']), ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'txt', 'csv', 'md', 'log', 'json', 'xml'], true) => 'doc',
        default => 'other',
    };
}

/** Stream all visible files of a ticket as one ZIP. */
function ticket_files_zip(string $ticketId): never
{
    [$user, $tenantId] = require_tenant_user();
    $ticket = find_ticket($ticketId, $tenantId);
    if (!$ticket || !ticket_visible($ticket, $user)) abort(404);
    $comments = q_all('SELECT "id","isInternal","deletedAt" FROM "Comment" WHERE "ticketId" = ?', [$ticket['id']]);
    $files = visible_attachments($ticket, $user, $comments);
    if (!$files) abort(404, 'This ticket has no files');
    if (!class_exists('ZipArchive')) abort(500, 'ZIP support is not installed on this server');
    $tmp = tempnam(sys_get_temp_dir(), 'tz');
    $zip = new ZipArchive();
    $zip->open($tmp, ZipArchive::OVERWRITE);
    $used = [];
    foreach ($files as $a) {
        $path = attachment_disk_path($tenantId, $a['id']);
        if (!is_file($path)) continue;
        // Same names get a number: report.pdf, report (2).pdf …
        $name = preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/', '_', $a['filename']) ?: 'file';
        $base = $name;
        for ($i = 2; isset($used[strtolower($name)]); $i++) {
            $ext = file_ext($base);
            $name = $ext ? substr($base, 0, -strlen($ext) - 1) . " ($i).$ext" : "$base ($i)";
        }
        $used[strtolower($name)] = true;
        $zip->addFile($path, $name);
    }
    $zip->close();
    audit($tenantId, $user, 'files-zip', 'Downloaded the files of ticket #' . (int) $ticket['number'] . ' as ZIP', 'ticket', $ticket['id']);
    header('Content-Type: application/zip');
    header('Content-Length: ' . filesize($tmp));
    header('Content-Disposition: attachment; filename="ticket-' . (int) $ticket['number'] . '-files.zip"');
    header('Cache-Control: private, no-store');
    readfile($tmp);
    @unlink($tmp);
    throw new HttpExit();
}

/* ---------- Canned-reply variables ---------- */

/** Placeholder => value for canned replies and macros on a ticket. */
function reply_vars(array $ticket, array $agent, string $tenantId): array
{
    $req = q_one('SELECT "name","email","organizationId" FROM "User" WHERE "id" = ?', [$ticket['requesterId']]) ?? ['name' => '', 'email' => '', 'organizationId' => null];
    $org = $req['organizationId'] ? (string) q_val('SELECT "name" FROM "Organization" WHERE "id" = ?', [$req['organizationId']]) : '';
    $first = fn (string $n) => explode(' ', trim($n))[0] ?? '';
    $status = resolve_status(tenant_statuses($tenantId), $ticket['status']);
    return [
        '{customer.name}' => $req['name'], '{customer.first_name}' => $first($req['name']), '{customer.email}' => $req['email'],
        '{organization.name}' => $org,
        '{ticket.number}' => '#' . (int) $ticket['number'], '{ticket.subject}' => $ticket['subject'], '{ticket.status}' => $status['label'],
        '{ticket.link}' => app_url() . '/tickets/' . $ticket['id'],
        '{agent.name}' => $agent['name'], '{agent.first_name}' => $first($agent['name']), '{agent.email}' => $agent['email'],
        '{workspace.name}' => (string) q_val('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$tenantId]),
        '{today}' => date('j M Y'),
        // Older macro placeholders keep working.
        '{{requester.name}}' => $req['name'], '{{requester.first_name}}' => $first($req['name']), '{{agent.name}}' => $agent['name'],
        '{{ticket.number}}' => (string) (int) $ticket['number'], '{{ticket.subject}}' => $ticket['subject'],
    ];
}

const REPLY_VAR_HELP = ['{customer.name}', '{customer.first_name}', '{customer.email}', '{organization.name}', '{ticket.number}', '{ticket.subject}', '{ticket.status}', '{ticket.link}', '{agent.name}', '{agent.first_name}', '{agent.email}', '{workspace.name}', '{today}'];

/** Fill placeholders in rich HTML (values are escaped). */
function fill_reply_vars(string $html, array $vars): string
{
    return strtr($html, array_map(fn ($v) => e((string) $v), $vars));
}

/* ---------- Signatures ---------- */

function user_signature(array $user): string
{
    return trim((string) ($user['signature'] ?? q_val('SELECT "signature" FROM "User" WHERE "id" = ?', [$user['id']]) ?? ''));
}

/** Body with the agent's signature appended (once). */
function with_signature(string $body, string $signature): string
{
    if ($signature === '' || html_to_text($signature) === '' && !str_contains($signature, '<img')) return $body;
    if (str_contains($body, $signature)) return $body;
    return $body . '<p>—</p>' . $signature;
}

/* ---------- Editable / pinned messages ---------- */

const MESSAGE_EDIT_MINUTES = 15;

/** May this person still edit or delete their message? */
function comment_editable(array $c, array $user): bool
{
    return $c['authorId'] === $user['id'] && empty($c['deletedAt']) && $c['createdAt'] >= now_iso(-MESSAGE_EDIT_MINUTES * 60);
}

/** May this person pin messages on the ticket? */
function can_pin(array $user): bool
{
    return is_staff($user['role']) && can_any($user, 'tickets.reply', 'tickets.internal');
}

/* ---------- Customer history ---------- */

/** Recent other tickets of the requester and their organizations (staff sidebar). */
function customer_history(array $ticket, string $tenantId): array
{
    $closed = closed_status_keys(tenant_statuses($tenantId));
    $mine = q_all('SELECT "id","number","subject","status","createdAt" FROM "Ticket" WHERE "tenantId" = ? AND "requesterId" = ? AND "id" <> ? AND "mergedIntoId" IS NULL ORDER BY "createdAt" DESC LIMIT 6',
        [$tenantId, $ticket['requesterId'], $ticket['id']]);
    $mineTotal = (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "requesterId" = ? AND "mergedIntoId" IS NULL', [$tenantId, $ticket['requesterId']]);
    $mineOpen = (int) q_val('SELECT COUNT(*) FROM "Ticket" WHERE "tenantId" = ? AND "requesterId" = ? AND "mergedIntoId" IS NULL' . ($closed ? ' AND "status" NOT IN (' . in_ph($closed) . ')' : ''), [$tenantId, $ticket['requesterId'], ...$closed]);
    $orgIds = pluck(q_all('SELECT "organizationId" FROM "UserOrganization" WHERE "userId" = ?', [$ticket['requesterId']]), 'organizationId');
    $org = $orgIds ? q_all('SELECT t."id", t."number", t."subject", t."status", t."createdAt", u."name" AS "who" FROM "Ticket" t JOIN "User" u ON u."id" = t."requesterId"
        WHERE t."tenantId" = ? AND t."requesterId" <> ? AND t."mergedIntoId" IS NULL AND t."requesterId" IN (SELECT "userId" FROM "UserOrganization" WHERE "organizationId" IN (' . in_ph($orgIds) . '))
        ORDER BY t."createdAt" DESC LIMIT 6', [$tenantId, $ticket['requesterId'], ...$orgIds]) : [];
    $csat = q_one('SELECT AVG("rating") AS a, COUNT(*) AS n FROM "CsatSurvey" WHERE "tenantId" = ? AND "requesterId" = ? AND "answeredAt" IS NOT NULL', [$tenantId, $ticket['requesterId']]);
    return ['mine' => $mine, 'mineTotal' => $mineTotal, 'mineOpen' => $mineOpen, 'org' => $org, 'csatAvg' => ($csat['n'] ?? 0) ? round((float) $csat['a'], 1) : null, 'csatCount' => (int) ($csat['n'] ?? 0)];
}

/* ---------- AI assistant (Claude API) ---------- */

const AI_MODELS = [
    'claude-opus-5-5' => 'Claude Opus 5.5 — best quality',
    'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — fast and capable',
    'claude-haiku-5-5' => 'Claude Haiku 5.5 — fastest, lowest cost',
];

function ai_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'ai', []);
    $model = (string) ($s['model'] ?? 'claude-opus-5-5');
    return [
        'enabled' => (bool) ($s['enabled'] ?? false),
        'hasKey' => !empty($s['apiKey']),
        'apiKey' => decrypt_secret($s['apiKey'] ?? null),
        'model' => isset(AI_MODELS[$model]) ? $model : 'claude-opus-5-5',
        'customers' => false,
    ];
}

/** Is the assistant available to this person? */
function ai_available(array $user, string $tenantId): bool
{
    $s = ai_settings($tenantId);
    return $s['enabled'] && $s['hasKey'] && is_staff($user['role']) && can($user, 'tickets.ai');
}

/**
 * One Messages API request. Returns ['ok' => true, 'text' => …] or ['ok' => false, 'error' => …].
 * Raw HTTP on purpose: the app has no Composer dependencies, so it deploys with a plain git pull.
 */
function ai_complete(string $tenantId, string $system, string $prompt, string $effort = 'low', int $maxTokens = 16000): array
{
    $s = ai_settings($tenantId);
    if ($s['apiKey'] === '') return ['ok' => false, 'error' => 'The AI assistant has no API key yet (Settings → Integrations).'];
    if (!function_exists('curl_init')) return ['ok' => false, 'error' => 'The PHP curl extension is required for the AI assistant.'];
    $body = [
        'model' => $s['model'],
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'output_config' => ['effort' => $effort],
    ];
    $headers = ['Content-Type: application/json', 'x-api-key: ' . $s['apiKey'], 'anthropic-version: 2026-10-21'];
    // Opus / Sonnet 5.5: if a safety check declines, the API retries on a suitable model itself.
    if ($s['model'] !== 'claude-haiku-5-5') {
        $body['fallbacks'] = 'default';
        $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01';
    }
    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE), CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $netErr = curl_error($ch);
    $errNo = curl_errno($ch);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'error' => in_array($errNo, [60, 77], true)
        ? 'This server’s PHP can’t verify SSL certificates (set curl.cainfo in php.ini to a CA bundle).'
        : 'Could not reach the AI service: ' . $netErr];
    $j = json_decode((string) $raw, true);
    if ($code !== 200) {
        $msg = (string) ($j['error']['message'] ?? 'HTTP ' . $code);
        return ['ok' => false, 'error' => match (true) {
            $code === 401 => 'The AI API key was rejected — check it in Settings → Integrations.',
            $code === 429 => 'The AI service is busy (rate limit). Try again in a minute.',
            $code >= 500 => 'The AI service is temporarily unavailable. Try again shortly.',
            default => 'AI request failed: ' . $msg,
        }];
    }
    if (($j['stop_reason'] ?? '') === 'refusal') return ['ok' => false, 'error' => 'The AI assistant declined this request.'];
    $text = '';
    foreach ((array) ($j['content'] ?? []) as $block) if (($block['type'] ?? '') === 'text') $text .= $block['text'];
    $text = trim($text);
    if ($text === '') return ['ok' => false, 'error' => 'The AI assistant returned no text.'];
    return ['ok' => true, 'text' => $text, 'truncated' => ($j['stop_reason'] ?? '') === 'max_tokens'];
}

/** The ticket as plain text for the assistant (what this agent may see). */
function ai_ticket_transcript(array $ticket, array $user): string
{
    $seesInternal = can($user, 'tickets.internal');
    $req = q_one('SELECT "name" FROM "User" WHERE "id" = ?', [$ticket['requesterId']]);
    $out = "Ticket #{$ticket['number']}: {$ticket['subject']}\nStatus: {$ticket['status']} · Priority: {$ticket['priority']}\n\n"
        . '[' . format_date($ticket['createdAt']) . '] ' . ($req['name'] ?? 'Customer') . " (customer) opened the ticket:\n" . html_to_text((string) $ticket['description']) . "\n";
    $rows = q_all('SELECT c."body", c."isInternal", c."createdAt", c."authorId", u."name", u."role" FROM "Comment" c JOIN "User" u ON u."id" = c."authorId" WHERE c."ticketId" = ? AND c."deletedAt" IS NULL ORDER BY c."createdAt" ASC', [$ticket['id']]);
    foreach ($rows as $c) {
        if ($c['isInternal'] && !$seesInternal) continue;
        $who = $c['authorId'] === $ticket['requesterId'] ? 'customer' : (is_staff($c['role']) ? 'support agent' : 'customer side');
        $out .= "\n[" . format_date($c['createdAt']) . '] ' . $c['name'] . " ($who" . ($c['isInternal'] ? ', internal note' : '') . "):\n" . html_to_text($c['body']) . "\n";
    }
    // Keep requests reasonable on very long tickets: the newest part matters most.
    return mb_strlen($out) > 60000 ? "…(earlier messages omitted)…\n" . mb_substr($out, -60000) : $out;
}

/** Plain text from the model → safe HTML paragraphs / lists. */
function ai_text_to_html(string $text): string
{
    $html = '';
    foreach (preg_split('/\n{2,}/', trim($text)) as $block) {
        $lines = preg_split('/\n/', trim($block));
        if (count(array_filter($lines, fn ($l) => preg_match('/^\s*[-*•]\s+/', $l))) === count($lines)) {
            $html .= '<ul>' . implode('', array_map(fn ($l) => '<li>' . e(preg_replace('/^\s*[-*•]\s+/', '', $l)) . '</li>', $lines)) . '</ul>';
        } else {
            $html .= '<p>' . implode('<br>', array_map('e', $lines)) . '</p>';
        }
    }
    return preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $html);
}
