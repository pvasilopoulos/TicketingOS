<?php
[$me, $tenantId] = require_perm('settings.*', 'users.*', 'groups.manage', 'orgs.manage', 'audit.view');
$canUsers = can($me, 'users.*');
$canGroups = can($me, 'groups.manage');
$canOrgs = can($me, 'orgs.manage');
$isAdminMe = is_tenant_admin($me['role']);
$canRoles = $isAdminMe && can($me, 'admin.roles');
$canSecurity = $isAdminMe && can($me, 'admin.security');
/** May the current user open a settings section? */
$sec = fn (string $id) => can_settings_tab($me, $id);
page_title('Settings');

$tenant = q_one('SELECT * FROM "Tenant" WHERE "id" = ?', [$tenantId]);
$categories = q_all('SELECT * FROM "Category" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
$email = email_settings($tenantId);
$statuses = tenant_statuses($tenantId);
$priorities = tenant_priorities($tenantId);
$sla = tenant_sla($tenantId);
$statusUse = array_column(q_all('SELECT "status", COUNT(*) AS n FROM "Ticket" WHERE "tenantId" = ? GROUP BY "status"', [$tenantId]), 'n', 'status');
$prioUse = array_column(q_all('SELECT "priority", COUNT(*) AS n FROM "Ticket" WHERE "tenantId" = ? GROUP BY "priority"', [$tenantId]), 'n', 'priority');
$tags = q_all('SELECT g."id", g."name", (SELECT COUNT(*) FROM "TicketTag" tt WHERE tt."tagId" = g."id") AS "count" FROM "Tag" g WHERE g."tenantId" = ? ORDER BY g."name" ASC', [$tenantId]);
$canned = q_all('SELECT "id","title","body" FROM "CannedResponse" WHERE "tenantId" = ? ORDER BY "title" ASC', [$tenantId]);
$rules = q_all('SELECT * FROM "AutomationRule" WHERE "tenantId" = ? ORDER BY "order" ASC, "createdAt" ASC', [$tenantId]);
$agents = q_all('SELECT "id","name" FROM "User" WHERE "tenantId" = ? AND "role" IN (\'SUPER_ADMIN\',\'ADMIN\',\'AGENT\') ORDER BY "name" ASC', [$tenantId]);
$policy = tenant_policy($tenantId);

$colorInput = 'h-10 w-14 cursor-pointer rounded-lg border border-border bg-surface p-1';
$rowColor = 'h-9 w-10 cursor-pointer rounded-lg border border-border bg-surface p-1';
$rowText = 'h-9 flex-1 min-w-32 rounded-lg border border-border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40';
$moveBtn = 'text-muted hover:text-fg disabled:opacity-30 cursor-pointer';
$save14 = icon('check', 14) . ' Save';

/** Up/down arrows for an orderable row. */
$mover = function (string $action, string $id, bool $first, bool $last) use ($moveBtn): string {
    $btn = fn ($dir, $ic, $disabled) => '<form method="post" action="/action/' . $action . '" data-ajax class="flex">' . form_meta()
        . '<input type="hidden" name="id" value="' . e($id) . '"><input type="hidden" name="dir" value="' . $dir . '">'
        . '<button type="submit" ' . ($disabled ? 'disabled' : '') . ' class="' . $moveBtn . '" aria-label="Move ' . $dir . '">' . icon($ic, 14) . '</button></form>';
    return '<div class="flex flex-col">' . $btn('up', 'chevron-up', $first) . $btn('down', 'chevron-down', $last) . '</div>';
};
$deleteForm = function (string $action, string $id, bool $enabled, string $title, string $label = 'Delete'): string {
    return '<form method="post" action="/action/' . $action . '" data-ajax>' . form_meta() . '<input type="hidden" name="id" value="' . e($id) . '">'
        . '<button type="submit" ' . ($enabled ? '' : 'disabled') . ' title="' . e($title) . '" aria-label="' . e($label) . '" class="' . ICON_BTN_DANGER . '">' . icon('trash-2', 15) . '</button></form>';
};

$s = fn (string $form) => flash($form);
?>
<?= page_header('Settings', 'Configure your workspace and who can do what.') ?>
<div class="lg:grid lg:grid-cols-[13rem_1fr] lg:gap-8" data-settings>
  <?php
  $groups = [
      'Workspace' => array_filter([
          ['general', 'General', 'settings'], ['workflow', 'Workflow', 'flag'], ['replies', 'Tags & replies', 'hash'],
          ['fields', 'Ticket fields & CSAT', 'sliders-horizontal'], ['automations', 'Automations', 'zap'],
          ['notifications', 'Notifications & email', 'mail'], ['integrations', 'Integrations', 'globe'],
      ], fn ($t) => $sec($t[0])),
      'People & access' => array_filter([
          $canUsers ? ['users', 'Users', 'users'] : null,
          $canGroups ? ['groups', 'Groups', 'users-round'] : null,
          $canOrgs ? ['orgs', 'Organizations', 'building-2'] : null,
          $canRoles ? ['roles', 'Roles & permissions', 'shield-check'] : null,
          $canSecurity ? ['security', 'Security', 'key-round'] : null,
          can($me, 'audit.view') ? ['audit', 'Audit log', 'scroll-text'] : null,
      ]),
      'Platform' => $me['role'] === 'SUPER_ADMIN' ? [['branding', 'Branding', 'sparkles']] : [],
  ];
  ?>
  <nav class="sticky top-0 z-20 -mx-4 mb-5 flex gap-2 overflow-x-auto border-b border-border bg-bg/95 px-4 py-2 backdrop-blur lg:top-6 lg:mx-0 lg:mb-0 lg:flex-col lg:gap-1 lg:self-start lg:border-0 lg:bg-transparent lg:p-0" aria-label="Settings sections">
    <?php $first = true; foreach ($groups as $glabel => $tabs): if (!$tabs) continue; ?>
      <?php if (!$first): ?><span class="mx-1 w-px shrink-0 self-stretch bg-border lg:hidden"></span><?php endif; ?>
      <p class="<?= $first ? 'hidden lg:block' : 'hidden lg:mt-4 lg:block' ?> px-3 pb-1 text-[11px] font-semibold uppercase tracking-wider text-muted"><?= e($glabel) ?></p>
      <?php foreach ($tabs as [$id, $label, $ic]): ?>
        <a href="#<?= $id ?>" data-settings-tab="<?= $id ?>" class="inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium text-muted transition-colors hover:bg-surface-2 hover:text-fg data-[active=true]:bg-brand/10 data-[active=true]:text-brand"><?= icon($ic, 16) ?><?= e($label) ?></a>
      <?php endforeach; ?>
    <?php $first = false; endforeach; ?>
  </nav>
  <div class="min-w-0 space-y-6">

  <?php if ($sec('general')): ?>
  <section id="general" data-settings-section="general" class="scroll-mt-24 space-y-6">
  <!-- Workspace -->
  <div class="space-y-6">
    <?php if (can($me, 'settings.general')): ?>
    <div class="rounded-xl border border-border bg-surface p-6">
      <h3 class="mb-4 text-sm font-semibold">Workspace</h3>
      <form method="post" action="/action/workspace-rename" class="space-y-4">
        <?= form_meta('workspace') ?>
        <?= banner($s('workspace')) ?>
        <div>
          <label for="ws-name" class="<?= LABEL_CLS ?>">Workspace name</label>
          <input id="ws-name" name="name" value="<?= e($tenant['name']) ?>" required class="<?= INPUT_CLS ?>">
        </div>
        <div>
          <label for="ws-slug" class="<?= LABEL_CLS ?>">Workspace URL slug</label>
          <input id="ws-slug" value="<?= e($tenant['slug']) ?>" disabled class="<?= INPUT_CLS ?> font-mono">
          <p class="mt-1 text-xs text-muted">Used to identify your tenant. Contact support to change it.</p>
        </div>
        <button type="submit" class="<?= btn_cls() ?>">Save changes</button>
      </form>
    </div>

    <?php endif; ?>
    <?php if (can($me, 'settings.categories')): ?>
    <!-- Categories -->
    <div class="rounded-xl border border-border bg-surface">
      <?= card_header('Ticket categories') ?>
      <div class="divide-y divide-border">
        <?php if (!$categories): ?><p class="px-5 py-6 text-center text-sm text-muted">No categories yet.</p><?php endif; ?>
        <?php foreach ($categories as $c): ?>
          <div class="flex items-center gap-3 px-5 py-3">
            <span class="h-3.5 w-3.5 rounded-full" style="background-color:<?= safe_color($c['color']) ?>"></span>
            <span class="flex-1 text-sm font-medium"><?= e($c['name']) ?></span>
            <?= $deleteForm('category-delete', $c['id'], true, 'Delete ' . $c['name'], 'Delete ' . $c['name']) ?>
          </div>
        <?php endforeach; ?>
      </div>
      <form method="post" action="/action/category-create" class="flex flex-wrap items-end gap-3 border-t border-border p-5">
        <?= form_meta('category') ?>
        <div class="flex-1 min-w-40">
          <label for="cat-name" class="<?= LABEL_CLS ?>">New category</label>
          <input id="cat-name" name="name" placeholder="e.g. Hardware" required class="<?= INPUT_CLS ?>">
        </div>
        <div>
          <label for="cat-color" class="<?= LABEL_CLS ?>">Color</label>
          <input id="cat-color" name="color" type="color" value="#6366f1" class="<?= $colorInput ?>">
        </div>
        <button type="submit" class="<?= btn_cls('secondary') ?>"><?= icon('plus', 16) ?> Add</button>
      </form>
      <?php if ($b = banner($s('category'))): ?><div class="px-5 pb-5"><?= $b ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
    <?php if (can($me, 'settings.general')): ?>
    <!-- Mobile bottom menu -->
    <?php
    $navState = $s('nav');
    $navIcons = array_map(fn ($i) => icon($i['icon'], 20), NAV_ITEMS);
    $navLabels = array_map(fn ($i) => $i['label'], NAV_ITEMS);
    $audienceNote = ['all' => '', 'staff' => ' (staff)', 'admin' => ' (admins)'];
    ?>
    <div class="rounded-xl border border-border bg-surface" data-nav-editor data-icons="<?= json_attr($navIcons) ?>" data-labels="<?= json_attr($navLabels) ?>" data-plus="<?= e(icon('plus', 22)) ?>" data-more="<?= e(icon('menu', 20)) ?>">
      <?= card_header(titled('smartphone', 'Mobile bottom menu')) ?>
      <p class="px-6 pt-4 text-sm text-muted">Choose up to <?= NAV_MAX_ITEMS ?> shortcuts for the bar at the bottom of the app on phones and tablets. “More” is always last and opens the full menu. Admin-only items are hidden from agents automatically.</p>
      <form method="post" action="/action/nav-save" class="p-5 sm:p-6">
        <?= form_meta('nav') ?>
        <?= banner($navState, true) ?>
        <div class="grid gap-8 lg:grid-cols-2">
          <?php foreach (['staff' => ['Agents & admins', 'users'], 'customer' => ['Customers', 'user-plus']] as $menu => [$title, $ic]):
              $layout = nav_layout($tenantId, $menu);
              $opts = ['' => '— Empty —'];
              foreach (nav_allowed($menu) as $k) $opts[$k] = NAV_ITEMS[$k]['label'] . $audienceNote[NAV_ITEMS[$k]['audience']]; ?>
            <fieldset data-nav-menu="<?= $menu ?>" class="min-w-0">
              <legend class="mb-3 flex items-center gap-2 text-sm font-semibold"><?= icon($ic, 16) ?> <?= e($title) ?></legend>
              <div class="grid grid-cols-2 gap-3">
                <?php for ($i = 1; $i <= NAV_MAX_ITEMS; $i++): ?>
                  <div>
                    <label class="mb-1 block text-xs font-medium text-muted">Slot <?= $i ?></label>
                    <select name="<?= $menu ?>_slot<?= $i ?>" data-nav-slot class="<?= SELECT_CLS ?>"><?= select_options($opts, $layout['items'][$i - 1] ?? '') ?></select>
                  </div>
                <?php endfor; ?>
              </div>
              <label class="mt-3 flex items-center gap-2 text-sm cursor-pointer select-none">
                <input type="checkbox" name="<?= $menu ?>_center" data-nav-center <?= $layout['center'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand">
                Show the round <strong class="font-medium">+ New ticket</strong> button in the middle
              </label>
              <p class="mb-2 mt-4 text-xs font-medium text-muted">Preview</p>
              <div class="mx-auto max-w-sm overflow-hidden rounded-2xl border border-border bg-bg pt-6 shadow-inner">
                <div class="flex h-16 items-stretch border-t border-border bg-surface px-2" data-nav-preview></div>
              </div>
            </fieldset>
          <?php endforeach; ?>
        </div>
        <div class="mt-6 flex flex-wrap items-center gap-3">
          <button type="submit" class="<?= btn_cls() ?>"><?= icon('check', 16) ?> Save menu</button>
          <button type="submit" name="reset" value="1" data-nav-reset class="<?= btn_cls('ghost') ?>">Reset to defaults</button>
        </div>
      </form>
    </div>
    <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($sec('workflow')): ?>
  <section id="workflow" data-settings-section="workflow" class="scroll-mt-24 space-y-6">
  <!-- Statuses -->
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header('Ticket statuses') ?>
    <p class="px-6 pt-4 text-sm text-muted">
      Customize the stages a ticket moves through. Statuses marked <strong>Closed</strong> count as resolved — they set the resolved date and
      a customer reply reopens the ticket. Order controls the board columns.
    </p>
    <div class="divide-y divide-border px-2 py-3">
      <?php foreach ($statuses as $i => $st):
          $inUse = (int) ($statusUse[$st['key']] ?? 0);
          $canDelete = count($statuses) > 1 && $inUse === 0; ?>
        <div class="flex flex-wrap items-center gap-2 px-3 py-2">
          <?= $mover('status-move', $st['id'], $i === 0, $i === count($statuses) - 1) ?>
          <form method="post" action="/action/status-update" data-ajax data-dirty class="contents">
            <?= form_meta() ?><input type="hidden" name="id" value="<?= e($st['id']) ?>">
            <input type="color" name="color" value="<?= e(safe_color($st['color'])) ?>" class="<?= $rowColor ?>">
            <input name="label" value="<?= e($st['label']) ?>" class="<?= $rowText ?>" minlength="2" required>
            <label class="flex items-center gap-2 text-sm text-muted cursor-pointer select-none">
              <input type="checkbox" name="isClosed" <?= $st['isClosed'] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Closed
            </label>
            <button type="submit" data-dirty-save disabled class="<?= btn_cls('secondary', 'sm') ?> data-[dirty=true]:bg-brand data-[dirty=true]:text-brand-fg"><?= $save14 ?></button>
          </form>
          <?= $deleteForm('status-delete', $st['id'], $canDelete, $canDelete ? 'Delete status' : ($inUse > 0 ? "In use by $inUse ticket(s)" : 'Keep at least one status'), 'Delete status') ?>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/action/status-create" class="flex flex-wrap items-end gap-3 border-t border-border p-5">
      <?= form_meta('status') ?>
      <div>
        <label for="new-color" class="<?= LABEL_CLS ?>">Color</label>
        <input id="new-color" name="color" type="color" value="#6366f1" class="<?= $colorInput ?>">
      </div>
      <div class="flex-1 min-w-40">
        <label for="new-label" class="<?= LABEL_CLS ?>">New status</label>
        <input id="new-label" name="label" placeholder="e.g. In Progress" required class="<?= INPUT_CLS ?>">
      </div>
      <label class="flex items-center gap-2 pb-2.5 text-sm text-muted cursor-pointer select-none">
        <input type="checkbox" name="isClosed" class="h-4 w-4 rounded border-border accent-brand"> Closed
      </label>
      <button type="submit" class="<?= btn_cls('secondary') ?>"><?= icon('plus', 16) ?> Add status</button>
    </form>
    <?= inline_error($s('status'), 'px-5 pb-5') ?>
  </div>

  <!-- Priorities -->
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header(titled('flag', 'Priorities')) ?>
    <p class="px-6 pt-4 text-sm text-muted">
      Severity levels for tickets, ordered low → high. The highest one drives the &quot;urgent&quot; stats. Set resolution targets per priority in SLA.
    </p>
    <div class="divide-y divide-border px-2 py-3">
      <?php foreach ($priorities as $i => $p):
          $inUse = (int) ($prioUse[$p['key']] ?? 0);
          $canDelete = count($priorities) > 1 && $inUse === 0; ?>
        <div class="flex flex-wrap items-center gap-2 px-3 py-2">
          <?= $mover('priority-move', $p['id'], $i === 0, $i === count($priorities) - 1) ?>
          <form method="post" action="/action/priority-update" data-ajax data-dirty class="contents">
            <?= form_meta() ?><input type="hidden" name="id" value="<?= e($p['id']) ?>">
            <input type="color" name="color" value="<?= e(safe_color($p['color'])) ?>" class="<?= $rowColor ?>">
            <input name="label" value="<?= e($p['label']) ?>" class="<?= $rowText ?>" minlength="2" required>
            <button type="submit" data-dirty-save disabled class="<?= btn_cls('secondary', 'sm') ?> data-[dirty=true]:bg-brand data-[dirty=true]:text-brand-fg"><?= $save14 ?></button>
          </form>
          <?= $deleteForm('priority-delete', $p['id'], $canDelete, $canDelete ? 'Delete priority' : ($inUse > 0 ? "In use by $inUse ticket(s)" : 'Keep at least one priority'), 'Delete priority') ?>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/action/priority-create" class="flex flex-wrap items-end gap-3 border-t border-border p-5">
      <?= form_meta('priority') ?>
      <div>
        <label for="np-color" class="<?= LABEL_CLS ?>">Color</label>
        <input id="np-color" name="color" type="color" value="#6366f1" class="<?= $colorInput ?>">
      </div>
      <div class="flex-1 min-w-40">
        <label for="np-label" class="<?= LABEL_CLS ?>">New priority</label>
        <input id="np-label" name="label" placeholder="e.g. Critical" required class="<?= INPUT_CLS ?>">
      </div>
      <button type="submit" class="<?= btn_cls('secondary') ?>"><?= icon('plus', 16) ?> Add priority</button>
    </form>
    <?= inline_error($s('priority'), 'px-5 pb-5') ?>
  </div>

  <!-- SLA -->
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header(titled('clock', 'SLA targets')) ?>
    <form method="post" action="/action/sla-save" class="space-y-4 p-6">
      <?= form_meta('sla') ?>
      <?= banner($s('sla'), true) ?>
      <p class="text-sm text-muted">
        Resolution deadline per priority (in hours). Open tickets past their deadline are flagged <span class="font-medium text-red-600 dark:text-red-400">overdue</span>.
      </p>
      <div class="grid gap-4 sm:grid-cols-2">
        <?php foreach ($priorities as $p): $c = safe_color($p['color']); ?>
          <div class="flex items-center gap-3">
            <span class="inline-flex min-w-20 rounded-full px-2.5 py-0.5 text-xs font-medium" style="background-color:<?= $c ?>22;color:<?= $c ?>"><?= e($p['label']) ?></span>
            <input name="sla_<?= e($p['key']) ?>" type="number" min="1" value="<?= e($sla[$p['key']] ?? '') ?>" class="<?= str_replace('w-full ', '', INPUT_CLS) ?> w-28">
            <span class="text-sm text-muted">hours</span>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="<?= btn_cls() ?>">Save SLA targets</button>
    </form>
  </div>
  </section>
  <?php endif; ?>

  <?php if ($sec('replies')): ?>
  <section id="replies" data-settings-section="replies" class="scroll-mt-24 space-y-6">
  <?php if (can($me, 'settings.tags')): ?>
  <!-- Tags -->
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header(titled('hash', 'Tags')) ?>
    <div class="divide-y divide-border px-2 py-2">
      <?php if (!$tags): ?><p class="px-3 py-6 text-center text-sm text-muted">No tags yet.</p><?php endif; ?>
      <?php foreach ($tags as $t): ?>
        <div class="flex items-center gap-2 px-3 py-2">
          <form method="post" action="/action/tag-rename" data-ajax data-dirty class="contents">
            <?= form_meta() ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
            <?= icon('hash', 14, 'text-muted') ?>
            <input name="name" value="<?= e($t['name']) ?>" required class="h-9 flex-1 rounded-lg border border-border bg-surface px-3 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40">
            <span class="text-xs text-muted"><?= (int) $t['count'] ?> used</span>
            <button type="submit" data-dirty-save disabled class="<?= btn_cls('secondary', 'sm') ?> data-[dirty=true]:bg-brand data-[dirty=true]:text-brand-fg"><?= $save14 ?></button>
          </form>
          <?= $deleteForm('tag-delete', $t['id'], true, 'Delete tag', 'Delete tag') ?>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/action/tag-create" class="flex flex-wrap items-end gap-3 border-t border-border p-5">
      <?= form_meta('tag') ?>
      <div class="flex-1 min-w-40"><input name="name" placeholder="e.g. regression" required class="<?= INPUT_CLS ?>"></div>
      <button type="submit" class="<?= btn_cls('secondary') ?>"><?= icon('plus', 16) ?> Add tag</button>
    </form>
    <?php $ts = $s('tag'); ?>
    <?= inline_error($ts, 'px-5 pb-5') ?><?= inline_success($ts, 'px-5 pb-5') ?>
  </div>
  <?php endif; ?>

  <?php if (can($me, 'settings.canned')): ?>
  <!-- Canned responses -->
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header(titled('message-square-plus', 'Canned responses')) ?>
    <p class="px-6 pt-4 text-sm text-muted">Reusable reply templates. Agents can insert them in the ticket reply box.</p>
    <div class="divide-y divide-border px-2 py-3">
      <?php if (!$canned): ?><p class="px-3 py-6 text-center text-sm text-muted">No canned responses yet.</p><?php endif; ?>
      <?php foreach ($canned as $c): $cs = $s('canned-' . $c['id']); ?>
        <div data-canned-row>
          <div class="flex items-start gap-3 px-3 py-3" <?= $cs && isset($cs['error']) ? 'hidden' : '' ?> data-canned-view>
            <div class="min-w-0 flex-1">
              <p class="text-sm font-medium"><?= e($c['title']) ?></p>
              <div class="rich mt-1 line-clamp-2 text-xs text-muted"><?= $c['body'] ?></div>
            </div>
            <button type="button" data-canned-edit class="inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer" aria-label="Edit"><?= icon('pencil', 15) ?></button>
            <?= $deleteForm('canned-delete', $c['id'], true, 'Delete', 'Delete') ?>
          </div>
          <form method="post" action="/action/canned-update" data-canned-form class="space-y-3 px-3 py-3" <?= $cs && isset($cs['error']) ? '' : 'hidden' ?>>
            <?= form_meta('canned-' . $c['id']) ?>
            <?= inline_error($cs) ?>
            <input type="hidden" name="id" value="<?= e($c['id']) ?>">
            <input name="title" value="<?= e($c['title']) ?>" required class="<?= INPUT_CLS ?>">
            <?= rich_editor('body', ['minHeight' => 100, 'html' => $c['body']]) ?>
            <div class="flex gap-2">
              <button type="submit" class="<?= btn_cls() ?>">Save</button>
              <button type="button" data-canned-cancel class="<?= btn_cls('secondary') ?>"><?= icon('x', 15) ?> Cancel</button>
            </div>
          </form>
        </div>
      <?php endforeach; ?>
    </div>
    <?php $cn = $s('canned-new'); ?>
    <form method="post" action="/action/canned-create" class="space-y-3 border-t border-border p-5">
      <?= form_meta('canned-new') ?>
      <?= inline_error($cn) ?>
      <div>
        <label for="c-title" class="<?= LABEL_CLS ?>">New template title</label>
        <input id="c-title" name="title" placeholder="e.g. Asking for more details" required value="<?= e(isset($cn['error']) ? old('canned-new', 'title') : '') ?>" class="<?= INPUT_CLS ?>">
      </div>
      <div>
        <label class="<?= LABEL_CLS ?>">Body</label>
        <?= rich_editor('body', ['minHeight' => 100, 'placeholder' => 'Template text…', 'html' => isset($cn['error']) ? sanitize_rich_html(old('canned-new', 'body')) : '']) ?>
      </div>
      <button type="submit" class="<?= btn_cls() ?>">Add template</button>
    </form>
  </div>
  <?php endif; ?>
  <?php if (can($me, 'settings.macros')): ?>
  <!-- Macros -->
  <?php
  $macros = q_all('SELECT * FROM "Macro" WHERE "tenantId" = ? ORDER BY "name" ASC', [$tenantId]);
  $ms = $s('macro');
  $statusLabel = array_column($statuses, 'label', 'key');
  $prioLabel = array_column($priorities, 'label', 'key');
  $agentName = array_column($agents, 'name', 'id');
  $tagName = array_column($tags, 'name', 'id');
  ?>
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header(titled('wand-sparkles', 'Macros')) ?>
    <p class="px-6 pt-4 text-sm text-muted">One click on a ticket posts a reply (or internal note) and changes its fields — e.g. “Ask for details → Pending”. Replies can use
      <?php foreach (MACRO_PLACEHOLDERS as $ph => $hint): ?><code class="rounded bg-surface-2 px-1 text-xs" title="<?= e($hint) ?>"><?= e($ph) ?></code> <?php endforeach; ?></p>
    <div class="divide-y divide-border px-2 py-3">
      <?php if (!$macros): ?><p class="px-3 py-6 text-center text-sm text-muted">No macros yet.</p><?php endif; ?>
      <?php foreach ($macros as $m):
          $bits = [];
          if ($m['body']) $bits[] = $m['isInternal'] ? 'internal note' : 'reply';
          if ($m['setStatus']) $bits[] = 'status → ' . ($statusLabel[$m['setStatus']] ?? $m['setStatus']);
          if ($m['setPriority']) $bits[] = 'priority → ' . ($prioLabel[$m['setPriority']] ?? $m['setPriority']);
          if ($m['setAssignee']) $bits[] = 'assign → ' . ($m['setAssignee'] === 'me' ? 'the agent using it' : ($m['setAssignee'] === 'none' ? 'nobody' : ($agentName[$m['setAssignee']] ?? '?')));
          if ($m['addTagId']) $bits[] = 'tag #' . ($tagName[$m['addTagId']] ?? '?'); ?>
        <div class="flex items-start gap-3 px-3 py-3">
          <span class="mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-brand/10 text-brand"><?= icon('wand-sparkles', 15) ?></span>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium"><?= e($m['name']) ?></p>
            <p class="text-xs text-muted"><?= e(implode(' · ', $bits)) ?></p>
          </div>
          <?= $deleteForm('macro-delete', $m['id'], true, 'Delete macro', 'Delete macro') ?>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/action/macro-create" class="space-y-4 border-t border-border p-5">
      <?= form_meta('macro') ?>
      <?= inline_error($ms) ?><?= inline_success($ms) ?>
      <div>
        <label for="m-name" class="<?= LABEL_CLS ?>">Macro name</label>
        <input id="m-name" name="name" placeholder="e.g. Ask for details → Pending" required class="<?= INPUT_CLS ?>">
      </div>
      <div>
        <label class="<?= LABEL_CLS ?>">Reply (optional)</label>
        <?= rich_editor('body', ['minHeight' => 90, 'placeholder' => 'Hi {{requester.first_name}}, …']) ?>
        <label class="mt-2 flex items-center gap-2 text-sm text-muted cursor-pointer select-none">
          <input type="checkbox" name="isInternal" class="h-4 w-4 rounded border-border accent-amber-500"> Post as internal note
        </label>
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="<?= LABEL_CLS ?>">Set status</label>
          <select name="setStatus" class="<?= SELECT_CLS ?>"><?= select_options(['' => 'Don’t change'] + $statusLabel, '') ?></select>
        </div>
        <div>
          <label class="<?= LABEL_CLS ?>">Set priority</label>
          <select name="setPriority" class="<?= SELECT_CLS ?>"><?= select_options(['' => 'Don’t change'] + $prioLabel, '') ?></select>
        </div>
        <div>
          <label class="<?= LABEL_CLS ?>">Assign to</label>
          <select name="setAssignee" class="<?= SELECT_CLS ?>"><?= select_options(['' => 'Don’t change', 'me' => 'The agent using it', 'none' => 'Unassign'] + $agentName, '') ?></select>
        </div>
        <div>
          <label class="<?= LABEL_CLS ?>">Add tag</label>
          <select name="addTagId" class="<?= SELECT_CLS ?>"><?= select_options(['' => 'None'] + array_map(fn ($n) => "#$n", $tagName), '') ?></select>
        </div>
      </div>
      <button type="submit" class="<?= btn_cls('secondary') ?>"><?= icon('plus', 16) ?> Add macro</button>
    </form>
  </div>
  <?php endif; ?>
  <?php if (can($me, 'settings.templates')): ?>
    <?php require ROOT . '/src/views/partials/templates_admin.php'; ?>
  <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($sec('automations')): ?>
  <section id="automations" data-settings-section="automations" class="scroll-mt-24 space-y-6">
  <!-- Automations -->
  <?php
  $opt = fn ($rows, $v, $l) => array_map(fn ($r) => ['value' => $r[$v], 'label' => $r[$l]], $rows);
  $ruleOpts = [
      'priority' => $opt($priorities, 'key', 'label'),
      'category' => $opt($categories, 'id', 'name'),
      'ASSIGN' => $opt($agents, 'id', 'name'),
      'SET_PRIORITY' => $opt($priorities, 'key', 'label'),
      'SET_STATUS' => $opt($statuses, 'key', 'label'),
      'ADD_TAG' => $opt($tags, 'id', 'name'),
  ];
  $labelFor = function (array $opts, ?string $v): string {
      foreach ($opts as $o) if ($o['value'] === $v) return $o['label'];
      return (string) $v;
  };
  $actionLabels = ['ASSIGN' => 'Assign to', 'SET_PRIORITY' => 'Set priority', 'SET_STATUS' => 'Set status', 'ADD_TAG' => 'Add tag'];
  $rs = $s('rule');
  ?>
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header(titled('zap', 'Automations')) ?>
    <p class="px-6 pt-4 text-sm text-muted">Run actions automatically when a ticket is created.</p>
    <div class="divide-y divide-border px-2 py-3">
      <?php if (!$rules): ?><p class="px-3 py-6 text-center text-sm text-muted">No rules yet.</p><?php endif; ?>
      <?php foreach ($rules as $r):
          $cond = $r['conditionField'] === 'priority' ? ' & priority is ' . $labelFor($ruleOpts['priority'], $r['conditionValue'])
              : ($r['conditionField'] === 'category' ? ' & category is ' . $labelFor($ruleOpts['category'], $r['conditionValue']) : '');
          $then = ($actionLabels[$r['actionType']] ?? $r['actionType']) . ' ' . $labelFor($ruleOpts[$r['actionType']] ?? [], $r['actionValue']); ?>
        <div class="flex flex-wrap items-center gap-3 px-3 py-3">
          <form method="post" action="/action/rule-toggle" data-ajax class="inline-flex">
            <?= form_meta() ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><input type="hidden" name="enabled" value="<?= $r['enabled'] ? 'false' : 'true' ?>">
            <label class="inline-flex cursor-pointer items-center">
              <input type="checkbox" <?= $r['enabled'] ? 'checked' : '' ?> data-submit-on-change class="h-4 w-4 rounded border-border accent-brand">
            </label>
          </form>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium"><?= e($r['name']) ?></p>
            <p class="flex flex-wrap items-center gap-1.5 text-xs text-muted">When ticket created<?= e($cond) ?> <?= icon('arrow-right', 11) ?> <span class="text-brand"><?= e($then) ?></span></p>
          </div>
          <?= $deleteForm('rule-delete', $r['id'], true, 'Delete rule', 'Delete rule') ?>
        </div>
      <?php endforeach; ?>
    </div>
    <form method="post" action="/action/rule-create" class="space-y-4 border-t border-border p-5" data-rule-form data-options="<?= json_attr($ruleOpts) ?>">
      <?= form_meta('rule') ?>
      <?= inline_error($rs) ?><?= inline_success($rs) ?>
      <div>
        <label for="r-name" class="<?= LABEL_CLS ?>">Rule name</label>
        <input id="r-name" name="name" placeholder="e.g. Route urgent to Dana" required class="<?= INPUT_CLS ?>">
      </div>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="<?= LABEL_CLS ?>">Condition (optional)</label>
          <select name="conditionField" data-rule-cond class="<?= SELECT_CLS ?>"><?= select_options(['' => 'Any ticket', 'priority' => 'Priority is…', 'category' => 'Category is…'], '') ?></select>
        </div>
        <div>
          <label class="<?= LABEL_CLS ?>">Condition value</label>
          <select name="conditionValue" data-rule-cond-value disabled class="<?= SELECT_CLS ?>"><option value="">—</option></select>
        </div>
        <div>
          <label class="<?= LABEL_CLS ?>">Action</label>
          <select name="actionType" data-rule-action class="<?= SELECT_CLS ?>"><?= select_options($actionLabels, 'ASSIGN') ?></select>
        </div>
        <div>
          <label class="<?= LABEL_CLS ?>">Action target</label>
          <select name="actionValue" data-rule-action-value required class="<?= SELECT_CLS ?>">
            <option value="">Choose…</option>
            <?php foreach ($ruleOpts['ASSIGN'] as $o): ?><option value="<?= e($o['value']) ?>"><?= e($o['label']) ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <button type="submit" class="<?= btn_cls('secondary') ?>">Add rule</button>
    </form>
  </div>
  </section>
  <?php endif; ?>

  <?php if ($sec('notifications')): ?>
  <section id="notifications" data-settings-section="notifications" class="scroll-mt-24 space-y-6">
  <?php if (can($me, 'settings.notifications')): ?>
  <!-- Notification policy -->
  <?php $ps = $s('policy'); ?>
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header(titled('bell-ring', 'Notification policy')) ?>
    <form method="post" action="/action/policy-save" class="space-y-5 p-6">
      <?= form_meta('policy') ?>
      <div>
        <p class="text-sm font-semibold">Allowed channels (org-wide)</p>
        <p class="mb-2 text-xs text-muted">Disabling a channel turns it off for everyone, overriding personal settings.</p>
        <div class="flex flex-wrap gap-4">
          <?php foreach ([['allowInApp', 'In-app'], ['allowPush', 'Push'], ['allowEmail', 'Email']] as [$n, $l]): ?>
            <label class="flex items-center gap-2 text-sm cursor-pointer select-none">
              <input type="checkbox" name="<?= $n ?>" <?= $policy[$n] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> <?= $l ?>
            </label>
          <?php endforeach; ?>
        </div>
      </div>
      <div>
        <p class="text-sm font-semibold">Defaults for new users</p>
        <p class="mb-2 text-xs text-muted">Applied until a user customizes their own preferences.</p>
        <div class="overflow-x-auto rounded-lg border border-border">
          <table class="w-full min-w-xl text-sm">
            <thead><tr class="text-xs text-muted">
              <th class="px-3 py-2 text-left font-medium">Event</th><th class="px-3 py-2 font-medium">In-app</th><th class="px-3 py-2 font-medium">Push</th><th class="px-3 py-2 font-medium">Email</th>
            </tr></thead>
            <tbody>
              <?php foreach (NOTIFY_CATEGORIES as $c): ?>
                <tr class="border-t border-border">
                  <td class="px-3 py-2.5"><?= e(CATEGORY_LABELS[$c]) ?></td>
                  <?php foreach (CHANNELS as $ch): ?>
                    <td class="px-3 py-2.5 text-center"><input type="checkbox" name="def_<?= $c ?>_<?= $ch ?>" <?= $policy['defaults'][$c][$ch] ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"></td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <button type="submit" class="<?= btn_cls() ?>">Save policy</button>
        <?php if (!empty($ps['success'])): ?><span class="inline-flex items-center gap-1.5 text-sm text-emerald-600 dark:text-emerald-400"><?= icon('circle-check', 15) ?> <?= e($ps['success']) ?></span><?php endif; ?>
      </div>
    </form>
  </div>

  <?php endif; ?>
  <?php if (can($me, 'settings.email')): ?>
  <!-- Email -->
  <?php
  $es = $s('email');
  $et = $s('email-test');
  $ev = fn ($k, $d = '') => isset($es['error']) ? old('email', $k, (string) ($email[$k] ?? $d)) : (string) ($email[$k] ?? $d);
  $provider = $ev('provider', 'SMTP');
  $pwSet = is_encrypted($email['smtpPassword'] ?? null);
  $secretSet = is_encrypted($email['msClientSecret'] ?? null);
  ?>
  <div class="rounded-xl border border-border bg-surface">
    <?= card_header(titled('mail', 'Email notifications')) ?>
    <form method="post" action="/action/email-save" class="space-y-5 p-6" data-email-form>
      <?= form_meta('email') ?>
      <?= banner($es, true) ?>
      <label class="flex items-center gap-3 rounded-lg border border-border bg-surface-2 px-3 py-2.5 cursor-pointer select-none">
        <input type="checkbox" name="enabled" <?= !empty($email['enabled']) ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand">
        <span>
          <span class="block text-sm font-medium">Enable email notifications</span>
          <span class="block text-xs text-muted">Send ticket updates to requesters, assignees and the team by email.</span>
        </span>
      </label>
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label for="provider" class="<?= LABEL_CLS ?>">Provider</label>
          <select id="provider" name="provider" data-email-provider class="<?= SELECT_CLS ?>"><?= select_options(['SMTP' => 'SMTP server', 'MICROSOFT365' => 'Microsoft 365'], $provider) ?></select>
        </div>
        <div>
          <label for="fromEmail" class="<?= LABEL_CLS ?>">From email</label>
          <input id="fromEmail" name="fromEmail" type="email" placeholder="support@company.com" value="<?= e($ev('fromEmail')) ?>" class="<?= INPUT_CLS ?>">
        </div>
        <div class="sm:col-span-2">
          <label for="fromName" class="<?= LABEL_CLS ?>">From name</label>
          <input id="fromName" name="fromName" placeholder="Acme Support" value="<?= e($ev('fromName')) ?>" class="<?= INPUT_CLS ?>">
        </div>
      </div>

      <fieldset data-provider-panel="SMTP" class="space-y-4" <?= $provider === 'SMTP' ? '' : 'hidden' ?>>
        <legend class="text-sm font-semibold">SMTP server</legend>
        <div class="grid gap-4 sm:grid-cols-3">
          <div class="sm:col-span-2">
            <label for="smtpHost" class="<?= LABEL_CLS ?>">Host</label>
            <input id="smtpHost" name="smtpHost" placeholder="smtp.company.com" value="<?= e($ev('smtpHost')) ?>" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="smtpPort" class="<?= LABEL_CLS ?>">Port</label>
            <input id="smtpPort" name="smtpPort" type="number" placeholder="587" value="<?= e($ev('smtpPort')) ?>" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="smtpUser" class="<?= LABEL_CLS ?>">Username</label>
            <input id="smtpUser" name="smtpUser" placeholder="apikey / user" value="<?= e($ev('smtpUser')) ?>" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="smtpPassword" class="<?= LABEL_CLS ?>">Password</label>
            <input id="smtpPassword" name="smtpPassword" type="password" autocomplete="new-password" placeholder="<?= $pwSet ? '•••••••• (set — leave blank to keep)' : 'Password' ?>" class="<?= INPUT_CLS ?>">
          </div>
          <label class="flex items-center gap-2 text-sm text-muted cursor-pointer self-end pb-2.5">
            <input type="checkbox" name="smtpSecure" <?= ($email['smtpSecure'] ?? 1) ? 'checked' : '' ?> class="h-4 w-4 rounded border-border accent-brand"> Use TLS/SSL
          </label>
        </div>
      </fieldset>

      <fieldset data-provider-panel="MICROSOFT365" class="space-y-4" <?= $provider === 'MICROSOFT365' ? '' : 'hidden' ?>>
        <legend class="text-sm font-semibold">Microsoft 365 (Graph)</legend>
        <p class="text-xs text-muted">Create an Entra ID app registration with the <code>Mail.Send</code> application permission (admin consent), then paste its details here.</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div>
            <label for="msTenantId" class="<?= LABEL_CLS ?>">Directory (tenant) ID</label>
            <input id="msTenantId" name="msTenantId" placeholder="00000000-0000-0000-0000-000000000000" value="<?= e($ev('msTenantId')) ?>" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="msClientId" class="<?= LABEL_CLS ?>">Application (client) ID</label>
            <input id="msClientId" name="msClientId" placeholder="00000000-0000-0000-0000-000000000000" value="<?= e($ev('msClientId')) ?>" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="msClientSecret" class="<?= LABEL_CLS ?>">Client secret</label>
            <input id="msClientSecret" name="msClientSecret" type="password" autocomplete="new-password" placeholder="<?= $secretSet ? '•••••••• (set — leave blank to keep)' : 'Client secret value' ?>" class="<?= INPUT_CLS ?>">
          </div>
          <div>
            <label for="msSender" class="<?= LABEL_CLS ?>">Send as (mailbox)</label>
            <input id="msSender" name="msSender" type="email" placeholder="support@company.com" value="<?= e($ev('msSender')) ?>" class="<?= INPUT_CLS ?>">
          </div>
        </div>
      </fieldset>

      <div class="flex items-center gap-3 pt-1">
        <button type="submit" class="<?= btn_cls() ?>">Save email settings</button>
      </div>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-3 border-t border-border px-6 py-4">
      <p class="text-xs text-muted">Send a test email to yourself using the currently saved settings.</p>
      <form method="post" action="/action/email-test" data-pending="Sending…">
        <?= form_meta('email-test') ?>
        <button type="submit" class="<?= btn_cls('outline') ?>"><?= icon('send', 15) ?> Send test email</button>
      </form>
    </div>
    <?php if ($et): ?><div class="px-6 pb-5"><?= banner($et, true) ?></div><?php endif; ?>
  </div>
  <?php endif; ?>
  </section>
  <?php endif; ?>

  <?php if ($sec('fields')): ?>
  <section id="fields" data-settings-section="fields" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/fields_admin.php'; ?>
  </section>
  <?php endif; ?>
  <?php if ($sec('integrations')): ?>
  <section id="integrations" data-settings-section="integrations" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/integrations_admin.php'; ?>
  </section>
  <?php endif; ?>

  <?php if ($canUsers): ?>
  <section id="users" data-settings-section="users" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/users_admin.php'; ?>
  </section>
  <?php endif; ?>
  <?php if ($canGroups): ?>
  <section id="groups" data-settings-section="groups" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/groups_admin.php'; ?>
  </section>
  <?php endif; ?>
  <?php if ($canOrgs): ?>
  <section id="orgs" data-settings-section="orgs" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/orgs_admin.php'; ?>
  </section>
  <?php endif; ?>

  <?php if ($canRoles): ?>
  <section id="roles" data-settings-section="roles" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/roles_admin.php'; ?>
  </section>
  <?php endif; ?>
  <?php if ($canSecurity): ?>
  <section id="security" data-settings-section="security" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/security_admin.php'; ?>
  </section>
  <?php endif; ?>
  <?php if (can($me, 'audit.view')): ?>
  <section id="audit" data-settings-section="audit" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/audit_admin.php'; ?>
  </section>
  <?php endif; ?>

  <?php if ($me['role'] === 'SUPER_ADMIN'): ?>
  <section id="branding" data-settings-section="branding" class="scroll-mt-24 space-y-6">
    <?php require ROOT . '/src/views/partials/branding_admin.php'; ?>
  </section>
  <?php endif; ?>
</div>
</div>
