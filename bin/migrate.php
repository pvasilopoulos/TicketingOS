<?php
declare(strict_types=1);

/*
 * Create the database schema.
 *   php bin/migrate.php           create missing tables
 *   php bin/migrate.php --fresh   drop everything first (DANGER: deletes all data)
 */

require __DIR__ . '/../src/bootstrap.php';

$fresh = in_array('--fresh', $argv, true);
$sql = file_get_contents(ROOT . '/database/schema.sqlite.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

$tables = [];
foreach ($statements as $s) {
    if (preg_match('/CREATE TABLE IF NOT EXISTS "(\w+)"/', $s, $m)) $tables[] = $m[1];
}

if ($fresh) {
    if (db_driver() === 'mysql') db()->exec('SET FOREIGN_KEY_CHECKS = 0');
    else db()->exec('PRAGMA foreign_keys = OFF');
    foreach (array_reverse($tables) as $t) db()->exec('DROP TABLE IF EXISTS ' . qi($t));
    if (db_driver() === 'mysql') db()->exec('SET FOREIGN_KEY_CHECKS = 1');
    else db()->exec('PRAGMA foreign_keys = ON');
    echo "Dropped " . count($tables) . " tables.\n";
}

foreach ($statements as $s) {
    if (db_driver() === 'mysql') {
        $s = to_mysql($s);
        if ($s === '') continue;
        try {
            db()->exec($s);
        } catch (PDOException $e) {
            // Re-running: indexes already exist (MySQL has no CREATE INDEX IF NOT EXISTS).
            if (!str_contains($e->getMessage(), 'Duplicate key name')) throw $e;
        }
    } else {
        db()->exec($s);
    }
}

/* ---------- Incremental upgrades for databases created by older versions ---------- */

function column_exists(string $table, string $col): bool
{
    if (db_driver() === 'mysql') {
        return (bool) q_val('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = LOWER(?) AND COLUMN_NAME = ?', [$table, $col]);
    }
    foreach (q_all('PRAGMA table_info(' . qi($table) . ')') as $c) if ($c['name'] === $col) return true;
    return false;
}

if (!column_exists('Ticket', 'searchText')) {
    db()->exec('ALTER TABLE "Ticket" ADD COLUMN "searchText" ' . (db_driver() === 'mysql' ? 'MEDIUMTEXT NULL' : 'TEXT'));
    echo "Added Ticket.searchText\n";
}
foreach (["mergedIntoId" => ["VARCHAR(40) NULL", "TEXT"], "firstResponseAt" => ["VARCHAR(30) NULL", "TEXT"]] as $col => [$my, $lite]) {
    if (!column_exists("Ticket", $col)) {
        db()->exec("ALTER TABLE \"Ticket\" ADD COLUMN \"$col\" " . (db_driver() === "mysql" ? $my : $lite));
        echo "Added Ticket.$col
";
    }
}
// Users: custom role, active flag, last login.
foreach (["customRoleId" => ["VARCHAR(40) NULL", "TEXT"], "active" => ["INT NOT NULL DEFAULT 1", "INTEGER NOT NULL DEFAULT 1"], "lastLoginAt" => ["VARCHAR(30) NULL", "TEXT"]] as $col => [$my, $lite]) {
    if (!column_exists("User", $col)) {
        db()->exec("ALTER TABLE \"User\" ADD COLUMN \"$col\" " . (db_driver() === "mysql" ? $my : $lite));
        echo "Added User.$col
";
    }
}
// v3: agent groups, customer organizations, ticket source.
$v3cols = [
    ['Ticket', 'groupId', 'VARCHAR(40) NULL', 'TEXT'],
    ['Ticket', 'source', "VARCHAR(20) NOT NULL DEFAULT 'web'", "TEXT NOT NULL DEFAULT 'web'"],
    ['Category', 'groupId', 'VARCHAR(40) NULL', 'TEXT'],
    ['User', 'organizationId', 'VARCHAR(40) NULL', 'TEXT'],
    ['User', 'orgManager', 'INT NOT NULL DEFAULT 0', 'INTEGER NOT NULL DEFAULT 0'],
];
foreach ($v3cols as [$tbl, $col, $my, $lite]) {
    if (!column_exists($tbl, $col)) {
        db()->exec('ALTER TABLE ' . qi($tbl) . ' ADD COLUMN ' . qi($col) . ' ' . (db_driver() === 'mysql' ? $my : $lite));
        echo "Added $tbl.$col\n";
    }
}
foreach (['Ticket_tenant_group_idx' => ['Ticket', '"tenantId", "groupId"'], 'User_org_idx' => ['User', '"organizationId"']] as $name => [$tbl, $c]) {
    try { db()->exec('CREATE INDEX ' . (db_driver() === 'mysql' ? '' : 'IF NOT EXISTS ') . qi($name) . ' ON ' . qi($tbl) . " ($c)"); }
    catch (PDOException $e) { if (!str_contains($e->getMessage(), 'Duplicate key name')) throw $e; }
}

// Indexes used by sorting and reports (CREATE INDEX IF NOT EXISTS is SQLite/MariaDB-safe; MySQL ignores dupes below).
foreach (["Ticket_tenant_updated_idx" => "\"tenantId\", \"updatedAt\"", "Ticket_tenant_closed_idx" => "\"tenantId\", \"closedAt\""] as $name => $cols) {
    try { db()->exec("CREATE INDEX " . (db_driver() === "mysql" ? "" : "IF NOT EXISTS ") . "\"$name\" ON \"Ticket\" ($cols)"); echo "Added index $name
"; }
    catch (PDOException $e) { if (!str_contains($e->getMessage(), "Duplicate key name")) throw $e; }
}
// Backfill first-response time: first public reply by staff (not the requester).
$n = q("UPDATE \"Ticket\" SET \"firstResponseAt\" = (SELECT MIN(c.\"createdAt\") FROM \"Comment\" c JOIN \"User\" u ON u.\"id\" = c.\"authorId\"
    WHERE c.\"ticketId\" = \"Ticket\".\"id\" AND c.\"isInternal\" = 0 AND c.\"authorId\" <> \"Ticket\".\"requesterId\" AND u.\"role\" IN ('ADMIN', 'AGENT', 'SUPER_ADMIN'))
    WHERE \"firstResponseAt\" IS NULL")->rowCount();
if ($n) echo "Backfilled firstResponseAt for $n tickets
";
if (db_driver() === 'mysql' && !q_val("SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND LOWER(TABLE_NAME) = 'ticket' AND INDEX_NAME = 'Ticket_search_ft'")) {
    db()->exec('CREATE FULLTEXT INDEX "Ticket_search_ft" ON "Ticket" ("searchText")');
    echo "Added FULLTEXT index on Ticket.searchText\n";
}

// Build the search document for any ticket that doesn't have one yet.
$missing = pluck(q_all('SELECT "id" FROM "Ticket" WHERE "searchText" IS NULL'), 'id');
foreach (array_chunk($missing, 500) as $chunk) {
    db_tx(function () use ($chunk) { foreach ($chunk as $id) reindex_ticket($id); });
}
if ($missing) echo 'Indexed ' . count($missing) . " tickets for search\n";

// Permissions v2: "settings.manage" was split per section and replies / tags /
// followers became permissions. Saved role sets keep what they could do before.
$added = ['tickets.reply', 'tickets.tags', 'tickets.team'];
foreach (q_all('SELECT "id" FROM "Tenant"') as $t) {
    if ((int) tenant_setting($t['id'], 'permissionsVersion', 1) >= 2) continue;
    foreach (q_all('SELECT "id","permissions" FROM "Role" WHERE "tenantId" = ?', [$t['id']]) as $r) {
        $p = json_decode((string) $r['permissions'], true) ?: [];
        db_update('Role', ['permissions' => json_encode(clean_permissions(array_merge($p, $added)))], '"id" = ?', [$r['id']]);
    }
    $ap = tenant_setting($t['id'], 'agentPermissions');
    if (is_array($ap)) tenant_setting_set($t['id'], 'agentPermissions', clean_permissions(array_merge($ap, $added)));
    tenant_setting_set($t['id'], 'permissionsVersion', 2);
}

echo "Schema ready (" . db_driver() . ", " . count($tables) . " tables).\n";

/** Translate the SQLite DDL into MySQL/MariaDB DDL. */
function to_mysql(string $s): string
{
    if (str_starts_with($s, 'CREATE INDEX')) {
        return str_replace('CREATE INDEX IF NOT EXISTS', 'CREATE INDEX', $s);
    }
    if (!str_starts_with($s, 'CREATE TABLE')) return $s;

    $long = ['description', 'body', 'message', 'summary', 'query', 'url', 'userAgent', 'searchText', 'payload', 'comment', 'notes',
        'smtpPassword', 'msClientSecret', 'passwordHash'];
    $lines = explode("\n", $s);
    $out = [];
    $fks = [];
    foreach ($lines as $line) {
        if (preg_match('/^\s*"(\w+)" (TEXT|INTEGER)(.*?)(,?)$/', $line, $m)) {
            [$all, $col, $type, $rest] = $m;
            if (preg_match('/ REFERENCES ("\w+"\("\w+"\))( ON DELETE (CASCADE|SET NULL))?/', $rest, $r)) {
                $fks[] = "  FOREIGN KEY (\"$col\") REFERENCES {$r[1]}" . ($r[2] ?? '');
                $rest = str_replace($r[0], '', $rest);
            }
            if ($type === 'INTEGER') {
                $sqlType = 'INT';
            } elseif ($col === 'endpoint') {
                $sqlType = 'VARCHAR(500)';
            } elseif (in_array($col, ['defaults', 'prefs', 'value', 'permissions', 'options', 'events', 'meta', 'recipients', 'error', 'lastError'], true)) {
                $sqlType = 'VARCHAR(4000)';
            } elseif (in_array($col, $long, true)) {
                $sqlType = 'MEDIUMTEXT';
            } elseif ($col === 'id' || str_ends_with($col, 'Id') && !str_starts_with($col, 'ms')) {
                $sqlType = 'VARCHAR(40)'; // cuid / uuid — compact keys keep indexes small
            } elseif (str_ends_with($col, 'At')) {
                $sqlType = 'VARCHAR(30)'; // ISO-8601 UTC timestamp
            } elseif (in_array($col, ['status', 'priority', 'key', 'role', 'type', 'scope', 'provider', 'trigger', 'actionType', 'conditionField', 'priorityThreshold', 'color', 'avatarColor', 'tokenHash'], true)) {
                $sqlType = 'VARCHAR(64)';
            } else {
                $sqlType = 'VARCHAR(191)';
            }
            $out[] = "  \"$col\" $sqlType" . rtrim($rest) . ',';
        } else {
            $out[] = $line;
        }
    }
    // Re-assemble: strip the trailing ");", add FKs, close with engine options.
    $body = implode("\n", $out);
    $body = preg_replace('/,?\s*\)\s*$/', '', $body);
    $body = rtrim($body, ", \n");
    if ($fks) $body .= ",\n" . implode(",\n", $fks);
    return $body . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
}
