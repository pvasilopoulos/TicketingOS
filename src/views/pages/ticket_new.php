<?php
[$user, $tenantId] = require_tenant_user();
page_title('New ticket');
$state = flash('ticket-new');
$categories = q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
$people = q_all('SELECT "id","name","role" FROM "User" WHERE "tenantId" = ? AND "role" <> \'SUPER_ADMIN\' AND "id" <> ? ORDER BY "name" ASC', [$tenantId, $user['id']]);
$priorities = tenant_priorities($tenantId);
$mentions = array_map(fn ($p) => ['id' => $p['id'], 'label' => $p['name']], $people);
?>
<div class="mx-auto max-w-2xl">
  <a href="/tickets" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><?= icon('arrow-left', 16) ?> Back to tickets</a>
  <h1 class="text-2xl font-bold tracking-tight">New ticket</h1>
  <p class="mt-1 text-sm text-muted">Tell us what you need help with. An agent will pick it up shortly.</p>

  <div class="mt-6 rounded-xl border border-border bg-surface p-6">
    <form method="post" action="/action/ticket-create" enctype="multipart/form-data" class="space-y-5" data-pending="Creating…">
      <?= form_meta('ticket-new', '/tickets/new') ?>
      <?= banner($state) ?>

      <div>
        <label for="subject" class="<?= LABEL_CLS ?>">Subject</label>
        <input id="subject" name="subject" placeholder="Brief summary of the issue" required maxlength="140" value="<?= e(old('ticket-new', 'subject')) ?>" class="<?= INPUT_CLS ?>" data-kb-source autocomplete="off">
        <!-- Knowledge-base suggestions (filled by app.js as the subject is typed) -->
        <div data-kb-suggest hidden class="mt-2 rounded-xl border border-brand/30 bg-brand/5 p-3">
          <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-brand"><?= icon('book-open', 14) ?> These articles might answer your question</p>
          <ul data-kb-list class="space-y-1"></ul>
        </div>
      </div>

      <div>
        <label class="<?= LABEL_CLS ?>">Description</label>
        <?= rich_editor('description', [
            'minHeight' => 160,
            'placeholder' => 'Describe what happened, steps to reproduce, and what you expected…',
            'mentions' => $mentions,
            'html' => sanitize_rich_html(old('ticket-new', 'description')),
        ]) ?>
      </div>

      <div>
        <label class="<?= LABEL_CLS ?>">Attachments</label>
        <?= file_input('files') ?>
      </div>

      <?php if ($people): ?>
        <div class="rounded-lg border border-border bg-surface p-4" data-team-picker data-people="<?= json_attr(array_map(fn ($p) => ['id' => $p['id'], 'name' => $p['name'], 'role' => role_label($p['role'])], $people)) ?>">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-fg">Team</span>
            <?= dropdown(
                '<button type="button" data-dropdown-trigger class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-2.5 py-1.5 text-xs font-medium text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer">' . icon('user-plus', 14) . ' Add people</button>',
                '<div class="max-h-72 overflow-y-auto py-1" data-team-options>'
                . implode('', array_map(fn ($p) => '<button type="button" data-team-add="' . e($p['id']) . '" data-dropdown-close class="flex w-full items-center gap-2 px-3 py-2 text-left text-sm hover:bg-surface-2 transition-colors cursor-pointer">'
                    . avatar($p['name'], '#6366f1', 26) . '<span class="min-w-0 flex-1 truncate">' . e($p['name']) . '</span><span class="text-xs text-muted">' . e(role_label($p['role'])) . '</span></button>', $people))
                . '</div>',
                250,
                'left',
            ) ?>
          </div>
          <p class="mt-1 text-xs text-muted">Add teammates who should see this ticket. Collaborators can also manage and reply.</p>
          <ul class="mt-3 space-y-2" hidden data-team-list></ul>
          <template data-team-row>
            <li class="flex items-center gap-2.5 rounded-lg border border-border bg-surface-2 px-2.5 py-1.5">
              <span data-slot="avatar"></span>
              <span class="min-w-0 flex-1 truncate text-sm font-medium" data-slot="name"></span>
              <select data-slot="role" class="<?= SMALL_SELECT ?>">
                <?php foreach (WATCHER_ROLES as $r): ?><option value="<?= $r ?>" title="<?= e(WATCHER_ROLE_HINTS[$r]) ?>"><?= WATCHER_ROLE_LABELS[$r] ?></option><?php endforeach; ?>
              </select>
              <button type="button" data-slot="remove" class="text-muted hover:text-red-600 cursor-pointer" aria-label="Remove"><?= icon('x', 15) ?></button>
            </li>
          </template>
          <input type="hidden" name="team" value="[]" data-team-value>
        </div>
      <?php endif; ?>

      <div class="grid gap-5 sm:grid-cols-2">
        <div>
          <label for="priority" class="<?= LABEL_CLS ?>">Priority</label>
          <select id="priority" name="priority" class="<?= SELECT_CLS ?>">
            <?= select_options(array_column($priorities, 'label', 'key'), old('ticket-new', 'priority', default_priority_key($priorities))) ?>
          </select>
        </div>
        <div>
          <label for="categoryId" class="<?= LABEL_CLS ?>">Category</label>
          <select id="categoryId" name="categoryId" class="<?= SELECT_CLS ?>">
            <?= select_options(['' => '— None —'] + array_column($categories, 'name', 'id'), old('ticket-new', 'categoryId')) ?>
          </select>
        </div>
      </div>

      <?= fields_form_block(array_merge(fields_for($tenantId, null, !is_staff($user['role'])), array_filter(tenant_fields($tenantId), fn ($f) => $f['categoryId'] && (is_staff($user['role']) || (int) $f['customerVisible']))), (array) (flash_peek('ticket-new')['old']['cf'] ?? [])) ?>

      <div class="flex gap-3 pt-2">
        <button type="submit" class="<?= btn_cls('primary', 'lg') ?>">Create ticket</button>
      </div>
    </form>
  </div>
</div>
