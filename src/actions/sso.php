<?php
declare(strict_types=1);

/* Single sign-on settings (see src/lib/sso.php). */

/** Settings → Security → Single sign-on (workspace administrators). */
function act_sso_save(array $in): ?array
{
    [, $tenantId] = require_roles_admin('admin.security');
    $providers = array_values(array_intersect(array_keys(SSO_PROVIDERS), (array) ($in['providers'] ?? [])));
    $tids = [];
    foreach (preg_split('/[\s,;]+/', strtolower(str_in($in, 'msTenants'))) ?: [] as $t) {
        if ($t === '') continue;
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $t)) return ['error' => "“$t” isn’t a Microsoft directory (tenant) ID. It looks like 00000000-0000-0000-0000-000000000000."];
        $tids[$t] = true;
    }
    $v = [
        'providers' => $providers,
        'staff' => ($in['staff'] ?? '') === 'on',
        'customers' => ($in['customers'] ?? '') === 'on',
        'autoCreate' => ($in['autoCreate'] ?? '') === 'on',
        'requireAgents' => ($in['requireAgents'] ?? '') === 'on',
        'msTenants' => array_keys($tids),
    ];
    if ($v['requireAgents'] && (!$v['staff'] || !array_filter($providers, 'sso_configured'))) {
        return ['error' => 'To require single sign-on for agents, turn on a provider that is set up and allow staff to use it'];
    }
    tenant_setting_set($tenantId, 'sso', $v);
    return ['success' => 'Single sign-on settings saved'];
}

/** Settings → Security → Sign-in providers (super admins): the platform's app registrations. */
function act_sso_platform_save(array $in): ?array
{
    $user = current_user();
    if (!$user || $user['role'] !== 'SUPER_ADMIN') return ['error' => 'Only a super admin can change the sign-in providers'];
    $p = str_in($in, 'provider');
    if (!isset(SSO_PROVIDERS[$p])) return ['error' => 'Unknown provider'];
    if (($in['clear'] ?? '') === '1') {
        sso_platform_save($p, '', '', true);
        return ['success' => SSO_PROVIDERS[$p] . ' sign-in removed'];
    }
    $id = str_in($in, 'clientId');
    $secret = trim((string) ($in['clientSecret'] ?? ''));
    if ($id === '' || mb_strlen($id) > 200) return ['error' => 'Enter the client ID'];
    $had = (sso_platform()[$p]['source'] ?? null) === 'settings';
    if ($secret === '' && !$had) return ['error' => 'Enter the client secret'];
    if (mb_strlen($secret) > 500) return ['error' => 'The client secret is too long'];
    sso_platform_save($p, $id, $secret, false);
    return ['success' => SSO_PROVIDERS[$p] . ' sign-in saved'];
}
