<?php
[$user, $tenantId] = require_tenant_user();
page_title('Tickets');
$staff = is_staff($user['role']);
$sp = query_params();
$view = ($sp['view'] ?? '') === 'board' ? 'board' : 'list';

$statuses = tenant_statuses($tenantId);
$priorities = tenant_priorities($tenantId);
$closedKeys = closed_status_keys($statuses);

$params = [];
$where = ticket_filter_sql($user, $tenantId, $sp, $params, $closedKeys);

$total = 0;
$rows = [];
$columns = [];
$page = max(1, (int) ($sp['page'] ?? 1));
$pages = 1;
// Sorting (list view). Every option ends with createdAt so pages are stable.
$SORTS = [
    'newest' => ['Newest first', 't."createdAt" DESC'],
    'oldest' => ['Oldest first', 't."createdAt" ASC'],
    'updated' => ['Recently updated', 't."updatedAt" DESC, t."createdAt" DESC'],
    'priority' => ['Highest priority', '(SELECT p."order" FROM "TicketPriority" p WHERE p."tenantId" = t."tenantId" AND p."key" = t."priority") DESC, t."createdAt" DESC'],
    'due' => ['Due soonest', 'CASE WHEN t."dueAt" IS NULL OR t."closedAt" IS NOT NULL THEN 1 ELSE 0 END, t."dueAt" ASC, t."createdAt" DESC'],
    'number' => ['Ticket number', 't."number" DESC'],
];
$sort = isset($SORTS[$sp['sort'] ?? '']) ? $sp['sort'] : 'newest';
// Group by (staff): sections in the list, swimlanes on the board.
$groupings = $staff ? ticket_groupings($view === 'board') : [];
$groupBy = isset($groupings[$sp['groupby'] ?? '']) ? $sp['groupby'] : '';
// A second level inside each group (list only), e.g. organization → assignee.
$groupBy2 = $groupBy && $view === 'list' && isset($groupings[$sp['then'] ?? '']) && $sp['then'] !== $groupBy ? $sp['then'] : '';
$groups = [];
$sections = [];
$lanes = [];
$laneMore = 0;
$perPage = in_array((int) ($sp['per'] ?? 0), [25, 50, 100], true) ? (int) $sp['per'] : TICKETS_PER_PAGE;

if ($view === 'board' && $groupBy) {
    // Swimlanes: one row per group (up to BOARD_LANE_LIMIT), the status columns inside.
    $groups = ticket_groups($groupBy, $where, $params, $tenantId, $statuses, $priorities, $closedKeys);
    $laneGroups = array_slice($groups, 0, BOARD_LANE_LIMIT, true);
    $laneMore = count($groups) - count($laneGroups);
    $total = array_sum(array_column($groups, 'total'));
    if ($laneGroups) {
        $expr = ticket_group_expr($groupBy);
        $keys = array_map('strval', array_keys($laneGroups));
        $inKeys = $expr . ' IN (' . in_ph($keys) . ')';
        $counts = [];
        foreach (q_all("SELECT $expr AS \"gk\", t.\"status\", COUNT(*) AS \"n\" FROM \"Ticket\" t WHERE $where AND $inKeys GROUP BY $expr, t.\"status\"", [...$params, ...$keys]) as $r) $counts[(string) $r['gk']][$r['status']] = (int) $r['n'];
        $pick = q_all("SELECT \"id\", \"gk\", \"status\" FROM (SELECT t.\"id\", $expr AS \"gk\", t.\"status\", ROW_NUMBER() OVER (PARTITION BY $expr, t.\"status\" ORDER BY t.\"createdAt\" DESC) AS \"rn\" FROM \"Ticket\" t WHERE $where AND $inKeys) x WHERE \"rn\" <= " . BOARD_LANE_CELL_LIMIT, [...$params, ...$keys]);
        $byId = key_by(ticket_rows_by_ids(array_column($pick, 'id'), $statuses, $priorities), 'id');
        foreach ($laneGroups as $k => $g) {
            $cols = [];
            foreach ($statuses as $s) $cols[$s['key']] = ['total' => $counts[(string) $k][$s['key']] ?? 0, 'rows' => []];
            $lanes[(string) $k] = $g + ['columns' => $cols];
        }
        foreach ($pick as $r) if (isset($byId[$r['id']], $lanes[(string) $r['gk']]['columns'][$r['status']])) $lanes[(string) $r['gk']]['columns'][$r['status']]['rows'][] = $byId[$r['id']];
    }
} elseif ($view === 'board') {
    // Each column shows its newest cards; the header shows the real total.
    $counts = array_column(q_all("SELECT t.\"status\", COUNT(*) AS n FROM \"Ticket\" t WHERE $where GROUP BY t.\"status\"", $params), 'n', 'status');
    foreach ($statuses as $s) {
        $n = (int) ($counts[$s['key']] ?? 0);
        $ids = $n ? pluck(q_all("SELECT t.\"id\" FROM \"Ticket\" t WHERE $where AND t.\"status\" = ? ORDER BY t.\"createdAt\" DESC LIMIT " . BOARD_COLUMN_LIMIT, [...$params, $s['key']]), 'id') : [];
        $columns[$s['key']] = ['total' => $n, 'rows' => ticket_rows_by_ids($ids, $statuses, $priorities)];
        $total += $n;
    }
} else {
    $total = (int) q_val("SELECT COUNT(*) FROM \"Ticket\" t WHERE $where", $params);
    $pages = max(1, (int) ceil($total / $perPage));
    $page = min($page, $pages);
    if ($groupBy) {
        // Sections: the page keeps the groups in order; a group may continue on the next page.
        $groups = ticket_groups($groupBy, $where, $params, $tenantId, $statuses, $priorities, $closedKeys);
        $op = [];
        $orderSql = ticket_group_order_sql($groupBy, $groups, $op);
        if ($groupBy2) {
            $groups2 = ticket_groups($groupBy2, $where, $params, $tenantId, $statuses, $priorities, $closedKeys);
            $pairs = ticket_group_pairs($groupBy, $groupBy2, $where, $params, $closedKeys);
            $orderSql .= ', ' . ticket_group_order_sql($groupBy2, $groups2, $op);
        }
        $picked = q_all("SELECT t.\"id\", " . ticket_group_expr($groupBy) . " AS \"gk\"" . ($groupBy2 ? ', ' . ticket_group_expr($groupBy2) . ' AS "gk2"' : '') . " FROM \"Ticket\" t WHERE $where ORDER BY $orderSql, " . $SORTS[$sort][1] . " LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage), [...$params, ...$op]);
        $gk2Of = $groupBy2 ? array_column($picked, 'gk2', 'id') : [];
        $rows = ticket_rows_by_ids(array_column($picked, 'id'), $statuses, $priorities);
        $gkOf = array_column($picked, 'gk', 'id');
        $seenBefore = $page > 1 ? (string) (q_val("SELECT " . ticket_group_expr($groupBy) . " FROM \"Ticket\" t WHERE $where ORDER BY $orderSql, " . $SORTS[$sort][1] . ' LIMIT 1 OFFSET ' . (($page - 1) * $perPage - 1), [...$params, ...$op]) ?? "\0") : "\0";
        foreach ($rows as $r) {
            $k = (string) ($gkOf[$r['id']] ?? '');
            if (!$sections || (string) array_key_last($sections) !== $k) $sections[$k] = ($groups[$k] ?? ['key' => $k, 'label' => $k, 'color' => null, 'total' => 0, 'open' => 0, 'overdue' => 0, 'link' => null]) + ['rows' => [], 'continued' => !$sections && $k === $seenBefore];
            $sections[$k]['rows'][] = $r;
            if ($groupBy2) {
                $k2 = (string) ($gk2Of[$r['id']] ?? '');
                $sections[$k]['subBy'] = $groupBy2;
                $sections[$k]['subs'][$k2] ??= ['key' => $k2, 'label' => $groups2[$k2]['label'] ?? $k2, 'color' => $groups2[$k2]['color'] ?? null, 'link' => null, 'rows' => []]
                    + ($pairs[$k][$k2] ?? ['total' => 0, 'open' => 0, 'overdue' => 0]);
                $sections[$k]['subs'][$k2]['rows'][] = $r;
            }
        }
    } else {
        $ids = pluck(q_all("SELECT t.\"id\" FROM \"Ticket\" t WHERE $where ORDER BY " . $SORTS[$sort][1] . " LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params), 'id');
        $rows = ticket_rows_by_ids($ids, $statuses, $priorities);
    }
}

$categories = q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
$tags = q_all('SELECT "id","name" FROM "Tag" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
$savedViews = q_all('SELECT "id","name","query","shared","userId" FROM "SavedView" WHERE "tenantId" = ? AND ("userId" = ?' . ($staff ? ' OR "shared" = 1' : '') . ') ORDER BY "shared" DESC, "createdAt" ASC', [$tenantId, $user['id']]);
$canShareViews = $staff && can($user, 'tickets.views_share');
$agents = $staff ? q_all('SELECT "id","name" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') ORDER BY "name" ASC', [$tenantId]) : [];

// Links preserve the current filters.
$filters = $sp;
unset($filters['view'], $filters['page']);
$qs = http_build_query($filters);
$url = function (array $change) use ($sp): string {
    $p = array_filter(array_merge($sp, $change), fn ($v) => $v !== null && $v !== '');
    if (($p['page'] ?? 1) == 1) unset($p['page']);
    if ((int) ($p['per'] ?? 0) === TICKETS_PER_PAGE) unset($p['per']);
    return '/tickets' . ($p ? '?' . http_build_query($p) : '');
};
$filterKeys = ['q', 'status', 'priority', 'category', 'tag', 'overdue', 'assignee', 'group', 'org', 'source'];
$groupsList = $staff ? tenant_groups($tenantId) : [];
$orgsList = $staff ? tenant_orgs($tenantId) : [];
$activeFilters = count(array_intersect_key($filters, array_flip($filterKeys)));
$hasFilters = $activeFilters > 0 || $groupBy !== '';
$overdue = ($sp['overdue'] ?? '') === '1';
$mine = ($sp['assignee'] ?? '') === 'me';

$toggleCls = fn ($active) => cx('inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-sm font-medium transition-colors', $active ? 'bg-surface-2 text-fg' : 'text-muted hover:text-fg');
$chip = fn ($active) => cx('inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border px-2.5 text-sm transition-colors cursor-pointer',
    $active ? 'border-brand/40 bg-brand/10 text-brand' : 'border-border bg-surface text-muted hover:text-fg');
$pagerOpts = ['perPageOptions' => [25, 50, 100]];
?>
<div>
  <?= page_header('Tickets', $staff ? 'All support requests for your workspace.' : 'Your support requests.',
      '<div class="flex items-center gap-2">'
      . ($staff && can($user, 'tickets.export') ? '<a href="/tickets/export' . ($qs ? '?' . e($qs) : '') . '" class="' . btn_cls('outline') . '" title="Export the filtered tickets as CSV">' . icon('download', 16) . '<span class="hidden sm:inline">Export</span></a>' : '')
      . '<a href="/tickets/new" class="' . btn_cls('primary', 'md', 'max-lg:hidden') . '">' . icon('circle-plus', 18) . ' New ticket</a></div>') ?>

  <?php if ($savedViews || $hasFilters): ?>
    <div class="-mx-4 mb-3 flex items-center gap-2 overflow-x-auto px-4 pb-1 sm:mx-0 sm:flex-wrap sm:px-0" data-saved-views data-query="<?= e($qs) ?>">
      <?= icon('bookmark', 15, 'text-muted') ?>
      <?php foreach ($savedViews as $v): ?>
        <?php $mine = $v['userId'] === $user['id']; ?>
        <span class="<?= cx('group inline-flex shrink-0 items-center gap-1 rounded-full border px-2.5 py-1 text-xs hover:border-brand/50', (int) $v['shared'] ? 'border-sky-500/40 bg-sky-500/5' : 'border-border bg-surface') ?>">
          <?= (int) $v['shared'] ? '<span title="Shared with the team" class="text-sky-600 dark:text-sky-400">' . icon('users', 12) . '</span>' : '' ?>
          <a href="/tickets<?= $v['query'] ? '?' . e($v['query']) : '' ?>" class="font-medium cursor-pointer"><?= e($v['name']) ?></a>
          <?php if ($canShareViews && ($mine || (int) $v['shared'])): ?>
            <form method="post" action="/action/view-share" data-ajax class="inline-flex"><?= form_meta() ?><input type="hidden" name="id" value="<?= e($v['id']) ?>"><input type="hidden" name="shared" value="<?= (int) $v['shared'] ? '0' : '1' ?>">
              <button type="submit" title="<?= (int) $v['shared'] ? 'Make personal' : 'Share with the team' ?>" aria-label="<?= (int) $v['shared'] ? 'Make personal' : 'Share with the team' ?>" class="text-muted hover:text-sky-600 cursor-pointer"><?= icon('share-2', 12) ?></button></form>
          <?php endif; ?>
          <?php if ($mine || $canShareViews): ?>
          <form method="post" action="/action/view-delete" data-ajax class="inline-flex">
            <?= form_meta() ?><input type="hidden" name="id" value="<?= e($v['id']) ?>">
            <button type="submit" aria-label="Delete <?= e($v['name']) ?>" class="text-muted hover:text-red-600 cursor-pointer"><?= icon('x', 12) ?></button>
          </form>
          <?php endif; ?>
        </span>
      <?php endforeach; ?>
      <?php if ($hasFilters): ?>
        <button type="button" data-save-view class="inline-flex shrink-0 items-center gap-1 rounded-full border border-dashed border-border px-2.5 py-1 text-xs text-muted hover:text-fg hover:border-brand/50 cursor-pointer disabled:opacity-50">
          <?= icon('bookmark-plus', 13) ?> Save view
        </button>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <form method="get" action="/tickets" class="mb-4" data-filters>
    <?php if ($view === 'board'): ?><input type="hidden" name="view" value="board"><?php endif; ?>
    <div class="relative flex flex-wrap items-center gap-2" data-live-wrap>
      <div class="relative min-w-0 flex-1 sm:min-w-48">
        <?= icon('search', 16, 'pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted') ?>
        <input name="q" type="search" data-live-search enterkeyhint="search" value="<?= e($sp['q'] ?? '') ?>" placeholder="Search tickets…" title="Search tickets, people and replies — try status:open or assignee:me" class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-base placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40 sm:h-9 sm:text-sm">
      </div>
      <button type="button" data-filters-toggle aria-expanded="false" class="<?= $chip($activeFilters > 0 && !($activeFilters === 1 && !empty($sp['q']))) ?> h-10 sm:hidden">
        <?= icon('settings', 15) ?> Filters<?php $nf = $activeFilters - (!empty($sp['q']) ? 1 : 0); if ($nf > 0): ?><span class="grid h-5 min-w-5 place-items-center rounded-full bg-brand px-1 text-[11px] font-semibold text-brand-fg"><?= $nf ?></span><?php endif; ?>
      </button>
      <div class="inline-flex rounded-lg border border-border bg-surface p-0.5 sm:hidden">
        <a href="<?= e($url(['view' => null, 'page' => null])) ?>" class="<?= $toggleCls($view === 'list') ?>" aria-label="List view"><?= icon('list', 15) ?><span class="hidden sm:inline">List</span></a>
        <a href="<?= e($url(['view' => 'board', 'page' => null])) ?>" class="<?= $toggleCls($view === 'board') ?>" aria-label="Board view"><?= icon('layout-grid', 15) ?><span class="hidden sm:inline">Board</span></a>
      </div>

      <div data-filters-panel class="flex w-full flex-wrap items-center gap-2 max-sm:hidden max-sm:data-[open=true]:grid max-sm:data-[open=true]:grid-cols-2 sm:w-auto">
        <?= multi_filter('status', 'All statuses', 'statuses', array_column($statuses, 'label', 'key'), $sp['status'] ?? '') ?>
        <?= multi_filter('priority', 'All priorities', 'priorities', array_column($priorities, 'label', 'key'), $sp['priority'] ?? '') ?>
        <?= multi_filter('category', 'All categories', 'categories', array_column($categories, 'name', 'id'), $sp['category'] ?? '') ?>
        <?php if ($tags): ?>
          <?= multi_filter('tag', 'All tags', 'tags', array_map(fn ($n) => "#$n", array_column($tags, 'name', 'id')), $sp['tag'] ?? '') ?>
        <?php endif; ?>
        <?php if ($staff): ?>
          <?= multi_filter('assignee', 'Anyone', 'assignees', ['me' => 'Assigned to me', 'none' => 'Unassigned'] + array_column(array_filter($agents, fn ($a) => $a['id'] !== $user['id']), 'name', 'id'), $sp['assignee'] ?? '') ?>
        <?php endif; ?>
        <?php if ($groupsList): ?>
          <?= multi_filter('group', 'All groups', 'groups', ['mine' => 'My groups', 'none' => 'No group'] + array_column($groupsList, 'name', 'id'), $sp['group'] ?? '') ?>
        <?php endif; ?>
        <?php if ($orgsList): ?>
          <?= multi_filter('org', 'All organizations', 'organizations', array_column($orgsList, 'name', 'id'), $sp['org'] ?? '') ?>
        <?php endif; ?>
        <?php if ($groupings): ?>
          <label class="inline-flex items-center gap-1.5 text-sm text-muted max-sm:col-span-2" title="Group the tickets">
            <?= icon('rows-3', 15) ?>
            <select name="groupby" aria-label="Group by" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:flex-1 max-sm:text-base" data-autosubmit>
              <?= select_options(['' => 'No grouping'] + array_map(fn ($l) => 'By ' . mb_strtolower($l), $groupings), $groupBy) ?>
            </select>
          </label>
          <?php if ($groupBy && $view === 'list'): ?>
            <label class="inline-flex items-center gap-1.5 text-sm text-muted max-sm:col-span-2" title="Group again inside each group">
              <?= icon('list-tree', 15) ?>
              <select name="then" aria-label="Then by" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:flex-1 max-sm:text-base" data-autosubmit>
                <?= select_options(['' => 'No subgroups'] + array_map(fn ($l) => 'Then by ' . mb_strtolower($l), array_diff_key($groupings, [$groupBy => 1])), $groupBy2) ?>
              </select>
            </label>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($view === 'list'): ?>
          <label class="inline-flex items-center gap-1.5 text-sm text-muted max-sm:col-span-2">
            <?= icon('arrow-up-down', 15) ?>
            <select name="sort" aria-label="Sort" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:flex-1 max-sm:text-base" data-autosubmit>
              <?= select_options(array_map(fn ($o) => $o[0], $SORTS), $sort) ?>
            </select>
          </label>
        <?php endif; ?>
        <?php if ($overdue): ?><input type="hidden" name="overdue" value="1" data-overdue-input><?php endif; ?>
        <?php if ($hasFilters): ?>
          <a href="/tickets<?= $view === 'board' ? '?view=board' : '' ?>" class="inline-flex h-9 items-center justify-center gap-1 rounded-lg px-2.5 text-sm text-muted hover:bg-surface-2 hover:text-fg cursor-pointer max-sm:h-10"><?= icon('x', 14) ?> Clear</a>
        <?php endif; ?>
      </div>
    </div>
  </form>

  <!-- Quick filters + (tablet/desktop) list/board toggle -->
  <div class="-mx-4 mb-4 flex items-center gap-2 overflow-x-auto px-4 sm:mx-0 sm:px-0">
    <?php if ($staff): ?>
      <a href="<?= e($url(['assignee' => $mine ? null : 'me', 'page' => null])) ?>" class="<?= $chip($mine) ?>"><?= icon('user-plus', 14) ?> My tickets</a>
      <a href="<?= e($url(['assignee' => ($sp['assignee'] ?? '') === 'none' ? null : 'none', 'page' => null])) ?>" class="<?= $chip(($sp['assignee'] ?? '') === 'none') ?>"><?= icon('inbox', 14) ?> Unassigned</a>
    <?php endif; ?>
    <a href="<?= e($url(['overdue' => $overdue ? null : '1', 'page' => null])) ?>" class="<?= $overdue ? cx('inline-flex h-9 shrink-0 items-center gap-1.5 rounded-lg border px-2.5 text-sm transition-colors', 'border-red-300 bg-red-50 text-red-700 dark:border-red-500/40 dark:bg-red-500/10 dark:text-red-300') : $chip(false) ?>"><?= icon('clock', 14) ?> Overdue</a>
    <div class="ml-auto hidden shrink-0 rounded-lg border border-border bg-surface p-0.5 sm:inline-flex">
      <a href="<?= e($url(['view' => null, 'page' => null])) ?>" class="<?= $toggleCls($view === 'list') ?>"><?= icon('list', 15) ?> List</a>
      <a href="<?= e($url(['view' => 'board', 'page' => null])) ?>" class="<?= $toggleCls($view === 'board') ?>"><?= icon('layout-grid', 15) ?> Board</a>
    </div>
  </div>

  <?php if ($total === 0): ?>
    <div class="rounded-xl border border-border bg-surface">
      <div class="flex flex-col items-center justify-center px-5 py-20 text-center">
        <?= icon('inbox', 40, 'text-muted') ?>
        <p class="mt-3 font-medium">No tickets found</p>
        <p class="mt-1 text-sm text-muted">Try adjusting your filters, or create a new ticket.</p>
      </div>
    </div>
  <?php elseif ($view === 'board'): ?>
    <?= render_partial('partials/ticket_board', ['columns' => $columns, 'lanes' => $lanes, 'laneMore' => $laneMore, 'groupBy' => $groupBy, 'statuses' => $statuses, 'canManage' => $staff && can($user, 'tickets.status'), 'url' => $url]) ?>
  <?php else: ?>
    <?= pager($page, $pages, $total, $perPage, $url, $pagerOpts + ['class' => 'mb-3']) ?>
    <?php if ($staff && can($user, 'tickets.bulk')): ?>
      <?= render_partial('partials/bulk_list', ['rows' => $rows, 'sections' => $sections, 'groupBy' => $groupBy, 'statuses' => $statuses, 'priorities' => $priorities, 'agents' => $agents]) ?>
    <?php elseif ($sections): ?>
      <div class="space-y-3"><?php foreach ($sections as $sec) echo ticket_group_section($groupBy, $sec, fn ($r) => ticket_list_item($r)); ?></div>
    <?php else: ?>
      <div class="rounded-xl border border-border bg-surface">
        <div class="divide-y divide-border"><?php foreach ($rows as $t) echo ticket_list_item($t); ?></div>
      </div>
    <?php endif; ?>
    <?php if ($pages > 1): ?>
      <?= pager($page, $pages, $total, $perPage, $url, $pagerOpts + ['class' => 'mt-4']) ?>
    <?php endif; ?>
  <?php endif; ?>
</div>
