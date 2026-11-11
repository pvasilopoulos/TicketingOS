<?php
/** @var array $columns status key => ['total' => int, 'rows' => array] @var array $statuses @var bool $canManage @var callable $url */
?>
<nav class="sticky top-0 z-20 -mx-4 mb-3 flex gap-2 overflow-x-auto border-b border-border bg-bg/95 px-4 py-2 backdrop-blur sm:hidden" data-board-tabs aria-label="Statuses">
  <?php foreach ($statuses as $i => $s): $tot = (int) ($columns[$s['key']]['total'] ?? 0); ?>
    <button type="button" data-board-tab="<?= e($s['key']) ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>" style="--st:<?= safe_color($s['color']) ?>"
            class="group inline-flex h-9 shrink-0 items-center gap-2 rounded-full border border-[color-mix(in_srgb,var(--st)_35%,transparent)] bg-[color-mix(in_srgb,var(--st)_8%,var(--color-surface))] px-3.5 text-sm font-medium text-fg transition-colors aria-[current=true]:border-transparent aria-[current=true]:bg-[var(--st)] aria-[current=true]:text-white cursor-pointer">
      <span class="h-2 w-2 rounded-full bg-[var(--st)] group-aria-[current=true]:bg-white"></span><?= e($s['label']) ?>
      <span class="rounded-full bg-[color-mix(in_srgb,var(--st)_15%,transparent)] px-1.5 text-[11px] tabular-nums group-aria-[current=true]:bg-white/25"><?= number_format($tot) ?></span>
    </button>
  <?php endforeach; ?>
</nav>
<div class="flex flex-col gap-4 pb-3 sm:flex-row sm:gap-4 sm:overflow-x-auto" data-board data-can-manage="<?= $canManage ? '1' : '0' ?>">
  <?php foreach ($statuses as $s):
      $col = $columns[$s['key']] ?? ['total' => 0, 'rows' => []];
      $cards = $col['rows'];
      $more = $col['total'] - count($cards); ?>
    <div data-board-col="<?= e($s['key']) ?>" style="--st:<?= safe_color($s['color']) ?>" class="flex w-full shrink-0 scroll-mt-16 flex-col overflow-hidden rounded-2xl border border-[color-mix(in_srgb,var(--st)_28%,var(--color-border))] border-t-4 border-t-[var(--st)] bg-[color-mix(in_srgb,var(--st)_7%,var(--color-surface-2))] transition-colors data-[over=true]:border-brand data-[over=true]:bg-brand/10 sm:w-72 sm:scroll-mt-0 sm:rounded-xl">
      <div class="flex items-center justify-between px-3.5 py-3 sm:px-3 sm:py-2.5">
        <span class="inline-flex items-center gap-2 text-sm font-semibold">
          <span class="h-2.5 w-2.5 rounded-full bg-[var(--st)] ring-4 ring-[color-mix(in_srgb,var(--st)_20%,transparent)]"></span><span class="text-[color-mix(in_srgb,var(--st)_75%,var(--color-fg))]"><?= e($s['label']) ?></span>
        </span>
        <span class="rounded-full bg-[color-mix(in_srgb,var(--st)_15%,transparent)] px-2 py-0.5 text-xs font-medium tabular-nums text-[color-mix(in_srgb,var(--st)_75%,var(--color-fg))]" data-board-count data-total="<?= (int) $col['total'] ?>"><?= number_format($col['total']) ?></span>
      </div>
      <div class="flex flex-1 flex-col gap-2 px-2 pb-2" data-board-cards>
        <?php foreach ($cards as $c): ?>
          <a href="/tickets/<?= e($c['id']) ?>" data-board-card="<?= e($c['id']) ?>" draggable="<?= $canManage ? 'true' : 'false' ?>"
             class="<?= cx('group block rounded-xl border border-border border-l-4 border-l-[var(--st)] bg-surface p-3.5 text-left shadow-sm shadow-black/[0.03] transition active:scale-[0.99] hover:shadow-md sm:rounded-lg sm:p-3 sm:shadow-none', $canManage ? 'sm:cursor-grab sm:active:cursor-grabbing' : '') ?>">
            <div class="flex items-start justify-between gap-2">
              <span class="line-clamp-2 text-[15px] font-medium leading-snug sm:text-sm"><?= e($c['subject']) ?></span>
              <?= priority_badge($c['priority']['label'], $c['priority']['color']) ?>
            </div>
            <?php if ($c['dueAt'] && ($db = due_badge($c['dueAt'], $c['status']['isClosed']))): ?>
              <div class="mt-1.5"><?= $db ?></div>
            <?php endif; ?>
            <div class="mt-2.5 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-xs text-muted sm:mt-2 sm:flex-nowrap sm:gap-2">
              <span class="font-mono">#<?= $c['number'] ?></span>
              <?php if ($c['category']): ?>
                <span class="inline-flex min-w-0 items-center gap-1"><span class="h-2 w-2 shrink-0 rounded-full" style="background-color:<?= safe_color($c['category']['color']) ?>"></span><span class="truncate"><?= e($c['category']['name']) ?></span></span>
              <?php endif; ?>
              <?php if ($c['commentCount'] > 0): ?>
                <span class="inline-flex items-center gap-0.5"><?= icon('message-square', 11) ?> <?= $c['commentCount'] ?></span>
              <?php endif; ?>
              <span class="ml-auto shrink-0"><?= e(time_ago($c['createdAt'])) ?></span>
            </div>
            <div class="mt-2.5 flex items-center gap-2 border-t border-border/60 pt-2.5 sm:mt-2 sm:border-0 sm:pt-0">
              <?= avatar($c['requester']['name'], $c['requester']['color'], 22) ?>
              <span class="truncate text-xs text-muted"><?= e($c['requester']['name']) ?></span>
              <?php if ($c['assignee']) echo '<span class="ml-auto inline-flex items-center">' . avatar($c['assignee']['name'], $c['assignee']['color'], 22) . (!empty($c['assignee']['more']) ? '<span class="-ml-1.5 grid h-[22px] min-w-[22px] place-items-center rounded-full bg-surface-2 px-1 text-[10px] font-semibold text-muted ring-2 ring-surface">+' . (int) $c['assignee']['more'] . '</span>' : '') . '</span>'; ?>
            </div>
          </a>
        <?php endforeach; ?>
        <?php if ($more > 0): ?>
          <a href="<?= e($url(['view' => null, 'status' => $s['key'], 'page' => null])) ?>" class="rounded-lg border border-dashed border-border py-2.5 text-center text-xs font-medium text-brand hover:bg-surface">
            +<?= number_format($more) ?> more — open in list
          </a>
        <?php endif; ?>
        <div data-board-empty class="rounded-lg border border-dashed border-border py-6 text-center text-xs text-muted" <?= $cards ? 'hidden' : '' ?>><?= $canManage ? '<span class="sm:hidden">No tickets</span><span class="max-sm:hidden">Drop here</span>' : 'No tickets' ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if ($canManage): ?>
  <p class="mt-1 text-center text-xs text-muted sm:hidden">Tap a status above to jump to it · open a ticket to change its status</p>
<?php endif; ?>
