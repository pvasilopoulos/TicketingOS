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

function q(string $sql, array $params = []): PDOStatement
{
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
