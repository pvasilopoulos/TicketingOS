<?php
/** @var array $rows @var array $statuses @var array $priorities @var array $agents */
?>
<div data-bulk>
  <form method="post" action="/action/ticket-bulk" data-ajax data-bulk-bar hidden class="mb-3 flex flex-wrap items-center gap-2 rounded-xl border border-brand/40 bg-brand/5 px-3 py-2 max-lg:fixed max-lg:inset-x-3 max-lg:bottom-[calc(4.75rem+env(safe-area-inset-bottom))] max-lg:z-30 max-lg:mb-0 max-lg:bg-surface max-lg:shadow-xl max-lg:shadow-black/15">
    <?= form_meta() ?>
    <input type="hidden" name="ids" value="[]" data-bulk-ids>
    <span class="text-sm font-medium"><span data-bulk-count>0</span> selected</span>
    <span class="mx-1 hidden h-5 w-px bg-border sm:block"></span>
    <select name="status" class="h-9 min-w-0 rounded-lg border border-border bg-surface px-2 text-sm text-fg max-sm:flex-1 focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer">
      <?= select_options(['' => 'Status…'] + array_column($statuses, 'label', 'key'), '') ?>
    </select>
    <select name="priority" class="h-9 min-w-0 rounded-lg border border-border bg-surface px-2 text-sm text-fg max-sm:flex-1 focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer">
      <?= select_options(['' => 'Priority…'] + array_column($priorities, 'label', 'key'), '') ?>
    </select>
    <select name="assigneeId" class="h-9 min-w-0 rounded-lg border border-border bg-surface px-2 text-sm text-fg max-sm:flex-1 focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer">
      <?= select_options(['__nochange__' => 'Assignee…', '' => 'Unassigned'] + array_column($agents, 'name', 'id'), '__nochange__') ?>
    </select>
    <button type="submit" class="<?= btn_cls('primary', 'sm') ?>">Apply</button>
    <?php if (($bu = current_user()) && can_delete_tickets($bu)): ?>
      <button type="submit" formaction="/action/ticket-bulk-delete" formnovalidate data-confirm="Delete the selected tickets permanently? Their replies, files, time entries and history are deleted too. This can’t be undone." class="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-sm text-red-600 hover:bg-red-500/10 dark:text-red-400 cursor-pointer"><?= icon('trash-2', 14) ?> Delete</button>
    <?php endif; ?>
    <button type="button" data-bulk-clear class="inline-flex h-9 items-center gap-1 rounded-lg px-2 text-sm text-muted hover:bg-surface-2 hover:text-fg cursor-pointer"><?= icon('x', 14) ?> Clear</button>
  </form>

  <div class="rounded-xl border border-border bg-surface">
    <label class="flex cursor-pointer items-center gap-3 border-b border-border px-4 py-2.5 sm:px-5 text-sm text-muted">
      <input type="checkbox" data-bulk-all class="h-4 w-4 rounded border-border accent-brand">
      Select all
    </label>
    <div class="divide-y divide-border">
      <?php foreach ($rows as $r): ?>
        <div class="flex items-center">
          <label class="flex cursor-pointer items-center self-stretch pl-4 pr-1 sm:pl-5 sm:pr-0">
            <input type="checkbox" value="<?= e($r['id']) ?>" data-bulk-item class="h-4 w-4 rounded border-border accent-brand">
          </label>
          <div class="min-w-0 flex-1"><?= ticket_list_item($r) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
