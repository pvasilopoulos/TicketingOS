<?php
declare(strict_types=1);

/* Sign in / sign up / sign out / password reset. */

function start_session_for(array $user): void
{
    $tenant = $user['tenantId'] ? q_one('SELECT "name","slug" FROM "Tenant" WHERE "id" = ?', [$user['tenantId']]) : null;
    create_session([
        'userId' => $user['id'],
        'role' => $user['role'],
        'name' => $user['name'],
        'email' => $user['email'],
        'tenantId' => $user['tenantId'],
        'tenantName' => $tenant['name'] ?? null,
        'tenantSlug' => $tenant['slug'] ?? null,
    ]);
}

function act_login(array $in): ?array
{
    $email = strtolower(str_in($in, 'email'));
    $password = (string) ($in['password'] ?? '');
    if (!is_valid_email($email)) return ['error' => 'Invalid email address'];
    if ($password === '') return ['error' => 'Password is required'];

    $user = q_one('SELECT * FROM "User" WHERE "email" = ?', [$email]);
    if (!$user || !verify_password($password, $user['passwordHash'])) {
        if ($user && $user['tenantId'] && is_staff($user['role'])) audit($user['tenantId'], null, 'sign-in-failed', "Failed sign-in for {$user['email']}", 'user', $user['id']);
        return ['error' => 'Invalid email or password'];
    }
    if (isset($user['active']) && !(int) $user['active']) {
        if ($user['tenantId']) audit($user['tenantId'], null, 'sign-in-blocked', "Blocked sign-in of deactivated account {$user['email']}", 'user', $user['id']);
        return ['error' => 'This account has been deactivated. Contact your workspace administrator.'];
    }
    if ($user['tenantId'] && is_staff($user['role'])) audit($user['tenantId'], $user, 'sign-in', "{$user['name']} signed in", 'user', $user['id']);
    db_update('User', ['lastLoginAt' => now_iso()], '"id" = ?', [$user['id']]);
    start_session_for($user);
    clear_active_tenant_id();
    redirect(home_for($user));
}

function act_register(array $in): ?array
{
    $org = str_in($in, 'organizationName');
    $name = str_in($in, 'name');
    $email = strtolower(str_in($in, 'email'));
    $password = (string) ($in['password'] ?? '');

    if (mb_strlen($org) < 2) return ['error' => 'Organization name must be at least 2 characters'];
    if (mb_strlen($org) > 60) return ['error' => 'Organization name is too long'];
    if (mb_strlen($name) < 2) return ['error' => 'Name must be at least 2 characters'];
    if (!is_valid_email($email)) return ['error' => 'Invalid email address'];
    if (strlen($password) < 6) return ['error' => 'Password must be at least 6 characters'];

    if (q_val('SELECT 1 FROM "User" WHERE "email" = ?', [$email])) {
        return ['error' => 'An account with this email already exists'];
    }

    // Generate a unique tenant slug.
    $base = slugify($org) ?: 'team';
    $slug = $base;
    $n = 1;
    while (q_val('SELECT 1 FROM "Tenant" WHERE "slug" = ?', [$slug])) $slug = $base . '-' . $n++;

    $user = db_tx(function () use ($org, $slug, $name, $email, $password) {
        $now = now_iso();
        $tenantId = cuid();
        db_insert('Tenant', ['id' => $tenantId, 'name' => $org, 'slug' => $slug, 'createdAt' => $now, 'updatedAt' => $now]);
        seed_tenant_defaults($tenantId);
        $u = [
            'id' => cuid(), 'email' => $email, 'name' => $name, 'passwordHash' => hash_password($password),
            'role' => 'ADMIN', 'avatarColor' => AVATAR_COLORS[0], 'createdAt' => $now, 'updatedAt' => $now, 'tenantId' => $tenantId,
        ];
        db_insert('User', $u);
        return $u;
    });

    start_session_for($user);
    redirect('/dashboard');
}

function act_logout(array $in): ?array
{
    destroy_session();
    clear_active_tenant_id();
    redirect('/login');
}

const RESET_TTL = 3600; // 1 hour

/** Request a password-reset link. Always a generic success (no enumeration). */
function act_forgot(array $in): ?array
{
    $email = strtolower(str_in($in, 'email'));
    $generic = ['success' => "If an account exists for that email, we've sent a reset link."];
    if (!$email || !str_contains($email, '@')) return $generic;

    $user = q_one('SELECT * FROM "User" WHERE "email" = ?', [$email]);
    if (!$user || (isset($user['active']) && !(int) $user['active'])) return $generic;
    send_reset_link($user);
    return $generic;
}

/**
 * Issue a one-time password link (replacing older ones) and email it.
 * $ttl: lifetime in seconds. Returns ['link' => …, 'emailed' => bool].
 */
function send_reset_link(array $user, int $ttl = RESET_TTL, bool $welcome = false): array
{
    q('DELETE FROM "PasswordResetToken" WHERE "userId" = ?', [$user['id']]);
    $raw = bin2hex(random_bytes(32));
    db_insert('PasswordResetToken', [
        'id' => cuid(), 'userId' => $user['id'], 'tokenHash' => hash('sha256', $raw),
        'expiresAt' => now_iso($ttl), 'usedAt' => null, 'createdAt' => now_iso(),
    ]);
    $link = app_url() . '/reset?token=' . $raw;
    // Dev convenience: the link is also written to the PHP error log.
    error_log("[password-link] {$user['email']} -> $link");

    $emailed = false;
    if ($user['tenantId']) {
        $hours = (int) round($ttl / 3600);
        $r = send_tenant_email($user['tenantId'], [
            'to' => $user['email'],
            'subject' => $welcome ? 'You’ve been invited to TicketingOS' : 'Reset your TicketingOS password',
            'html' => '<p>Hi ' . e($user['name']) . ',</p>'
                . ($welcome ? '<p>An account was created for you. Choose your password to get started (the link expires in ' . $hours . ' hours):</p>'
                    : '<p>We received a request to reset your password. This link expires in ' . $hours . ' hour' . ($hours === 1 ? '' : 's') . ':</p>')
                . '<p><a href="' . e($link) . '">' . ($welcome ? 'Set my password' : 'Reset my password') . '</a></p>'
                . ($welcome ? '' : '<p>If you didn\'t request this, you can ignore this email.</p>'),
        ]);
        $emailed = (bool) ($r['ok'] ?? false);
    }
    return ['link' => $link, 'emailed' => $emailed];
}

function act_reset(array $in): ?array
{
    $token = str_in($in, 'token');
    $password = (string) ($in['password'] ?? '');
    $confirm = (string) ($in['confirm'] ?? '');
    if (strlen($token) < 10) return ['error' => 'Invalid input'];
    if (strlen($password) < 6) return ['error' => 'Password must be at least 6 characters'];
    if ($password !== $confirm) return ['error' => 'Passwords do not match'];

    $row = q_one('SELECT * FROM "PasswordResetToken" WHERE "tokenHash" = ?', [hash('sha256', $token)]);
    if (!$row || $row['usedAt'] || $row['expiresAt'] < now_iso()) {
        return ['error' => 'This reset link is invalid or has expired. Please request a new one.'];
    }
    // The workspace's password policy applies.
    $tenantId = q_val('SELECT "tenantId" FROM "User" WHERE "id" = ?', [$row['userId']]);
    if ($problem = password_problem($password, $tenantId)) return ['error' => $problem];
    db_tx(function () use ($row, $password) {
        db_update('User', ['passwordHash' => hash_password($password), 'updatedAt' => now_iso()], '"id" = ?', [$row['userId']]);
        db_update('PasswordResetToken', ['usedAt' => now_iso()], '"id" = ?', [$row['id']]);
    });
    redirect('/login?reset=1');
}
