<?php
[$user, $tenantId] = require_tenant_user();
page_title('New ticket');
$state = flash('ticket-new');
$old = fn (string $k, string $d = '') => old('ticket-new', $k, $d);
$staff = is_staff($user['role']);
$categories = q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
// Staff can add anyone; customers only colleagues of their own organization.
$people = array_values(array_filter(addable_people($user, $tenantId), fn ($p) => $p['id'] !== $user['id']));
$priorities = tenant_priorities($tenantId);
$mentions = array_map(fn ($p) => ['id' => $p['id'], 'label' => $p['name']], $people);
$templates = array_map(fn ($t) => template_payload($t, $staff), ticket_templates($tenantId, $staff));
$slaHours = sla_hours_map($tenantId);
$parts = ticket_form_parts($tenantId); // Settings → General → New-ticket form
if (!$parts['templates']) $templates = [];

if ($staff) {
    $agents = q_all('SELECT "id","name","avatarColor" FROM "User" WHERE "tenantId" = ? AND "active" = 1 AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') ORDER BY "name"', [$tenantId]);
    $groups = tenant_groups($tenantId);
    $statuses = tenant_statuses($tenantId);
    $allTags = pluck(q_all('SELECT "name" FROM "Tag" WHERE "tenantId" = ? ORDER BY "name" LIMIT 300', [$tenantId]), 'name');
    $canNewCustomer = can($user, 'users.invite');
    $mode = $old('requesterMode', 'self');
}
$section = 'rounded-xl border border-border bg-surface p-5 sm:p-6';
$sectionTitle = fn (string $ic, string $t, string $sub = '') => '<div class="mb-4 flex items-start gap-3"><span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand/10 text-brand">' . icon($ic, 16) . '</span><div><h2 class="text-sm font-semibold">' . e($t) . '</h2>' . ($sub !== '' ? '<p class="text-xs text-muted">' . e($sub) . '</p>' : '') . '</div></div>';
?>
<div class="mx-auto max-w-3xl">
  <a href="/tickets" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><?= icon('arrow-left', 16) ?> Back to tickets</a>
  <h1 class="text-2xl font-bold tracking-tight">New ticket</h1>
  <p class="mt-1 text-sm text-muted"><?= $staff ? 'Log a request for yourself or for a customer.' : 'Tell us what you need help with. An agent will pick it up shortly.' ?></p>

  <form method="post" action="/action/ticket-create" enctype="multipart/form-data" class="mt-6 space-y-5" data-pending="Creating…" data-new-ticket
        data-sla="<?= json_attr($slaHours) ?>" data-templates="<?= json_attr($templates) ?>">
    <?= form_meta('ticket-new', '/tickets/new') ?>
    <?= banner($state) ?>
    <input type="hidden" name="templateId" value="<?= e($old('templateId')) ?>" data-template-id>

    <?php if ($templates): ?>
      <!-- Templates -->
      <div class="rounded-xl border border-dashed border-border bg-surface-2/40 p-4">
        <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted"><?= icon('layers', 13) ?> Start from a template</p>
        <div class="flex flex-wrap gap-2">
          <?php foreach ($templates as $t): ?>
            <button type="button" data-use-template="<?= e($t['id']) ?>" class="rounded-full border border-border bg-surface px-3 py-1.5 text-sm font-medium transition hover:border-brand hover:text-brand data-[on=true]:border-brand data-[on=true]:bg-brand/10 data-[on=true]:text-brand cursor-pointer"><?= e($t['name']) ?></button>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($staff && ($parts['forCustomer'] || $parts['channel'])): ?>
      <!-- Requester & channel -->
      <div class="<?= $section ?>">
        <?= $parts['forCustomer'] ? $sectionTitle('user-round', 'Who is this for?', 'Open it for yourself or log it for a customer.') : $sectionTitle('phone', 'How did it come in?') ?>
        <?php if ($parts['forCustomer']): ?>
        <div class="grid grid-cols-3 gap-1 rounded-xl bg-surface-2 p-1 text-sm font-medium" role="radiogroup">
          <?php foreach (['self' => 'Myself', 'customer' => 'A customer'] + ($canNewCustomer ? ['new' => 'New customer'] : []) as $k => $label): ?>
            <label class="flex cursor-pointer items-center justify-center rounded-lg px-2 py-2 text-center text-muted transition has-[:checked]:bg-surface has-[:checked]:text-fg has-[:checked]:shadow-sm">
              <input type="radio" name="requesterMode" value="<?= $k ?>" <?= $mode === $k ? 'checked' : '' ?> class="sr-only" data-requester-mode><?= e($label) ?>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="mt-4" data-mode-panel="customer" <?= $mode === 'customer' ? '' : 'hidden' ?>>
          <label class="<?= LABEL_CLS ?>" for="customerSearch">Customer</label>
          <div class="relative">
            <input id="customerSearch" type="search" autocomplete="off" placeholder="Search by name or email…" class="<?= INPUT_CLS ?>" data-customer-search>
            <div data-customer-results hidden class="absolute left-0 right-0 z-30 mt-1 max-h-64 overflow-y-auto rounded-xl border border-border bg-surface p-1 shadow-xl"></div>
          </div>
          <input type="hidden" name="requesterId" value="<?= e($old('requesterId')) ?>" data-requester-id>
          <div data-customer-chosen hidden class="mt-2 flex items-center gap-2 rounded-lg border border-brand/30 bg-brand/5 px-3 py-2 text-sm">
            <?= icon('circle-check', 15, 'text-brand') ?><span class="min-w-0 flex-1 truncate" data-customer-label></span>
            <button type="button" data-customer-clear class="text-xs font-medium text-muted hover:text-fg cursor-pointer">Change</button>
          </div>
        </div>

        <?php if ($canNewCustomer): ?>
          <div class="mt-4 grid gap-3 sm:grid-cols-2" data-mode-panel="new" <?= $mode === 'new' ? '' : 'hidden' ?>>
            <div><label class="<?= LABEL_CLS ?>" for="newName">Name</label><input id="newName" name="newName" value="<?= e($old('newName')) ?>" placeholder="Jane Doe" class="<?= INPUT_CLS ?>"></div>
            <div><label class="<?= LABEL_CLS ?>" for="newEmail">Email</label><input id="newEmail" name="newEmail" type="email" value="<?= e($old('newEmail')) ?>" placeholder="jane@company.com" class="<?= INPUT_CLS ?>"></div>
            <p class="text-xs text-muted sm:col-span-2">They’re added as a customer (and to their company’s organization by email domain). If the email already exists, that customer is used.</p>
          </div>
        <?php endif; ?>
        <?php endif; ?>

        <?php if ($parts['channel']): ?>
        <div class="<?= $parts['forCustomer'] ? 'mt-4' : '' ?>">
          <label class="<?= LABEL_CLS ?>" for="channel">Received via</label>
          <select id="channel" name="channel" class="<?= SELECT_CLS ?>"><?= select_options(TICKET_CHANNELS, $old('channel', 'web')) ?></select>
        </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <!-- The request -->
    <div class="<?= $section ?> space-y-5">
      <div>
        <label for="subject" class="<?= LABEL_CLS ?>">Subject</label>
        <input id="subject" name="subject" placeholder="Brief summary of the issue" required maxlength="140" value="<?= e($old('subject')) ?>" class="<?= INPUT_CLS ?>"<?= $parts['kb'] ? ' data-kb-source' : '' ?><?= $parts['similar'] ? ' data-similar-source' : '' ?> autocomplete="off">
        <!-- Possible duplicates (filled by app.js as the subject is typed) -->
        <div data-similar hidden class="mt-2 rounded-xl border border-amber-300/60 bg-amber-50 p-3 dark:border-amber-500/30 dark:bg-amber-500/10">
          <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-amber-800 dark:text-amber-300"><?= icon('triangle-alert', 14) ?> Is it the same as one of these open tickets?</p>
          <ul data-similar-list class="space-y-1"></ul>
          <p class="mt-2 text-[11px] text-amber-800/80 dark:text-amber-300/80">If so, open it and reply there instead — it keeps everything in one place.</p>
        </div>
        <!-- Knowledge-base suggestions -->
        <div data-kb-suggest hidden class="mt-2 rounded-xl border border-brand/30 bg-brand/5 p-3">
          <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-brand"><?= icon('book-open', 14) ?> These articles might answer your question</p>
          <ul data-kb-list class="space-y-1"></ul>
        </div>
      </div>

      <div>
        <div class="mb-1.5 flex items-center justify-between gap-2"><label class="text-sm font-medium text-fg">Description</label><span class="hidden items-center gap-1 text-[11px] text-muted sm:inline-flex"><?= icon('image', 12) ?> Paste or drop screenshots</span></div>
        <?= rich_editor('description', [
            'minHeight' => 180,
            'placeholder' => 'Describe what happened, steps to reproduce, and what you expected…',
            'mentions' => $mentions,
            'html' => sanitize_rich_html($old('description')),
        ]) ?>
      </div>

      <div class="grid gap-5 sm:grid-cols-2">
        <div>
          <label for="priority" class="<?= LABEL_CLS ?>">Priority</label>
          <select id="priority" name="priority" class="<?= SELECT_CLS ?>" data-priority>
            <?= select_options(array_column($priorities, 'label', 'key'), $old('priority', default_priority_key($priorities))) ?>
          </select>
          <p class="mt-1.5 flex items-center gap-1.5 text-xs text-muted" data-sla-preview hidden><?= icon('clock', 13) ?><span></span></p>
        </div>
        <div>
          <label for="categoryId" class="<?= LABEL_CLS ?>">Category</label>
          <select id="categoryId" name="categoryId" class="<?= SELECT_CLS ?>">
            <?= select_options(['' => '— None —'] + array_column($categories, 'name', 'id'), $old('categoryId')) ?>
          </select>
        </div>
      </div>

      <?= fields_form_block(array_merge(fields_for($tenantId, null, !$staff), array_filter(tenant_fields($tenantId), fn ($f) => $f['categoryId'] && ($staff || (int) $f['customerVisible']))), (array) (flash_peek('ticket-new')['old']['cf'] ?? [])) ?>

      <?php if ($parts['attachments']): ?>
      <div>
        <label class="<?= LABEL_CLS ?>">Attachments</label>
        <?= file_input('files') ?>
      </div>
      <?php endif; ?>
    </div>

    <?php $showTeam = $parts['team'] && $people; if ($parts['cc'] || $showTeam): ?>
    <!-- People -->
    <div class="<?= $section ?> space-y-5">
      <?= $sectionTitle('users-round', 'Who else should know?') ?>
      <?php if ($parts['cc']): ?>
      <div>
        <label for="cc" class="<?= LABEL_CLS ?>">CC by email</label>
        <input id="cc" name="cc" value="<?= e($old('cc')) ?>" placeholder="name@company.com, other@company.com" class="<?= INPUT_CLS ?>" autocomplete="off">
        <p class="mt-1.5 text-xs text-muted">People without an account get public replies and status changes by email. Up to 10.</p>
      </div>
      <?php endif; ?>

      <?php if ($showTeam): ?>
        <div class="rounded-lg border border-border bg-surface p-4" data-team-picker data-people="[]">
          <div class="flex items-center justify-between">
            <span class="text-sm font-medium text-fg">Team</span>
            <?= people_picker(['label' => 'Add people', 'staff' => $staff, 'multi' => true]) ?>
          </div>
          <p class="mt-1 text-xs text-muted"><?= $staff ? 'Add teammates who should see this ticket. Collaborators can also manage and reply.' : 'Add colleagues from your organization who should follow this ticket.' ?></p>
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
    </div>
    <?php endif; ?>

    <?php if ($staff && $parts['agentOptions']): ?>
      <!-- Agent options -->
      <details class="group <?= $section ?>" <?= $old('subtasks') !== '' || $old('internalNote') !== '' ? 'open' : '' ?>>
        <summary class="flex cursor-pointer list-none items-center gap-3 [&::-webkit-details-marker]:hidden">
          <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand/10 text-brand"><?= icon('sliders-horizontal', 16) ?></span>
          <span class="min-w-0 flex-1"><span class="block text-sm font-semibold">Agent options</span><span class="block text-xs text-muted">Assignees, group, status, tags, deadline, a first internal note and sub-tickets.</span></span>
          <?= icon('chevron-down', 18, 'shrink-0 text-muted transition-transform group-open:rotate-180') ?>
        </summary>
        <div class="mt-5 space-y-5">
          <?php if ($agents): ?>
            <div>
              <p class="<?= LABEL_CLS ?>">Assignees</p>
              <div class="grid max-h-48 gap-1 overflow-y-auto rounded-lg border border-border p-1 sm:grid-cols-2">
                <?php foreach ($agents as $ag): $lock = !can($user, 'tickets.assign') && $ag['id'] !== $user['id']; ?>
                  <label class="<?= cx('flex items-center gap-2 rounded-md px-2 py-1.5 text-sm', $lock ? 'opacity-50' : 'cursor-pointer hover:bg-surface-2') ?>">
                    <input type="checkbox" name="assigneeIds[]" value="<?= e($ag['id']) ?>" <?= $lock ? 'disabled' : '' ?> class="h-4 w-4 rounded border-border accent-brand" data-assignee-box>
                    <?= avatar($ag['name'], $ag['avatarColor'], 22) ?><span class="min-w-0 truncate"><?= e($ag['name']) ?><?= $ag['id'] === $user['id'] ? ' <span class="text-muted">(you)</span>' : '' ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <p class="mt-1.5 text-xs text-muted">The first one checked is the lead. Leave empty to let group routing assign it.</p>
            </div>
          <?php endif; ?>
          <div class="grid gap-4 sm:grid-cols-3">
            <?php if ($groups && can($user, 'tickets.group')): ?>
              <div><label class="<?= LABEL_CLS ?>" for="groupId">Group</label><select id="groupId" name="groupId" class="<?= SELECT_CLS ?>"><?= select_options(['' => 'From category'] + array_column($groups, 'name', 'id'), $old('groupId')) ?></select></div>
            <?php endif; ?>
            <?php if (can($user, 'tickets.status')): ?>
              <div><label class="<?= LABEL_CLS ?>" for="status">Status</label><select id="status" name="status" class="<?= SELECT_CLS ?>"><?= select_options(['' => 'Default'] + array_column($statuses, 'label', 'key'), $old('status')) ?></select></div>
            <?php endif; ?>
            <?php if (can($user, 'tickets.priority')): ?>
              <div><label class="<?= LABEL_CLS ?>" for="dueAt">Custom deadline</label><input id="dueAt" name="dueAt" type="datetime-local" value="<?= e($old('dueAt')) ?>" class="<?= INPUT_CLS ?>" data-due-input></div>
            <?php endif; ?>
          </div>
          <?php if (can($user, 'tickets.tags')): ?>
            <div>
              <label class="<?= LABEL_CLS ?>" for="tagsText">Tags</label>
              <input id="tagsText" name="tagsText" list="tag-suggestions" value="<?= e($old('tagsText')) ?>" placeholder="billing, vip" class="<?= INPUT_CLS ?>" data-tags-input>
              <datalist id="tag-suggestions"><?php foreach ($allTags as $tg): ?><option value="<?= e($tg) ?>"><?php endforeach; ?></datalist>
              <p class="mt-1.5 text-xs text-muted">Separate with commas. New tags are created.</p>
            </div>
          <?php endif; ?>
          <?php if (can($user, 'tickets.internal')): ?>
            <div>
              <label class="<?= LABEL_CLS ?>" for="internalNote"><?= icon('lock', 13, 'inline text-amber-600') ?> First internal note</label>
              <textarea id="internalNote" name="internalNote" rows="3" placeholder="Context for the team — the customer never sees this" class="w-full rounded-lg border border-amber-300/60 bg-amber-50/50 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-amber-400/40 dark:border-amber-500/30 dark:bg-amber-500/5"><?= e($old('internalNote')) ?></textarea>
            </div>
          <?php endif; ?>
          <div>
            <label class="<?= LABEL_CLS ?>" for="subtasks">Sub-tickets</label>
            <textarea id="subtasks" name="subtasks" rows="3" placeholder="One task per line, e.g.&#10;Create email account&#10;Order laptop" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40" data-subtasks-input><?= e($old('subtasks')) ?></textarea>
            <p class="mt-1.5 text-xs text-muted">Each line becomes an internal sub-ticket linked to this one, so the work can be split and tracked.</p>
          </div>
        </div>
      </details>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-3 pt-1">
      <button type="submit" class="<?= btn_cls('primary', 'lg') ?>"><?= icon('send', 16) ?> Create ticket</button>
      <a href="/tickets" class="<?= btn_cls('ghost', 'lg') ?>">Cancel</a>
    </div>
  </form>
</div>
