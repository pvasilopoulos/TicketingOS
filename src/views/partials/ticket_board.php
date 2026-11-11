<?php
/** @var array $columns status key => ['total' => int, 'rows' => array] @var array $statuses @var bool $canManage @var callable $url */
?>
<div class="-mx-4 flex snap-x snap-mandatory gap-3 overflow-x-auto px-4 pb-2 sm:mx-0 sm:snap-none sm:gap-4 sm:px-0" data-board data-can-manage="<?= $canManage ? '1' : '0' ?>">
  <?php foreach ($statuses as $s):
      $col = $columns[$s['key']] ?? ['total' => 0, 'rows' => []];
      $cards = $col['rows'];
      $more = $col['total'] - count($cards); ?>
    <div data-board-col="<?= e($s['key']) ?>" class="flex w-[85vw] max-w-80 shrink-0 snap-start flex-col rounded-xl border border-border bg-surface-2/50 transition-colors data-[over=true]:border-brand data-[over=true]:bg-brand/5 sm:w-72">
      <div class="flex items-center justify-between px-3 py-2.5">
        <span class="inline-flex items-center gap-2 text-sm font-semibold">
          <span class="h-2 w-2 rounded-full" style="background-color:<?= safe_color($s['color']) ?>"></span><?= e($s['label']) ?>
        </span>
        <span class="rounded-full bg-surface px-2 py-0.5 text-xs text-muted" data-board-count data-total="<?= (int) $col['total'] ?>"><?= number_format($col['total']) ?></span>
      </div>
      <div class="flex flex-1 flex-col gap-2 px-2 pb-2" data-board-cards>
        <?php foreach ($cards as $c): ?>
          <a href="/tickets/<?= e($c['id']) ?>" data-board-card="<?= e($c['id']) ?>" draggable="<?= $canManage ? 'true' : 'false' ?>"
             class="<?= cx('group block rounded-lg border border-border bg-surface p-3 text-left transition-shadow hover:shadow-sm', $canManage ? 'sm:cursor-grab sm:active:cursor-grabbing' : '') ?>">
            <div class="flex items-start justify-between gap-2">
              <span class="line-clamp-2 text-sm font-medium"><?= e($c['subject']) ?></span>
              <?= priority_badge($c['priority']['label'], $c['priority']['color']) ?>
            </div>
            <?php if ($c['dueAt'] && ($db = due_badge($c['dueAt'], $c['status']['isClosed']))): ?>
              <div class="mt-1.5"><?= $db ?></div>
            <?php endif; ?>
            <div class="mt-2 flex items-center gap-2 text-xs text-muted">
              <span class="font-mono">#<?= $c['number'] ?></span>
              <?php if ($c['category']): ?>
                <span class="inline-flex min-w-0 items-center gap-1"><span class="h-2 w-2 shrink-0 rounded-full" style="background-color:<?= safe_color($c['category']['color']) ?>"></span><span class="truncate"><?= e($c['category']['name']) ?></span></span>
              <?php endif; ?>
              <?php if ($c['commentCount'] > 0): ?>
                <span class="inline-flex items-center gap-0.5"><?= icon('message-square', 11) ?> <?= $c['commentCount'] ?></span>
              <?php endif; ?>
              <span class="ml-auto shrink-0"><?= e(time_ago($c['createdAt'])) ?></span>
            </div>
            <div class="mt-2 flex items-center gap-2">
              <?= avatar($c['requester']['name'], $c['requester']['color'], 22) ?>
              <span class="truncate text-xs text-muted"><?= e($c['requester']['name']) ?></span>
              <?php if ($c['assignee']) echo avatar($c['assignee']['name'], $c['assignee']['color'], 22, 'ml-auto'); ?>
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
  <p class="mt-2 text-center text-xs text-muted sm:hidden">Swipe between columns · open a ticket to change its status</p>
<?php endif; ?>
