<?php
/**
 * Settings → Audit log (administrators).
 * @var array $me @var string $tenantId
 */
[$where, $params] = audit_filter_sql($tenantId, $_GET);
$per = 50;
$total = (int) q_val('SELECT COUNT(*) FROM "AuditLog" WHERE ' . $where, $params);
$pages = max(1, (int) ceil($total / $per));
$page = min(max(1, (int) ($_GET['apage'] ?? 1)), $pages);
$rows = q_all('SELECT * FROM "AuditLog" WHERE ' . $where . ' ORDER BY "createdAt" DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $params);
$actors = q_all('SELECT DISTINCT a."actorId" AS id, u."name" FROM "AuditLog" a JOIN "User" u ON u."id" = a."actorId" WHERE a."tenantId" = ? ORDER BY u."name" LIMIT 200', [$tenantId]);
$filters = array_filter(['aq' => $_GET['aq'] ?? '', 'acat' => $_GET['acat'] ?? '', 'actor' => $_GET['actor'] ?? '', 'adays' => $_GET['adays'] ?? ''], fn ($v) => $v !== '');
$aurl = function (array $c) use ($filters, $page): string {
    $p = $filters + ['apage' => $page];
    if (array_key_exists('page', $c)) $p['apage'] = $c['page'] ?? 1;
    if ((int) $p['apage'] <= 1) unset($p['apage']);
    return '/settings?' . http_build_query($p) . '#audit';
};
$catOf = function (string $action): string {
    if (str_starts_with($action, 'sign-in')) return 'signin';
    if ($action === 'ticket-export') return 'tickets';
    return AUDITED_ACTIONS[$action][0] ?? 'settings';
};
$tone = [
    'users' => ['users', 'bg-sky-500/10 text-sky-600'], 'roles' => ['shield-check', 'bg-indigo-500/10 text-indigo-600'],
    'security' => ['key-round', 'bg-amber-500/10 text-amber-600'], 'signin' => ['log-in', 'bg-emerald-500/10 text-emerald-600'],
    'settings' => ['settings', 'bg-slate-500/10 text-slate-600 dark:text-slate-300'], 'content' => ['book-open', 'bg-violet-500/10 text-violet-600'],
    'integrations' => ['zap', 'bg-pink-500/10 text-pink-600'], 'reports' => ['chart-column', 'bg-teal-500/10 text-teal-600'], 'tickets' => ['ticket', 'bg-orange-500/10 text-orange-600'],
];
$sel = 'h-9 min-w-0 flex-1 cursor-pointer rounded-lg border border-border bg-surface px-2.5 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40 sm:flex-none';
?>
<div class="rounded-xl border border-border bg-surface">
  <div class="flex flex-wrap items-start gap-4 p-5 sm:p-6">
    <span class="hidden h-11 w-11 shrink-0 place-items-center rounded-xl bg-slate-500/10 text-slate-600 sm:grid dark:text-slate-300"><?= icon('scroll-text', 22) ?></span>
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Audit log</h2>
      <p class="mt-0.5 text-sm text-muted">Every change to users, roles, settings and integrations, plus staff sign-ins and exports. Ticket-level changes stay in each ticket’s timeline.</p>
    </div>
    <a href="/settings/audit.csv?<?= e(http_build_query($filters)) ?>" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('download', 14) ?> Export CSV</a>
  </div>
  <form method="get" action="/settings#audit" class="flex flex-wrap items-center gap-2 border-t border-border px-4 py-3 sm:px-5">
    <div class="relative min-w-0 flex-1 basis-full sm:basis-56">
      <?= icon('search', 16, 'pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted') ?>
      <input name="aq" type="search" value="<?= e($_GET['aq'] ?? '') ?>" placeholder="Search what happened or who…" class="h-9 w-full rounded-lg border border-border bg-surface pl-9 pr-3 text-sm placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40">
    </div>
    <select name="acat" data-autosubmit aria-label="Category" class="<?= $sel ?>"><?= select_options(['' => 'All activity', 'signin' => 'Sign-ins'] + AUDIT_CATEGORIES, (string) ($_GET['acat'] ?? '')) ?></select>
    <select name="actor" data-autosubmit aria-label="Person" class="<?= $sel ?>"><?= select_options(['' => 'Anyone'] + array_column($actors, 'name', 'id'), (string) ($_GET['actor'] ?? '')) ?></select>
    <select name="adays" data-autosubmit aria-label="Period" class="<?= $sel ?>"><?= select_options(['' => 'All time', '1' => 'Last 24 hours', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'], (string) ($_GET['adays'] ?? '')) ?></select>
    <?php if ($filters): ?><a href="/settings#audit" class="inline-flex h-9 items-center gap-1 rounded-lg px-2.5 text-sm text-muted hover:bg-surface-2 hover:text-fg"><?= icon('x', 14) ?> Clear</a><?php endif; ?>
  </form>
  <?php if ($rows): ?>
    <?= pager($page, $pages, $total, $per, $aurl, ['noun' => ['entry', 'entries'], 'class' => 'border-y border-border bg-surface-2/30 px-4 py-2 sm:px-5']) ?>
    <ol class="divide-y divide-border">
      <?php foreach ($rows as $r): $cat = $catOf($r['action']); [$ic, $cls] = $tone[$cat] ?? $tone['settings']; $meta = $r['meta'] ? (json_decode($r['meta'], true) ?: []) : []; ?>
        <li class="flex gap-3 px-4 py-3 sm:px-5">
          <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg <?= $cls ?>"><?= icon($ic, 15) ?></span>
          <div class="min-w-0 flex-1">
            <p class="text-sm"><span class="font-medium"><?= e($r['actorName'] ?? 'System') ?></span> <span class="text-muted">·</span> <?= e($r['summary']) ?></p>
            <p class="mt-0.5 flex flex-wrap gap-x-3 text-xs text-muted">
              <time datetime="<?= e($r['createdAt']) ?>" title="<?= e(local_dt($r['createdAt'])?->format('Y-m-d H:i:s')) ?>"><?= e(time_ago($r['createdAt'])) ?></time>
              <span class="font-mono"><?= e($r['action']) ?></span>
              <?php if ($r['ip']): ?><span><?= e($r['ip']) ?></span><?php endif; ?>
            </p>
            <?php if ($meta): ?>
              <details class="mt-1.5">
                <summary class="cursor-pointer text-xs text-muted hover:text-fg">Details</summary>
                <dl class="mt-1.5 grid grid-cols-[auto_minmax(0,1fr)] gap-x-3 gap-y-0.5 rounded-lg bg-surface-2/60 p-2.5 text-xs">
                  <?php foreach ($meta as $k => $v): ?><dt class="text-muted"><?= e($k) ?></dt><dd class="truncate font-mono"><?= e(is_array($v) ? implode(', ', $v) : $v) ?></dd><?php endforeach; ?>
                </dl>
              </details>
            <?php endif; ?>
          </div>
        </li>
      <?php endforeach; ?>
    </ol>
    <?php if ($pages > 1): ?><?= pager($page, $pages, $total, $per, $aurl, ['noun' => ['entry', 'entries'], 'class' => 'border-t border-border px-4 py-3 sm:px-5']) ?><?php endif; ?>
  <?php else: ?>
    <p class="border-t border-border px-5 py-12 text-center text-sm text-muted">Nothing recorded<?= $filters ? ' for these filters' : ' yet' ?>.</p>
  <?php endif; ?>
</div>
