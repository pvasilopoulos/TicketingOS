<?php
/**
 * Settings → Roles & permissions (administrators only).
 * @var array $me @var string $tenantId @var callable $s
 */
$roles = q_all('SELECT r.*, (SELECT COUNT(*) FROM "User" u WHERE u."customRoleId" = r."id" AND u."role" = \'AGENT\') AS "members" FROM "Role" r WHERE r."tenantId" = ? ORDER BY r."name"', [$tenantId]);
$agentPerms = agent_permissions($tenantId);
$info = permission_info();
$total = count($info);
$counts = array_column(q_all('SELECT "role", COUNT(*) AS n FROM "User" WHERE "tenantId" = ? GROUP BY "role"', [$tenantId]), 'n', 'role');
$plainAgents = (int) q_val('SELECT COUNT(*) FROM "User" WHERE "tenantId" = ? AND "role" = \'AGENT\' AND "customRoleId" IS NULL', [$tenantId]);
$staff = q_all('SELECT "id","name","avatarColor","role","customRoleId" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'ADMIN\',\'AGENT\') AND "active" = 1 ORDER BY "name" LIMIT 500', [$tenantId]);
$membersOf = function (string $key) use ($staff): array {
    return array_values(array_filter($staff, fn ($u) => match ($key) {
        'admin' => $u['role'] === 'ADMIN',
        'agent' => $u['role'] === 'AGENT' && !$u['customRoleId'],
        default => $u['role'] === 'AGENT' && $u['customRoleId'] === $key,
    }));
};

// Which role is open (?role=admin|agent|customer|new|<id>), and an optional source to copy for "new".
$keys = ['admin', 'agent', 'customer', 'new', ...array_column($roles, 'id')];
$sel = in_array($_GET['role'] ?? '', $keys, true) ? $_GET['role'] : 'agent';
$rolesById = key_by($roles, 'id');
$from = (string) ($_GET['from'] ?? '');
$newPerms = $from === 'agent' ? $agentPerms : (isset($rolesById[$from]) ? (json_decode($rolesById[$from]['permissions'], true) ?: []) : ROLE_TEMPLATES['junior'][2]);
$newName = $from === 'agent' ? 'Copy of Agent' : (isset($rolesById[$from]) ? 'Copy of ' . $rolesById[$from]['name'] : '');
$rs = $s('roles');
$swatches = ['#6366f1', '#8b5cf6', '#ec4899', '#ef4444', '#f59e0b', '#22c55e', '#14b8a6', '#0ea5e9', '#64748b'];

$avatars = function (array $users, string $filterKey) use ($tenantId): string {
    if (!$users) return '<span class="text-xs text-muted">Nobody yet</span>';
    $h = '<a href="/settings?urole=' . e($filterKey) . '#users" class="group inline-flex items-center gap-2" title="See these users"><span class="flex -space-x-2">';
    foreach (array_slice($users, 0, 5) as $u) $h .= avatar($u['name'], $u['avatarColor'], 26, 'ring-2 ring-surface');
    $h .= '</span><span class="text-xs text-muted group-hover:text-fg">' . count($users) . ' member' . (count($users) === 1 ? '' : 's') . '</span></a>';
    return $h;
};
$meter = fn (int $n) => '<span class="mt-1.5 block h-1 overflow-hidden rounded-full bg-surface-2"><span class="block h-full rounded-full bg-current opacity-70" style="width:' . round($n / max(1, $total) * 100) . '%"></span></span>';

/** Toggle-switch permission editor (behaviour in app.js → bindPermForm). */
$editor = function (array $checked, bool $readonly = false) use ($info): string {
    $h = '<div class="space-y-4">';
    foreach (PERMISSIONS as $group => [$gicon, $gdesc, $perms]) {
        $on = count(array_intersect(array_keys($perms), $checked));
        $h .= '<div class="overflow-hidden rounded-xl border border-border" data-perm-group>'
            . '<div class="flex items-center gap-3 border-b border-border bg-surface-2/50 px-4 py-3">'
            . '<span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-surface text-muted ring-1 ring-border">' . icon($gicon, 15) . '</span>'
            . '<span class="min-w-0 flex-1"><span class="block text-sm font-semibold">' . e($group) . '</span><span class="block truncate text-xs text-muted">' . e($gdesc) . '</span></span>'
            . '<span class="shrink-0 text-xs tabular-nums text-muted"><span data-group-count>' . $on . '</span>/' . count($perms) . '</span>';
        if (!$readonly) {
            $h .= '<label class="relative inline-flex shrink-0 cursor-pointer items-center" title="All in this group">'
                . '<input type="checkbox" data-group-all class="peer sr-only" ' . ($on === count($perms) ? 'checked' : '') . '>'
                . '<span class="h-5 w-9 rounded-full bg-border transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40 peer-data-[partial=true]:bg-brand/50"></span>'
                . '<span class="absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span></label>';
        }
        $h .= '</div><div class="divide-y divide-border">';
        foreach ($perms as $key => [$label, $hint, $flags]) {
            $is = in_array($key, $checked, true);
            $req = $flags['requires'] ?? '';
            $h .= '<label class="flex items-start gap-3 px-4 py-3 ' . ($readonly ? '' : 'cursor-pointer hover:bg-surface-2/40') . '">'
                . '<span class="min-w-0 flex-1"><span class="flex flex-wrap items-center gap-x-2 gap-y-1 text-sm font-medium">' . e($label)
                . (!empty($flags['sensitive']) ? '<span class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-1.5 py-px text-[10px] font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">' . icon('shield-alert', 11) . 'Sensitive</span>' : '')
                . '</span>'
                . ($hint ? '<span class="mt-0.5 block text-xs text-muted">' . e($hint) . '</span>' : '')
                . ($req ? '<span class="mt-1 block text-[11px] text-muted">' . icon('link', 11, 'inline -mt-px') . ' Needs “' . e($info[$req][0]) . '”</span>' : '')
                . '</span>';
            if ($readonly) {
                $h .= $is ? '<span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-emerald-500/10 text-emerald-600">' . icon('check', 14) . '</span>'
                    : '<span class="mt-0.5 grid h-6 w-6 shrink-0 place-items-center rounded-full bg-surface-2 text-muted">' . icon('minus', 14) . '</span>';
            } else {
                $h .= '<span class="relative mt-0.5 inline-flex shrink-0 items-center">'
                    . '<input type="checkbox" name="perm[' . e($key) . ']" value="on" data-perm="' . e($key) . '"' . ($req ? ' data-requires="' . e($req) . '"' : '') . ($is ? ' checked' : '') . ' class="peer sr-only">'
                    . '<span class="h-5 w-9 rounded-full bg-border transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40"></span>'
                    . '<span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span></span>';
            }
            $h .= '</label>';
        }
        $h .= '</div></div>';
    }
    return $h . '</div>';
};

/** Sticky footer of an editable role. */
$saveBar = function (string $extra = '') : string {
    return '<div class="sticky bottom-[calc(5.5rem+env(safe-area-inset-bottom))] z-10 mt-5 flex flex-wrap items-center gap-2 rounded-xl border border-border bg-surface/95 p-3 shadow-lg shadow-black/5 backdrop-blur lg:bottom-4">'
        . '<span class="mr-auto inline-flex items-center gap-2 text-sm text-muted"><span data-perm-total class="font-semibold tabular-nums text-fg"></span><span class="max-sm:hidden">permissions on</span>'
        . '<span data-dirty-note hidden class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-700 dark:text-amber-400">Unsaved changes</span></span>'
        . $extra
        . '<button type="submit" data-perm-save class="' . btn_cls('primary', 'sm') . '">' . icon('check', 14) . ' Save<span class="max-sm:hidden"> changes</span></button></div>';
};

$listItem = function (string $key, string $name, string $sub, string $color, string $ic, int $n) use ($sel, $meter): string {
    $active = $sel === $key;
    return '<a href="/settings?role=' . e($key) . '#roles" data-role-pick="' . e($key) . '" data-active="' . ($active ? 'true' : 'false') . '" '
        . 'class="group flex items-center gap-3 rounded-xl border border-transparent px-3 py-2.5 transition-colors hover:bg-surface-2 data-[active=true]:border-border data-[active=true]:bg-surface data-[active=true]:shadow-sm">'
        . '<span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg text-white" style="background:' . e($color) . '">' . icon($ic, 16) . '</span>'
        . '<span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium">' . e($name) . '</span>'
        . '<span class="block truncate text-xs text-muted">' . e($sub) . '</span>'
        . '<span class="block" style="color:' . e($color) . '">' . ($n >= 0 ? $meter($n) : '') . '</span></span></a>';
};
?>
<div class="rounded-xl border border-border bg-surface">
  <div class="flex flex-wrap items-start gap-4 p-5 sm:p-6">
    <span class="hidden h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand/10 text-brand sm:grid"><?= icon('shield-check', 22) ?></span>
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Roles & permissions</h2>
      <p class="mt-0.5 text-sm text-muted">Decide what each kind of user can see and change. Every agent uses the built-in <strong class="text-fg">Agent</strong> role unless you give them a custom one.</p>
    </div>
    <a href="/settings?role=new#roles" data-role-pick="new" class="<?= btn_cls('primary', 'sm') ?> max-sm:hidden"><?= icon('plus', 14) ?> New role</a>
  </div>
  <?php if ($rs): ?><div class="px-5 pb-5 sm:px-6"><?= banner($rs, true) ?></div><?php endif; ?>
</div>

<div class="grid gap-5 2xl:grid-cols-[16rem_minmax(0,1fr)]" data-roles>
  <!-- Role list -->
  <nav class="flex gap-1 overflow-x-auto rounded-xl border border-border bg-surface-2/40 p-1.5 2xl:flex-col 2xl:self-start 2xl:overflow-visible" aria-label="Roles">
    <p class="hidden px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-wider text-muted 2xl:block">Built-in</p>
    <div class="flex shrink-0 gap-1 2xl:flex-col [&>a]:min-w-52 2xl:[&>a]:min-w-0">
      <?= $listItem('admin', 'Administrator', number_format((int) ($counts['ADMIN'] ?? 0)) . ' · all permissions', '#4f46e5', 'crown', $total) ?>
      <?= $listItem('agent', 'Agent', $plainAgents . ' · ' . count($agentPerms) . '/' . $total . ' permissions', '#0284c7', 'users-round', count($agentPerms)) ?>
      <?= $listItem('customer', 'Customer', number_format((int) ($counts['CUSTOMER'] ?? 0)) . ' · own tickets only', '#64748b', 'circle-user-round', -1) ?>
    </div>
    <p class="hidden px-3 pb-1 pt-4 text-[11px] font-semibold uppercase tracking-wider text-muted 2xl:block">Custom</p>
    <div class="flex shrink-0 gap-1 2xl:flex-col [&>a]:min-w-52 2xl:[&>a]:min-w-0">
      <?php foreach ($roles as $r): $rp = clean_permissions(json_decode($r['permissions'], true) ?: []); ?>
        <?= $listItem($r['id'], $r['name'], (int) $r['members'] . ' · ' . count($rp) . '/' . $total . ' permissions', safe_color($r['color']), 'shield-check', count($rp)) ?>
      <?php endforeach; ?>
      <a href="/settings?role=new#roles" data-role-pick="new" data-active="<?= $sel === 'new' ? 'true' : 'false' ?>" class="flex min-w-40 items-center gap-3 rounded-xl border border-dashed border-border px-3 py-2.5 text-sm font-medium text-brand transition-colors hover:bg-surface data-[active=true]:bg-surface 2xl:min-w-0">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-brand/10"><?= icon('plus', 16) ?></span> New role
      </a>
    </div>
  </nav>

  <div class="min-w-0">
    <!-- Administrator -->
    <div data-role-panel="admin" <?= $sel === 'admin' ? '' : 'hidden' ?> class="space-y-4">
      <div class="rounded-xl border border-border bg-surface p-5">
        <div class="flex flex-wrap items-center gap-4">
          <span class="grid h-12 w-12 place-items-center rounded-xl bg-indigo-600 text-white"><?= icon('crown', 22) ?></span>
          <div class="min-w-0 flex-1"><h3 class="font-semibold">Administrator</h3><p class="text-sm text-muted">Full access, always. Can't be edited.</p></div>
          <?= $avatars($membersOf('admin'), 'ADMIN') ?>
        </div>
        <ul class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
          <?php foreach (['Every permission below', 'Create and edit roles', 'Add, change and remove administrators', 'Security and password rules'] as $t): ?>
            <li class="flex items-center gap-2"><?= icon('circle-check', 15, 'text-emerald-600') ?><?= e($t) ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
      <?= $editor(all_permissions(), true) ?>
    </div>

    <!-- Customer -->
    <div data-role-panel="customer" <?= $sel === 'customer' ? '' : 'hidden' ?> class="space-y-4">
      <div class="rounded-xl border border-border bg-surface p-5">
        <div class="flex flex-wrap items-center gap-4">
          <span class="grid h-12 w-12 place-items-center rounded-xl bg-slate-500 text-white"><?= icon('circle-user-round', 22) ?></span>
          <div class="min-w-0 flex-1"><h3 class="font-semibold">Customer</h3><p class="text-sm text-muted">People who ask for help. They never get staff permissions.</p></div>
          <a href="/settings?urole=CUSTOMER#users" class="text-xs text-muted hover:text-fg"><?= number_format((int) ($counts['CUSTOMER'] ?? 0)) ?> customers</a>
        </div>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Can</p>
            <ul class="space-y-2 text-sm">
              <?php foreach (['Open new tickets', 'See and reply to their own tickets', 'Add followers to their own tickets', 'Read published help-center articles', 'Get notifications for their tickets'] as $t): ?>
                <li class="flex items-start gap-2"><?= icon('circle-check', 15, 'mt-0.5 shrink-0 text-emerald-600') ?><?= e($t) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
          <div>
            <p class="mb-2 text-xs font-semibold uppercase tracking-wide text-muted">Can't</p>
            <ul class="space-y-2 text-sm text-muted">
              <?php foreach (['See other people’s tickets', 'Read internal notes', 'Change status, priority or assignee', 'Open reports or settings'] as $t): ?>
                <li class="flex items-start gap-2"><?= icon('x', 15, 'mt-0.5 shrink-0') ?><?= e($t) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        </div>
      </div>
    </div>

    <!-- Agent (built-in) -->
    <form method="post" action="/action/agent-perms-save" data-role-panel="agent" data-perm-form <?= $sel === 'agent' ? '' : 'hidden' ?>>
      <?= form_meta('roles', '/settings?role=agent#roles') ?>
      <div class="mb-4 rounded-xl border border-border bg-surface p-5">
        <div class="flex flex-wrap items-center gap-4">
          <span class="grid h-12 w-12 place-items-center rounded-xl bg-sky-600 text-white"><?= icon('users-round', 22) ?></span>
          <div class="min-w-0 flex-1"><h3 class="font-semibold">Agent <span class="ml-1 rounded-full bg-surface-2 px-2 py-0.5 text-xs font-medium text-muted">Built-in</span></h3>
            <p class="text-sm text-muted max-sm:hidden">The default for every agent without a custom role.</p></div>
          <?= $avatars($membersOf('agent'), 'AGENT') ?>
        </div>
      </div>
      <?= $editor($agentPerms) ?>
      <?= $saveBar('<a href="/settings?role=new&amp;from=agent#roles" title="Duplicate role" class="' . btn_cls('ghost', 'sm') . '">' . icon('copy', 14) . '<span class="max-sm:hidden">Duplicate</span></a>'
          . '<button type="submit" name="reset" value="1" formnovalidate data-confirm="Reset the Agent role to the default permissions?" title="Reset to defaults" class="' . btn_cls('ghost', 'sm') . '">' . icon('rotate-ccw', 14) . '<span class="max-sm:hidden">Reset to defaults</span></button>') ?>
    </form>

    <!-- Custom roles -->
    <?php foreach ($roles as $r): $rp = clean_permissions(json_decode($r['permissions'], true) ?: []); $color = safe_color($r['color']); ?>
      <form method="post" action="/action/role-save" data-role-panel="<?= e($r['id']) ?>" data-perm-form <?= $sel === $r['id'] ? '' : 'hidden' ?>>
        <?= form_meta('roles', '/settings?role=' . $r['id'] . '#roles') ?><input type="hidden" name="id" value="<?= e($r['id']) ?>">
        <div class="mb-4 rounded-xl border border-border bg-surface p-5">
          <div class="flex flex-wrap items-center gap-4">
            <span data-role-swatch class="grid h-12 w-12 place-items-center rounded-xl text-white" style="background:<?= $color ?>"><?= icon('shield-check', 22) ?></span>
            <div class="min-w-0 flex-1"><h3 class="truncate font-semibold" data-role-title><?= e($r['name']) ?></h3><p class="text-sm text-muted">Custom role</p></div>
            <?= $avatars($membersOf($r['id']), $r['id']) ?>
          </div>
          <?php $roleName = $r['name']; $roleDesc = $r['description'] ?? ''; require __DIR__ . '/role_fields.php'; ?>
        </div>
        <?= $editor($rp) ?>
        <?= $saveBar('<a href="/settings?role=new&amp;from=' . e($r['id']) . '#roles" title="Duplicate role" class="' . btn_cls('ghost', 'sm') . '">' . icon('copy', 14) . '<span class="max-sm:hidden">Duplicate</span></a>'
            . '<button type="submit" formaction="/action/role-delete" formnovalidate data-confirm="Delete the role “' . e($r['name']) . '”? ' . ((int) $r['members'] ? (int) $r['members'] . ' agent(s) will fall back to the Agent role.' : 'Nobody uses it.') . '" title="Delete role" class="' . btn_cls('ghost', 'sm') . ' text-red-600">' . icon('trash-2', 14) . '<span class="max-sm:hidden">Delete</span></button>') ?>
      </form>
    <?php endforeach; ?>

    <!-- New role -->
    <form method="post" action="/action/role-save" data-role-panel="new" data-perm-form data-perm-new <?= $sel === 'new' ? '' : 'hidden' ?>>
      <?= form_meta('roles', '/settings?role=new#roles') ?>
      <div class="mb-4 rounded-xl border border-border bg-surface p-5">
        <div class="flex flex-wrap items-center gap-4">
          <span data-role-swatch class="grid h-12 w-12 place-items-center rounded-xl text-white" style="background:#8b5cf6"><?= icon('shield-check', 22) ?></span>
          <div class="min-w-0 flex-1"><h3 class="truncate font-semibold" data-role-title><?= e($newName ?: 'New role') ?></h3><p class="text-sm text-muted">Pick a starting point, then adjust.</p></div>
        </div>
        <?php if ($from === ''): ?>
          <div class="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach (ROLE_TEMPLATES as $tk => [$tname, $tdesc, $tperms]): ?>
              <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-border p-3 transition-colors hover:bg-surface-2/50 has-[:checked]:border-brand has-[:checked]:bg-brand/5">
                <input type="radio" name="template" value="<?= e($tk) ?>" data-template='<?= e(json_encode($tperms)) ?>' <?= $tk === 'junior' ? 'checked' : '' ?> class="mt-0.5 accent-brand">
                <span class="min-w-0"><span class="block text-sm font-medium"><?= e($tname) ?></span><span class="block text-xs text-muted"><?= e($tdesc) ?></span><span class="mt-1 block text-[11px] text-muted"><?= count($tperms) ?> permissions</span></span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php $roleName = $newName; $roleDesc = ''; $color = '#8b5cf6'; require __DIR__ . '/role_fields.php'; ?>
      </div>
      <?= $editor($newPerms) ?>
      <?= $saveBar('<a href="/settings?role=agent#roles" data-role-pick="agent" class="' . btn_cls('ghost', 'sm') . '">Cancel</a>') ?>
    </form>
  </div>
</div>

<!-- Compare -->
<details class="group rounded-xl border border-border bg-surface">
  <summary class="flex cursor-pointer list-none items-center gap-3 px-5 py-4">
    <span class="grid h-8 w-8 place-items-center rounded-lg bg-surface-2 text-muted"><?= icon('table', 15) ?></span>
    <span class="flex-1"><span class="block text-sm font-semibold">Compare roles</span><span class="block text-xs text-muted">Every permission side by side</span></span>
    <?= icon('chevron-down', 16, 'text-muted transition-transform group-open:rotate-180') ?>
  </summary>
  <div class="overflow-x-auto border-t border-border">
    <table class="w-full min-w-[36rem] text-sm">
      <thead>
        <tr class="border-b border-border text-xs text-muted">
          <th class="sticky left-0 z-[1] bg-surface px-4 py-2.5 text-left font-medium">Permission</th>
          <th class="px-3 py-2.5 text-center font-medium">Admin</th>
          <th class="px-3 py-2.5 text-center font-medium">Agent</th>
          <?php foreach ($roles as $r): ?><th class="px-3 py-2.5 text-center font-medium"><span class="inline-flex items-center gap-1.5 whitespace-nowrap"><span class="h-2 w-2 rounded-full" style="background:<?= safe_color($r['color']) ?>"></span><?= e($r['name']) ?></span></th><?php endforeach; ?>
        </tr>
      </thead>
      <tbody>
        <?php $sets = array_map(fn ($r) => clean_permissions(json_decode($r['permissions'], true) ?: []), $roles);
        $yes = icon('check', 15, 'inline text-emerald-600'); $no = '<span class="text-border">—</span>';
        foreach (PERMISSIONS as $group => [, , $perms]): ?>
          <tr class="bg-surface-2/60"><td colspan="<?= 3 + count($roles) ?>" class="sticky left-0 px-4 py-1.5 text-[11px] font-semibold uppercase tracking-wider text-muted"><?= e($group) ?></td></tr>
          <?php foreach ($perms as $key => [$label]): ?>
            <tr class="border-t border-border">
              <td class="sticky left-0 z-[1] bg-surface px-4 py-2"><?= e($label) ?></td>
              <td class="px-3 py-2 text-center"><?= $yes ?></td>
              <td class="px-3 py-2 text-center"><?= in_array($key, $agentPerms, true) ? $yes : $no ?></td>
              <?php foreach ($sets as $set): ?><td class="px-3 py-2 text-center"><?= in_array($key, $set, true) ? $yes : $no ?></td><?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</details>
