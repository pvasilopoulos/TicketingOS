<?php
/**
 * Settings → Tags & replies → Ticket templates.
 * @var array $me @var string $tenantId @var callable $s @var array $categories @var array $priorities @var array $agents
 */
$tplState = $s('template');
$tpls = q_all('SELECT * FROM "TicketTemplate" WHERE "tenantId" = ? ORDER BY "order", "name"', [$tenantId]);
$catNames = array_column($categories, 'name', 'id');
$prioOpts = array_column($priorities, 'label', 'key');
$dec = fn ($v) => json_decode((string) $v, true) ?: [];
$tplForm = function (?array $t) use ($categories, $prioOpts, $agents, $dec): string {
    $id = $t['id'] ?? 'new';
    $chosen = $t ? $dec($t['assigneeIds']) : [];
    $h = '<form method="post" action="/action/template-save" class="space-y-3" data-pending="Saving…">' . form_meta('template', '/settings#replies')
        . ($t ? '<input type="hidden" name="id" value="' . e($t['id']) . '">' : '')
        . '<div class="grid gap-3 sm:grid-cols-2">'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Template name</label><input name="name" required maxlength="80" value="' . e($t['name'] ?? '') . '" placeholder="New employee setup" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Who can use it</label><select name="audience" class="' . SELECT_CLS . '">' . select_options(TEMPLATE_AUDIENCES, $t['audience'] ?? 'everyone') . '</select></div>'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Category</label><select name="categoryId" class="' . SELECT_CLS . '">' . select_options(['' => '— Keep as chosen —'] + array_column($categories, 'name', 'id'), $t['categoryId'] ?? '') . '</select></div>'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Priority</label><select name="priority" class="' . SELECT_CLS . '">' . select_options(['' => '— Keep as chosen —'] + $prioOpts, $t['priority'] ?? '') . '</select></div>'
        . '<div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-muted">Subject</label><input name="subject" maxlength="140" value="' . e($t['subject'] ?? '') . '" placeholder="New employee: [name]" class="' . INPUT_CLS . '"></div>'
        . '<div class="sm:col-span-2"><label class="mb-1 block text-xs font-medium text-muted">Description</label>' . rich_editor('body', ['minHeight' => 100, 'html' => sanitize_rich_html((string) ($t['body'] ?? '')), 'placeholder' => 'Pre-filled text, e.g. a checklist of what to include']) . '</div>'
        . '</div>'
        . '<details class="rounded-lg border border-border p-3"><summary class="cursor-pointer text-xs font-semibold text-muted">Staff-only: tags, assignees and sub-tickets</summary><div class="mt-3 space-y-3">'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Tags (comma separated)</label><input name="tags" value="' . e(implode(', ', $t ? $dec($t['tags']) : [])) . '" placeholder="onboarding, hr" class="' . INPUT_CLS . '"></div>'
        . '<div><p class="mb-1 text-xs font-medium text-muted">Assign to</p><div class="grid max-h-40 gap-1 overflow-y-auto rounded-lg border border-border p-1 sm:grid-cols-2">';
    foreach ($agents as $ag) {
        $h .= '<label class="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1 text-sm hover:bg-surface-2"><input type="checkbox" name="assigneeIds[]" value="' . e($ag['id']) . '" ' . (in_array($ag['id'], $chosen, true) ? 'checked' : '') . ' class="h-4 w-4 rounded border-border accent-brand">' . e($ag['name']) . '</label>';
    }
    $h .= '</div></div>'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Sub-tickets (one per line)</label><textarea name="subtasks" rows="3" class="w-full rounded-lg border border-border bg-surface px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40" placeholder="Create email account&#10;Order laptop">' . e(implode("\n", $t ? $dec($t['subtasks']) : [])) . '</textarea></div>'
        . '<p class="text-[11px] text-muted">Applied only when staff create the ticket — customers never see them.</p>'
        . '</div></details>'
        . '<div class="flex gap-2"><button type="submit" class="' . btn_cls('primary', 'sm') . '">' . icon('check', 14) . ($t ? ' Save template' : ' Add template') . '</button>'
        . '<button type="button" data-toggle-target="#tpl-' . e($id) . '" class="' . btn_cls('ghost', 'sm') . '">Cancel</button></div></form>';
    return $h;
};
?>
<div class="rounded-xl border border-border bg-surface" id="templates">
  <?= card_header(titled('layers', 'Ticket templates'), '<button type="button" data-toggle-target="#tpl-new" class="' . btn_cls('outline', 'sm') . '">' . icon('plus', 13) . ' New template</button>') ?>
  <p class="px-5 text-xs text-muted">Ready-made tickets people can start from on the new-ticket form: subject, description, category and priority — plus tags, assignees and sub-tickets when staff use them.</p>
  <?php if ($tplState): ?><div class="px-5 pt-3"><?= banner($tplState, true) ?></div><?php endif; ?>
  <div id="tpl-new" class="m-5 rounded-xl border border-border bg-surface-2/40 p-4" <?= isset($tplState['error']) ? '' : 'hidden' ?>><?= $tplForm(null) ?></div>
  <div class="divide-y divide-border px-2 py-3">
    <?php if (!$tpls): ?><p class="px-3 py-6 text-center text-sm text-muted">No templates yet.</p><?php endif; ?>
    <?php foreach ($tpls as $t): $nTags = count($dec($t['tags'])); $nAs = count($dec($t['assigneeIds'])); $nSub = count($dec($t['subtasks'])); ?>
      <div class="px-3 py-3">
        <div class="flex flex-wrap items-center gap-2">
          <span class="min-w-0 flex-1"><span class="block truncate text-sm font-medium"><?= e($t['name']) ?></span>
            <span class="block truncate text-xs text-muted"><?= e($t['subject'] ?: 'No subject') ?><?= $t['categoryId'] && isset($catNames[$t['categoryId']]) ? ' · ' . e($catNames[$t['categoryId']]) : '' ?><?= $nTags ? " · $nTags tag" . ($nTags > 1 ? 's' : '') : '' ?><?= $nAs ? " · $nAs assignee" . ($nAs > 1 ? 's' : '') : '' ?><?= $nSub ? " · $nSub sub-ticket" . ($nSub > 1 ? 's' : '') : '' ?></span></span>
          <span class="rounded-full px-2 py-0.5 text-[11px] font-medium <?= $t['audience'] === 'staff' ? 'bg-amber-500/10 text-amber-700 dark:text-amber-400' : 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400' ?>"><?= e(TEMPLATE_AUDIENCES[$t['audience']] ?? $t['audience']) ?></span>
          <button type="button" data-toggle-target="#tpl-<?= e($t['id']) ?>" class="<?= btn_cls('ghost', 'sm') ?>"><?= icon('pen-line', 13) ?> Edit</button>
          <form method="post" action="/action/template-delete" data-ajax data-confirm="Delete the template “<?= e($t['name']) ?>”?"><?= form_meta('template') ?><input type="hidden" name="id" value="<?= e($t['id']) ?>">
            <button type="submit" aria-label="Delete <?= e($t['name']) ?>" class="<?= ICON_BTN_DANGER ?>"><?= icon('trash-2', 15) ?></button></form>
        </div>
        <div id="tpl-<?= e($t['id']) ?>" hidden class="mt-3 rounded-xl border border-border bg-surface-2/40 p-4"><?= $tplForm($t) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
