<?php
/** Printable invoice (browser “Save as PDF”). Standalone page — no app chrome. @var array $m route match */
[$user, $tenantId] = require_tenant_user();
$inv = q_one('SELECT * FROM "Invoice" WHERE "id" = ? AND "tenantId" = ?', [$m[1], $tenantId]);
if (!$inv || !invoice_visible($inv, $user)) abort(404);
header('Content-Type: text/html; charset=utf-8');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e(invoice_title($inv) . ' ' . ($inv['number'] ?: 'draft')) ?></title>
<style>
  @page { size: A4; margin: 16mm 14mm; }
  body { margin: 0; background: #f1f5f9; }
  .sheet { background: #fff; max-width: 820px; margin: 24px auto; padding: 40px; box-shadow: 0 1px 3px rgba(15, 23, 42, .12); border-radius: 8px; }
  .bar { max-width: 820px; margin: 16px auto 0; display: flex; gap: 8px; justify-content: flex-end; font: 14px system-ui, sans-serif; }
  .bar button { padding: 8px 14px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; cursor: pointer; font: inherit; }
  @media print { body { background: #fff; } .sheet { box-shadow: none; margin: 0; padding: 0; max-width: none; border-radius: 0; } .bar { display: none; } }
</style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Print / Save as PDF</button><button type="button" onclick="window.close()">Close</button></div>
<div class="sheet"><?= invoice_document_html($inv) ?></div>
<script>window.addEventListener("load", function () { setTimeout(function () { window.print(); }, 300); });</script>
</body>
</html>
<?php throw new HttpExit();
