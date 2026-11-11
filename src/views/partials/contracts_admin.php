<?php
/** Support contracts of one organization (inside Settings → Organizations). @var array $sel @var array $me */
$contracts = org_contracts($sel['id']);
$canEdit = can($me, 'contracts.manage');
if (!$contracts && !$canEdit) return;
$contractForm = function (?array $c) use ($sel): string {
    $v = fn (string $k, string $d = '') => e((string) ($c[$k] ?? $d));
    $hours = $c && $c['minutesIncluded'] !== null ? rtrim(rtrim(number_format($c['minutesIncluded'] / 60, 2, '.', ''), '0'), '.') : '';
    return '<form method="post" action="/action/contract-save" class="space-y-3">' . form_meta('contracts', '/settings?org=' . e($sel['id']) . '#orgs')
        . '<input type="hidden" name="organizationId" value="' . e($sel['id']) . '">' . ($c ? '<input type="hidden" name="id" value="' . e($c['id']) . '">' : '')
        . '<div class="grid gap-3 sm:grid-cols-2">'
        . '<div class="sm:col-span-2"><label class="' . LABEL_CLS . '">Name</label><input name="name" required maxlength="120" value="' . $v('name') . '" placeholder="Gold support 2027" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="' . LABEL_CLS . '">Starts</label><input type="date" name="startsOn" required value="' . $v('startsOn', date('Y-m-d')) . '" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="' . LABEL_CLS . '">Ends <span class="font-normal text-muted">(optional)</span></label><input type="date" name="endsOn" value="' . $v('endsOn') . '" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="' . LABEL_CLS . '">Hours included <span class="font-normal text-muted">(empty = unlimited)</span></label><input name="hours" inputmode="decimal" value="' . e($hours) . '" placeholder="20" class="' . INPUT_CLS . '"></div>'
        . '<div class="grid grid-cols-2 gap-2"><div><label class="' . LABEL_CLS . '">Reply within (h)</label><input type="number" min="1" name="firstResponseHours" value="' . $v('firstResponseHours') . '" class="' . INPUT_CLS . '"></div>'
        . '<div><label class="' . LABEL_CLS . '">Solve within (h)</label><input type="number" min="1" name="resolutionHours" value="' . $v('resolutionHours') . '" class="' . INPUT_CLS . '"></div></div>'
        . '<div class="sm:col-span-2"><label class="' . LABEL_CLS . '">Notes</label><textarea name="notes" rows="2" maxlength="2000" class="' . INPUT_CLS . ' h-auto py-2">' . $v('notes') . '</textarea></div></div>'
        . '<p class="text-xs text-muted">“Solve within” sets the deadline of new tickets from this organization instead of the workspace SLA. Billable time logged on their tickets counts against the hours.</p>'
        . '<div class="flex flex-wrap items-center gap-3"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="active" ' . (!$c || (int) $c['active'] ? 'checked' : '') . ' class="h-4 w-4 rounded border-border accent-brand"> Active</label>'
        . '<button type="submit" class="' . btn_cls('primary', 'sm', 'ml-auto') . '">' . icon('check', 14) . ($c ? ' Save' : ' Add contract') . '</button></div></form>';
};
?>
<div class="border-t border-border p-5">
  <div class="mb-3 flex items-center justify-between gap-3">
    <p class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide text-muted"><?= icon('file-text', 13) ?> Support contracts</p>
    <?php if ($canEdit): ?><button type="button" data-toggle-target="#contract-new" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('plus', 13) ?> New contract</button><?php endif; ?>
  </div>
  <?php if ($canEdit): ?><div id="contract-new" class="mb-4 rounded-xl border border-dashed border-border p-4" <?= $contracts ? 'hidden' : '' ?>><?= $contractForm(null) ?></div><?php endif; ?>
  <?php if (!$contracts): ?><p class="text-sm text-muted">No contract yet — add one to track an hour bank and give this organization its own response times.</p><?php endif; ?>
  <div class="space-y-3">
    <?php foreach ($contracts as $c): $st = contract_state($c); $cur = contract_is_current($c); ?>
      <details class="group rounded-xl border border-border">
        <summary class="flex cursor-pointer list-none flex-wrap items-center gap-3 px-4 py-3 [&::-webkit-details-marker]:hidden">
          <span class="<?= cx('h-2.5 w-2.5 rounded-full', !$cur ? 'bg-slate-400' : ['ok' => 'bg-emerald-500', 'low' => 'bg-amber-500', 'over' => 'bg-red-500', 'expired' => 'bg-slate-400'][$st['level']]) ?>"></span>
          <span class="min-w-0 flex-1">
            <span class="block truncate text-sm font-medium"><?= e($c['name']) ?><?= !$cur ? ' <span class="text-xs font-normal text-muted">(' . ((int) $c['active'] ? ($c['startsOn'] > date('Y-m-d') ? 'not started' : 'ended') : 'inactive') . ')</span>' : '' ?></span>
            <span class="block text-xs text-muted"><?= e(date('j M Y', strtotime($c['startsOn']))) ?> – <?= $c['endsOn'] ? e(date('j M Y', strtotime($c['endsOn']))) : 'open-ended' ?>
              · <?= $st['included'] !== null ? e(fmt_minutes($st['used'])) . ' of ' . e(fmt_minutes($st['included'])) . ' used' : e(fmt_minutes($st['used'])) . ' used (unlimited)' ?></span>
          </span>
          <?php if ($st['included'] !== null): ?><span class="w-28"><span class="block h-1.5 overflow-hidden rounded-full bg-surface-2"><span class="block h-full rounded-full <?= $st['level'] === 'ok' ? 'bg-emerald-500' : ($st['level'] === 'low' ? 'bg-amber-500' : 'bg-red-500') ?>" style="width:<?= (int) $st['pct'] ?>%"></span></span></span><?php endif; ?>
          <?= icon('chevron-down', 15, 'text-muted transition-transform group-open:rotate-180') ?>
        </summary>
        <div class="border-t border-border p-4">
          <?php if ($canEdit): ?>
            <?= $contractForm($c) ?>
            <form method="post" action="/action/contract-delete" data-ajax data-confirm="Delete the contract “<?= e($c['name']) ?>”? Logged time stays on the tickets." class="mt-2"><?= form_meta() ?><input type="hidden" name="id" value="<?= e($c['id']) ?>">
              <button type="submit" class="<?= btn_cls('ghost', 'sm') ?> text-red-600"><?= icon('trash-2', 14) ?> Delete contract</button></form>
          <?php else: ?>
            <p class="whitespace-pre-line text-sm text-muted"><?= e((string) $c['notes']) ?: 'No notes.' ?></p>
          <?php endif; ?>
        </div>
      </details>
    <?php endforeach; ?>
  </div>
</div>
