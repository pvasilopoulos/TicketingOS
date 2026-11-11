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
$perPage = in_array((int) ($sp['per'] ?? 0), [25, 50, 100], true) ? (int) $sp['per'] : TICKETS_PER_PAGE;

if ($view === 'board') {
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
    $ids = pluck(q_all("SELECT t.\"id\" FROM \"Ticket\" t WHERE $where ORDER BY " . $SORTS[$sort][1] . " LIMIT " . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params), 'id');
    $rows = ticket_rows_by_ids($ids, $statuses, $priorities);
}

$categories = q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
$tags = q_all('SELECT "id","name" FROM "Tag" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
$savedViews = q_all('SELECT "id","name","query" FROM "SavedView" WHERE "userId" = ? ORDER BY "createdAt" ASC', [$user['id']]);
$agents = $staff ? q_all('SELECT "id","name" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'ADMIN\',\'AGENT\') ORDER BY "name" ASC', [$tenantId]) : [];

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
$hasFilters = $activeFilters > 0;
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
        <span class="group inline-flex shrink-0 items-center gap-1 rounded-full border border-border bg-surface px-2.5 py-1 text-xs hover:border-brand/50">
          <a href="/tickets<?= $v['query'] ? '?' . e($v['query']) : '' ?>" class="font-medium cursor-pointer"><?= e($v['name']) ?></a>
          <form method="post" action="/action/view-delete" data-ajax class="inline-flex">
            <?= form_meta() ?><input type="hidden" name="id" value="<?= e($v['id']) ?>">
            <button type="submit" aria-label="Delete <?= e($v['name']) ?>" class="text-muted hover:text-red-600 cursor-pointer"><?= icon('x', 12) ?></button>
          </form>
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
        <input name="q" type="search" data-live-search enterkeyhint="search" value="<?= e($sp['q'] ?? '') ?>" placeholder="Search tickets, people, replies… try status:open" class="h-10 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-base placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40 sm:h-9 sm:text-sm">
      </div>
      <button type="button" data-filters-toggle aria-expanded="false" class="<?= $chip($activeFilters > 0 && !($activeFilters === 1 && !empty($sp['q']))) ?> h-10 sm:hidden">
        <?= icon('settings', 15) ?> Filters<?php $nf = $activeFilters - (!empty($sp['q']) ? 1 : 0); if ($nf > 0): ?><span class="grid h-5 min-w-5 place-items-center rounded-full bg-brand px-1 text-[11px] font-semibold text-brand-fg"><?= $nf ?></span><?php endif; ?>
      </button>
      <div class="inline-flex rounded-lg border border-border bg-surface p-0.5 sm:hidden">
        <a href="<?= e($url(['view' => null, 'page' => null])) ?>" class="<?= $toggleCls($view === 'list') ?>" aria-label="List view"><?= icon('list', 15) ?><span class="hidden sm:inline">List</span></a>
        <a href="<?= e($url(['view' => 'board', 'page' => null])) ?>" class="<?= $toggleCls($view === 'board') ?>" aria-label="Board view"><?= icon('layout-grid', 15) ?><span class="hidden sm:inline">Board</span></a>
      </div>

      <div data-filters-panel class="flex w-full flex-wrap items-center gap-2 max-sm:hidden max-sm:data-[open=true]:grid max-sm:data-[open=true]:grid-cols-2 sm:w-auto">
        <select name="status" aria-label="Status" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:w-full max-sm:text-base" data-autosubmit>
          <?= select_options(['' => 'All statuses'] + array_column($statuses, 'label', 'key'), $sp['status'] ?? '') ?>
        </select>
        <select name="priority" aria-label="Priority" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:w-full max-sm:text-base" data-autosubmit>
          <?= select_options(['' => 'All priorities'] + array_column($priorities, 'label', 'key'), $sp['priority'] ?? '') ?>
        </select>
        <select name="category" aria-label="Category" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:w-full max-sm:text-base" data-autosubmit>
          <?= select_options(['' => 'All categories'] + array_column($categories, 'name', 'id'), $sp['category'] ?? '') ?>
        </select>
        <?php if ($tags): ?>
          <select name="tag" aria-label="Tag" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:w-full max-sm:text-base" data-autosubmit>
            <?= select_options(['' => 'All tags'] + array_map(fn ($n) => "#$n", array_column($tags, 'name', 'id')), $sp['tag'] ?? '') ?>
          </select>
        <?php endif; ?>
        <?php if ($staff): ?>
          <select name="assignee" aria-label="Assignee" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:w-full max-sm:text-base" data-autosubmit>
            <?= select_options(['' => 'Anyone', 'me' => 'Assigned to me', 'none' => 'Unassigned'], $sp['assignee'] ?? '') ?>
          </select>
        <?php endif; ?>
        <?php if ($groupsList): ?>
          <select name="group" aria-label="Group" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:w-full max-sm:text-base" data-autosubmit>
            <?= select_options(['' => 'All groups', 'mine' => 'My groups', 'none' => 'No group'] + array_column($groupsList, 'name', 'id'), $sp['group'] ?? '') ?>
          </select>
        <?php endif; ?>
        <?php if ($orgsList): ?>
          <select name="org" aria-label="Organization" class="<?= FILTER_SELECT ?> max-sm:h-10 max-sm:w-full max-sm:text-base" data-autosubmit>
            <?= select_options(['' => 'All organizations'] + array_column($orgsList, 'name', 'id'), $sp['org'] ?? '') ?>
          </select>
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
    <?= render_partial('partials/ticket_board', ['columns' => $columns, 'statuses' => $statuses, 'canManage' => $staff && can($user, 'tickets.edit'), 'url' => $url]) ?>
  <?php else: ?>
    <?= pager($page, $pages, $total, $perPage, $url, $pagerOpts + ['class' => 'mb-3']) ?>
    <?php if ($staff && can($user, 'tickets.bulk')): ?>
      <?= render_partial('partials/bulk_list', ['rows' => $rows, 'statuses' => $statuses, 'priorities' => $priorities, 'agents' => $agents]) ?>
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
