<?php
declare(strict_types=1);

/*
 * Single sign-on with Microsoft 365 / Entra ID and Google (OpenID Connect).
 *
 * The platform has one app registration per provider (a super admin enters it in
 * Settings → Security → Sign-in providers, or SSO_* in .env). Each workspace then
 * decides in Settings → Security who may use it, whether customers of its
 * organizations' email domains get an account on their first sign-in, and
 * whether agents must use it.
 *
 * Flow: /sso/{provider} → provider → /sso/{provider}/callback. The state, nonce
 * and PKCE verifier travel in a short signed cookie. The ID token comes straight
 * from the provider's token endpoint over TLS (OpenID Connect Core 3.1.3.7), so
 * its issuer, audience, expiry and nonce are checked instead of its signature.
 */

const SSO_PROVIDERS = ['microsoft' => 'Microsoft', 'google' => 'Google'];
const SSO_COOKIE = 'tos_sso';
/** Microsoft personal accounts (outlook.com, hotmail.com…) all sign in from this directory. */
const SSO_MS_CONSUMER_TID = '9188040d-6c67-4c5b-b112-36a304b66dad';

/* ---------- Platform app registrations ---------- */

function sso_config_file(): string
{
    return ROOT . '/storage/branding/sso.json';
}

/**
 * ['microsoft' => ['clientId' => …, 'clientSecret' => plain, 'directory' => tenant ID or '', 'source' => 'settings'|'env'|null], 'google' => …]
 * directory: set for a single-tenant Microsoft app (only that directory's people can use it); empty = any directory.
 */
function sso_platform(): array
{
    static $c = null;
    if ($c !== null) return $c;
    $raw = @file_get_contents(sso_config_file());
    $saved = $raw ? (json_decode($raw, true) ?: []) : [];
    $c = [];
    foreach (array_keys(SSO_PROVIDERS) as $p) {
        $id = trim((string) ($saved[$p]['clientId'] ?? ''));
        $secret = decrypt_secret($saved[$p]['clientSecret'] ?? '');
        $dir = strtolower(trim((string) ($saved[$p]['directory'] ?? '')));
        $expires = (string) ($saved[$p]['expires'] ?? '');
        $source = $id !== '' && $secret !== '' ? 'settings' : null;
        if (!$source) {
            $k = 'SSO_' . strtoupper($p) . '_';
            $id = (string) env($k . 'CLIENT_ID', '');
            $secret = (string) env($k . 'CLIENT_SECRET', '');
            $dir = strtolower(trim((string) env($k . 'DIRECTORY', '')));
            $source = $id !== '' && $secret !== '' ? 'env' : null;
        }
        $c[$p] = ['clientId' => $id, 'clientSecret' => $secret, 'directory' => $p === 'microsoft' ? $dir : '', 'expires' => $source === 'settings' ? $expires : '', 'source' => $source];
    }
    return $c;
}

function sso_configured(string $provider): bool
{
    return (bool) (sso_platform()[$provider]['source'] ?? null);
}

/** Providers set up on the platform (shown on the sign-in page). */
function sso_available(): array
{
    return array_values(array_filter(array_keys(SSO_PROVIDERS), 'sso_configured'));
}

/** Save (or with $clear remove) a provider's app registration. An empty secret keeps the saved one. */
function sso_platform_save(string $provider, string $clientId, string $secret, bool $clear, string $directory = '', string $expires = ''): void
{
    $raw = @file_get_contents(sso_config_file());
    $data = $raw ? (json_decode($raw, true) ?: []) : [];
    if ($clear) {
        unset($data[$provider]);
    } else {
        $data[$provider] = ['clientId' => $clientId, 'clientSecret' => $secret !== '' ? encrypt_secret($secret) : ($data[$provider]['clientSecret'] ?? ''), 'directory' => $directory, 'expires' => $expires, 'reminded' => $data[$provider]['reminded'] ?? null];
    }
    if (!is_dir(dirname(sso_config_file()))) mkdir(dirname(sso_config_file()), 0775, true);
    file_put_contents(sso_config_file(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** The last expiry reminder sent for a provider's secret ("date|stage"); with $set, record it. */
function sso_reminded(string $provider, ?string $set = null): ?string
{
    $raw = @file_get_contents(sso_config_file());
    $data = $raw ? (json_decode($raw, true) ?: []) : [];
    if ($set === null) return $data[$provider]['reminded'] ?? null;
    if (!isset($data[$provider])) return null;
    $data[$provider]['reminded'] = $set;
    file_put_contents(sso_config_file(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
    return $set;
}

function sso_redirect_uri(string $provider): string
{
    return app_url() . '/sso/' . $provider . '/callback';
}

/** Endpoints; the bases can point elsewhere in .env (a proxy, or a local stand-in when testing). */
function sso_endpoints(string $provider): array
{
    if ($provider === 'microsoft') {
        $b = rtrim((string) env('SSO_MICROSOFT_BASE', 'https://login.microsoftonline.com'), '/');
        // A single-tenant app must use its own directory's endpoints; a multi-tenant one uses /common.
        $dir = sso_platform()['microsoft']['directory'] ?: 'common';
        return ['authorize' => "$b/$dir/oauth2/v2.0/authorize", 'token' => "$b/$dir/oauth2/v2.0/token", 'issuer' => "$b/{tid}/v2.0"];
    }
    $a = rtrim((string) env('SSO_GOOGLE_AUTH_BASE', 'https://accounts.google.com'), '/');
    $t = rtrim((string) env('SSO_GOOGLE_TOKEN_BASE', 'https://oauth2.googleapis.com'), '/');
    return ['authorize' => "$a/o/oauth2/v2/auth", 'token' => "$t/token", 'issuer' => $a];
}

/* ---------- Workspace policy ---------- */

/**
 * Settings → Security → Single sign-on.
 * staff / customers: who may sign in with a provider; providers: which ones;
 * autoCreate: unknown people whose email domain belongs to one of the
 * workspace's organizations become customers of it on their first sign-in;
 * requireAgents: agents can't sign in with a password (administrators still can);
 * msTenants: Microsoft directories trusted even when Microsoft doesn't mark the
 * email as verified.
 */
function sso_policy(string $tenantId): array
{
    $v = tenant_setting($tenantId, 'sso');
    $v = is_array($v) ? $v : [];
    return [
        'providers' => array_values(array_intersect((array) ($v['providers'] ?? []), array_keys(SSO_PROVIDERS))),
        'staff' => (bool) ($v['staff'] ?? true),
        'customers' => (bool) ($v['customers'] ?? true),
        'autoCreate' => (bool) ($v['autoCreate'] ?? false),
        'requireAgents' => (bool) ($v['requireAgents'] ?? false),
        'msTenants' => array_values(array_filter(array_map('strtolower', (array) ($v['msTenants'] ?? [])))),
    ];
}

/** Why this user can't sign in with a password, or null. */
function sso_password_blocked(array $user): ?string
{
    if (!$user['tenantId'] || $user['role'] !== 'AGENT') return null;
    $p = sso_policy($user['tenantId']);
    $usable = array_values(array_filter($p['providers'], 'sso_configured'));
    if (!$p['requireAgents'] || !$p['staff'] || !$usable) return null;
    return 'Your workspace signs agents in with ' . implode(' or ', array_map(fn ($x) => SSO_PROVIDERS[$x], $usable)) . '. Use the button below.';
}

/* ---------- Linked accounts ---------- */

function sso_identities(string $userId): array
{
    return q_all('SELECT * FROM "UserIdentity" WHERE "userId" = ? ORDER BY "provider"', [$userId]);
}

/* ---------- Sign-in flow ---------- */

/** GET /sso/{provider}: off to the provider. */
function sso_start(string $provider): never
{
    if (!isset(SSO_PROVIDERS[$provider]) || !sso_configured($provider)) redirect('/login');
    $cfg = sso_platform()[$provider];
    $state = bin2hex(random_bytes(16));
    $nonce = bin2hex(random_bytes(16));
    $verifier = b64url_encode(random_bytes(32));
    set_cookie(SSO_COOKIE, jwt_sign(['p' => $provider, 's' => $state, 'n' => $nonce, 'v' => $verifier], 600), 600);
    $q = [
        'client_id' => $cfg['clientId'], 'response_type' => 'code', 'redirect_uri' => sso_redirect_uri($provider),
        'scope' => 'openid email profile', 'state' => $state, 'nonce' => $nonce,
        'code_challenge' => b64url_encode(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
        'prompt' => 'select_account',
    ];
    if ($provider === 'microsoft') $q['response_mode'] = 'query';
    redirect(sso_endpoints($provider)['authorize'] . '?' . http_build_query($q), 302);
}

/** GET /sso/{provider}/callback */
function sso_callback(string $provider): never
{
    $fail = function (string $msg): never {
        set_cookie(SSO_COOKIE, '', -1);
        flash_set('login', ['error' => $msg]);
        redirect('/login');
    };
    if (!isset(SSO_PROVIDERS[$provider]) || !sso_configured($provider)) $fail('This sign-in option isn’t available.');
    $name = SSO_PROVIDERS[$provider];
    $st = jwt_verify((string) ($_COOKIE[SSO_COOKIE] ?? ''));
    if (isset($_GET['error'])) {
        $fail(($_GET['error'] === 'access_denied' ? "$name sign-in was cancelled." : "$name couldn’t sign you in: " . mb_substr((string) ($_GET['error_description'] ?? $_GET['error']), 0, 200)));
    }
    if (!$st || ($st['p'] ?? '') !== $provider || !hash_equals((string) ($st['s'] ?? ''), (string) ($_GET['state'] ?? ''))) {
        $fail('The sign-in took too long or was started in another window. Please try again.');
    }
    $code = (string) ($_GET['code'] ?? '');
    if ($code === '') $fail("$name didn’t send a sign-in code. Please try again.");
    set_cookie(SSO_COOKIE, '', -1);

    $claims = sso_exchange($provider, $code, (string) $st['v'], (string) $st['n']);
    if (is_string($claims)) $fail($claims);
    $user = sso_resolve($provider, $claims);
    if (is_string($user)) $fail($user);

    if ($user['tenantId'] && is_staff($user['role'])) audit($user['tenantId'], $user, 'sign-in', "{$user['name']} signed in with $name", 'user', $user['id']);
    db_update('User', ['lastLoginAt' => now_iso()], '"id" = ?', [$user['id']]);
    start_session_for($user);
    clear_active_tenant_id();
    redirect(home_for($user));
}

/** Trade the code for an ID token; returns its checked claims or an error message. */
function sso_exchange(string $provider, string $code, string $verifier, string $nonce): array|string
{
    $cfg = sso_platform()[$provider];
    $ep = sso_endpoints($provider);
    $name = SSO_PROVIDERS[$provider];
    try {
        [$status, $body] = http_request('POST', $ep['token'], ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'], http_build_query([
            'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => sso_redirect_uri($provider),
            'client_id' => $cfg['clientId'], 'client_secret' => $cfg['clientSecret'], 'code_verifier' => $verifier,
        ]));
    } catch (Throwable $e) {
        error_log("[sso] $provider token request: " . $e->getMessage());
        return "Couldn’t reach $name. Please try again.";
    }
    $j = json_decode($body, true) ?: [];
    if ($status !== 200 || empty($j['id_token'])) {
        error_log("[sso] $provider token error $status: " . mb_substr($body, 0, 500));
        return "$name refused the sign-in" . (!empty($j['error_description']) ? ': ' . mb_substr((string) $j['error_description'], 0, 200) : '.');
    }
    $parts = explode('.', (string) $j['id_token']);
    $c = count($parts) === 3 ? json_decode(b64url_decode($parts[1]), true) : null;
    if (!is_array($c)) return "$name sent an unreadable sign-in.";
    $iss = $provider === 'microsoft' ? str_replace('{tid}', (string) ($c['tid'] ?? ''), $ep['issuer']) : $ep['issuer'];
    $issOk = $provider === 'google' ? in_array($c['iss'] ?? '', [$iss, preg_replace('#^https://#', '', $iss)], true) : ($c['iss'] ?? '') === $iss;
    $aud = (array) ($c['aud'] ?? []);
    if (!$issOk || !in_array($cfg['clientId'], $aud, true) || (int) ($c['exp'] ?? 0) < time() - 60 || !hash_equals($nonce, (string) ($c['nonce'] ?? ''))) {
        error_log("[sso] $provider rejected ID token (iss/aud/exp/nonce)");
        return "$name sent a sign-in this app can’t accept.";
    }
    return $c;
}

/**
 * The user these claims sign in, or why not. Order: an account linked before
 * (provider + subject), else the account with the verified email (then linked),
 * else a new customer of the one organization that owns the email domain when
 * its workspace allows that.
 */
function sso_resolve(string $provider, array $c): array|string
{
    $name = SSO_PROVIDERS[$provider];
    if ($provider === 'microsoft') {
        $subject = strtolower(($c['tid'] ?? '') . '/' . ($c['oid'] ?? ''));
        $email = strtolower(trim((string) ($c['email'] ?? '')));
        if ($email === '' && str_contains((string) ($c['preferred_username'] ?? ''), '@')) $email = strtolower(trim((string) $c['preferred_username']));
        // Microsoft vouches for the address when the domain owner verified it, and for personal accounts.
        $verified = !empty($c['xms_edov']) || ($c['tid'] ?? '') === SSO_MS_CONSUMER_TID;
        $tid = strtolower((string) ($c['tid'] ?? ''));
    } else {
        $subject = (string) ($c['sub'] ?? '');
        $email = strtolower(trim((string) ($c['email'] ?? '')));
        $verified = ($c['email_verified'] ?? false) === true || ($c['email_verified'] ?? '') === 'true';
        $tid = '';
    }
    if ($subject === '' || $subject === '/') return "$name didn’t identify your account.";
    $display = trim((string) ($c['name'] ?? ''));

    $link = q_one('SELECT * FROM "UserIdentity" WHERE "provider" = ? AND "subject" = ?', [$provider, $subject]);
    $user = $link ? q_one('SELECT * FROM "User" WHERE "id" = ?', [$link['userId']]) : null;
    $new = false;
    if (!$user) {
        if (!is_valid_email($email)) return "Your $name account has no email address this app can use.";
        $user = q_one('SELECT * FROM "User" WHERE "email" = ?', [$email]);
        $trusted = fn (?string $tenantId) => $verified || ($tid !== '' && $tenantId && in_array($tid, sso_policy($tenantId)['msTenants'], true));
        if ($user) {
            if (!$trusted($user['tenantId'])) {
                return "Microsoft didn’t confirm that $email belongs to you. Ask your administrator to trust your Microsoft directory ($tid) in Settings → Security.";
            }
        } else {
            $tenantId = sso_signup_tenant($provider, $email);
            if (!$tenantId) return "There’s no account for $email. Ask your support team to invite you.";
            if (!$trusted($tenantId)) {
                return "Microsoft didn’t confirm that $email belongs to you. Ask your administrator to trust your Microsoft directory ($tid) in Settings → Security.";
            }
            $user = find_or_create_customer($tenantId, $email, $display);
            if (is_string($user)) return $user;
            $new = true;
        }
    }
    if (isset($user['active']) && !(int) $user['active']) {
        if ($user['tenantId']) audit($user['tenantId'], null, 'sign-in-blocked', "Blocked $name sign-in of deactivated account {$user['email']}", 'user', $user['id']);
        return 'This account has been deactivated. Contact your workspace administrator.';
    }
    if (!$user['tenantId']) return "$name sign-in is for workspace accounts. Sign in with your password.";
    $p = sso_policy($user['tenantId']);
    if (!in_array($provider, $p['providers'], true) || !(is_staff($user['role']) ? $p['staff'] : $p['customers'])) {
        return "Your workspace doesn’t use $name sign-in" . (is_staff($user['role']) ? ' for staff' : '') . '. Sign in with your password.';
    }
    $now = now_iso();
    if ($link) {
        db_update('UserIdentity', ['lastUsedAt' => $now, 'email' => $email ?: $link['email']], '"id" = ?', [$link['id']]);
    } else {
        db_insert('UserIdentity', ['id' => cuid(), 'userId' => $user['id'], 'provider' => $provider, 'subject' => $subject, 'email' => $email, 'createdAt' => $now, 'lastUsedAt' => $now]);
        if (!$new) audit($user['tenantId'], $user, 'sso-linked', "{$user['name']} linked their $name account", 'user', $user['id']);
    }
    if ($new) audit($user['tenantId'], null, 'sso-signup', "{$user['email']} joined with $name", 'user', $user['id']);
    return $user;
}

/** The one workspace that would take a new customer with this email (its organization owns the domain), or null. */
function sso_signup_tenant(string $provider, string $email): ?string
{
    $d = email_domain($email);
    if ($d === '') return null;
    $found = [];
    foreach (q_all('SELECT "tenantId", "domain" FROM "Organization" WHERE "domain" IS NOT NULL AND "domain" <> \'\'') as $o) {
        if (!in_array($d, org_domains($o['domain']), true)) continue;
        $p = sso_policy($o['tenantId']);
        if ($p['autoCreate'] && $p['customers'] && in_array($provider, $p['providers'], true)) $found[$o['tenantId']] = true;
    }
    return count($found) === 1 ? array_key_first($found) : null;
}

/* ---------- Pieces for the sign-in page ---------- */

function sso_logo(string $provider): string
{
    if ($provider === 'microsoft') {
        return '<svg width="18" height="18" viewBox="0 0 21 21" aria-hidden="true"><rect x="1" y="1" width="9" height="9" fill="#f25022"/><rect x="11" y="1" width="9" height="9" fill="#7fba00"/><rect x="1" y="11" width="9" height="9" fill="#00a4ef"/><rect x="11" y="11" width="9" height="9" fill="#ffb900"/></svg>';
    }
    return '<svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#FFC107" d="M43.6 20.1H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.6-.4-3.9z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-7.9l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.1H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C37 39.2 44 34 44 24c0-1.3-.1-2.6-.4-3.9z"/></svg>';
}

/** "Continue with Microsoft / Google" buttons, or '' when none is set up. */
function sso_buttons(): string
{
    $ps = sso_available();
    if (!$ps) return '';
    $h = '<div class="grid gap-2.5">';
    foreach ($ps as $p) {
        $h .= '<a href="/sso/' . $p . '" class="inline-flex h-12 items-center justify-center gap-2.5 rounded-xl border border-border bg-surface/70 px-4 text-[15px] font-medium text-fg shadow-sm shadow-black/[0.02] transition hover:bg-surface-2 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-brand/15">'
            . sso_logo($p) . 'Continue with ' . SSO_PROVIDERS[$p] . '</a>';
    }
    return $h . '</div>';
}
