<?php
declare(strict_types=1);

/* Per-tenant settings store + the configurable mobile bottom menu. */

function tenant_setting(string $tenantId, string $key, mixed $default = null): mixed
{
    static $cache = [];
    $ck = "$tenantId:$key";
    if (!array_key_exists($ck, $cache)) {
        $raw = q_val('SELECT "value" FROM "TenantSetting" WHERE "tenantId" = ? AND "key" = ?', [$tenantId, $key]);
        $cache[$ck] = $raw === null ? null : json_decode((string) $raw, true);
    }
    return $cache[$ck] ?? $default;
}

function tenant_setting_set(string $tenantId, string $key, mixed $value): void
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = now_iso();
    if (q_val('SELECT 1 FROM "TenantSetting" WHERE "tenantId" = ? AND "key" = ?', [$tenantId, $key])) {
        db_update('TenantSetting', ['value' => $json, 'updatedAt' => $now], '"tenantId" = ? AND "key" = ?', [$tenantId, $key]);
    } else {
        db_insert('TenantSetting', ['tenantId' => $tenantId, 'key' => $key, 'value' => $json, 'updatedAt' => $now]);
    }
}

/**
 * Everything that can sit in the bottom menu.
 * audience: all = everyone, staff = agents & admins, admin = admin-type items;
 * perm: the item is only shown to users holding that permission.
 * match: query params that must be present for the item to look active.
 */
const NAV_ITEMS = [
    'home' => ['label' => 'Home', 'href' => '/dashboard', 'icon' => 'layout-dashboard', 'audience' => 'all'],
    'tickets' => ['label' => 'Tickets', 'href' => '/tickets', 'icon' => 'ticket', 'audience' => 'all'],
    'mine' => ['label' => 'Mine', 'href' => '/tickets?assignee=me', 'icon' => 'user-plus', 'audience' => 'staff', 'match' => ['assignee' => 'me']],
    'unassigned' => ['label' => 'Unassigned', 'href' => '/tickets?assignee=none', 'icon' => 'inbox', 'audience' => 'staff', 'match' => ['assignee' => 'none']],
    'groups' => ['label' => 'My groups', 'href' => '/tickets?group=mine', 'icon' => 'users-round', 'audience' => 'staff', 'match' => ['group' => 'mine']],
    'overdue' => ['label' => 'Overdue', 'href' => '/tickets?overdue=1', 'icon' => 'clock', 'audience' => 'staff', 'match' => ['overdue' => '1']],
    'board' => ['label' => 'Board', 'href' => '/tickets?view=board', 'icon' => 'layout-grid', 'audience' => 'staff', 'match' => ['view' => 'board']],
    'kb' => ['label' => 'Help', 'href' => '/kb', 'icon' => 'book-open', 'audience' => 'all'],
    'reports' => ['label' => 'Reports', 'href' => '/reports', 'icon' => 'chart-column', 'audience' => 'staff', 'perm' => 'reports.view'],
    'alerts' => ['label' => 'Alerts', 'href' => '/notifications', 'icon' => 'bell', 'audience' => 'all'],
    'team' => ['label' => 'Users', 'href' => '/team', 'icon' => 'users', 'audience' => 'admin', 'perm' => 'users.*'],
    'settings' => ['label' => 'Settings', 'href' => '/settings', 'icon' => 'settings', 'audience' => 'admin', 'perm' => 'settings.*'],
];
const NAV_MAX_ITEMS = 4;
const NAV_DEFAULTS = [
    'staff' => ['items' => ['home', 'tickets', 'mine'], 'center' => true],
    'customer' => ['items' => ['home', 'tickets', 'alerts'], 'center' => true],
];

/** Item keys allowed for a menu audience ("staff" menu may also hold admin items; they're hidden from agents). */
function nav_allowed(string $menu): array
{
    return array_keys(array_filter(NAV_ITEMS, fn ($i) => $menu === 'staff' || $i['audience'] === 'all'));
}

/** Saved (or default) layout for a menu: ['items' => [...keys], 'center' => bool]. */
function nav_layout(string $tenantId, string $menu): array
{
    $saved = tenant_setting($tenantId, 'bottomNav', []);
    $cfg = is_array($saved[$menu] ?? null) ? $saved[$menu] : NAV_DEFAULTS[$menu];
    $allowed = nav_allowed($menu);
    $items = array_values(array_unique(array_filter($cfg['items'] ?? [], fn ($k) => in_array($k, $allowed, true))));
    return ['items' => array_slice($items ?: NAV_DEFAULTS[$menu]['items'], 0, NAV_MAX_ITEMS), 'center' => (bool) ($cfg['center'] ?? true)];
}

/** The bottom-menu items to render for a given user role. */
function nav_items_for(string $tenantId, array $user): array
{
    $role = $user['role'];
    $menu = is_staff($role) ? 'staff' : 'customer';
    $layout = nav_layout($tenantId, $menu);
    $out = [];
    foreach ($layout['items'] as $k) {
        $item = NAV_ITEMS[$k];
        // Items tied to a permission only show for users who hold it.
        if (!empty($item['perm']) && !can($user, $item['perm'])) continue;
        $out[$k] = $item;
    }
    return ['items' => $out, 'center' => $layout['center']];
}

/** Is a menu item the current page? Query-specific items win over the plain Tickets item. */
function nav_is_active(string $key, array $items, string $path, array $query): bool
{
    $matches = function (array $item) use ($path, $query): bool {
        $p = parse_url($item['href'], PHP_URL_PATH);
        if (!empty($item['match'])) {
            if ($path !== $p) return false;
            foreach ($item['match'] as $qk => $qv) if (($query[$qk] ?? '') !== $qv) return false;
            return true;
        }
        return $path === $p || ($p !== '/' && str_starts_with($path, $p . '/') && $path !== '/tickets/new');
    };
    if (!$matches($items[$key])) return false;
    // A plain item (Tickets) is not active when a more specific item in the menu matches.
    if (empty($items[$key]['match'])) {
        foreach ($items as $k => $i) if ($k !== $key && !empty($i['match']) && $matches($i)) return false;
    }
    return true;
}
