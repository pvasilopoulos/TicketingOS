<?php
declare(strict_types=1);

/* Request/response helpers: redirects, JSON, CSRF, flash messages. */

final class HttpExit extends Exception {}

function is_ajax(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function request_path(): string
{
    $p = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $p = '/' . trim(rawurldecode($p), '/');
    return $p;
}

function redirect(string $to, int $code = 303): never
{
    if (is_ajax() && $_SERVER['REQUEST_METHOD'] === 'POST') {
        json_out(['redirect' => $to]);
    }
    header('Location: ' . $to, true, $code);
    throw new HttpExit();
}

function json_out(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    throw new HttpExit();
}

function abort(int $status, string $message = ''): never
{
    http_response_code($status);
    if ($status === 404 && !is_ajax()) {
        echo render('pages/not_found', [], 'base');
    } else {
        header('Content-Type: text/plain; charset=utf-8');
        echo $message ?: ($status === 404 ? 'Not found' : 'Error');
    }
    throw new HttpExit();
}

/** Only allow same-site relative paths for redirects. */
function safe_back(?string $url, string $fallback = '/dashboard'): string
{
    if (!$url) return $fallback;
    if (preg_match('#^https?://#i', $url)) {
        $p = parse_url($url);
        $host = $p['host'] ?? '';
        if ($host !== ($_SERVER['HTTP_HOST'] ?? '') && $host !== parse_url(app_url(), PHP_URL_HOST)) return $fallback;
        $url = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    }
    if (!str_starts_with($url, '/') || str_starts_with($url, '//')) return $fallback;
    return $url;
}

function is_https(): bool
{
    return ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function set_cookie(string $name, string $value, int $maxAge = 0, bool $httpOnly = true): void
{
    setcookie($name, $value, [
        'expires' => $maxAge > 0 ? time() + $maxAge : ($maxAge < 0 ? time() - 3600 : 0),
        'path' => '/',
        'secure' => is_https(),
        'httponly' => $httpOnly,
        'samesite' => 'Lax',
    ]);
    if ($maxAge < 0) unset($_COOKIE[$name]);
    else $_COOKIE[$name] = $value;
}

/* ---------- CSRF (double-submit cookie) ---------- */

function csrf_token(): string
{
    static $tok = null;
    if ($tok) return $tok;
    $tok = $_COOKIE['tos_csrf'] ?? '';
    if (!preg_match('/^[a-f0-9]{40}$/', $tok)) {
        $tok = bin2hex(random_bytes(20));
        set_cookie('tos_csrf', $tok, 0, true);
    }
    return $tok;
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $sent = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $cookie = $_COOKIE['tos_csrf'] ?? '';
    if (!$cookie || !is_string($sent) || !hash_equals($cookie, $sent)) {
        abort(419, 'Your session expired. Please reload the page and try again.');
    }
}

/* ---------- Flash state (per form) ---------- */

function session_boot(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_name('tos_flash');
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
            'cookie_secure' => is_https(),
            'use_strict_mode' => true,
        ]);
    }
}

function flash_set(string $form, array $state): void
{
    session_boot();
    $_SESSION['flash'][$form] = $state;
}

/** Read (and consume) a form's flash state. */
function flash(string $form): ?array
{
    static $cache = [];
    if (array_key_exists($form, $cache)) return $cache[$form];
    if (session_status() === PHP_SESSION_NONE && !isset($_COOKIE['tos_flash'])) return $cache[$form] = null;
    session_boot();
    $v = $_SESSION['flash'][$form] ?? null;
    unset($_SESSION['flash'][$form]);
    return $cache[$form] = $v;
}

/** Previously submitted value for a form field (re-filled after a validation error). */
function old(string $form, string $field, string $default = ''): string
{
    $f = flash_peek($form);
    return isset($f['old'][$field]) && is_string($f['old'][$field]) ? $f['old'][$field] : $default;
}

function flash_peek(string $form): ?array
{
    return flash($form);
}
