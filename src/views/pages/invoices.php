<?php
/** Invoices list (staff with "Invoices"; organization managers see their organization's issued invoices). */
[$user, $tenantId] = require_tenant_user();
$staff = is_staff($user['role']);
if ($staff && !can($user, 'invoices.manage')) redirect('/dashboard');
page_title('Invoices');
$cfg = invoice_settings($tenantId);
$bset = billing_settings($tenantId);
$status = isset(INVOICE_STATUSES[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$orgF = (string) ($_GET['org'] ?? '');
$where = ['i."tenantId" = ?'];
$p = [$tenantId];
if (!$staff) { $where[] = 'i."status" <> \'draft\' AND i."organizationId" IN (SELECT "organizationId" FROM "UserOrganization" WHERE "userId" = ? AND "isManager" = 1)'; $p[] = $user['id']; }
if ($status !== '') { $where[] = 'i."status" = ?'; $p[] = $status; }
if ($orgF !== '') { $where[] = 'i."organizationId" = ?'; $p[] = $orgF; }
$per = 50;
$total = (int) q_val('SELECT COUNT(*) FROM "Invoice" i WHERE ' . implode(' AND ', $where), $p);
$pages = max(1, (int) ceil($total / $per));
$page = min(max(1, (int) ($_GET['page'] ?? 1)), $pages);
$rows = q_all('SELECT i.*, o."name" AS "orgName" FROM "Invoice" i JOIN "Organization" o ON o."id" = i."organizationId" WHERE ' . implode(' AND ', $where)
    . ' ORDER BY CASE i."status" WHEN \'draft\' THEN 0 ELSE 1 END, i."issueDate" DESC, i."seq" DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
$sum = fn (string $st, string $since = '') => (float) q_val('SELECT COALESCE(SUM(i."total"), 0) FROM "Invoice" i WHERE ' . implode(' AND ', array_merge($where, ['i."status" = ?'])) . ($since ? ' AND i."issueDate" >= ?' : ''), array_merge($p, [$st], $since ? [$since] : []));
$orgs = $staff ? tenant_orgs($tenantId) : q_all('SELECT o."id", o."name" FROM "Organization" o JOIN "UserOrganization" uo ON uo."organizationId" = o."id" WHERE uo."userId" = ? AND uo."isManager" = 1', [$user['id']]);
$badge = ['draft' => 'bg-amber-500/10 text-amber-700 dark:text-amber-400', 'issued' => 'bg-sky-500/10 text-sky-700 dark:text-sky-400', 'paid' => 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400', 'void' => 'bg-surface-2 text-muted line-through'];
$url = fn (array $c) => '/invoices?' . http_build_query(array_filter(['status' => $status, 'org' => $orgF, 'page' => ($c['page'] ?? 1) > 1 ? $c['page'] : null]));
$prevMonth = date('Y-m', strtotime('first day of last month'));
?>
<div>
  <?= page_header('Invoices', $staff ? 'Bill organizations for their billable time. Drafts can be edited; issued documents are numbered and locked.' : 'Invoices of your organization.') ?>

  <div class="mb-5 grid gap-3 sm:grid-cols-3">
    <?php foreach ([['Outstanding', $sum('issued'), 'clock', 'text-sky-600'], ['Paid this year', $sum('paid', date('Y') . '-01-01'), 'circle-check', 'text-emerald-600'], ['Drafts', $staff ? $sum('draft') : 0.0, 'pencil', 'text-amber-600']] as [$label, $amount, $ic, $tone]): if (!$staff && $label === 'Drafts') continue; ?>
      <div class="flex items-center gap-3 rounded-xl border border-border bg-surface px-4 py-3">
        <span class="grid h-9 w-9 shrink-0 place-items-center rounded-lg bg-surface-2 <?= $tone ?>"><?= icon($ic, 17) ?></span>
        <div><p class="text-lg font-semibold tabular-nums"><?= e(money($amount, $bset['currency'])) ?></p><p class="text-xs text-muted"><?= $label ?></p></div>
      </div>
    <?php endforeach; ?>
  </div>

  <?php if ($staff): ?>
    <form method="post" action="/action/invoice-create" data-ajax class="mb-5 flex flex-wrap items-end gap-3 rounded-xl border border-border bg-surface p-4">
      <?= form_meta() ?>
      <div class="min-w-0 flex-1 basis-56"><label class="<?= LABEL_CLS ?>">Organization</label>
        <select name="organizationId" required class="<?= SELECT_CLS ?>"><?= select_options(['' => 'Choose…'] + array_column($orgs, 'name', 'id'), '') ?></select></div>
      <div><label class="<?= LABEL_CLS ?>">Billable time of</label><input type="month" name="month" value="<?= e($prevMonth) ?>" class="<?= INPUT_CLS ?>"></div>
      <button type="submit" class="<?= btn_cls() ?>"><?= icon('plus', 15) ?> New invoice</button>
      <p class="w-full text-xs text-muted">Creates a draft with the organization’s billable time of that month that isn’t on another invoice yet (one line per ticket, at its hourly rate). You can edit it before issuing.</p>
    </form>
  <?php endif; ?>

  <div class="rounded-xl border border-border bg-surface">
    <form method="get" class="flex flex-wrap gap-2 border-b border-border p-3">
      <select name="status" onchange="this.form.submit()" class="<?= SELECT_CLS ?> w-auto"><?= select_options(['' => 'All statuses'] + ($staff ? INVOICE_STATUSES : array_diff_key(INVOICE_STATUSES, ['draft' => 1])), $status) ?></select>
      <?php if (count($orgs) > 1): ?><select name="org" onchange="this.form.submit()" class="<?= SELECT_CLS ?> w-auto max-w-64"><?= select_options(['' => 'All organizations'] + array_column($orgs, 'name', 'id'), $orgF) ?></select><?php endif; ?>
    </form>
    <?php if (!$rows): ?>
      <div class="p-10 text-center"><span class="mx-auto grid h-11 w-11 place-items-center rounded-full bg-surface-2 text-muted"><?= icon('receipt', 20) ?></span><p class="mt-3 text-sm font-medium">No invoices<?= $status || $orgF ? ' match' : ' yet' ?></p></div>
    <?php else: ?>
      <div class="divide-y divide-border">
        <?php foreach ($rows as $r): ?>
          <a href="/invoices/<?= e($r['id']) ?>" class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-1 px-4 py-3 hover:bg-surface-2/50 sm:grid-cols-[8rem_minmax(0,1fr)_7rem_7rem_auto] sm:px-5">
            <span class="font-mono text-sm font-semibold"><?= $r['number'] ? e($r['number']) : '<span class="font-sans font-normal text-muted">Draft</span>' ?></span>
            <span class="min-w-0 truncate text-sm max-sm:col-start-1"><?= e($r['orgName']) ?> <span class="text-xs text-muted">· <?= e(date('M Y', strtotime($r['periodFrom']))) ?></span></span>
            <span class="text-sm text-muted max-sm:hidden"><?= e(date('j M Y', strtotime($r['issueDate']))) ?></span>
            <span class="text-right text-sm font-semibold tabular-nums max-sm:row-start-1 max-sm:col-start-2"><?= e(money((float) $r['total'], $r['currency'])) ?></span>
            <span class="justify-self-end rounded-full px-2 py-0.5 text-xs font-medium <?= $badge[$r['status']] ?>"><?= INVOICE_STATUSES[$r['status']] ?><?= $r['status'] === 'issued' && $r['dueDate'] < date('Y-m-d') ? ' · overdue' : '' ?></span>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if ($pages > 1): ?><?= pager($page, $pages, $total, $per, $url, ['noun' => ['invoice', 'invoices'], 'class' => 'border-t border-border px-4 py-3']) ?><?php endif; ?>
    <?php endif; ?>
  </div>

  <?php if ($staff): $s = $cfg['seller']; ?>
    <details class="group mt-6 rounded-xl border border-border bg-surface" id="invoice-settings" <?= $s['vat'] === '' ? 'open' : '' ?>>
      <summary class="flex cursor-pointer list-none items-center justify-between px-5 py-4 [&::-webkit-details-marker]:hidden">
        <span class="flex items-center gap-2 font-semibold"><?= icon('settings', 16) ?> Invoice settings</span><?= icon('chevron-down', 16, 'text-muted transition-transform group-open:rotate-180') ?>
      </summary>
      <form method="post" action="/action/invoice-settings-save" data-ajax class="space-y-4 border-t border-border p-5">
        <?= form_meta() ?>
        <p class="rounded-lg bg-amber-500/10 p-3 text-xs leading-relaxed text-amber-800 dark:text-amber-300">In Greece, a document is a valid tax invoice only when it is issued through <strong>myDATA (ΑΑΔΕ)</strong> or a certified e-invoicing provider. Until you connect one, keep the title “Billing statement” or “Pro forma invoice” and issue the legal invoice from your accounting software.</p>
        <div class="grid gap-4 sm:grid-cols-4">
          <div class="sm:col-span-2"><label class="<?= LABEL_CLS ?>">Document title</label><select name="title" class="<?= SELECT_CLS ?>"><?= select_options(INVOICE_TITLES, $cfg['title']) ?></select></div>
          <div><label class="<?= LABEL_CLS ?>">Series</label><input name="series" maxlength="12" value="<?= e($cfg['series']) ?>" class="<?= INPUT_CLS ?>"></div>
          <div><label class="<?= LABEL_CLS ?>">First number</label><input type="number" min="1" name="startNumber" value="<?= (int) $cfg['startNumber'] ?>" class="<?= INPUT_CLS ?>"></div>
          <div><label class="<?= LABEL_CLS ?>">VAT rate</label><select name="vatRate" class="<?= SELECT_CLS ?>"><?= select_options(array_combine(VAT_RATES, array_map(fn ($v) => $v . '%', VAT_RATES)), (string) $cfg['vatRate']) ?></select></div>
          <div><label class="<?= LABEL_CLS ?>">Payment due (days)</label><input type="number" min="0" max="180" name="dueDays" value="<?= (int) $cfg['dueDays'] ?>" class="<?= INPUT_CLS ?>"></div>
          <div class="sm:col-span-2"><label class="<?= LABEL_CLS ?>">Lines</label><select name="grouping" class="<?= SELECT_CLS ?>"><?= select_options(['ticket' => 'One line per ticket', 'single' => 'One line for the whole period'], $cfg['grouping']) ?></select></div>
        </div>
        <p class="pt-2 text-xs font-semibold uppercase tracking-wide text-muted">Your company</p>
        <div class="grid gap-4 sm:grid-cols-2">
          <div><label class="<?= LABEL_CLS ?>">Legal name</label><input name="sellerName" value="<?= e($s['name']) ?>" class="<?= INPUT_CLS ?>"></div>
          <div><label class="<?= LABEL_CLS ?>">Activity</label><input name="sellerActivity" value="<?= e($s['activity']) ?>" placeholder="IT support services" class="<?= INPUT_CLS ?>"></div>
          <div><label class="<?= LABEL_CLS ?>">VAT number (ΑΦΜ)</label><input name="sellerVat" value="<?= e($s['vat']) ?>" class="<?= INPUT_CLS ?>"></div>
          <div><label class="<?= LABEL_CLS ?>">Tax office (ΔΟΥ)</label><input name="sellerTaxOffice" value="<?= e($s['taxOffice']) ?>" class="<?= INPUT_CLS ?>"></div>
          <div class="sm:col-span-2"><label class="<?= LABEL_CLS ?>">Address</label><textarea name="sellerAddress" rows="2" class="<?= INPUT_CLS ?> h-auto py-2"><?= e($s['address']) ?></textarea></div>
          <div><label class="<?= LABEL_CLS ?>">Phone</label><input name="sellerPhone" value="<?= e($s['phone']) ?>" class="<?= INPUT_CLS ?>"></div>
          <div><label class="<?= LABEL_CLS ?>">Email</label><input name="sellerEmail" value="<?= e($s['email']) ?>" class="<?= INPUT_CLS ?>"></div>
          <div class="sm:col-span-2"><label class="<?= LABEL_CLS ?>">IBAN</label><input name="sellerIban" value="<?= e($s['iban']) ?>" placeholder="GR16 0110 1250 0000 0001 2300 695" class="<?= INPUT_CLS ?>"></div>
          <div class="sm:col-span-2"><label class="<?= LABEL_CLS ?>">Notes printed on every invoice</label><textarea name="notes" rows="2" maxlength="1000" class="<?= INPUT_CLS ?> h-auto py-2"><?= e($cfg['notes']) ?></textarea></div>
        </div>
        <button type="submit" class="<?= btn_cls() ?>">Save settings</button>
      </form>
    </details>
  <?php endif; ?>
</div>
