<?php
/** @var string $content */
[$user, $tenantId, $impersonating] = require_tenant_user();
$tenant = q_one('SELECT * FROM "Tenant" WHERE "id" = ?', [$tenantId]);
$tenantName = $tenant['name'] ?? null;
$notifications = q_all('SELECT * FROM "Notification" WHERE "userId" = ? AND "tenantId" = ? ORDER BY "createdAt" DESC LIMIT 15', [$user['id'], $tenantId]);
$unreadTotal = (int) q_val('SELECT COUNT(*) FROM "Notification" WHERE "userId" = ? AND "tenantId" = ? AND "read" = 0', [$user['id'], $tenantId]);

$role = $user['role'];
$isAdmin = is_tenant_admin($role);
$isStaffUser = is_staff($role);
$path = request_path();
$isActive = fn (string $href) => $path === $href || str_starts_with($path, $href . '/');

$nav = [['/dashboard', 'Dashboard', 'layout-dashboard'], ['/tickets', 'Tickets', 'ticket']];
if (is_staff($user['role']) && can($user, 'chat.answer') && chat_settings($tenantId)['enabled']) {
    $chatWaiting = chat_waiting_count($tenantId);
    $nav[] = ['/chat', 'Live chat' . ($chatWaiting ? " ($chatWaiting)" : ''), 'messages-square'];
}
$nav[] = ['/kb', 'Knowledge base', 'book-open'];
if (can($user, 'reports.view')) $nav[] = ['/reports', 'Reports', 'chart-column'];
// Users & roles live inside Settings (Users / Roles tabs), not in the menu.
if (can($user, 'settings.*')) $nav[] = ['/settings', 'Settings', 'settings'];

// Saved ticket views (own + shared with the team) with live counts.
$menuViews = menu_views($user, $tenantId);
$curQuery = $_SERVER['QUERY_STRING'] ?? '';
$sidebar = function (bool $drawer = false) use ($nav, $isActive, $tenantName, $isStaffUser, $menuViews, $path, $curQuery): string {
    $h = '<aside class="flex h-full w-72 max-w-[85vw] flex-col border-r border-border bg-surface lg:w-64">';
    $h .= '<div class="flex h-16 items-center gap-2.5 border-b border-border px-5">' . brand_mark(36, 'rounded-lg')
        . '<div class="min-w-0"><p class="truncate text-sm font-semibold leading-tight">' . e(app_name()) . '</p><p class="truncate text-xs text-muted">' . e($tenantName ?? 'Helpdesk') . '</p></div>'
        . ($drawer ? '<button type="button" data-drawer-close class="ml-auto inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:bg-surface-2" aria-label="Close menu">' . icon('x', 20) . '</button>' : '')
        . '</div>';
    $h .= '<div class="px-3 pt-4"><a href="/tickets/new" class="flex items-center justify-center gap-2 rounded-lg bg-brand px-3 py-2.5 text-sm font-medium text-brand-fg hover:opacity-90 transition">' . icon('circle-plus', 18) . 'New ticket</a></div>';
    $h .= '<nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">';
    $links = $nav;
    if ($drawer) $links[] = ['/notifications', 'Notification settings', 'bell'];
    foreach ($links as [$href, $label, $ic]) {
        $h .= '<a href="' . $href . '" class="' . cx('flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium transition-colors lg:py-2', $isActive($href) ? 'bg-brand/10 text-brand' : 'text-muted hover:bg-surface-2 hover:text-fg') . '">' . icon($ic, 18) . e($label) . '</a>';
    }
    if ($menuViews) {
        $h .= '<p class="px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-muted">Views</p>';
        foreach ($menuViews as $v) {
            $on = $path === '/tickets' && $curQuery === $v['query'];
            $h .= '<a href="/tickets' . ($v['query'] !== '' ? '?' . e($v['query']) : '') . '" title="' . ((int) $v['shared'] ? 'Shared with the team' : 'Only you') . '" class="' . cx('flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-colors', $on ? 'bg-brand/10 font-medium text-brand' : 'text-muted hover:bg-surface-2 hover:text-fg') . '">'
                . icon((int) $v['shared'] ? 'users' : 'bookmark', 16) . '<span class="min-w-0 flex-1 truncate">' . e($v['name']) . '</span>'
                . ($v['count'] !== null ? '<span class="' . cx('rounded-full px-1.5 text-[11px] tabular-nums', $v['count'] ? 'bg-surface-2 font-medium text-fg' : 'text-muted') . '">' . number_format($v['count']) . '</span>' : '') . '</a>';
        }
    }
    $h .= '</nav>';
    // Install-app entry (shown by app.js when the browser offers it).
    $h .= '<div class="px-3 pb-2"><button type="button" data-install-app hidden class="flex w-full items-center gap-3 rounded-lg border border-dashed border-brand/40 px-3 py-2.5 text-sm font-medium text-brand hover:bg-brand/5 cursor-pointer">' . icon('smartphone', 18) . 'Install app</button></div>';
    $h .= '<div class="border-t border-border p-3 pb-[max(0.75rem,env(safe-area-inset-bottom))]"><p class="px-3 text-xs text-muted">' . ($isStaffUser ? 'Agent workspace' : 'Customer portal') . '</p></div></aside>';
    return $h;
};

$bell = function () use ($notifications, $unreadTotal): string {
    $items = '';
    if (!$notifications) $items = '<p class="px-4 py-8 text-center text-sm text-muted">You&apos;re all caught up 🎉</p>';
    foreach ($notifications as $n) {
        $items .= '<button type="button" data-notification="' . e($n['id']) . '" data-href="' . ($n['ticketId'] ? '/tickets/' . e($n['ticketId']) : '') . '" class="' . cx('flex w-full gap-3 border-b border-border px-4 py-3 text-left hover:bg-surface-2 transition-colors cursor-pointer', !$n['read'] ? 'bg-brand/5' : '') . '">'
            . '<span class="' . cx('mt-1.5 h-2 w-2 shrink-0 rounded-full', $n['read'] ? 'bg-transparent' : 'bg-brand') . '"></span>'
            . '<span class="min-w-0"><span class="block text-sm text-fg">' . e($n['message']) . '</span><span class="mt-0.5 block text-xs text-muted">' . e(time_ago($n['createdAt'])) . '</span></span></button>';
    }
    return dropdown(
        '<button type="button" data-dropdown-trigger aria-label="Notifications" data-unread="' . $unreadTotal . '" class="relative inline-flex h-10 w-10 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer sm:h-9 sm:w-9">' . icon('bell', 19)
        . ($unreadTotal > 0 ? '<span class="absolute right-0.5 top-0.5 grid h-4 min-w-4 place-items-center rounded-full bg-red-500 px-1 text-[10px] font-bold text-white sm:-right-0.5 sm:-top-0.5">' . ($unreadTotal > 9 ? '9+' : $unreadTotal) . '</span>' : '') . '</button>',
        '<div><div class="flex items-center justify-between border-b border-border px-4 py-2.5"><p class="text-sm font-semibold">Notifications</p>'
        . ($unreadTotal > 0 ? '<button type="button" data-mark-all-read class="flex items-center gap-1 text-xs text-brand hover:underline cursor-pointer">' . icon('check-check', 14) . ' Mark all read</button>' : '')
        . '</div><div class="max-h-96 overflow-y-auto">' . $items . '</div>'
        . '<a href="/notifications" class="block px-4 py-2.5 text-center text-xs font-medium text-muted hover:text-fg">Notification settings</a></div>',
        340, 'right', true,
    );
};

$pushRoot = '<div hidden data-push-root>' . dropdown(
    '<button type="button" data-dropdown-trigger data-push-button aria-label="Push notifications" title="Push notifications" class="inline-flex h-10 w-10 items-center justify-center rounded-lg transition-colors cursor-pointer text-muted hover:bg-surface-2 hover:text-fg sm:h-9 sm:w-9">'
    . '<span data-push-icon="off">' . icon('bell-off', 18) . '</span><span data-push-icon="on" hidden>' . icon('bell-ring', 18) . '</span><span data-push-icon="busy" hidden>' . icon('loader-circle', 18, 'animate-spin') . '</span></button>',
    '<div class="p-3"><p class="text-sm font-semibold">Device push notifications</p>'
    . '<p class="mt-1 text-xs text-muted" data-push-text>Get notified on this device even when the app is closed.</p>'
    . '<p class="mt-2 hidden rounded-lg bg-amber-50 px-2.5 py-2 text-xs text-amber-800 dark:bg-amber-500/10 dark:text-amber-300" data-push-ios>On iPhone/iPad, first add the app to your Home Screen (Share → Add to Home Screen), then enable notifications from there.</p>'
    . '<div class="mt-3 flex flex-col gap-2">'
    . '<button type="button" data-push-enable class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-brand px-3 py-2.5 text-sm font-medium text-brand-fg hover:opacity-90 cursor-pointer">' . icon('bell-ring', 14) . ' Enable on this device</button>'
    . '<button type="button" data-push-disable hidden class="inline-flex items-center justify-center gap-1.5 rounded-lg px-3 py-2.5 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-500/10 cursor-pointer">' . icon('bell-off', 14) . ' Turn off on this device</button>'
    . '</div></div>',
    280, 'right', true,
) . '</div>';

// Bottom tab bar (phones/tablets) — layout is configurable in Settings → General.
$bottomNav = nav_items_for($tenantId, $user);
$bottomHtml = (function () use ($bottomNav, $path): string {
    $cells = [];
    foreach ($bottomNav['items'] as $k => $it) {
        $active = nav_is_active($k, $bottomNav['items'], $path, $_GET);
        $cells[] = '<a href="' . e($it['href']) . '" class="' . cx('flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[10px] font-medium transition-colors', $active ? 'text-brand' : 'text-muted') . '"' . ($active ? ' aria-current="page"' : '') . '>'
            . icon($it['icon'], 21) . '<span class="max-w-full truncate">' . e($it['label']) . '</span></a>';
    }
    $cells[] = '<button type="button" data-drawer-open class="flex min-w-0 flex-1 flex-col items-center justify-center gap-0.5 py-1.5 text-[10px] font-medium text-muted cursor-pointer" aria-label="Open menu">' . icon('menu', 21) . 'More</button>';
    if ($bottomNav['center']) {
        $plus = '<a href="/tickets/new" class="flex min-w-0 flex-1 flex-col items-center justify-center" aria-label="New ticket">'
            . '<span class="-mt-5 grid h-12 w-12 place-items-center rounded-2xl bg-brand text-brand-fg shadow-lg shadow-brand/30 active:scale-95 transition-transform">' . icon('plus', 24) . '</span>'
            . '<span class="mt-0.5 text-[10px] font-medium text-muted">New</span></a>';
        array_splice($cells, intdiv(count($cells), 2), 0, [$plus]);
    }
    return implode('', $cells);
})();

ob_start(); ?>
<div class="flex h-dvh overflow-hidden" data-app-shell>
  <!-- Desktop sidebar -->
  <div class="hidden lg:block"><?= $sidebar() ?></div>

  <!-- Mobile drawer -->
  <div class="fixed inset-0 z-50 lg:hidden" hidden data-drawer>
    <div class="absolute inset-0 bg-black/40 backdrop-blur-[1px]" data-drawer-close></div>
    <div class="absolute left-0 top-0 h-full animate-in pt-[env(safe-area-inset-top)] bg-surface"><?= $sidebar(true) ?></div>
  </div>

  <div class="flex min-w-0 flex-1 flex-col">
    <?php if ($actingFor = impersonator()): ?>
      <div class="flex shrink-0 items-center gap-2 bg-amber-500 px-4 py-1.5 pt-[max(0.375rem,env(safe-area-inset-top))] text-sm text-amber-950 lg:px-6 lg:py-2">
        <?= icon('eye', 16) ?>
        <span class="min-w-0 flex-1 truncate"><span class="hidden sm:inline">You’re viewing the app as</span><span class="sm:hidden">As</span> <strong><?= e($user['name']) ?></strong> <span class="opacity-75">· <?= e(role_label($user['role'])) ?><span class="hidden md:inline"> · everything you do is done as them</span></span></span>
        <form method="post" action="/action/impersonate-stop" class="shrink-0">
          <?= form_meta() ?>
          <button type="submit" class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-md bg-amber-950/10 px-2.5 text-xs font-semibold ring-1 ring-amber-950/20 hover:bg-amber-950/20 transition-colors cursor-pointer">
            <?= icon('log-out', 14) ?><span>Back to <?= e(strtok($actingFor['name'], ' ')) ?></span>
          </button>
        </form>
      </div>
    <?php endif; ?>
    <?php if ($impersonating): ?>
      <div class="flex shrink-0 items-center gap-2 bg-sky-600 px-4 py-1.5 pt-[max(0.375rem,env(safe-area-inset-top))] text-sm text-white lg:px-6 lg:py-2">
        <?= icon('shield-check', 16) ?>
        <span class="min-w-0 flex-1 truncate"><span class="hidden sm:inline">Platform operator — viewing</span><span class="sm:hidden">Viewing</span> <strong><?= e($tenantName) ?></strong><span class="hidden sm:inline"> as admin</span></span>
        <form method="post" action="/action/platform-exit" class="shrink-0">
          <?= form_meta() ?>
          <button type="submit" class="inline-flex h-8 items-center gap-1.5 whitespace-nowrap rounded-md bg-white/15 px-2.5 text-xs font-medium ring-1 ring-white/25 hover:bg-white/25 active:bg-white/30 transition-colors cursor-pointer">
            <?= icon('log-out', 14) ?><span>Exit<span class="hidden sm:inline"> tenant</span></span>
          </button>
        </form>
      </div>
    <?php endif; ?>

    <header class="relative z-40 flex h-14 shrink-0 items-center gap-2 border-b border-border bg-surface/95 px-3 backdrop-blur sm:h-16 sm:gap-3 sm:px-4 lg:px-6 <?= $impersonating ? '' : 'pt-[env(safe-area-inset-top)] box-content' ?>">
      <!-- Mobile: brand -->
      <a href="/dashboard" class="flex min-w-0 items-center gap-2 lg:hidden">
        <?= brand_mark(32, 'rounded-lg') ?>
        <span class="truncate text-sm font-semibold" data-mobile-title><?= e(app_name()) ?></span>
      </a>

      <!-- Search: inline on tablet/desktop, expandable on phones -->
      <form method="get" action="/tickets" class="relative hidden max-w-md flex-1 sm:block" role="search">
        <?= icon('search', 16, 'pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted') ?>
        <input name="q" type="search" data-live-search placeholder="Search tickets…  (try assignee:me)" class="h-9 w-full rounded-lg border border-border bg-surface-2 pl-9 pr-3 text-sm placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40">
      </form>

      <div class="ml-auto flex items-center gap-0.5 sm:gap-1">
        <button type="button" data-search-open aria-label="Search" class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg sm:hidden"><?= icon('search', 19) ?></button>
        <span class="hidden sm:contents"><?= theme_toggle() ?></span>
        <?= $pushRoot ?>
        <?= $bell() ?>
        <div class="ml-0.5 sm:ml-1"><?= render_partial('partials/user_menu', ['user' => $user, 'mobileSheet' => true]) ?></div>
      </div>

      <!-- Phone search overlay -->
      <form method="get" action="/tickets" role="search" hidden data-search-panel class="absolute inset-x-0 top-0 z-40 flex h-14 items-center gap-2 border-b border-border bg-surface px-3 sm:hidden" data-live-wrap>
        <?= icon('search', 18, 'shrink-0 text-muted') ?>
        <input name="q" type="search" data-live-search enterkeyhint="search" placeholder="Search tickets or #number…" class="h-10 min-w-0 flex-1 bg-transparent text-base placeholder:text-muted focus:outline-none">
        <button type="button" data-search-close class="inline-flex h-10 w-10 items-center justify-center rounded-lg text-muted hover:bg-surface-2" aria-label="Close search"><?= icon('x', 19) ?></button>
      </form>
    </header>

    <main class="relative flex-1 overflow-y-auto overscroll-contain" data-main>
      <div data-ptr class="pointer-events-none absolute inset-x-0 top-0 z-10 flex justify-center opacity-0 transition-opacity"><span class="mt-2 grid h-8 w-8 place-items-center rounded-full border border-border bg-surface shadow"><?= icon('loader-circle', 16, 'text-brand') ?></span></div>
      <!-- Bottom padding clears the bottom tab bar (shown below lg) plus the iPhone home indicator. -->
      <div class="mx-auto max-w-6xl px-4 pt-5 pb-[calc(6.5rem+env(safe-area-inset-bottom))] sm:pt-6 lg:px-8 lg:pt-8 lg:pb-8 animate-in"><?= $content ?></div>
    </main>

    <!-- Bottom tab bar -->
    <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-border bg-surface/95 pb-[env(safe-area-inset-bottom)] backdrop-blur lg:hidden" aria-label="Primary">
      <div class="mx-auto flex h-16 max-w-md items-stretch px-2">
        <?= $bottomHtml ?>
      </div>
    </nav>
  </div>
</div>
<div data-offline-banner hidden class="fixed inset-x-0 top-0 z-[90] flex items-center justify-center gap-2 bg-amber-500 px-3 py-1.5 pt-[max(0.375rem,env(safe-area-inset-top))] text-xs font-medium text-white">
  You're offline — showing the last saved copy. Changes will fail until you reconnect.
</div>
<?php if ($role === 'CUSTOMER' && chat_settings($tenantId)['enabled']): ?>
<div data-app-chat-panel hidden class="fixed right-4 bottom-[calc(9rem+env(safe-area-inset-bottom))] z-40 h-[min(600px,calc(100dvh-12rem))] w-[min(400px,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-border bg-surface shadow-2xl lg:bottom-24 lg:h-[min(620px,calc(100dvh-8rem))]"></div>
<button type="button" data-app-chat-btn aria-label="Chat with us" aria-expanded="false" class="fixed right-4 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-40 grid h-14 w-14 cursor-pointer place-items-center rounded-full bg-brand text-white shadow-xl transition-transform hover:scale-105 lg:bottom-6">
  <span data-icon-open><?= icon('messages-square', 24) ?></span><span data-chat-dot hidden class="absolute -right-1 -top-1 grid h-5 min-w-5 place-items-center rounded-full border-2 border-white bg-red-500 px-1 text-[10px] font-bold leading-none text-white"></span><span data-icon-close hidden><?= icon('x', 24) ?></span>
</button>
<script>
(function () {
  var btn = document.querySelector("[data-app-chat-btn]"), panel = document.querySelector("[data-app-chat-panel]");
  var src = "/messenger";
  function set(open) {
    if (open && !panel.firstChild) {
      var f = document.createElement("iframe");
      f.src = src; f.title = "Chat"; f.className = "h-full w-full border-0";
      panel.appendChild(f);
    }
    panel.hidden = !open;
    btn.querySelector("[data-chat-dot]").hidden = open || !unread;
    btn.setAttribute("aria-expanded", open ? "true" : "false");
    btn.querySelector("[data-icon-open]").hidden = open;
    btn.querySelector("[data-icon-close]").hidden = !open;
    try { sessionStorage.setItem("tos-app-chat", open ? "1" : ""); } catch (e) {}
  }
  btn.addEventListener("click", function () { set(panel.hidden); });
  var unread = 0, dot = btn.querySelector("[data-chat-dot]");
  // The messenger reports unread conversations; show them on the button while it is closed.
  window.addEventListener("message", function (e) {
    if (e.origin !== location.origin || !e.data) return;
    if (e.data.tosChat === "unread") { unread = e.data.count || 0; dot.textContent = unread > 9 ? "9+" : unread || ""; dot.hidden = !unread || !panel.hidden; }
  });
  document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !panel.hidden) set(false); });
  // Load the chat quietly so new replies show a dot even while it is closed.
  setTimeout(function () { if (!panel.firstChild) { var f = document.createElement("iframe"); f.src = src; f.title = "Chat"; f.className = "h-full w-full border-0"; panel.appendChild(f); } }, 1500);
  // Keep the chat open while moving between pages.
  try { if (sessionStorage.getItem("tos-app-chat") === "1") set(true); } catch (e) {}
})();
</script>
<?php endif; ?>
<div class="pointer-events-none fixed inset-x-3 bottom-[calc(5rem+env(safe-area-inset-bottom))] z-[100] flex flex-col items-center gap-2 sm:inset-x-auto sm:right-4 sm:items-end lg:bottom-4" data-toasts data-realtime></div>
<?php
echo render_partial('layouts/base', ['content' => ob_get_clean()]);
