<?php
/**
 * Settings → Groups (users.manage).
 * @var array $me @var string $tenantId @var callable $s
 */
$groups = q_all('SELECT g.*, (SELECT COUNT(*) FROM "AgentGroupMember" m WHERE m."groupId" = g."id") AS "members" FROM "AgentGroup" g WHERE g."tenantId" = ? ORDER BY g."name"', [$tenantId]);
$closed = closed_status_keys(tenant_statuses($tenantId));
$openByGroup = array_column(q_all('SELECT "groupId", COUNT(*) AS n FROM "Ticket" WHERE "tenantId" = ? AND "groupId" IS NOT NULL' . ($closed ? ' AND "status" NOT IN (' . in_ph($closed) . ')' : '') . ' GROUP BY "groupId"', [$tenantId, ...$closed]), 'n', 'groupId');
$unassignedByGroup = array_column(q_all('SELECT "groupId", COUNT(*) AS n FROM "Ticket" WHERE "tenantId" = ? AND "groupId" IS NOT NULL AND "assigneeId" IS NULL' . ($closed ? ' AND "status" NOT IN (' . in_ph($closed) . ')' : '') . ' GROUP BY "groupId"', [$tenantId, ...$closed]), 'n', 'groupId');
$staff = q_all('SELECT "id","name","avatarColor","role" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') AND "active" = 1 ORDER BY "name" LIMIT 500', [$tenantId]);
$membersOf = [];
foreach (q_all('SELECT m."groupId", m."userId" FROM "AgentGroupMember" m JOIN "AgentGroup" g ON g."id" = m."groupId" WHERE g."tenantId" = ?', [$tenantId]) as $m) $membersOf[$m['groupId']][] = $m['userId'];
$staffById = key_by($staff, 'id');
$categories = q_all('SELECT "id","name","color","groupId" FROM "Category" WHERE "tenantId" = ? ORDER BY "name"', [$tenantId]);
$gs = $s('groups');
$openGroup = (string) ($_GET['group'] ?? '');
$swatches = ['#0ea5e9', '#6366f1', '#8b5cf6', '#ec4899', '#f59e0b', '#22c55e', '#14b8a6', '#64748b'];

$groupForm = function (?array $g) use ($staff, $membersOf, $swatches): string {
    $id = $g['id'] ?? '';
    $members = $membersOf[$id] ?? [];
    $color = $g['color'] ?? '#0ea5e9';
    $h = '<form method="post" action="/action/group-save" class="space-y-4">' . form_meta('groups', '/settings#groups')
        . ($id ? '<input type="hidden" name="id" value="' . e($id) . '">' : '') . '<input type="hidden" name="membersSent" value="1">'
        . '<div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.5fr)]">'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Name</label><input name="name" required maxlength="60" value="' . e($g['name'] ?? '') . '" placeholder="e.g. Billing" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Description</label><input name="description" maxlength="200" value="' . e($g['description'] ?? '') . '" placeholder="What this team handles" class="' . INPUT_CLS . '"></div></div>'
        . '<div><span class="mb-1.5 block text-xs font-medium text-muted">Colour</span><div class="flex flex-wrap gap-2">';
    foreach ($swatches as $sw) {
        $h .= '<label class="cursor-pointer"><input type="radio" name="color" value="' . $sw . '" ' . (strcasecmp($sw, $color) === 0 ? 'checked' : '') . ' class="peer sr-only">'
            . '<span class="block h-6 w-6 rounded-full ring-2 ring-transparent ring-offset-2 ring-offset-surface peer-checked:ring-fg" style="background:' . $sw . '"></span></label>';
    }
    $h .= '</div></div><div><span class="mb-1.5 block text-xs font-medium text-muted">New tickets in this group</span><div class="grid gap-2 sm:grid-cols-3">';
    foreach (GROUP_ASSIGN_MODES as $k => [$label, $hint]) {
        $h .= '<label class="flex cursor-pointer items-start gap-2.5 rounded-xl border border-border p-3 has-[:checked]:border-brand has-[:checked]:bg-brand/5">'
            . '<input type="radio" name="assignMode" value="' . $k . '" ' . (($g['assignMode'] ?? 'none') === $k ? 'checked' : '') . ' class="mt-0.5 accent-brand">'
            . '<span><span class="block text-sm font-medium">' . e($label) . '</span><span class="block text-xs text-muted">' . e($hint) . '</span></span></label>';
    }
    $h .= '</div></div><div><span class="mb-1.5 block text-xs font-medium text-muted">Members <span data-member-count class="font-normal">(' . count($members) . ')</span></span>'
        . '<input type="search" placeholder="Filter agents…" data-member-filter class="mb-2 h-9 w-full rounded-lg border border-border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 sm:max-w-xs">'
        . '<div class="grid max-h-64 gap-1 overflow-y-auto rounded-xl border border-border p-1.5 sm:grid-cols-2" data-member-list>';
    foreach ($staff as $u) {
        $h .= '<label class="flex cursor-pointer items-center gap-2.5 rounded-lg px-2 py-1.5 hover:bg-surface-2" data-member-name="' . e(mb_strtolower($u['name'])) . '">'
            . '<input type="checkbox" name="members[]" value="' . e($u['id']) . '" ' . (in_array($u['id'], $members, true) ? 'checked' : '') . ' class="h-4 w-4 rounded border-border accent-brand">'
            . avatar($u['name'], $u['avatarColor'], 24) . '<span class="min-w-0 flex-1 truncate text-sm">' . e($u['name']) . '</span>'
            . '<span class="text-[11px] text-muted">' . e(role_label($u['role'])) . '</span></label>';
    }
    $h .= '</div></div><div class="flex flex-wrap gap-2"><button type="submit" class="' . btn_cls('primary', 'sm') . '">' . icon('check', 14) . ($id ? ' Save group' : ' Create group') . '</button>';
    if ($id) {
        $h .= '<button type="submit" formaction="/action/group-delete" formnovalidate data-confirm="Delete the group “' . e($g['name']) . '”? Its tickets stay, without a group." class="' . btn_cls('ghost', 'sm') . ' text-red-600">' . icon('trash-2', 14) . ' Delete</button>';
    }
    return $h . '</div></form>';
};
?>
<div class="rounded-xl border border-border bg-surface">
  <div class="flex flex-wrap items-start gap-4 p-5 sm:p-6">
    <span class="hidden h-11 w-11 shrink-0 place-items-center rounded-xl bg-sky-500/10 text-sky-600 sm:grid"><?= icon('users-round', 22) ?></span>
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Groups</h2>
      <p class="mt-0.5 text-sm text-muted">Teams of agents (e.g. Billing, Technical). Tickets can belong to a group; members always see their group’s tickets, and new tickets can be assigned automatically.</p>
    </div>
    <button type="button" data-toggle-target="#group-new" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('plus', 14) ?> New group</button>
  </div>
  <?php if ($gs): ?><div class="px-5 pb-5 sm:px-6"><?= banner($gs, true) ?></div><?php endif; ?>
  <div id="group-new" class="border-t border-border bg-surface-2/40 p-5" <?= $openGroup === 'new' ? '' : 'hidden' ?>><?= $groupForm(null) ?></div>
</div>

<?php if (!$groups): ?>
  <div class="rounded-xl border border-dashed border-border p-10 text-center">
    <span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-surface-2 text-muted"><?= icon('users-round', 20) ?></span>
    <p class="mt-3 text-sm font-medium">No groups yet</p>
    <p class="text-xs text-muted">Create one to route tickets to the right team.</p>
  </div>
<?php endif; ?>

<div class="grid gap-3">
  <?php foreach ($groups as $g): $members = $membersOf[$g['id']] ?? []; $c = safe_color($g['color']); ?>
    <details class="group rounded-xl border border-border bg-surface" <?= $openGroup === $g['id'] ? 'open' : '' ?>>
      <summary class="flex cursor-pointer list-none flex-wrap items-center gap-3 px-4 py-3.5 sm:px-5">
        <span class="grid h-10 w-10 shrink-0 place-items-center rounded-xl text-white" style="background:<?= $c ?>"><?= icon('users-round', 18) ?></span>
        <span class="min-w-0 flex-1">
          <span class="block truncate font-medium"><?= e($g['name']) ?></span>
          <span class="block truncate text-xs text-muted"><?= e($g['description'] ?: GROUP_ASSIGN_MODES[$g['assignMode']][0] . ' assignment') ?></span>
        </span>
        <span class="flex -space-x-2"><?php foreach (array_slice($members, 0, 5) as $uid): if (!isset($staffById[$uid])) continue; ?><?= avatar($staffById[$uid]['name'], $staffById[$uid]['avatarColor'], 24, 'ring-2 ring-surface') ?><?php endforeach; ?></span>
        <span class="flex items-center gap-3 text-xs text-muted">
          <span class="rounded-full bg-surface-2 px-2 py-0.5"><?= count($members) ?> member<?= count($members) === 1 ? '' : 's' ?></span>
          <a href="/tickets?group=<?= e($g['id']) ?>" class="rounded-full bg-surface-2 px-2 py-0.5 hover:text-fg"><?= (int) ($openByGroup[$g['id']] ?? 0) ?> open</a>
          <?php if ((int) ($unassignedByGroup[$g['id']] ?? 0)): ?><span class="rounded-full bg-amber-500/10 px-2 py-0.5 text-amber-700 dark:text-amber-400"><?= (int) $unassignedByGroup[$g['id']] ?> unassigned</span><?php endif; ?>
          <span class="rounded-full bg-brand/10 px-2 py-0.5 text-brand"><?= e(GROUP_ASSIGN_MODES[$g['assignMode']][0]) ?></span>
        </span>
        <?= icon('chevron-down', 16, 'text-muted transition-transform group-open:rotate-180') ?>
      </summary>
      <div class="border-t border-border p-4 sm:p-5"><?= $groupForm($g) ?></div>
    </details>
  <?php endforeach; ?>
</div>

<?php if ($groups && $categories): ?>
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('route', 'Routing')) ?>
  <form method="post" action="/action/group-routing" class="p-5 sm:p-6" data-dirty>
    <?= form_meta('groups', '/settings#groups') ?>
    <p class="mb-4 text-sm text-muted">New tickets in a category go to its group automatically (and get assigned if the group does that).</p>
    <div class="divide-y divide-border rounded-xl border border-border">
      <?php foreach ($categories as $c): ?>
        <div class="flex flex-wrap items-center gap-3 px-4 py-2.5">
          <span class="h-2.5 w-2.5 rounded-full" style="background:<?= safe_color($c['color']) ?>"></span>
          <span class="min-w-0 flex-1 truncate text-sm font-medium"><?= e($c['name']) ?></span>
          <?= icon('arrow-right', 14, 'text-muted') ?>
          <select name="route[<?= e($c['id']) ?>]" class="h-9 min-w-40 cursor-pointer rounded-lg border border-border bg-surface px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40"><?= select_options(['' => 'No group'] + array_column($groups, 'name', 'id'), $c['groupId'] ?? '') ?></select>
        </div>
      <?php endforeach; ?>
    </div>
    <button type="submit" data-dirty-save disabled class="<?= btn_cls('primary', 'sm') ?> mt-4"><?= icon('check', 14) ?> Save routing</button>
  </form>
</div>
<?php endif; ?>
