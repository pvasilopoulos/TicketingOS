<?php
declare(strict_types=1);

/*
 * Stateless session: an HS256-signed JWT in an httpOnly cookie (7 days),
 * same as the original app.
 */

const SESSION_COOKIE = 'tos_session';
const SESSION_MAX_AGE = 60 * 60 * 24 * 7;

function b64url_encode(string $bin): string
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function b64url_decode(string $s): string
{
    $pad = strlen($s) % 4;
    if ($pad) $s .= str_repeat('=', 4 - $pad);
    return base64_decode(strtr($s, '-_', '+/'), true) ?: '';
}

function jwt_sign(array $payload, int $ttl): string
{
    $now = time();
    $payload += ['iat' => $now, 'exp' => $now + $ttl];
    $h = b64url_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $p = b64url_encode(json_encode($payload));
    $sig = b64url_encode(hash_hmac('sha256', "$h.$p", auth_secret(), true));
    return "$h.$p.$sig";
}

function jwt_verify(string $token): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;
    [$h, $p, $s] = $parts;
    $expected = b64url_encode(hash_hmac('sha256', "$h.$p", auth_secret(), true));
    if (!hash_equals($expected, $s)) return null;
    $payload = json_decode(b64url_decode($p), true);
    if (!is_array($payload) || ($payload['exp'] ?? 0) < time()) return null;
    return $payload;
}

function create_session(array $payload): void
{
    set_cookie(SESSION_COOKIE, jwt_sign($payload, SESSION_MAX_AGE), SESSION_MAX_AGE);
}

function get_session(): ?array
{
    $tok = $_COOKIE[SESSION_COOKIE] ?? '';
    return $tok ? jwt_verify($tok) : null;
}

function destroy_session(): void
{
    set_cookie(SESSION_COOKIE, '', -1);
}
