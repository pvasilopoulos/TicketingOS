<?php
/**
 * Settings → Users.
 * @var array $me @var string $tenantId @var callable $s (flash reader)
 */
$isAdminMe = is_tenant_admin($me['role']);
$canAdmins = can_manage_admins($me);
$isSuperMe = $me['role'] === 'SUPER_ADMIN';
$canInvite = can($me, 'users.invite');
$canEditUsers = can($me, 'users.edit');
$canAccess = can($me, 'users.access');
$canRemove = can($me, 'users.remove');
$roles = q_all('SELECT r.*, (SELECT COUNT(*) FROM "User" u WHERE u."customRoleId" = r."id" AND u."role" = \'AGENT\') AS "members" FROM "Role" r WHERE r."tenantId" = ? ORDER BY r."name"', [$tenantId]);
$rolesById = key_by($roles, 'id');
$roleCounts = array_column(q_all('SELECT "role", COUNT(*) AS n FROM "User" WHERE "tenantId" = ? GROUP BY "role"', [$tenantId]), 'n', 'role');
$inactiveCount = (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" = ? AND "active" = 0', [$tenantId]);
$allCount = array_sum($roleCounts);
$agentPermCount = count(agent_permissions($tenantId));
$permTotal = count(all_permissions());

// ---- filters + paging (query string: uq, urole, ustatus, upage, uper) ----
$uq = trim((string) ($_GET['uq'] ?? ''));
$urole = (string) ($_GET['urole'] ?? '');
if (!in_array($urole, ['', 'STAFF', 'SUPER_ADMIN', ...TENANT_ROLES], true) && !isset($rolesById[$urole])) $urole = '';
$ustatus = in_array($_GET['ustatus'] ?? '', ['active', 'inactive', 'never'], true) ? $_GET['ustatus'] : '';
$orgs = tenant_orgs($tenantId);
$orgNames = array_column($orgs, 'name', 'id');
$uorg = isset($orgNames[$_GET['uorg'] ?? '']) ? $_GET['uorg'] : '';
$uper = in_array((int) ($_GET['uper'] ?? 0), [25, 50, 100], true) ? (int) $_GET['uper'] : 25;

$where = 'u."tenantId" = ?';
$params = [$tenantId];
if ($uq !== '') {
    $where .= " AND (LOWER(u.\"name\") LIKE ? ESCAPE '!' OR LOWER(u.\"email\") LIKE ? ESCAPE '!')";
    array_push($params, like_arg(mb_strtolower($uq)), like_arg(mb_strtolower($uq)));
}
if ($urole === 'STAFF') $where .= ' AND u."role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\')';
elseif ($urole === 'ADMIN') $where .= ' AND u."role" IN (\'SUPER_ADMIN\',\'ADMIN\')';
elseif ($urole === 'AGENT') $where .= ' AND u."role" = \'AGENT\' AND u."customRoleId" IS NULL';
elseif (in_array($urole, ['SUPER_ADMIN', ...TENANT_ROLES], true)) { $where .= ' AND u."role" = ?'; $params[] = $urole; }
elseif ($urole !== '') { $where .= ' AND u."role" = \'AGENT\' AND u."customRoleId" = ?'; $params[] = $urole; }
if ($ustatus === 'active') $where .= ' AND u."active" = 1';
elseif ($ustatus === 'inactive') $where .= ' AND u."active" = 0';
elseif ($ustatus === 'never') $where .= ' AND u."lastLoginAt" IS NULL AND u."role" <> \'CUSTOMER\'';
if ($uorg) { $where .= ' AND EXISTS (SELECT 1 FROM "UserOrganization" fo WHERE fo."userId" = u."id" AND fo."organizationId" = ?)'; $params[] = $uorg; }

$total = (int) q_val("SELECT COUNT(*) FROM \"User\" u WHERE $where", $params);
$upages = max(1, (int) ceil($total / $uper));
$upage = min(max(1, (int) ($_GET['upage'] ?? 1)), $upages);
$ids = pluck(q_all("SELECT u.\"id\" FROM \"User\" u WHERE $where ORDER BY CASE u.\"role\" WHEN 'SUPER_ADMIN' THEN 0 WHEN 'ADMIN' THEN 1 WHEN 'AGENT' THEN 2 ELSE 3 END, u.\"active\" DESC, u.\"name\" ASC LIMIT $uper OFFSET " . (($upage - 1) * $uper), $params), 'id');
$users = $ids ? q_all('SELECT u.*, (SELECT COUNT(*) FROM "Ticket" t WHERE t."assigneeId" = u."id" AND t."closedAt" IS NULL) AS "openAssigned" FROM "User" u WHERE u."id" IN (' . in_ph($ids) . ')', $ids) : [];
$users = array_values(array_replace(array_flip($ids), key_by($users, 'id')));
// Every organization of the listed people (primary first).
$memberships = [];
if ($ids) foreach (q_all('SELECT uo."userId", uo."organizationId", uo."isManager" FROM "UserOrganization" uo WHERE uo."userId" IN (' . in_ph($ids) . ')', $ids) as $m) $memberships[$m['userId']][$m['organizationId']] = (int) $m['isManager'] === 1;

$uurl = function (array $c) use ($uq, $urole, $ustatus, $upage, $uper, $uorg): string {
    $p = array_merge(['uq' => $uq, 'urole' => $urole, 'ustatus' => $ustatus, 'uorg' => $uorg, 'upage' => $upage, 'uper' => $uper], $c);
    if (array_key_exists('page', $c)) { $p['upage'] = $c['page'] ?? 1; unset($p['page']); }
    if (isset($c['per'])) { $p['uper'] = $c['per']; unset($p['per']); $p['upage'] = 1; }
    if ((int) $p['upage'] <= 1) unset($p['upage']);
    if ((int) $p['uper'] === 25) unset($p['uper']);
    $p = array_filter($p, fn ($v) => $v !== '' && $v !== null);
    return '/settings' . ($p ? '?' . http_build_query($p) : '') . '#users';
};

$roleOptions = ['SUPER_ADMIN' => 'Super admin', 'ADMIN' => 'Administrator', 'AGENT' => 'Agent', 'CUSTOMER' => 'Customer'];
if (!$isSuperMe) unset($roleOptions['SUPER_ADMIN']);
if (!$canAdmins) unset($roleOptions['ADMIN']);
$customOptions = ['' => "Agent — built-in ($agentPermCount/$permTotal permissions)"];
foreach ($roles as $r) $customOptions[$r['id']] = $r['name'] . ' (' . count(clean_permissions(json_decode($r['permissions'], true) ?: [])) . "/$permTotal permissions)";
$filterRoles = ['' => 'All roles', 'STAFF' => 'All staff'] + (($roleCounts['SUPER_ADMIN'] ?? 0) ? ['SUPER_ADMIN' => 'Super admins'] : []) + ['ADMIN' => 'Administrators', 'AGENT' => 'Agents (built-in role)'];
foreach ($roles as $r) $filterRoles[$r['id']] = $r['name'];
$filterRoles['CUSTOMER'] = 'Customers';
$uState = $s('users');
$inviteState = $s('invite');

$roleBadge = function (array $u) use ($rolesById): string {
    if ($u['role'] === 'AGENT' && $u['customRoleId'] && isset($rolesById[$u['customRoleId']])) {
        $r = $rolesById[$u['customRoleId']];
        $c = safe_color($r['color']);
        return '<span class="inline-flex max-w-full items-center gap-1.5 truncate rounded-full px-2 py-0.5 text-xs font-medium" style="background:' . $c . '1f;color:' . $c . '"><span class="h-1.5 w-1.5 shrink-0 rounded-full" style="background:' . $c . '"></span>' . e($r['name']) . '</span>';
    }
    $map = ['SUPER_ADMIN' => ['bg-amber-500/15 text-amber-700 dark:text-amber-300', 'shield-check'], 'ADMIN' => ['bg-indigo-500/10 text-indigo-700 dark:text-indigo-300', 'crown'], 'AGENT' => ['bg-sky-500/10 text-sky-700 dark:text-sky-300', 'users-round'], 'CUSTOMER' => ['bg-surface-2 text-muted', 'circle-user-round']];
    [$cls, $ic] = $map[$u['role']] ?? ['', 'users'];
    return '<span class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ' . $cls . '">' . icon($ic, 11) . e(role_label($u['role'])) . '</span>';
};
$menuItem = 'flex w-full items-center gap-2.5 px-3 py-2.5 text-left text-sm hover:bg-surface-2 cursor-pointer sm:py-2';
$tile = function (string $label, int $n, string $key, string $ic, string $tone) use ($urole, $ustatus, $uurl): string {
    $isStatus = $key === 'inactive';
    $on = $isStatus ? $ustatus === 'inactive' : ($urole === $key && $ustatus === '');
    $href = $isStatus ? $uurl(['ustatus' => $on ? '' : 'inactive', 'urole' => '', 'page' => 1]) : $uurl(['urole' => $on ? '' : $key, 'ustatus' => '', 'page' => 1]);
    return '<a href="' . e($href) . '" class="' . cx('flex items-center gap-3 rounded-xl border p-3 transition-colors', $on ? 'border-brand bg-brand/5' : 'border-border bg-surface hover:bg-surface-2/50') . '">'
        . '<span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg ' . $tone . '">' . icon($ic, 17) . '</span>'
        . '<span class="min-w-0"><span class="block text-lg font-semibold leading-tight tabular-nums">' . number_format($n) . '</span><span class="block truncate text-xs text-muted">' . e($label) . '</span></span></a>';
};
?>
<?php if ($isSuperMe && ($me['tenantId'] ?? null) !== $tenantId): ?>
  <!-- A visiting super admin isn't a member of this workspace -->
  <div class="flex flex-wrap items-center gap-3 rounded-xl border border-amber-300/60 bg-amber-50 p-4 dark:border-amber-500/30 dark:bg-amber-500/10">
    <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-amber-500/15 text-amber-700 dark:text-amber-300"><?= icon('shield-check', 18) ?></span>
    <div class="min-w-0 flex-1 text-sm">
      <p class="font-medium">You’re not in this list because this isn’t your workspace.</p>
      <p class="text-xs text-muted">As super admin you’re visiting it from the platform console<?= $me['tenantId'] ? '' : ' and have no workspace of your own yet' ?>. Make it your workspace to appear here, in assignee lists and in notifications as an agent.</p>
    </div>
    <form method="post" action="/action/platform-home" data-confirm="Make this your own workspace? You’ll appear here as an agent and land here after signing in.">
      <?= form_meta() ?><input type="hidden" name="tenantId" value="<?= e($tenantId) ?>">
      <button type="submit" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('house', 14) ?> Make this my workspace</button>
    </form>
  </div>
<?php endif; ?>
<!-- Overview -->
<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
  <?= $tile('Administrators', (int) ($roleCounts['ADMIN'] ?? 0) + (int) ($roleCounts['SUPER_ADMIN'] ?? 0), 'ADMIN', 'crown', 'bg-indigo-500/10 text-indigo-600') ?>
  <?= $tile('Agents', (int) ($roleCounts['AGENT'] ?? 0), 'STAFF', 'users-round', 'bg-sky-500/10 text-sky-600') ?>
  <?= $tile('Customers', (int) ($roleCounts['CUSTOMER'] ?? 0), 'CUSTOMER', 'circle-user-round', 'bg-slate-500/10 text-slate-600 dark:text-slate-300') ?>
  <?= $tile('Deactivated', $inactiveCount, 'inactive', 'user-x', 'bg-red-500/10 text-red-600') ?>
</div>

<div class="rounded-xl border border-border bg-surface" id="users-list">
  <div class="flex flex-wrap items-center gap-3 border-b border-border px-4 py-4 sm:px-5">
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Users <span class="font-normal text-muted">· <?= number_format($allCount) ?></span></h2>
      <p class="text-xs text-muted">Invite people, change their role and control who can sign in.<?php if ($isAdminMe && can($me, 'admin.roles')): ?> <a href="#roles" data-settings-jump="roles" class="font-medium text-brand hover:underline">Edit roles</a><?php endif; ?></p>
    </div>
    <?php if ($canInvite): ?><button type="button" data-toggle-target="#invite-card" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('user-plus', 14) ?> Invite user</button><?php endif; ?>
  </div>

  <!-- Invite -->
  <div id="invite-card" class="border-b border-border bg-surface-2/40 p-4 sm:p-5" <?= $inviteState && isset($inviteState['error']) ? '' : 'hidden' ?>>
    <form method="post" action="/action/member-create" class="space-y-4 rounded-xl border border-border bg-surface p-4 sm:p-5" data-invite-form data-pending="Adding…">
      <?= form_meta('invite') ?>
      <div class="flex items-center gap-3">
        <span class="grid h-9 w-9 place-items-center rounded-lg bg-brand/10 text-brand"><?= icon('user-plus', 17) ?></span>
        <div><p class="text-sm font-semibold">Invite a user</p><p class="text-xs text-muted">They’ll appear in the list right away.</p></div>
      </div>
      <?= banner($inviteState) ?>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="<?= LABEL_CLS ?>" for="inv-name">Full name</label>
          <input id="inv-name" name="name" required value="<?= e(old('invite', 'name')) ?>" placeholder="Jane Doe" class="<?= INPUT_CLS ?>">
        </div>
        <div>
          <label class="<?= LABEL_CLS ?>" for="inv-email">Email</label>
          <input id="inv-email" name="email" type="email" required value="<?= e(old('invite', 'email')) ?>" placeholder="jane@company.com" class="<?= INPUT_CLS ?>">
        </div>
        <div>
          <label class="<?= LABEL_CLS ?>" for="inv-role">Role</label>
          <select id="inv-role" name="role" data-role-select class="<?= SELECT_CLS ?>"><?= select_options($roleOptions, old('invite', 'role', 'AGENT')) ?></select>
        </div>
        <div data-custom-role-wrap>
          <label class="<?= LABEL_CLS ?>" for="inv-perm">Permissions</label>
          <select id="inv-perm" name="customRoleId" class="<?= SELECT_CLS ?>"><?= select_options($customOptions, old('invite', 'customRoleId')) ?></select>
        </div>
        <?php if ($orgs): ?>
          <div data-org-wrap>
            <label class="<?= LABEL_CLS ?>" for="inv-org">Organization</label>
            <select id="inv-org" name="organizationId" class="<?= SELECT_CLS ?>"><?= select_options(['' => 'Match by email domain'] + $orgNames, old('invite', 'organizationId')) ?></select>
          </div>
          <label data-org-wrap class="flex items-center gap-2 self-end pb-2.5 text-sm"><input type="checkbox" name="orgManager" value="1" class="h-4 w-4 rounded border-border accent-brand"> Organization manager</label>
        <?php endif; ?>
      </div>
      <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-border p-3.5">
        <span><span class="block text-sm font-medium">Send an invitation email</span><span class="block text-xs text-muted">They choose their own password (link valid 72 hours). Without email set up, you get the link to share.</span></span>
        <span class="relative mt-0.5 inline-flex shrink-0 items-center">
          <input type="checkbox" name="invite" data-invite-toggle <?= old('invite', 'invite', 'on') === 'on' ? 'checked' : '' ?> class="peer sr-only">
          <span class="h-5 w-9 rounded-full bg-border transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40"></span>
          <span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span>
        </span>
      </label>
      <div data-temp-password hidden>
        <label class="<?= LABEL_CLS ?>" for="inv-pw">Temporary password</label>
        <input id="inv-pw" name="password" type="text" autocomplete="off" placeholder="At least <?= password_min_length($tenantId) ?> characters" class="<?= INPUT_CLS ?> sm:max-w-xs">
      </div>
      <div class="flex gap-2">
        <button type="submit" class="<?= btn_cls() ?>"><?= icon('user-plus', 16) ?> Add user</button>
        <button type="button" data-toggle-target="#invite-card" class="<?= btn_cls('ghost') ?>">Cancel</button>
      </div>
    </form>
  </div>

  <?php if ($uState): ?><div class="px-4 pt-4 sm:px-5"><?= banner($uState, true) ?></div><?php endif; ?>

  <!-- Toolbar -->
  <form method="get" action="/settings#users" class="flex flex-wrap items-center gap-2 px-4 py-3 sm:px-5">
    <div class="relative min-w-0 flex-1 basis-full sm:basis-56">
      <?= icon('search', 16, 'pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted') ?>
      <input name="uq" type="search" value="<?= e($uq) ?>" placeholder="Search name or email…" class="h-9 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40">
    </div>
    <select name="urole" data-autosubmit aria-label="Role" class="h-9 min-w-0 flex-1 cursor-pointer rounded-lg border border-border bg-surface px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 sm:flex-none"><?= select_options($filterRoles, $urole) ?></select>
    <select name="ustatus" data-autosubmit aria-label="Status" class="h-9 min-w-0 flex-1 cursor-pointer rounded-lg border border-border bg-surface px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 sm:flex-none"><?= select_options(['' => 'Any status', 'active' => 'Active', 'inactive' => 'Deactivated', 'never' => 'Staff never signed in'], $ustatus) ?></select>
    <?php if ($orgs): ?>
      <select name="uorg" data-autosubmit aria-label="Organization" class="h-9 min-w-0 flex-1 cursor-pointer rounded-lg border border-border bg-surface px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 sm:flex-none"><?= select_options(['' => 'Any organization'] + $orgNames, $uorg) ?></select>
    <?php endif; ?>
    <?php if ($uq !== '' || $urole !== '' || $ustatus !== '' || $uorg !== ''): ?>
      <a href="/settings#users" class="inline-flex h-9 items-center gap-1 rounded-lg px-2.5 text-sm text-muted hover:bg-surface-2 hover:text-fg"><?= icon('x', 14) ?> Clear</a>
    <?php endif; ?>
  </form>

  <?php if ($users): ?>
    <?= pager($upage, $upages, $total, $uper, $uurl, ['perPageOptions' => [25, 50, 100], 'noun' => ['user', 'users'], 'class' => 'border-y border-border bg-surface-2/30 px-4 py-2 sm:px-5']) ?>
    <div class="hidden grid-cols-[minmax(0,1fr)_9.5rem_6.5rem_4.75rem] gap-4 border-b border-border px-5 py-2 text-[11px] font-semibold uppercase tracking-wider text-muted xl:grid 2xl:grid-cols-[minmax(0,1fr)_10rem_7rem_9rem_5rem]">
      <span>User</span><span>Role</span><span>Status</span><span class="hidden 2xl:block">Activity</span><span></span>
    </div>
  <?php else: ?>
    <div class="border-t border-border px-5 py-12 text-center">
      <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-surface-2 text-muted"><?= icon('search', 20) ?></span>
      <p class="mt-3 text-sm font-medium">No users match</p>
      <p class="text-xs text-muted">Try another search or clear the filters.</p>
    </div>
  <?php endif; ?>

  <div class="divide-y divide-border">
    <?php foreach ($users as $u):
        $isMe = $u['id'] === $me['id'];
        // Non-admin managers can't touch admins; only a super admin touches a super admin.
        $locked = ($u['role'] === 'ADMIN' && !$canAdmins) || ($u['role'] === 'SUPER_ADMIN' && !$isSuperMe);
        $active = (int) $u['active'] === 1; ?>
      <div class="<?= cx('px-4 py-3 sm:px-5', !$active ? 'bg-surface-2/40' : '') ?>" data-user-row>
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 xl:grid xl:grid-cols-[minmax(0,1fr)_9.5rem_6.5rem_4.75rem] xl:gap-4 2xl:grid-cols-[minmax(0,1fr)_10rem_7rem_9rem_5rem]">
          <div class="flex min-w-0 flex-1 items-center gap-3">
            <?= avatar($u['name'], $u['avatarColor'], 38, $active ? '' : 'opacity-50 grayscale') ?>
            <div class="min-w-0">
              <p class="flex items-center gap-1.5"><span class="truncate text-sm font-medium <?= $active ? '' : 'text-muted' ?>"><?= e($u['name']) ?></span><?php if ($isMe): ?><span class="shrink-0 rounded bg-surface-2 px-1.5 text-[10px] font-semibold uppercase text-muted">You</span><?php endif; ?></p>
              <p class="truncate text-xs text-muted"><?= e($u['email']) ?><?php $mine = $memberships[$u['id']] ?? []; if ($mine): ?> · <span class="inline-flex items-center gap-0.5"><?= icon('building-2', 11) ?><?= e(implode(', ', array_map(fn ($oid) => ($orgNames[$oid] ?? '?') . ($mine[$oid] ? ' (manager)' : ''), array_keys($mine)))) ?></span><?php endif; ?></p>
            </div>
          </div>
          <div class="min-w-0 max-xl:order-3 max-xl:ml-[3.25rem]"><?= $roleBadge($u) ?></div>
          <div class="max-xl:order-4">
            <?php if ($active): ?>
              <span class="inline-flex items-center gap-1.5 text-xs text-emerald-700 dark:text-emerald-400"><span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>Active</span>
            <?php else: ?>
              <span class="inline-flex items-center gap-1.5 text-xs font-medium text-red-700 dark:text-red-400"><?= icon('lock', 11) ?>Deactivated</span>
            <?php endif; ?>
          </div>
          <div class="text-xs text-muted hidden 2xl:block">
            <p class="truncate"><?= $u['lastLoginAt'] ? e(time_ago($u['lastLoginAt'])) : 'Never signed in' ?></p>
            <?php if ($u['role'] !== 'CUSTOMER'): ?><p><?= (int) $u['openAssigned'] ?> open assigned</p><?php endif; ?>
          </div>
          <div class="flex items-center justify-end gap-1 max-xl:order-2">
            <?php if (!$locked && ($canEditUsers || $canAccess || $canRemove || $isSuperMe)): ?>
              <?php if ($canEditUsers): ?>
              <button type="button" data-toggle-target="#edit-<?= e($u['id']) ?>" aria-label="Edit <?= e($u['name']) ?>" class="inline-flex h-8 items-center gap-1.5 rounded-lg border border-border px-2.5 text-xs font-medium hover:bg-surface-2 cursor-pointer"><?= icon('pen-line', 13) ?><span class="max-sm:hidden">Edit</span></button>
              <?php endif; ?>
              <?php
              $menu = '<div class="py-1">'
                  . '<p class="truncate border-b border-border px-3 pb-2 pt-1 text-xs text-muted">' . e($u['email']) . '</p>';
              if ($canAccess) {
                  $menu .= '<form method="post" action="/action/member-password" data-ajax>' . form_meta('users') . '<input type="hidden" name="userId" value="' . e($u['id']) . '"><input type="hidden" name="mode" value="link">'
                  . '<button type="submit" data-dropdown-close class="' . $menuItem . '">' . icon('mail', 15, 'text-muted') . 'Send password reset link</button></form>'
                  . '<form method="post" action="/action/member-password" data-ajax data-ask="New password for ' . e($u['name']) . '" data-ask-field="password" data-ask-type="text" data-ask-placeholder="At least ' . password_min_length($tenantId) . ' characters" data-ask-ok="Set password">' . form_meta('users')
                  . '<input type="hidden" name="userId" value="' . e($u['id']) . '"><input type="hidden" name="mode" value="set"><input type="hidden" name="password" value="">'
                  . '<button type="submit" data-dropdown-close class="' . $menuItem . '">' . icon('key-round', 15, 'text-muted') . 'Set a new password</button></form>';
              }
              if ($isSuperMe && !$isMe && $active && $u['role'] !== 'SUPER_ADMIN') {
                  $menu .= '<form method="post" action="/action/impersonate">' . form_meta('users') . '<input type="hidden" name="userId" value="' . e($u['id']) . '">'
                      . '<button type="submit" data-dropdown-close class="' . $menuItem . ' text-brand">' . icon('log-in', 15) . 'Sign in as ' . e(strtok($u['name'], ' ')) . '</button></form>';
              }
              if (!$isMe && ($canAccess || $canRemove)) {
                  $menu .= '<div class="my-1 border-t border-border"></div>';
              }
              if (!$isMe && $canAccess) {
                  $menu .= '<form method="post" action="/action/member-status" data-ajax' . ($active ? ' data-confirm="Deactivate ' . e($u['name']) . '? They are signed out at once and can’t sign in until you reactivate them. Their tickets and history stay."' : '') . '>' . form_meta('users') . '<input type="hidden" name="userId" value="' . e($u['id']) . '"><input type="hidden" name="active" value="' . ($active ? '0' : '1') . '">'
                      . '<button type="submit" data-dropdown-close class="' . $menuItem . ' ' . ($active ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400') . '">' . icon($active ? 'user-x' : 'user-round-check', 15) . ($active ? 'Deactivate…' : 'Reactivate') . '</button></form>';
              }
              if (!$isMe && $canRemove) {
                  $menu .= '<form method="post" action="/action/member-remove" data-ajax data-confirm="Remove ' . e($u['name']) . '? This permanently deletes the tickets they opened and every reply they wrote; tickets assigned to them become unassigned. Deactivating keeps all history.">' . form_meta('users') . '<input type="hidden" name="userId" value="' . e($u['id']) . '">'
                      . '<button type="submit" data-dropdown-close class="' . $menuItem . ' text-red-600 dark:text-red-400">' . icon('trash-2', 15) . 'Remove permanently…</button></form>';
              }
              $menu .= '</div>';
              echo dropdown('<button type="button" data-dropdown-trigger aria-label="More actions for ' . e($u['name']) . '" class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg cursor-pointer">' . icon('ellipsis', 16) . '</button>', $menu, 250, 'right', true);
              ?>
            <?php else: ?>
              <span class="inline-flex items-center gap-1 text-xs text-muted" title="<?= $u['role'] === 'SUPER_ADMIN' ? 'Only a super admin can change this account' : 'Only administrators can change administrator accounts' ?>"><?= icon('lock', 12) ?> <?= $u['role'] === 'SUPER_ADMIN' ? 'Protected' : 'Admin only' ?></span>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$locked && $canEditUsers): ?>
          <form method="post" action="/action/member-update" id="edit-<?= e($u['id']) ?>" hidden class="mt-3 rounded-xl border border-border bg-surface p-4 shadow-sm xl:ml-[3.25rem]" data-ajax>
            <?= form_meta('users') ?><input type="hidden" name="userId" value="<?= e($u['id']) ?>">
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
              <div><label class="mb-1 block text-xs font-medium text-muted">Name</label><input name="name" value="<?= e($u['name']) ?>" required class="<?= INPUT_CLS ?>"></div>
              <div><label class="mb-1 block text-xs font-medium text-muted">Email</label><input name="email" type="email" value="<?= e($u['email']) ?>" required class="<?= INPUT_CLS ?>"></div>
              <div><label class="mb-1 block text-xs font-medium text-muted">Role</label>
                <select name="role" data-role-select class="<?= SELECT_CLS ?>"><?= select_options($roleOptions + ($u['role'] === 'ADMIN' ? ['ADMIN' => 'Administrator'] : []), $u['role']) ?></select></div>
              <div data-custom-role-wrap><label class="mb-1 block text-xs font-medium text-muted">Permissions</label>
                <select name="customRoleId" class="<?= SELECT_CLS ?>"><?= select_options($customOptions, $u['customRoleId'] ?? '') ?></select></div>
              <?php if ($orgs): ?>
                <div class="sm:col-span-2 xl:col-span-4">
                  <input type="hidden" name="orgsSent" value="1">
                  <p class="mb-1 text-xs font-medium text-muted">Organizations <span class="font-normal">— customers: where they belong (<?= icon('crown', 10, 'inline') ?> manager sees all of it); staff: the organizations they handle (they see all of their tickets)</span></p>
                  <?php // The list of every organization is rendered once (template below) and filled in when the form opens. ?>
                  <div data-org-picker data-orgs="<?= e(json_encode((object) array_map(fn ($m) => (bool) $m, $memberships[$u['id']] ?? []))) ?>"></div>
                </div>
              <?php endif; ?>
            </div>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <button type="submit" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('check', 14) ?> Save</button>
              <button type="button" data-toggle-target="#edit-<?= e($u['id']) ?>" class="<?= btn_cls('ghost', 'sm') ?>">Cancel</button>
              <span class="text-xs text-muted 2xl:hidden"><?= $u['lastLoginAt'] ? 'Last sign-in ' . e(time_ago($u['lastLoginAt'])) : 'Never signed in' ?></span>
            </div>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($orgs && $canEditUsers): ?>
    <?php // Every organization as compact data; app.js builds the checklist when an edit form opens. ?>
    <script type="application/json" id="org-picker-data"><?= json_encode(array_map(null, array_keys($orgNames), array_values($orgNames)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
  <?php endif; ?>
  <?php if ($upages > 1): ?>
    <?= pager($upage, $upages, $total, $uper, $uurl, ['perPageOptions' => [25, 50, 100], 'noun' => ['user', 'users'], 'class' => 'border-t border-border px-4 py-3 sm:px-5']) ?>
  <?php endif; ?>
</div>
