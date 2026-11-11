<?php
/**
 * Settings → Ticket fields & satisfaction (settings.workflow).
 * @var array $me @var string $tenantId @var callable $s
 */
$fields = tenant_fields($tenantId);
$cats = array_column(q_all('SELECT "id","name" FROM "Category" WHERE "tenantId" = ? ORDER BY "name"', [$tenantId]), 'name', 'id');
$used = array_column(q_all('SELECT v."fieldId", COUNT(*) AS n FROM "TicketFieldValue" v JOIN "CustomField" f ON f."id" = v."fieldId" WHERE f."tenantId" = ? GROUP BY v."fieldId"', [$tenantId]), 'n', 'fieldId');
$fs = $s('fields');
$cs = $s('csat');
$csat = csat_settings($tenantId);
$stats = csat_stats($tenantId, now_iso(-30 * 86400));

$fieldForm = function (?array $f) use ($cats): string {
    $type = $f['type'] ?? 'text';
    $h = '<form method="post" action="/action/field-save" class="space-y-4" data-field-form>' . form_meta('fields', '/settings#fields')
        . ($f ? '<input type="hidden" name="id" value="' . e($f['id']) . '">' : '')
        . '<div class="grid gap-3 sm:grid-cols-2">'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Label</label><input name="label" required maxlength="60" value="' . e($f['label'] ?? '') . '" placeholder="e.g. Order number" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="mb-1 block text-xs font-medium text-muted">Show for</label><select name="categoryId" class="' . SELECT_CLS . '">' . select_options(['' => 'All categories'] + $cats, $f['categoryId'] ?? '') . '</select></div></div>'
        . '<div><span class="mb-1.5 block text-xs font-medium text-muted">Type</span><div class="grid grid-cols-2 gap-2 sm:grid-cols-3">';
    foreach (FIELD_TYPES as $k => [$label, $ic]) {
        $h .= '<label class="flex cursor-pointer items-center gap-2 rounded-lg border border-border px-3 py-2 text-sm has-[:checked]:border-brand has-[:checked]:bg-brand/5' . ($f ? ' has-[:disabled]:opacity-50' : '') . '">'
            . '<input type="radio" name="type" value="' . $k . '" ' . ($type === $k ? 'checked' : '') . ' data-field-type class="sr-only">' . icon($ic, 15, 'text-muted') . e($label) . '</label>';
    }
    $h .= '</div></div>'
        . '<div data-field-options ' . ($type === 'select' ? '' : 'hidden') . '><label class="mb-1 block text-xs font-medium text-muted">Options <span class="font-normal">(one per line)</span></label>'
        . '<textarea name="options" rows="4" class="' . str_replace(' h-10', '', INPUT_CLS) . ' py-2" placeholder="Laptop&#10;Phone&#10;Tablet">' . e(implode("\n", $f['opts'] ?? [])) . '</textarea></div>'
        . '<div class="flex flex-wrap gap-x-6 gap-y-2 text-sm">'
        . '<label class="flex items-center gap-2"><input type="checkbox" name="required" ' . ((int) ($f['required'] ?? 0) ? 'checked' : '') . ' class="h-4 w-4 rounded border-border accent-brand"> Required on new tickets</label>'
        . '<label class="flex items-center gap-2"><input type="checkbox" name="customerVisible" ' . ((int) ($f['customerVisible'] ?? 1) ? 'checked' : '') . ' class="h-4 w-4 rounded border-border accent-brand"> Customers can see and fill it</label></div>'
        . '<div class="flex gap-2"><button type="submit" class="' . btn_cls('primary', 'sm') . '">' . icon('check', 14) . ($f ? ' Save field' : ' Add field') . '</button>'
        . ($f ? '' : '<button type="button" data-toggle-target="#field-new" class="' . btn_cls('ghost', 'sm') . '">Cancel</button>') . '</div></form>';
    return $h;
};
?>
<?php if (can($me, 'settings.fields')): ?>
<div class="rounded-xl border border-border bg-surface">
  <div class="flex flex-wrap items-start gap-4 p-5 sm:p-6">
    <span class="hidden h-11 w-11 shrink-0 place-items-center rounded-xl bg-brand/10 text-brand sm:grid"><?= icon('sliders-horizontal', 22) ?></span>
    <div class="min-w-0 flex-1">
      <h2 class="font-semibold">Ticket fields</h2>
      <p class="mt-0.5 text-sm text-muted">Extra information to collect on tickets — order numbers, devices, dates. Fields appear on the new-ticket form, the support form, the ticket sidebar, CSV exports and the API, and are searchable as <code class="rounded bg-surface-2 px-1 text-xs">key:value</code>.</p>
    </div>
    <button type="button" data-toggle-target="#field-new" class="<?= btn_cls('primary', 'sm') ?>"><?= icon('plus', 14) ?> Add field</button>
  </div>
  <?php if ($fs): ?><div class="px-5 pb-5 sm:px-6"><?= banner($fs, true) ?></div><?php endif; ?>
  <div id="field-new" class="border-t border-border bg-surface-2/40 p-5" <?= $fs && isset($fs['error']) ? '' : 'hidden' ?>><?= $fieldForm(null) ?></div>
  <?php if (!$fields): ?>
    <p class="border-t border-border px-5 py-10 text-center text-sm text-muted">No custom fields yet.</p>
  <?php else: ?>
    <ul class="divide-y divide-border border-t border-border">
      <?php foreach ($fields as $i => $f): [$tLabel, $tIcon] = FIELD_TYPES[$f['type']] ?? FIELD_TYPES['text']; ?>
        <li>
          <details class="group">
            <summary class="flex cursor-pointer list-none items-center gap-3 px-4 py-3 sm:px-5">
              <span class="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-surface-2 text-muted"><?= icon($tIcon, 15) ?></span>
              <span class="min-w-0 flex-1">
                <span class="flex flex-wrap items-center gap-x-2 text-sm font-medium"><?= e($f['label']) ?><?php if ((int) $f['required']): ?><span class="text-[10px] font-semibold uppercase text-red-600">Required</span><?php endif; ?><?php if (!(int) $f['customerVisible']): ?><span class="inline-flex items-center gap-1 text-[10px] font-semibold uppercase text-amber-600"><?= icon('lock', 10) ?>Staff only</span><?php endif; ?></span>
                <span class="block truncate text-xs text-muted"><code><?= e($f['key']) ?></code> · <?= e($tLabel) ?> · <?= e($f['categoryId'] ? ($cats[$f['categoryId']] ?? '—') : 'All categories') ?> · <?= (int) ($used[$f['id']] ?? 0) ?> tickets</span>
              </span>
              <span class="flex items-center gap-0.5" onclick="event.stopPropagation()">
                <?php foreach (['up' => 'chevron-up', 'down' => 'chevron-down'] as $dir => $ic): ?>
                  <form method="post" action="/action/field-move" data-ajax><?= form_meta() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>"><input type="hidden" name="dir" value="<?= $dir ?>">
                    <button type="submit" <?= ($dir === 'up' && $i === 0) || ($dir === 'down' && $i === count($fields) - 1) ? 'disabled' : '' ?> aria-label="Move <?= $dir ?>" class="grid h-7 w-7 place-items-center rounded-md text-muted hover:bg-surface-2 hover:text-fg disabled:opacity-30 cursor-pointer"><?= icon($ic, 14) ?></button></form>
                <?php endforeach; ?>
                <form method="post" action="/action/field-delete" data-ajax data-confirm="Delete the field “<?= e($f['label']) ?>” and its values on <?= (int) ($used[$f['id']] ?? 0) ?> ticket(s)?"><?= form_meta() ?><input type="hidden" name="id" value="<?= e($f['id']) ?>">
                  <button type="submit" aria-label="Delete field" class="grid h-7 w-7 place-items-center rounded-md text-muted hover:bg-red-500/10 hover:text-red-600 cursor-pointer"><?= icon('trash-2', 14) ?></button></form>
              </span>
              <?= icon('chevron-down', 16, 'text-muted transition-transform group-open:rotate-180') ?>
            </summary>
            <div class="border-t border-border bg-surface-2/30 p-4 sm:p-5"><?= $fieldForm($f) ?></div>
          </details>
        </li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (can($me, 'settings.csat')): ?>
<!-- Satisfaction surveys -->
<div class="rounded-xl border border-border bg-surface">
  <?= card_header(titled('smile', 'Satisfaction surveys (CSAT)')) ?>
  <form method="post" action="/action/csat-settings" class="space-y-4 p-5 sm:p-6" data-dirty>
    <?= form_meta('csat', '/settings#fields') ?>
    <?= banner($cs, true) ?>
    <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-border p-4">
      <span><span class="block text-sm font-medium">Ask customers to rate resolved tickets</span><span class="block text-xs text-muted">When an agent moves a ticket to a closed status, the customer gets a one-click 1–5 rating by email and on the ticket page. Low ratings (1–2) notify the agent.</span></span>
      <span class="relative mt-0.5 inline-flex shrink-0 items-center">
        <input type="checkbox" name="enabled" <?= $csat['enabled'] ? 'checked' : '' ?> class="peer sr-only">
        <span class="h-5 w-9 rounded-full bg-border transition-colors peer-checked:bg-brand peer-focus-visible:ring-2 peer-focus-visible:ring-brand/40"></span>
        <span class="pointer-events-none absolute left-0.5 top-0.5 h-4 w-4 rounded-full bg-white shadow transition-transform peer-checked:translate-x-4"></span>
      </span>
    </label>
    <div><label class="<?= LABEL_CLS ?>" for="csat-q">Question</label><input id="csat-q" name="question" maxlength="200" value="<?= e($csat['question']) ?>" class="<?= INPUT_CLS ?>"></div>
    <div class="flex flex-wrap items-center gap-3">
      <button type="submit" data-dirty-save disabled class="<?= btn_cls('primary', 'sm') ?>"><?= icon('check', 14) ?> Save</button>
      <span class="text-xs text-muted">Last 30 days: <?= $stats['count'] ? '<strong class="text-fg">' . $stats['pct'] . '% satisfied</strong> · ' . $stats['count'] . ' ratings · avg ' . $stats['avg'] . '/5' : 'no ratings yet' ?></span>
    </div>
  </form>
</div>
<?php endif; ?>
