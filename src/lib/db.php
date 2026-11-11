<?php
declare(strict_types=1);

/*
 * PDO data layer. SQLite by default (storage/database.sqlite); MySQL/MariaDB
 * via DB_DRIVER=mysql. Timestamps are stored as UTC ISO-8601 strings with
 * milliseconds ("2026-01-31T09:15:00.123Z") so they sort lexicographically on
 * every driver.
 */

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $driver = env('DB_DRIVER', 'sqlite');
    $opts = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    if ($driver === 'mysql') {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            env('DB_HOST', '127.0.0.1'),
            env('DB_PORT', '3306'),
            env('DB_NAME', 'ticketingos'),
        );
        $pdo = new PDO($dsn, env('DB_USER', 'root'), env('DB_PASSWORD', ''), $opts);
        $pdo->exec("SET sql_mode = CONCAT(@@sql_mode, ',ANSI_QUOTES')");
    } else {
        $path = env('DB_PATH', 'storage/database.sqlite');
        if (!preg_match('#^([a-zA-Z]:)?[\\\\/]#', $path)) $path = ROOT . '/' . $path;
        if (!is_dir(dirname($path))) mkdir(dirname($path), 0775, true);
        $pdo = new PDO('sqlite:' . $path, null, null, $opts);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // Case-insensitive LIKE matches Prisma's SQLite `contains` behaviour.
        $pdo->exec('PRAGMA case_sensitive_like = OFF');
    }
    return $pdo;
}

function db_driver(): string
{
    return env('DB_DRIVER', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
}

/**
 * Optional query profiler: with DB_PROFILE=1 in .env, requests that run many or slow
 * queries are written to storage/logs/queries.log (count, total time, slowest queries).
 */
function db_profile(?string $sql = null, float $ms = 0.0): void
{
    static $on = null, $log = [];
    if ($on === null) {
        $on = env('DB_PROFILE', '') === '1';
        if ($on) register_shutdown_function(function () use (&$log) {
            $total = array_sum(array_column($log, 1));
            if (count($log) < 40 && $total < 150) return;
            usort($log, fn ($a, $b) => $b[1] <=> $a[1]);
            $lines = sprintf("%s %s %s — %d queries, %.0f ms\n", gmdate('c'), $_SERVER['REQUEST_METHOD'] ?? 'CLI', $_SERVER['REQUEST_URI'] ?? '', count($log), $total);
            foreach (array_slice($log, 0, 5) as [$q, $t]) $lines .= sprintf("  %6.1f ms  %s\n", $t, preg_replace('/\s+/', ' ', mb_substr($q, 0, 300)));
            // The same query run again and again usually means a query per row (N+1).
            $rep = array_count_values(array_column($log, 0));
            arsort($rep);
            foreach (array_slice($rep, 0, 3, true) as $q => $n) if ($n > 5) $lines .= sprintf("  repeated %dx: %s\n", $n, preg_replace('/\s+/', ' ', mb_substr($q, 0, 200)));
            @mkdir(ROOT . '/storage/logs', 0775, true);
            @file_put_contents(ROOT . '/storage/logs/queries.log', $lines, FILE_APPEND);
        });
    }
    if ($on && $sql !== null) $log[] = [$sql, $ms];
}

function q(string $sql, array $params = []): PDOStatement
{
    $t0 = microtime(true);
    $st = db()->prepare($sql);
    foreach (array_values($params) as $i => $v) {
        $type = match (true) {
            is_int($v) => PDO::PARAM_INT,
            is_bool($v) => PDO::PARAM_INT,
            $v === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
        $st->bindValue($i + 1, is_bool($v) ? (int) $v : $v, $type);
    }
    $st->execute();
    db_profile($sql, (microtime(true) - $t0) * 1000);
    return $st;
}

function q_all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function q_one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function q_val(string $sql, array $params = []): mixed
{
    $v = q($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

/** "?, ?, ?" placeholders for an IN (...) list (never empty: falls back to NULL). */
function in_ph(array $values): string
{
    return $values ? implode(', ', array_fill(0, count($values), '?')) : 'NULL';
}

function qi(string $ident): string
{
    return '"' . str_replace('"', '', $ident) . '"';
}

function db_insert(string $table, array $data): void
{
    $cols = implode(', ', array_map('qi', array_keys($data)));
    $ph = in_ph($data);
    q("INSERT INTO " . qi($table) . " ($cols) VALUES ($ph)", array_values($data));
}

function db_update(string $table, array $data, string $where, array $params = []): int
{
    if (!$data) return 0;
    $set = implode(', ', array_map(fn ($c) => qi($c) . ' = ?', array_keys($data)));
    return q("UPDATE " . qi($table) . " SET $set WHERE $where", [...array_values($data), ...$params])->rowCount();
}

function db_tx(callable $fn): mixed
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Collision-resistant id, similar in spirit to Prisma's cuid(). */
function cuid(): string
{
    static $counter = 0;
    $counter = ($counter + 1) % 1679616;
    $time = base_convert((string) (int) (microtime(true) * 1000), 10, 36);
    $count = str_pad(base_convert((string) $counter, 10, 36), 4, '0', STR_PAD_LEFT);
    $rand = '';
    for ($i = 0; $i < 8; $i++) $rand .= base_convert((string) random_int(0, 35), 10, 36);
    return 'c' . $time . $count . $rand;
}

/** UTC ISO timestamp with ms; optional offset in seconds. */
function now_iso(float $offsetSeconds = 0): string
{
    return iso_from_ts(microtime(true) + $offsetSeconds);
}

function iso_from_ts(float $ts): string
{
    $sec = (int) floor($ts);
    $ms = (int) round(($ts - $sec) * 1000);
    if ($ms === 1000) { $sec++; $ms = 0; }
    return gmdate('Y-m-d\TH:i:s', $sec) . sprintf('.%03dZ', $ms);
}

/** Parse an ISO timestamp (as stored) into a unix timestamp (float seconds). */
function ts(?string $iso): ?float
{
    if (!$iso) return null;
    try {
        $d = new DateTimeImmutable($iso);
        return (float) $d->format('U.u');
    } catch (Throwable) {
        return null;
    }
}

/**
 * Small schema additions applied automatically on first request after a deploy
 * (hosts like Plesk often have no shell for bin/migrate.php). A marker file in
 * storage/ keeps this to one cheap check per request.
 */
const RUNTIME_SCHEMA_VERSION = 11;

/** Does a table have a column? (works for SQLite and MySQL/MariaDB) */
function db_column_exists(string $table, string $col): bool
{
    if (db_driver() === 'mysql') {
        return (bool) q_val('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND COLUMN_NAME = ?', [$table, $col]);
    }
    foreach (q_all('PRAGMA table_info(' . qi($table) . ')') as $c) if ($c['name'] === $col) return true;
    return false;
}

function ensure_runtime_schema(): void
{
    // The marker names the database too: a database that is dropped and imported
    // again (or a different one in .env) gets every step again. All steps are
    // idempotent (CREATE IF NOT EXISTS, column checks, backfill of missing rows).
    $marker = ROOT . '/storage/.schema-version';
    $sig = RUNTIME_SCHEMA_VERSION . '|' . db_driver() . '|' . (db_driver() === 'mysql' ? env('DB_HOST', '') . '/' . env('DB_NAME', '') . '|' . (string) q_val('SELECT MIN("createdAt") FROM "Tenant"') : env('DB_PATH', ''));
    if (trim((string) @file_get_contents($marker)) === $sig) return;
    try {
        runtime_schema_v1();
        runtime_schema_v2();
        runtime_schema_v3();
        runtime_schema_v4();
        runtime_schema_v5();
        runtime_schema_v6();
        db_add_column('Organization', 'hourlyRate', 'DECIMAL(10,2) NULL', 'REAL');
        db_add_column('ChatSession', 'requestedAgentId', 'VARCHAR(40) NULL', 'TEXT');
        db_add_column('ChatSession', 'visitorTypingAt', 'VARCHAR(30) NULL', 'TEXT');
        db_add_column('ChatSession', 'agentTypingAt', 'VARCHAR(30) NULL', 'TEXT');
        db_add_column('ChatSession', 'rating', 'INT NULL', 'INTEGER');
        // v9: chats inside the app (between colleagues too) and files in chats.
        db_add_column('ChatSession', 'kind', 'VARCHAR(10) NULL', 'TEXT');
        db_add_column('ChatSession', 'visitorUserId', 'VARCHAR(40) NULL', 'TEXT');
        db_add_column('ChatSession', 'visitorSeenAt', 'VARCHAR(30) NULL', 'TEXT');
        db_add_column('ChatMessage', 'attachmentId', 'VARCHAR(40) NULL', 'TEXT');
        db_add_column('Attachment', 'chatSessionId', 'VARCHAR(40) NULL', 'TEXT');
        // v10: indexes for big workspaces (many chats, customers and tickets).
        db_add_index('ChatSession', 'ChatSession_visitorUser_idx', ['visitorUserId', 'status']);
        db_add_index('ChatSession', 'ChatSession_requested_idx', ['requestedAgentId']);
        db_add_index('ChatSession', 'ChatSession_tenant_closed_idx', ['tenantId', 'closedAt']);
        db_add_index('Attachment', 'Attachment_chat_idx', ['chatSessionId']);
        db_add_index('Ticket', 'Ticket_tenant_assignee_idx', ['tenantId', 'assigneeId', 'status']);
        db_add_index('Notification', 'Notification_user_tenant_read_idx', ['userId', 'tenantId', 'read']);
        runtime_schema_v11();
        @file_put_contents($marker, $sig);
    } catch (Throwable $e) {
        error_log('[schema] ' . $e->getMessage());
    }
}

/** v11: AI assistant for customers, SMS / Viber / WhatsApp, invoices. */
function runtime_schema_v11(): void
{
    db_add_column('ChatSession', 'aiActive', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column('ChatSession', 'handoffAt', 'VARCHAR(30) NULL', 'TEXT');
    db_add_column('User', 'phone', 'VARCHAR(32) NULL', 'TEXT');
    db_add_index('User', 'User_tenant_phone_idx', ['tenantId', 'phone']);
    $s = ['VARCHAR(40) NOT NULL', 'TEXT NOT NULL'];
    db_create_table('ChannelContact', [
        'id' => $s, 'tenantId' => $s, 'channel' => ['VARCHAR(20) NOT NULL', 'TEXT NOT NULL'], 'externalId' => ['VARCHAR(191) NOT NULL', 'TEXT NOT NULL'],
        'userId' => $s, 'name' => ['VARCHAR(120) NULL', 'TEXT'], 'phone' => ['VARCHAR(32) NULL', 'TEXT'],
        'createdAt' => ['VARCHAR(30) NOT NULL', 'TEXT NOT NULL'], 'lastInboundAt' => ['VARCHAR(30) NULL', 'TEXT'],
    ], ['ChannelContact_ext_idx' => ['tenantId', 'channel', 'externalId'], 'ChannelContact_user_idx' => ['userId']]);
}

/** Add an index if it's missing (MySQL/MariaDB and SQLite). */
function db_add_index(string $table, string $name, array $cols): void
{
    $list = implode(', ', array_map('qi', $cols));
    if (db_driver() === 'mysql') {
        if (q_val('SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND INDEX_NAME = ? LIMIT 1', [$table, $name])) return;
        db()->exec('CREATE INDEX ' . qi($name) . ' ON ' . qi($table) . ' (' . $list . ')');
        return;
    }
    db()->exec('CREATE INDEX IF NOT EXISTS ' . qi($name) . ' ON ' . qi($table) . ' (' . $list . ')');
}

/** Add a column if it's missing. */
function db_add_column(string $table, string $col, string $mysqlType, string $sqliteType): void
{
    if (db_column_exists($table, $col)) return;
    db()->exec('ALTER TABLE ' . qi($table) . ' ADD COLUMN ' . qi($col) . ' ' . (db_driver() === 'mysql' ? $mysqlType : $sqliteType));
}

/** v5: signatures, editable / deletable / pinned messages with history, staff-only files. */
function runtime_schema_v5(): void
{
    $my = db_driver() === 'mysql';
    db_add_column('User', 'signature', 'MEDIUMTEXT NULL', 'TEXT');
    db_add_column('Comment', 'editedAt', 'VARCHAR(30) NULL', 'TEXT');
    db_add_column('Comment', 'deletedAt', 'VARCHAR(30) NULL', 'TEXT');
    db_add_column('Comment', 'pinned', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column('Attachment', 'internal', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    // Scheduled reports for one organization and to outside email addresses.
    db_add_column('ScheduledReport', 'organizationId', 'VARCHAR(40) NULL', 'TEXT');
    db_add_column('ScheduledReport', 'emails', 'VARCHAR(4000) NULL', 'TEXT');
    $T = $my ? 'VARCHAR(40)' : 'TEXT';
    db()->exec('CREATE TABLE IF NOT EXISTS "CommentRevision" (
  "id" ' . $T . ' NOT NULL PRIMARY KEY,
  "commentId" ' . $T . ' NOT NULL,
  "body" ' . ($my ? 'MEDIUMTEXT' : 'TEXT') . ' NOT NULL,
  "editedById" ' . $T . ' NULL,
  "createdAt" ' . ($my ? 'VARCHAR(30)' : 'TEXT') . ' NOT NULL' . ($my ? ',
  KEY "CommentRevision_comment_idx" ("commentId")' : '') . '
)' . ($my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    if (!$my) db()->exec('CREATE INDEX IF NOT EXISTS "CommentRevision_comment_idx" ON "CommentRevision"("commentId")');
}

/** Create a table (MySQL or SQLite) from [column => [mysqlType, sqliteType]] plus extra indexes. */
function db_create_table(string $table, array $cols, array $indexes = []): void
{
    $my = db_driver() === 'mysql';
    $defs = [];
    foreach ($cols as $c => [$mt, $st]) $defs[] = '  ' . qi($c) . ' ' . ($my ? $mt : $st);
    $defs[0] .= ' NOT NULL PRIMARY KEY';
    if ($my) foreach ($indexes as $name => $on) $defs[] = '  KEY ' . qi($name) . ' (' . implode(',', array_map('qi', $on)) . ')';
    db()->exec('CREATE TABLE IF NOT EXISTS ' . qi($table) . " (\n" . implode(",\n", $defs) . "\n)" . ($my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : ''));
    if (!$my) foreach ($indexes as $name => $on) db()->exec('CREATE INDEX IF NOT EXISTS ' . qi($name) . ' ON ' . qi($table) . '(' . implode(',', array_map('qi', $on)) . ')');
}

/** v6: time tracking, support contracts, live chat, shared views, AI triage, webhook options. */
function runtime_schema_v6(): void
{
    $id = ['VARCHAR(40)', 'TEXT'];
    $idn = ['VARCHAR(40) NULL', 'TEXT'];
    $ts = ['VARCHAR(30) NOT NULL', 'TEXT NOT NULL'];
    $tsn = ['VARCHAR(30) NULL', 'TEXT'];
    $int0 = ['INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'];
    db_create_table('TimeEntry', [
        'id' => $id, 'tenantId' => ['VARCHAR(40) NOT NULL', 'TEXT NOT NULL'], 'ticketId' => ['VARCHAR(40) NOT NULL', 'TEXT NOT NULL'],
        'userId' => ['VARCHAR(40) NOT NULL', 'TEXT NOT NULL'], 'minutes' => $int0, 'billable' => ['INT NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1'],
        'note' => ['VARCHAR(500) NULL', 'TEXT'], 'workedAt' => $ts, 'createdAt' => $ts,
    ], ['TimeEntry_ticket_idx' => ['ticketId'], 'TimeEntry_tenant_day_idx' => ['tenantId', 'workedAt']]);
    db_create_table('Contract', [
        'id' => $id, 'tenantId' => ['VARCHAR(40) NOT NULL', 'TEXT NOT NULL'], 'organizationId' => ['VARCHAR(40) NOT NULL', 'TEXT NOT NULL'],
        'name' => ['VARCHAR(120) NOT NULL', 'TEXT NOT NULL'], 'minutesIncluded' => ['INT NULL', 'INTEGER'],
        'startsOn' => ['VARCHAR(10) NOT NULL', 'TEXT NOT NULL'], 'endsOn' => ['VARCHAR(10) NULL', 'TEXT'],
        'firstResponseHours' => ['INT NULL', 'INTEGER'], 'resolutionHours' => ['INT NULL', 'INTEGER'],
        'notes' => ['TEXT NULL', 'TEXT'], 'active' => ['INT NOT NULL DEFAULT 1', 'INTEGER NOT NULL DEFAULT 1'], 'createdAt' => $ts,
    ], ['Contract_org_idx' => ['organizationId']]);
    db_create_table('ChatSession', [
        'id' => $id, 'tenantId' => ['VARCHAR(40) NOT NULL', 'TEXT NOT NULL'], 'tokenHash' => ['VARCHAR(64) NOT NULL', 'TEXT NOT NULL'],
        'visitorName' => ['VARCHAR(120) NOT NULL', 'TEXT NOT NULL'], 'visitorEmail' => ['VARCHAR(191) NOT NULL', 'TEXT NOT NULL'],
        'status' => ["VARCHAR(20) NOT NULL DEFAULT 'open'", "TEXT NOT NULL DEFAULT 'open'"], 'agentId' => $idn, 'ticketId' => $idn,
        'pageUrl' => ['VARCHAR(500) NULL', 'TEXT'], 'lastVisitorAt' => $tsn, 'lastAgentAt' => $tsn, 'agentSeenAt' => $tsn,
        'createdAt' => $ts, 'closedAt' => $tsn,
    ], ['ChatSession_tenant_idx' => ['tenantId', 'status'], 'ChatSession_token_idx' => ['tokenHash']]);
    db_create_table('ChatMessage', [
        'id' => $id, 'sessionId' => ['VARCHAR(40) NOT NULL', 'TEXT NOT NULL'], 'sender' => ['VARCHAR(10) NOT NULL', 'TEXT NOT NULL'],
        'authorId' => $idn, 'body' => ['TEXT NOT NULL', 'TEXT NOT NULL'], 'createdAt' => $ts,
    ], ['ChatMessage_session_idx' => ['sessionId', 'createdAt']]);
    db_add_column('SavedView', 'shared', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0');
    db_add_column('Ticket', 'sentiment', 'VARCHAR(20) NULL', 'TEXT');
    db_add_column('Ticket', 'aiTriagedAt', 'VARCHAR(30) NULL', 'TEXT');
    db_add_column('Webhook', 'options', 'MEDIUMTEXT NULL', 'TEXT');
}

/** v4: notification queue (push/email sent in the background, retried, logged) and rule alerts. */
function runtime_schema_v4(): void
{
    $my = db_driver() === 'mysql';
    $T = $my ? 'VARCHAR(40)' : 'TEXT';
    $S = $my ? 'VARCHAR(191)' : 'TEXT';
    $D = $my ? 'VARCHAR(30)' : 'TEXT';
    $I = $my ? 'INT' : 'INTEGER';
    $L = $my ? 'MEDIUMTEXT' : 'TEXT';
    $engine = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    db()->exec("CREATE TABLE IF NOT EXISTS \"NotificationJob\" (
  \"id\" $T NOT NULL PRIMARY KEY,
  \"tenantId\" $T NOT NULL,
  \"userId\" $T NOT NULL,
  \"channel\" $S NOT NULL,
  \"event\" $S NOT NULL,
  \"ticketId\" $T NULL,
  \"payload\" $L NOT NULL,
  \"status\" $S NOT NULL DEFAULT 'pending',
  \"attempts\" $I NOT NULL DEFAULT 0,
  \"sendAfter\" $D NOT NULL,
  \"lastError\" $L NULL,
  \"createdAt\" $D NOT NULL,
  \"sentAt\" $D NULL" . ($my ? ",
  KEY \"NotificationJob_due_idx\" (\"status\", \"sendAfter\"),
  KEY \"NotificationJob_tenant_idx\" (\"tenantId\", \"createdAt\")" : '') . "
)$engine");
    db()->exec("CREATE TABLE IF NOT EXISTS \"TicketAlert\" (
  \"ticketId\" $T NOT NULL,
  \"kind\" $S NOT NULL,
  \"createdAt\" $D NOT NULL,
  PRIMARY KEY (\"ticketId\", \"kind\")
)$engine");
    if (!$my) {
        db()->exec('CREATE INDEX IF NOT EXISTS "NotificationJob_due_idx" ON "NotificationJob"("status", "sendAfter")');
        db()->exec('CREATE INDEX IF NOT EXISTS "NotificationJob_tenant_idx" ON "NotificationJob"("tenantId", "createdAt")');
    }
}

/** v3: people can belong to several organizations (manager rights per organization). */
function runtime_schema_v3(): void
{
    $my = db_driver() === 'mysql';
    $T = $my ? 'VARCHAR(40)' : 'TEXT';
    db()->exec('CREATE TABLE IF NOT EXISTS "UserOrganization" (
  "userId" ' . $T . ' NOT NULL,
  "organizationId" ' . $T . ' NOT NULL,
  "isManager" ' . ($my ? 'INT' : 'INTEGER') . ' NOT NULL DEFAULT 0,
  "createdAt" ' . ($my ? 'VARCHAR(30)' : 'TEXT') . ' NOT NULL,
  PRIMARY KEY ("userId", "organizationId")' . ($my ? ',
  KEY "UserOrganization_org_idx" ("organizationId"),
  FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE,
  FOREIGN KEY ("organizationId") REFERENCES "Organization" ("id") ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '
)'));
    if (!$my) db()->exec('CREATE INDEX IF NOT EXISTS "UserOrganization_org_idx" ON "UserOrganization"("organizationId")');
    backfill_user_orgs();
}

/** Copy each user's single organization (User.organizationId) into UserOrganization. Idempotent. */
function backfill_user_orgs(): void
{
    $rows = q_all('SELECT u."id", u."organizationId", u."orgManager" FROM "User" u WHERE u."organizationId" IS NOT NULL
        AND NOT EXISTS (SELECT 1 FROM "UserOrganization" x WHERE x."userId" = u."id" AND x."organizationId" = u."organizationId")');
    $now = now_iso();
    foreach ($rows as $r) db_insert('UserOrganization', ['userId' => $r['id'], 'organizationId' => $r['organizationId'], 'isManager' => (int) $r['orgManager'], 'createdAt' => $now]);
}

/** v2: ticket templates, sub-tickets (Ticket.parentId) and CC email addresses. */
function runtime_schema_v2(): void
{
    $my = db_driver() === 'mysql';
    $T = $my ? 'VARCHAR(40)' : 'TEXT';
    $S = $my ? 'VARCHAR(191)' : 'TEXT';
    $L = $my ? 'MEDIUMTEXT' : 'TEXT';
    $D = $my ? 'VARCHAR(30)' : 'TEXT';
    $I = $my ? 'INT' : 'INTEGER';
    $engine = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    db()->exec("CREATE TABLE IF NOT EXISTS \"TicketTemplate\" (
  \"id\" $T NOT NULL PRIMARY KEY,
  \"tenantId\" $T NOT NULL,
  \"name\" $S NOT NULL,
  \"audience\" $S NOT NULL DEFAULT 'everyone',
  \"categoryId\" $T NULL,
  \"priority\" $S NULL,
  \"subject\" $S NULL,
  \"body\" $L NULL,
  \"tags\" $L NULL,
  \"assigneeIds\" $L NULL,
  \"subtasks\" $L NULL,
  \"order\" $I NOT NULL DEFAULT 0,
  \"createdAt\" $D NOT NULL,
  \"updatedAt\" $D NOT NULL" . ($my ? ",\n  KEY \"TicketTemplate_tenant_idx\" (\"tenantId\"),\n  FOREIGN KEY (\"tenantId\") REFERENCES \"Tenant\" (\"id\") ON DELETE CASCADE" : '') . "
)$engine");
    db()->exec("CREATE TABLE IF NOT EXISTS \"TicketCc\" (
  \"ticketId\" $T NOT NULL,
  \"email\" $S NOT NULL,
  \"addedById\" $T NULL,
  \"createdAt\" $D NOT NULL,
  PRIMARY KEY (\"ticketId\", \"email\")" . ($my ? ",\n  FOREIGN KEY (\"ticketId\") REFERENCES \"Ticket\" (\"id\") ON DELETE CASCADE" : '') . "
)$engine");
    if (!$my) db()->exec('CREATE INDEX IF NOT EXISTS "TicketTemplate_tenant_idx" ON "TicketTemplate"("tenantId")');
    if (!db_column_exists('Ticket', 'parentId')) {
        db()->exec('ALTER TABLE "Ticket" ADD COLUMN "parentId" ' . ($my ? 'VARCHAR(40) NULL' : 'TEXT'));
        db()->exec('CREATE INDEX ' . ($my ? '' : 'IF NOT EXISTS ') . '"Ticket_parent_idx" ON "Ticket" ("parentId")');
    }
}

/** v1: several assignees per ticket. */
function runtime_schema_v1(): void
{
        if (db_driver() === 'mysql') {
            db()->exec('CREATE TABLE IF NOT EXISTS "TicketAssignee" (
  "ticketId" VARCHAR(40) NOT NULL,
  "userId" VARCHAR(40) NOT NULL,
  "createdAt" VARCHAR(30) NOT NULL,
  PRIMARY KEY ("ticketId", "userId"),
  KEY "TicketAssignee_userId_idx" ("userId"),
  FOREIGN KEY ("ticketId") REFERENCES "Ticket" ("id") ON DELETE CASCADE,
  FOREIGN KEY ("userId") REFERENCES "User" ("id") ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        } else {
            db()->exec('CREATE TABLE IF NOT EXISTS "TicketAssignee" (
  "ticketId" TEXT NOT NULL REFERENCES "Ticket"("id") ON DELETE CASCADE,
  "userId" TEXT NOT NULL REFERENCES "User"("id") ON DELETE CASCADE,
  "createdAt" TEXT NOT NULL,
  PRIMARY KEY ("ticketId", "userId")
)');
            db()->exec('CREATE INDEX IF NOT EXISTS "TicketAssignee_userId_idx" ON "TicketAssignee"("userId")');
        }
}
