<?php
declare(strict_types=1);

/*
 * Backups and data exports (whole workspace, one organization, one person)
 * and GDPR erasure. Secrets (password hashes, tokens, API keys) never leave.
 */

/** Tables in a workspace backup: name => how rows belong to the workspace. */
const BACKUP_TABLES = [
    'Tenant' => ['self'], 'User' => ['tenant'], 'Organization' => ['tenant'], 'UserOrganization' => ['via', 'userId', 'User'],
    'Role' => ['tenant'], 'AgentGroup' => ['tenant'], 'AgentGroupMember' => ['via', 'groupId', 'AgentGroup'],
    'Category' => ['tenant'], 'Tag' => ['tenant'], 'TicketStatus' => ['tenant'], 'TicketPriority' => ['tenant'], 'SlaTarget' => ['tenant'],
    'CustomField' => ['tenant'], 'Ticket' => ['tenant'], 'TicketFieldValue' => ['via', 'ticketId', 'Ticket'], 'TicketTag' => ['via', 'ticketId', 'Ticket'],
    'TicketWatcher' => ['via', 'ticketId', 'Ticket'], 'TicketAssignee' => ['via', 'ticketId', 'Ticket'], 'TicketCc' => ['via', 'ticketId', 'Ticket'],
    'TicketLink' => ['tenant'], 'TicketEvent' => ['tenant'], 'Comment' => ['tenant'], 'CommentRevision' => ['via', 'commentId', 'Comment'],
    'Attachment' => ['tenant'], 'CsatSurvey' => ['tenant'], 'TimeEntry' => ['tenant'], 'Contract' => ['tenant'],
    'ChatSession' => ['tenant'], 'ChatMessage' => ['via', 'sessionId', 'ChatSession'], 'KbArticle' => ['tenant'],
    'CannedResponse' => ['tenant'], 'Macro' => ['tenant'], 'AutomationRule' => ['tenant'], 'TicketTemplate' => ['tenant'],
    'SavedView' => ['tenant'], 'ScheduledReport' => ['tenant'], 'TenantSetting' => ['tenant'], 'TenantNotificationPolicy' => ['tenant'],
    'NotificationPreference' => ['via', 'userId', 'User'], 'EmailSetting' => ['tenant'], 'Webhook' => ['tenant'], 'ApiKey' => ['tenant'], 'AuditLog' => ['tenant'],
];
/** Columns never exported. */
const BACKUP_SECRET_COLUMNS = ['passwordHash', 'password', 'secret', 'keyHash', 'tokenHash', 'totpSecret', 'smtpPassword', 'msClientSecret', 'apiKey'];

function backup_rows(string $table, string $tenantId): array
{
    if (!db_table_exists($table)) return [];
    [$kind, $col, $parent] = BACKUP_TABLES[$table] + [null, null, null];
    $rows = match ($kind) {
        'self' => q_all('SELECT * FROM ' . qi($table) . ' WHERE "id" = ?', [$tenantId]),
        'tenant' => q_all('SELECT * FROM ' . qi($table) . ' WHERE "tenantId" = ?', [$tenantId]),
        'via' => q_all('SELECT x.* FROM ' . qi($table) . ' x JOIN ' . qi($parent) . ' p ON p."id" = x.' . qi($col) . ' WHERE p."tenantId" = ?', [$tenantId]),
    };
    foreach ($rows as &$r) {
        foreach (BACKUP_SECRET_COLUMNS as $c) if (array_key_exists($c, $r)) $r[$c] = null;
        // Settings stored as JSON can hold encrypted secrets.
        if ($table === 'TenantSetting' && is_string($r['value'] ?? null) && ($j = json_decode($r['value'], true)) && is_array($j)) {
            foreach (BACKUP_SECRET_COLUMNS as $c) if (array_key_exists($c, $j)) $j[$c] = null;
            $r['value'] = json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
    return $rows;
}

function db_table_exists(string $table): bool
{
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try { db()->query('SELECT 1 FROM ' . qi($table) . ' WHERE 1 = 0'); return $cache[$table] = true; } catch (Throwable $e) { return $cache[$table] = false; }
}

/** Write a full workspace backup ZIP to $path. */
function backup_build(string $tenantId, string $path, bool $withFiles): array
{
    $z = new ZipArchive();
    if ($z->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) throw new RuntimeException('Cannot create the backup file');
    $counts = [];
    foreach (array_keys(BACKUP_TABLES) as $t) {
        $rows = backup_rows($t, $tenantId);
        $counts[$t] = count($rows);
        $z->addFromString("data/$t.json", json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }
    $files = 0;
    if ($withFiles) {
        foreach (q_all('SELECT "id","filename" FROM "Attachment" WHERE "tenantId" = ?', [$tenantId]) as $a) {
            $p = attachment_disk_path($tenantId, $a['id']);
            if (is_file($p)) { $z->addFile($p, 'files/' . $a['id'] . '/' . preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/', '_', $a['filename'])); $files++; }
        }
    }
    $tenant = q_one('SELECT "name","slug" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    $z->addFromString('README.txt', app_name() . " backup of “{$tenant['name']}” ({$tenant['slug']})\nCreated: " . now_iso() . "\nSchema version: " . RUNTIME_SCHEMA_VERSION
        . "\n\ndata/<Table>.json — every row of the workspace, one file per table.\n" . ($withFiles ? "files/<attachment id>/<name> — uploaded files.\n" : "Files were not included.\n")
        . "Password hashes, tokens and API keys are left out on purpose.\n");
    $z->close();
    return ['tables' => $counts, 'files' => $files];
}

function backup_dir(string $tenantId): string
{
    return ROOT . '/storage/backups/' . preg_replace('/[^A-Za-z0-9_-]/', '', $tenantId);
}

function backup_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'backup', []);
    return ['daily' => (bool) ($s['daily'] ?? false), 'keep' => max(1, min(60, (int) ($s['keep'] ?? 7))), 'files' => (bool) ($s['files'] ?? false)];
}

/** Stored automatic backups, newest first. */
function backup_list(string $tenantId): array
{
    $out = [];
    foreach (glob(backup_dir($tenantId) . '/backup-*.zip') ?: [] as $f) $out[] = ['name' => basename($f), 'size' => filesize($f), 'at' => filemtime($f)];
    usort($out, fn ($a, $b) => $b['at'] <=> $a['at']);
    return $out;
}

/** Cron: one automatic backup per workspace per day, keeping the newest N. */
function backup_run_daily(): int
{
    if (!class_exists('ZipArchive')) return 0;
    $n = 0;
    foreach (q_all('SELECT "tenantId" FROM "TenantSetting" WHERE "key" = ?', ['backup']) as $r) {
        $cfg = backup_settings($r['tenantId']);
        if (!$cfg['daily']) continue;
        $dir = backup_dir($r['tenantId']);
        $file = $dir . '/backup-' . date('Y-m-d') . '.zip';
        if (is_file($file)) continue;
        if (!is_dir($dir)) mkdir($dir, 0775, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
        try { backup_build($r['tenantId'], $file, $cfg['files']); $n++; } catch (Throwable $e) { error_log('[backup] ' . $e->getMessage()); @unlink($file); continue; }
        foreach (array_slice(backup_list($r['tenantId']), $cfg['keep']) as $old) @unlink($dir . '/' . $old['name']);
    }
    return $n;
}

/* ---------- CSV helpers ---------- */

function csv_string(array $rows): string
{
    $h = fopen('php://temp', 'r+');
    fwrite($h, "\xEF\xBB\xBF"); // Excel reads UTF-8 with a BOM
    foreach ($rows as $r) fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'" . $v : $v, $r), ',', '"', '\\');
    rewind($h);
    $s = stream_get_contents($h);
    fclose($h);
    return $s;
}

/** ZIP with an organization's people, tickets, messages, time, contracts (+ files). */
function org_export_build(string $tenantId, string $orgId, string $path, bool $withFiles): void
{
    $org = q_one('SELECT * FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [$orgId, $tenantId]);
    $people = q_all('SELECT u."id", u."name", u."email", u."role", u."createdAt" FROM "User" u JOIN "UserOrganization" uo ON uo."userId" = u."id" WHERE uo."organizationId" = ? ORDER BY u."name"', [$orgId]);
    $ids = array_column($people, 'id');
    $tickets = $ids ? q_all('SELECT t.*, r."email" AS "requesterEmail" FROM "Ticket" t JOIN "User" r ON r."id" = t."requesterId" WHERE t."tenantId" = ? AND t."requesterId" IN (' . in_ph($ids) . ') ORDER BY t."number"', [$tenantId, ...$ids]) : [];
    $tids = array_column($tickets, 'id');
    $z = new ZipArchive();
    $z->open($path, ZipArchive::OVERWRITE | ZipArchive::CREATE);
    $z->addFromString('people.csv', csv_string(array_merge([['Name', 'Email', 'Role', 'Since']], array_map(fn ($p) => [$p['name'], $p['email'], $p['role'], $p['createdAt']], $people))));
    $z->addFromString('tickets.csv', csv_string(array_merge([['Number', 'Subject', 'Status', 'Priority', 'Requester', 'Created', 'Closed', 'Description']],
        array_map(fn ($t) => [(int) $t['number'], $t['subject'], $t['status'], $t['priority'], $t['requesterEmail'], $t['createdAt'], $t['closedAt'], html_to_text((string) $t['description'])], $tickets))));
    $msgs = $tids ? q_all('SELECT c."createdAt", c."isInternal", c."body", c."deletedAt", t."number", u."name" FROM "Comment" c JOIN "Ticket" t ON t."id" = c."ticketId" JOIN "User" u ON u."id" = c."authorId" WHERE c."ticketId" IN (' . in_ph($tids) . ') ORDER BY t."number", c."createdAt"', $tids) : [];
    $z->addFromString('messages.csv', csv_string(array_merge([['Ticket', 'Date', 'Author', 'Internal note', 'Text']],
        array_map(fn ($m) => [(int) $m['number'], $m['createdAt'], $m['name'], (int) $m['isInternal'] ? 'yes' : 'no', $m['deletedAt'] ? '(deleted)' : html_to_text((string) $m['body'])], $msgs))));
    $time = $tids ? q_all('SELECT te."workedAt", te."minutes", te."billable", te."note", t."number", u."name" FROM "TimeEntry" te JOIN "Ticket" t ON t."id" = te."ticketId" JOIN "User" u ON u."id" = te."userId" WHERE te."ticketId" IN (' . in_ph($tids) . ') ORDER BY te."workedAt"', $tids) : [];
    $z->addFromString('time.csv', csv_string(array_merge([['Date', 'Ticket', 'Agent', 'Minutes', 'Hours', 'Billable', 'Note']],
        array_map(fn ($e) => [$e['workedAt'], (int) $e['number'], $e['name'], (int) $e['minutes'], hours_dec((int) $e['minutes']), (int) $e['billable'] ? 'yes' : 'no', (string) $e['note']], $time))));
    $z->addFromString('contracts.csv', csv_string(array_merge([['Name', 'Starts', 'Ends', 'Hours included', 'Reply within (h)', 'Solve within (h)', 'Active']],
        array_map(fn ($c) => [$c['name'], $c['startsOn'], $c['endsOn'], $c['minutesIncluded'] !== null ? hours_dec((int) $c['minutesIncluded']) : 'unlimited', $c['firstResponseHours'], $c['resolutionHours'], (int) $c['active'] ? 'yes' : 'no'], org_contracts($orgId)))));
    if ($withFiles && $tids) {
        foreach (q_all('SELECT a."id", a."filename", t."number" FROM "Attachment" a JOIN "Ticket" t ON t."id" = a."ticketId" WHERE a."ticketId" IN (' . in_ph($tids) . ')', $tids) as $a) {
            $p = attachment_disk_path($tenantId, $a['id']);
            if (is_file($p)) $z->addFile($p, 'files/ticket-' . (int) $a['number'] . '/' . substr($a['id'], 0, 8) . '-' . preg_replace('/[\\\\\/:*?"<>|\x00-\x1f]/', '_', $a['filename']));
        }
    }
    $z->addFromString('README.txt', "Data of the organization “{$org['name']}” exported " . now_iso() . ".\n");
    $z->close();
}

/** Everything stored about one person (GDPR access request). */
function person_export(string $tenantId, array $p): array
{
    $tickets = q_all('SELECT "id","number","subject","status","createdAt","closedAt","description" FROM "Ticket" WHERE "tenantId" = ? AND "requesterId" = ? ORDER BY "number"', [$tenantId, $p['id']]);
    foreach ($tickets as &$t) {
        $t['description'] = html_to_text((string) $t['description']);
        $t['messages'] = array_map(fn ($c) => ['at' => $c['createdAt'], 'from' => $c['name'], 'text' => $c['deletedAt'] ? '(deleted)' : html_to_text((string) $c['body'])],
            q_all('SELECT c."createdAt", c."body", c."deletedAt", u."name" FROM "Comment" c JOIN "User" u ON u."id" = c."authorId" WHERE c."ticketId" = ? AND c."isInternal" = 0 ORDER BY c."createdAt"', [$t['id']]));
        unset($t['id']);
    }
    unset($t);
    return [
        'exportedAt' => now_iso(),
        'profile' => array_intersect_key($p, array_flip(['name', 'email', 'role', 'phone', 'createdAt', 'lastLoginAt', 'active'])),
        'organizations' => array_values(array_column(user_orgs($p['id']), 'name')),
        'tickets' => $tickets,
        'messagesWritten' => (int) q_val('SELECT COUNT(*) FROM "Comment" WHERE "authorId" = ?', [$p['id']]),
        'ratings' => q_all('SELECT "rating","comment","answeredAt" FROM "CsatSurvey" WHERE "requesterId" = ? AND "answeredAt" IS NOT NULL', [$p['id']]),
        'devicesWithPush' => (int) q_val('SELECT COUNT(*) FROM "PushSubscription" WHERE "userId" = ?', [$p['id']]),
    ];
}

/** Anonymize a person; optionally blank their messages and delete their files. */
function person_erase(string $tenantId, array $p, bool $content): array
{
    $now = now_iso();
    $anonEmail = 'erased-' . substr(hash('sha256', $p['id']), 0, 12) . '@invalid.local';
    $upd = ['name' => 'Erased user', 'email' => $anonEmail, 'active' => 0, 'passwordHash' => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT)];
    foreach (['phone' => null, 'signature' => null] as $c => $v) if (db_column_exists('User', $c)) $upd[$c] = $v;
    db_update('User', $upd, '"id" = ?', [$p['id']]);
    foreach (['PushSubscription', 'NotificationPreference', 'SavedView', 'PasswordResetToken', 'Notification', 'UserOrganization'] as $t) {
        if (db_table_exists($t) && db_column_exists($t, 'userId')) q('DELETE FROM ' . qi($t) . ' WHERE "userId" = ?', [$p['id']]);
    }
    q('UPDATE "CsatSurvey" SET "comment" = NULL WHERE "requesterId" = ?', [$p['id']]);
    $out = ['messages' => 0, 'files' => 0];
    if ($content) {
        $out['messages'] = q('UPDATE "Comment" SET "body" = ?, "deletedAt" = COALESCE("deletedAt", ?) WHERE "authorId" = ?', ['', $now, $p['id']])->rowCount();
        foreach (q_all('SELECT "id" FROM "CommentRevision" WHERE "commentId" IN (SELECT "id" FROM "Comment" WHERE "authorId" = ?)', [$p['id']]) as $r) q('DELETE FROM "CommentRevision" WHERE "id" = ?', [$r['id']]);
        q('UPDATE "Ticket" SET "description" = ? WHERE "tenantId" = ? AND "requesterId" = ?', ['<p><em>(removed at the person’s request)</em></p>', $tenantId, $p['id']]);
        foreach (q_all('SELECT "id" FROM "Attachment" WHERE "tenantId" = ? AND "uploaderId" = ?', [$tenantId, $p['id']]) as $a) {
            @unlink(attachment_disk_path($tenantId, $a['id']));
            q('DELETE FROM "Attachment" WHERE "id" = ?', [$a['id']]);
            $out['files']++;
        }
        q('DELETE FROM "TicketCc" WHERE "email" = ?', [strtolower((string) $p['email'])]);
        foreach (q_all('SELECT "id" FROM "Ticket" WHERE "tenantId" = ? AND "requesterId" = ?', [$tenantId, $p['id']]) as $t) reindex_ticket($t['id']);
    }
    return $out;
}
