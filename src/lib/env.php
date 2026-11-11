<?php
declare(strict_types=1);

/** Minimal .env loader (KEY=value, optional quotes, # comments). Real env vars win. */
function load_env(string $path): void
{
    if (!is_file($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if (getenv($k) === false) {
            putenv("$k=$v");
            $_ENV[$k] = $v;
        }
    }
}

function env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? getenv($key);
    return ($v === false || $v === null) ? $default : (string) $v;
}

function app_url(): string
{
    return rtrim(env('APP_URL', 'http://localhost:8000'), '/');
}

function auth_secret(): string
{
    return env('AUTH_SECRET', 'insecure-dev-secret');
}
