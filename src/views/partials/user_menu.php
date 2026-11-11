<?php
/** @var array $user */
$showPrefs = $showPrefs ?? ($user['role'] !== 'SUPER_ADMIN' || effective_tenant_id($user));
$isSuper = $user['role'] === 'SUPER_ADMIN';
$mobileSheet = $mobileSheet ?? false;
$item = 'flex w-full items-center gap-2 rounded-lg px-2.5 py-2.5 text-sm text-fg hover:bg-surface-2 transition-colors cursor-pointer sm:py-2';
echo dropdown(
    '<button type="button" data-dropdown-trigger aria-label="Account menu" class="flex items-center gap-1 rounded-lg p-1 hover:bg-surface-2 transition-colors cursor-pointer sm:gap-2 sm:px-1.5">'
    . avatar($user['name'], $user['avatarColor'], 32) . icon('chevron-down', 16, 'hidden text-muted sm:block') . '</button>',
    '<div>'
    . '<div class="flex items-center gap-3 p-3 border-b border-border">' . avatar($user['name'], $user['avatarColor'], 40)
    . '<div class="min-w-0"><p class="truncate text-sm font-medium">' . e($user['name']) . '</p><p class="truncate text-xs text-muted">' . e($user['email']) . '</p></div></div>'
    . '<div class="px-3 py-2 text-xs text-muted border-b border-border">Role: <span class="font-medium text-fg">' . e(role_label($user['role'])) . '</span></div>'
    . '<div class="p-1.5">'
    . ($isSuper ? '<a href="/platform" class="' . $item . '">' . icon('shield-check', 16, 'text-sky-600') . 'Platform console</a>' : '')
    . ($isSuper && $user['tenantId'] && !($showPrefs) ? '<a href="/dashboard" class="' . $item . '">' . icon('layout-dashboard', 16) . 'My workspace</a>' : '')
    . ($showPrefs ? '<a href="/notifications" class="' . $item . '">' . icon('bell', 16) . 'Notification settings</a>' : '')
    . '<button type="button" data-theme-toggle class="' . $item . ' sm:hidden">' . '<span class="dark:hidden">' . icon('moon', 16) . '</span><span class="hidden dark:inline">' . icon('sun', 16) . '</span><span class="dark:hidden">Dark mode</span><span class="hidden dark:inline">Light mode</span></button>'
    . '<button type="button" data-install-app hidden class="' . $item . ' text-brand">' . icon('smartphone', 16) . 'Install app</button>'
    . '</div>'
    . '<form method="post" action="/action/logout" class="border-t border-border p-1.5" data-logout>' . form_meta()
    . '<button type="submit" class="flex w-full items-center gap-2 rounded-lg px-2.5 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 transition-colors cursor-pointer sm:py-2">' . icon('log-out', 16) . 'Sign out</button></form>'
    . '</div>',
    240,
    'right',
    $mobileSheet,
);
