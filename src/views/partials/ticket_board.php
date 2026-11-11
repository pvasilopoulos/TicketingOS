<?php
/** @var array $columns status key => ['total' => int, 'rows' => array] @var array $statuses @var bool $canManage @var callable $url */
?>
<nav class="sticky top-0 z-20 -mx-4 mb-3 flex gap-2 overflow-x-auto border-b border-border bg-bg/95 px-4 py-2 backdrop-blur sm:hidden" data-board-tabs aria-label="Statuses">
  <?php foreach ($statuses as $i => $s): $tot = (int) ($columns[$s['key']]['total'] ?? 0); ?>
    <button type="button" data-board-tab="<?= e($s['key']) ?>" aria-current="<?= $i === 0 ? 'true' : 'false' ?>"
            class="inline-flex h-9 shrink-0 items-center gap-2 rounded-full border border-border bg-surface px-3.5 text-sm font-medium text-muted transition-colors aria-[current=true]:border-transparent aria-[current=true]:bg-fg aria-[current=true]:text-bg cursor-pointer">
      <span class="h-2 w-2 rounded-full" style="background-color:<?= safe_color($s['color']) ?>"></span><?= e($s['label']) ?>
      <span class="rounded-full bg-surface-2 px-1.5 text-[11px] tabular-nums text-muted"><?= number_format($tot) ?></span>
    </button>
  <?php endforeach; ?>
</nav>
<div class="-mx-4 flex snap-x snap-mandatory scroll-px-4 gap-3 overflow-x-auto px-4 pb-3 [scrollbar-width:none] sm:mx-0 sm:snap-none sm:gap-4 sm:px-0" data-board data-can-manage="<?= $canManage ? '1' : '0' ?>">
  <?php foreach ($statuses as $s):
      $col = $columns[$s['key']] ?? ['total' => 0, 'rows' => []];
      $cards = $col['rows'];
      $more = $col['total'] - count($cards); ?>
    <div data-board-col="<?= e($s['key']) ?>" class="flex w-[calc(100vw-3.25rem)] shrink-0 snap-start flex-col rounded-2xl border border-border bg-surface-2/50 transition-colors data-[over=true]:border-brand data-[over=true]:bg-brand/5 sm:w-72 sm:rounded-xl">
      <div class="flex items-center justify-between px-3.5 py-3 sm:px-3 sm:py-2.5">
        <span class="inline-flex items-center gap-2 text-sm font-semibold">
          <span class="h-2 w-2 rounded-full" style="background-color:<?= safe_color($s['color']) ?>"></span><?= e($s['label']) ?>
        </span>
        <span class="rounded-full bg-surface px-2 py-0.5 text-xs text-muted" data-board-count data-total="<?= (int) $col['total'] ?>"><?= number_format($col['total']) ?></span>
      </div>
      <div class="flex flex-1 flex-col gap-2 px-2 pb-2" data-board-cards>
        <?php foreach ($cards as $c): ?>
          <a href="/tickets/<?= e($c['id']) ?>" data-board-card="<?= e($c['id']) ?>" draggable="<?= $canManage ? 'true' : 'false' ?>"
             class="<?= cx('group block rounded-xl border border-border bg-surface p-3.5 text-left shadow-sm shadow-black/[0.03] transition active:scale-[0.99] hover:shadow-md sm:rounded-lg sm:p-3 sm:shadow-none', $canManage ? 'sm:cursor-grab sm:active:cursor-grabbing' : '') ?>">
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
        <div data-board-empty class="rounded-lg border border-dashed border-border py-6 text-center text-xs text-muted" <?= $cards ? 'hidden' : '' ?>><?= $canManage ? 'Drop here' : '—' ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php if ($canManage): ?>
  <p class="mt-1 text-center text-xs text-muted sm:hidden">Swipe or tap a status above · open a ticket to change its status</p>
<?php endif; ?>
