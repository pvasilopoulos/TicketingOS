<?php
declare(strict_types=1);

/** HTML-escape. */
function e(mixed $v): string
{
    return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** JSON for embedding in HTML attributes / <script> blocks. */
function json_attr(mixed $v): string
{
    return e(json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Join CSS classes, skipping falsy values (like clsx). */
function cx(...$parts): string
{
    $out = [];
    foreach ($parts as $p) {
        if (is_array($p)) {
            foreach ($p as $k => $v) {
                if (is_int($k)) { if ($v) $out[] = $v; }
                elseif ($v) $out[] = $k;
            }
        } elseif ($p) {
            $out[] = $p;
        }
    }
    return implode(' ', $out);
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/u', trim($name)) ?: [];
    $s = '';
    foreach ($parts as $p) {
        if ($p !== '') $s .= mb_substr($p, 0, 1);
        if (mb_strlen($s) >= 2) break;
    }
    return mb_strtoupper($s);
}

function first_name(string $name): string
{
    return explode(' ', trim($name))[0] ?? $name;
}

/** Human distance, matching date-fns formatDistance wording. */
function distance_words(float $seconds): string
{
    $s = abs($seconds);
    $m = $s / 60;
    if ($s < 30) return 'less than a minute';
    if ($s < 90) return '1 minute';
    if ($m < 45) return round($m) . ' minutes';
    if ($m < 90) return 'about 1 hour';
    if ($m < 1440) return 'about ' . round($m / 60) . ' hours';
    if ($m < 2520) return '1 day';
    if ($m < 43200) return round($m / 1440) . ' days';
    if ($m < 86400) {
        $mo = (int) round($m / 43200);
        return 'about ' . $mo . ' month' . ($mo === 1 ? '' : 's');
    }
    $months = (int) floor($m / 43200);
    if ($months < 12) return $months . ' months';
    $years = intdiv($months, 12);
    $rem = $months % 12;
    if ($rem < 3) return 'about ' . $years . ' year' . ($years === 1 ? '' : 's');
    if ($rem < 9) return 'over ' . $years . ' year' . ($years === 1 ? '' : 's');
    return 'almost ' . ($years + 1) . ' years';
}

function time_ago(?string $iso): string
{
    $t = ts($iso);
    if ($t === null) return '';
    $diff = microtime(true) - $t;
    $w = distance_words($diff);
    return $diff >= 0 ? "$w ago" : "in $w";
}

function local_dt(?string $iso): ?DateTimeImmutable
{
    if (!$iso) return null;
    return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone(date_default_timezone_get()));
}

function format_date(?string $iso): string
{
    $d = local_dt($iso);
    return $d ? $d->format('M j, Y \a\t H:i') : '';
}

/** SLA due-date display: ['text', 'tone'] or null. */
function due_info(?string $dueAt, bool $isClosed): ?array
{
    if (!$dueAt || $isClosed) return null;
    $diff = ts($dueAt) - microtime(true);
    $rel = distance_words($diff);
    if ($diff < 0) return ['text' => "Overdue by $rel", 'tone' => 'overdue'];
    if ($diff < 4 * 3600) return ['text' => "Due in $rel", 'tone' => 'soon'];
    return ['text' => "Due in $rel", 'tone' => 'ok'];
}

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) return '0 B';
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    $i = min($i, 3);
    $v = round($bytes / (1024 ** $i), 1);
    return rtrim(rtrim(number_format($v, 1, '.', ''), '0'), '.') . ' ' . $sizes[$i];
}

function slugify(string $input): string
{
    $s = strtolower(trim($input));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return substr(trim($s, '-'), 0, 40);
}

function str_in(array $src, string $key, int $max = 0): string
{
    $v = $src[$key] ?? '';
    $v = is_string($v) ? trim($v) : '';
    return $max > 0 ? mb_substr($v, 0, $max) : $v;
}

function is_valid_email(string $v): bool
{
    return (bool) filter_var($v, FILTER_VALIDATE_EMAIL);
}

function minutes_to_hhmm(?int $v): string
{
    if ($v === null) return '';
    return sprintf('%02d:%02d', intdiv($v, 60), $v % 60);
}

function hhmm_to_minutes(string $v): ?int
{
    if (!preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m)) return null;
    return min(23, (int) $m[1]) * 60 + min(59, (int) $m[2]);
}

/** Pluck one key from a list of rows. */
function pluck(array $rows, string $key): array
{
    return array_map(fn ($r) => $r[$key], $rows);
}

/** Index rows by a key. */
function key_by(array $rows, string $key): array
{
    $o = [];
    foreach ($rows as $r) $o[$r[$key]] = $r;
    return $o;
}
