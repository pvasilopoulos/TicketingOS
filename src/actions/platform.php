<?php
declare(strict_types=1);

/* Super admin tools: platform branding and "sign in as" any user. */

/* ---------- Branding (Settings → Branding) ---------- */

function act_branding_save(array $in): ?array
{
    $user = current_user();
    if (!$user || $user['role'] !== 'SUPER_ADMIN') return ['error' => 'Only a super admin can change the branding'];
    $name = trim(preg_replace('/\s+/u', ' ', str_in($in, 'appName')));
    if (mb_strlen($name) < 2 || mb_strlen($name) > 40) return ['error' => 'The app name must be 2–40 characters'];
    $data = ['name' => $name, 'signup' => ($in['signup'] ?? '') === 'on'];

    $f = $_FILES['logo'] ?? null;
    if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) return ['error' => 'The logo could not be uploaded'];
        if ($f['size'] > BRAND_LOGO_MAX) return ['error' => 'The logo must be 1 MB or smaller'];
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) ?: '';
        if ($mime === 'text/xml' || $mime === 'application/xml' || ($mime === 'text/plain' && str_ends_with(strtolower($f['name']), '.svg'))) $mime = 'image/svg+xml';
        $ext = BRAND_LOGO_TYPES[$mime] ?? null;
        if (!$ext) return ['error' => 'Use a PNG, JPG, WebP or SVG image'];
        if ($ext === 'svg') {
            $svg = (string) file_get_contents($f['tmp_name']);
            // Logos are shown with <img>, which never runs scripts; refuse active content anyway.
            if (!str_contains($svg, '<svg') || preg_match('/<script|on\w+\s*=|javascript:|<foreignObject/i', $svg)) return ['error' => 'This SVG contains scripts and can’t be used'];
        }
        if (!is_dir(brand_dir())) mkdir(brand_dir(), 0775, true);
        foreach (glob(brand_dir() . '/logo.*') as $old) @unlink($old);
        if (!move_uploaded_file($f['tmp_name'], brand_dir() . "/logo.$ext")) return ['error' => 'The logo could not be saved (is storage/ writable?)'];
        $data['logo'] = "logo.$ext";
    } elseif (($in['removeLogo'] ?? '') === '1') {
        foreach (glob(brand_dir() . '/logo.*') as $old) @unlink($old);
        $data['logo'] = null;
    }
    branding_save($data);
    return ['success' => 'Branding saved'];
}

/* ---------- Sign in as another user ---------- */

/** The super admin behind the current session while they act as someone else. */
function impersonator(): ?array
{
    static $cache = false;
    if ($cache !== false) return $cache;
    $id = get_session()['impBy'] ?? null;
    $u = $id ? q_one('SELECT * FROM "User" WHERE "id" = ? AND "role" = \'SUPER_ADMIN\' AND "active" = 1', [$id]) : null;
    return $cache = $u ?: null;
}

function act_impersonate(array $in): ?array
{
    $me = current_user();
    if (!$me || $me['role'] !== 'SUPER_ADMIN' || impersonator()) return ['error' => 'Only a super admin can sign in as another user'];
    $target = q_one('SELECT * FROM "User" WHERE "id" = ?', [str_in($in, 'userId')]);
    if (!$target) return ['error' => 'User not found'];
    if ($target['id'] === $me['id']) return ['error' => 'That’s you'];
    if ($target['role'] === 'SUPER_ADMIN') return ['error' => 'You can’t sign in as another super admin'];
    if (!(int) $target['active']) return ['error' => 'This account is deactivated'];

    if ($target['tenantId']) audit($target['tenantId'], $me, 'impersonate', "{$me['name']} signed in as {$target['name']}", 'member', $target['id']);
    clear_active_tenant_id();
    $tenant = $target['tenantId'] ? q_one('SELECT "name","slug" FROM "Tenant" WHERE "id" = ?', [$target['tenantId']]) : null;
    create_session([
        'userId' => $target['id'], 'role' => $target['role'], 'name' => $target['name'], 'email' => $target['email'],
        'tenantId' => $target['tenantId'], 'tenantName' => $tenant['name'] ?? null, 'tenantSlug' => $tenant['slug'] ?? null,
        'impBy' => $me['id'],
    ]);
    redirect('/dashboard');
}

function act_impersonate_stop(array $in): ?array
{
    $super = impersonator();
    $as = current_user();
    if (!$super) {
        // Session no longer valid for impersonation: just sign out.
        destroy_session();
        redirect('/login');
    }
    if ($as && $as['tenantId']) audit($as['tenantId'], $super, 'impersonate-stop', "{$super['name']} stopped acting as {$as['name']}", 'member', $as['id']);
    start_session_for($super);
    redirect(home_for($super));
}

/* ---------- Super admin's own workspace ---------- */

/**
 * Give the super admin a home workspace: they appear there as staff (assignee
 * lists, notifications, reports) and land there after signing in.
 */
function act_platform_home(array $in): ?array
{
    $me = current_user();
    if (!$me || $me['role'] !== 'SUPER_ADMIN' || impersonator()) return ['error' => 'Only a super admin can do that'];
    $tenant = q_one('SELECT "id","name" FROM "Tenant" WHERE "id" = ?', [str_in($in, 'tenantId')]);
    if (!$tenant) return ['error' => 'Workspace not found'];
    db_update('User', ['tenantId' => $tenant['id'], 'organizationId' => null, 'orgManager' => 0, 'updatedAt' => now_iso()], '"id" = ?', [$me['id']]);
    audit($tenant['id'], $me, 'member-role', "{$me['name']} made {$tenant['name']} their home workspace", 'member', $me['id']);
    clear_active_tenant_id();
    start_session_for(array_merge($me, ['tenantId' => $tenant['id']]));
    redirect('/dashboard');
}
