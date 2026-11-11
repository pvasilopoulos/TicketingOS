<?php
/** One invoice: edit a draft, or view / print / email / mark paid an issued one. @var string $id */
[$user, $tenantId] = require_tenant_user();
$inv = q_one('SELECT * FROM "Invoice" WHERE "id" = ? AND "tenantId" = ?', [$id, $tenantId]);
if (!$inv || !invoice_visible($inv, $user)) abort(404);
$staff = is_staff($user['role']);
$org = q_one('SELECT * FROM "Organization" WHERE "id" = ?', [$inv['organizationId']]);
$draft = $inv['status'] === 'draft';
$lines = json_decode((string) $inv['lines'], true) ?: [];
$cust = json_decode((string) $inv['customer'], true) ?: [];
page_title(($inv['number'] ?: 'Draft') . ' · Invoices');
$cell = 'h-9 w-full rounded-lg border border-border bg-surface px-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand/40';
$entries = (int) q_val('SELECT COUNT(*) FROM "TimeEntry" WHERE "invoiceId" = ?', [$inv['id']]);
?>
<div>
  <a href="/invoices" class="mb-4 inline-flex items-center gap-1.5 text-sm text-muted hover:text-fg"><?= icon('arrow-left', 15) ?> Invoices</a>
  <div class="mb-5 flex flex-wrap items-center gap-3">
    <h1 class="text-2xl font-bold tracking-tight"><?= $inv['number'] ? e(invoice_title($inv) . ' ' . $inv['number']) : 'Draft invoice' ?></h1>
    <span class="rounded-full bg-surface-2 px-2.5 py-0.5 text-xs font-medium"><?= INVOICE_STATUSES[$inv['status']] ?></span>
    <span class="text-sm text-muted"><?= e($org['name'] ?? '') ?> · <?= e(money((float) $inv['total'], $inv['currency'])) ?></span>
    <div class="ml-auto flex flex-wrap gap-2">
      <a href="/invoices/<?= e($inv['id']) ?>/print" target="_blank" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('printer', 14) ?> Print / PDF</a>
      <?php if ($staff && !$draft && $inv['status'] !== 'void'): ?>
        <form method="post" action="/action/invoice-paid" data-ajax><?= form_meta() ?><input type="hidden" name="id" value="<?= e($inv['id']) ?>"><button type="submit" class="<?= btn_cls('outline', 'sm') ?>"><?= icon($inv['status'] === 'paid' ? 'rotate-ccw' : 'circle-check', 14) ?> <?= $inv['status'] === 'paid' ? 'Mark unpaid' : 'Mark paid' ?></button></form>
        <button type="button" data-toggle-target="#inv-email" class="<?= btn_cls('outline', 'sm') ?>"><?= icon('mail', 14) ?> Email</button>
        <form method="post" action="/action/invoice-void" data-ajax data-confirm="Void <?= e($inv['number']) ?>? The number stays used and its time can be invoiced again."><?= form_meta() ?><input type="hidden" name="id" value="<?= e($inv['id']) ?>"><button type="submit" class="<?= btn_cls('ghost', 'sm') ?> text-red-600"><?= icon('circle-x', 14) ?> Void</button></form>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($staff && !$draft): ?>
    <form method="post" action="/action/invoice-email" data-ajax id="inv-email" hidden class="mb-5 flex flex-wrap items-end gap-2 rounded-xl border border-border bg-surface p-4">
      <?= form_meta() ?><input type="hidden" name="id" value="<?= e($inv['id']) ?>">
      <div class="min-w-0 flex-1 basis-64"><label class="<?= LABEL_CLS ?>">Send to</label><input type="email" name="to" value="<?= e((string) ($cust['email'] ?? '')) ?>" placeholder="accounts@customer.gr" class="<?= INPUT_CLS ?>"></div>
      <button type="submit" class="<?= btn_cls() ?>"><?= icon('send', 14) ?> Send</button>
      <p class="w-full text-xs text-muted">The invoice is in the email body, with a link for the organization’s managers to open it in the portal.</p>
    </form>
  <?php endif; ?>

  <?php if ($draft): ?>
    <form method="post" action="/action/invoice-save" data-ajax class="mb-6 space-y-4 rounded-xl border border-border bg-surface p-5" data-inv-form>
      <?= form_meta() ?><input type="hidden" name="id" value="<?= e($inv['id']) ?>">
      <p class="text-sm text-muted">Built from <?= $entries ?> billable time entr<?= $entries === 1 ? 'y' : 'ies' ?> of <?= e(date('j M', strtotime($inv['periodFrom']))) ?> – <?= e(date('j M Y', strtotime($inv['periodTo']))) ?>. Change anything below, save, then issue it.</p>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[40rem] text-sm">
          <thead><tr class="text-left text-xs text-muted"><th class="pb-2 font-medium">Description</th><th class="w-24 pb-2 font-medium">Qty</th><th class="w-20 pb-2 font-medium">Unit</th><th class="w-28 pb-2 font-medium">Price</th><th class="w-28 pb-2 text-right font-medium">Amount</th><th class="w-10"></th></tr></thead>
          <tbody data-inv-lines>
            <?php foreach ($lines ?: [['description' => '', 'qty' => 1, 'unit' => 'h', 'price' => 0]] as $l): ?>
              <tr data-inv-line>
                <td class="py-1 pr-2"><input name="desc[]" value="<?= e($l['description']) ?>" class="<?= $cell ?>"></td>
                <td class="py-1 pr-2"><input name="qty[]" inputmode="decimal" value="<?= e((string) $l['qty']) ?>" class="<?= $cell ?> text-right" data-calc></td>
                <td class="py-1 pr-2"><input name="unit[]" value="<?= e($l['unit']) ?>" class="<?= $cell ?>"></td>
                <td class="py-1 pr-2"><input name="price[]" inputmode="decimal" value="<?= e((string) $l['price']) ?>" class="<?= $cell ?> text-right" data-calc></td>
                <td class="py-1 text-right tabular-nums" data-amount></td>
                <td class="py-1 text-right"><button type="button" data-inv-remove class="grid h-8 w-8 place-items-center rounded-lg text-muted hover:bg-surface-2 hover:text-red-600 cursor-pointer" aria-label="Remove line"><?= icon('x', 14) ?></button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="button" data-inv-add class="<?= btn_cls('ghost', 'sm') ?>"><?= icon('plus', 14) ?> Add line</button>
      <div class="grid gap-4 sm:grid-cols-4">
        <div><label class="<?= LABEL_CLS ?>">Issue date</label><input type="date" name="issueDate" value="<?= e($inv['issueDate']) ?>" class="<?= INPUT_CLS ?>"></div>
        <div><label class="<?= LABEL_CLS ?>">Due date</label><input type="date" name="dueDate" value="<?= e($inv['dueDate']) ?>" class="<?= INPUT_CLS ?>"></div>
        <div><label class="<?= LABEL_CLS ?>">VAT</label><select name="vatRate" data-calc class="<?= SELECT_CLS ?>"><?= select_options(array_combine(VAT_RATES, array_map(fn ($v) => $v . '%', VAT_RATES)), (string) (int) $inv['vatRate']) ?></select></div>
        <div class="text-right"><p class="text-xs text-muted">Net <span data-net></span> · VAT <span data-vat></span></p><p class="text-xl font-bold tabular-nums" data-total></p></div>
        <div class="sm:col-span-4"><label class="<?= LABEL_CLS ?>">Notes</label><textarea name="notes" rows="2" maxlength="1000" class="<?= INPUT_CLS ?> h-auto w-full py-2"><?= e((string) $inv['notes']) ?></textarea></div>
      </div>
      <div class="flex flex-wrap items-center gap-2 border-t border-border pt-4">
        <button type="submit" class="<?= btn_cls() ?>"><?= icon('check', 15) ?> Save draft</button>
        <button type="submit" formaction="/action/invoice-issue" data-confirm="Issue this invoice? Your changes are saved, it gets the next number and can’t be edited afterwards." class="<?= btn_cls('outline') ?>"><?= icon('send', 15) ?> Issue</button>
        <button type="submit" formaction="/action/invoice-void" formnovalidate data-confirm="Delete this draft? Its time can be invoiced again." class="<?= btn_cls('ghost') ?> ml-auto text-red-600"><?= icon('trash-2', 15) ?> Delete draft</button>
      </div>
    </form>
    <script>
    (function () {
      var f = document.querySelector("[data-inv-form]"), body = f.querySelector("[data-inv-lines]"), cur = <?= json_encode($inv['currency']) ?>;
      var n = function (v) { return parseFloat(String(v).replace(",", ".")) || 0; };
      var fmt = function (v) { return v.toLocaleString("el-GR", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + (cur === "EUR" ? " €" : " " + cur); };
      function calc() {
        var net = 0;
        f.querySelectorAll("[data-inv-line]").forEach(function (r) {
          var a = Math.round(n(r.querySelector('[name="qty[]"]').value) * n(r.querySelector('[name="price[]"]').value) * 100) / 100;
          net += a; r.querySelector("[data-amount]").textContent = fmt(a);
        });
        net = Math.round(net * 100) / 100;
        var vat = Math.round(net * n(f.querySelector('[name="vatRate"]').value)) / 100;
        f.querySelector("[data-net]").textContent = fmt(net); f.querySelector("[data-vat]").textContent = fmt(vat); f.querySelector("[data-total]").textContent = fmt(net + vat);
      }
      f.addEventListener("input", calc); f.addEventListener("change", calc);
      f.querySelector("[data-inv-add]").addEventListener("click", function () {
        var row = body.querySelector("[data-inv-line]").cloneNode(true);
        row.querySelectorAll("input").forEach(function (i) { i.value = i.name === "unit[]" ? "h" : i.name === "qty[]" ? "1" : ""; });
        body.appendChild(row); row.querySelector("input").focus(); calc();
      });
      body.addEventListener("click", function (e) {
        var b = e.target.closest("[data-inv-remove]"); if (!b) return;
        if (body.querySelectorAll("[data-inv-line]").length > 1) b.closest("[data-inv-line]").remove(); else b.closest("[data-inv-line]").querySelectorAll("input").forEach(function (i) { i.value = ""; });
        calc();
      });
      calc();
    })();
    </script>
  <?php endif; ?>

  <div class="overflow-x-auto rounded-xl border border-border bg-white p-6 text-slate-900 shadow-sm sm:p-10">
    <?= invoice_document_html($inv) ?>
  </div>
  <?php if ($draft): ?><p class="mt-2 text-xs text-muted">Preview of the saved draft.</p><?php endif; ?>
</div>
