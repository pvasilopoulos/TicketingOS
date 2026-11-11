<?php
declare(strict_types=1);

/* Invoices: settings, organization billing details, drafts, issue / paid / void, email. */

function invoice_ctx(array $in): array|false
{
    if (!($ctx = ctx_with('invoices.manage'))) return false;
    [$user, $tenantId] = $ctx;
    $inv = q_one('SELECT * FROM "Invoice" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'id'), $tenantId]);
    return $inv ? [$user, $tenantId, $inv] : false;
}

function act_invoice_settings_save(array $in): ?array
{
    if (!($ctx = ctx_with('invoices.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $t = fn (string $k, int $max = 200) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    $vat = (int) ($in['vatRate'] ?? 24);
    tenant_setting_set($tenantId, 'invoices', [
        'title' => isset(INVOICE_TITLES[$in['title'] ?? '']) ? $in['title'] : 'statement',
        'series' => preg_replace('/[^\p{L}\p{N}-]/u', '', $t('series', 12)) ?: 'A',
        'startNumber' => max(1, (int) ($in['startNumber'] ?? 1)),
        'vatRate' => in_array($vat, VAT_RATES, true) ? $vat : 24,
        'dueDays' => max(0, min(180, (int) ($in['dueDays'] ?? 15))),
        'grouping' => ($in['grouping'] ?? '') === 'single' ? 'single' : 'ticket',
        'seller' => ['name' => $t('sellerName'), 'vat' => $t('sellerVat', 20), 'taxOffice' => $t('sellerTaxOffice', 80), 'activity' => $t('sellerActivity'),
            'address' => $t('sellerAddress', 300), 'phone' => $t('sellerPhone', 40), 'email' => $t('sellerEmail', 120), 'iban' => $t('sellerIban', 40)],
        'notes' => $t('notes', 1000),
    ]);
    return ['success' => 'Invoice settings saved'];
}

/** Billing details of an organization (Settings → Organizations → an organization). */
function act_org_billing_save(array $in): ?array
{
    if (!($ctx = ctx_with('invoices.manage'))) return NOT_ALLOWED;
    [, $tenantId] = $ctx;
    $org = q_one('SELECT * FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'organizationId'), $tenantId]);
    if (!$org) return ['error' => 'Organization not found'];
    $t = fn (string $k, int $max = 200) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    $email = strtolower($t('email', 191));
    if ($email !== '' && !is_valid_email($email)) return ['error' => 'Invalid invoice email'];
    $vat = ($in['vatRate'] ?? '') === '' ? '' : (int) $in['vatRate'];
    db_update('Organization', ['billing' => json_encode(['legalName' => $t('legalName'), 'vat' => $t('vat', 20), 'taxOffice' => $t('taxOffice', 80),
        'activity' => $t('activity'), 'address' => $t('address', 300), 'email' => $email, 'vatRate' => in_array($vat, VAT_RATES, true) ? $vat : ''], JSON_UNESCAPED_UNICODE)],
        '"id" = ?', [$org['id']]);
    return ['success' => 'Billing details saved'];
}

function act_invoice_create(array $in): ?array
{
    if (!($ctx = ctx_with('invoices.manage'))) return NOT_ALLOWED;
    [$user, $tenantId] = $ctx;
    $org = q_one('SELECT * FROM "Organization" WHERE "id" = ? AND "tenantId" = ?', [str_in($in, 'organizationId'), $tenantId]);
    if (!$org) return ['error' => 'Pick an organization'];
    $month = (string) ($in['month'] ?? '');
    if (preg_match('/^\d{4}-\d{2}$/', $month)) { $from = $month . '-01'; $to = date('Y-m-t', strtotime($from)); }
    else {
        $from = (string) ($in['from'] ?? ''); $to = (string) ($in['to'] ?? '');
        if (!strtotime($from) || !strtotime($to) || $from > $to) return ['error' => 'Pick a month or a valid period'];
    }
    $id = invoice_create_draft($tenantId, $org, $from, $to, $user);
    if (is_array($id)) return $id;
    return ['success' => 'Draft created', 'redirect' => '/invoices/' . $id];
}

function act_invoice_save(array $in): ?array
{
    if (!($c = invoice_ctx($in))) return NOT_ALLOWED;
    [, , $inv] = $c;
    if ($inv['status'] !== 'draft') return ['error' => 'Issued invoices can’t be changed — void it and create a new one'];
    $raw = [];
    foreach ((array) ($in['desc'] ?? []) as $i => $d) $raw[] = ['description' => $d, 'qty' => $in['qty'][$i] ?? 0, 'unit' => $in['unit'][$i] ?? 'h', 'price' => $in['price'][$i] ?? 0];
    $lines = invoice_clean_lines($raw);
    $vat = (int) ($in['vatRate'] ?? $inv['vatRate']);
    if (!in_array($vat, VAT_RATES, true)) return ['error' => 'Pick a VAT rate'];
    $issue = (string) ($in['issueDate'] ?? '');
    $due = (string) ($in['dueDate'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) || $due < $issue) return ['error' => 'Check the issue and due dates'];
    $tot = invoice_totals($lines, (float) $vat);
    db_update('Invoice', ['lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'vatRate' => $vat, 'issueDate' => $issue, 'dueDate' => $due,
        'notes' => mb_substr(trim((string) ($in['notes'] ?? '')), 0, 1000)] + $tot, '"id" = ?', [$inv['id']]);
    return ['success' => 'Saved · total ' . money($tot['total'], $inv['currency'])];
}

function act_invoice_issue(array $in): ?array
{
    if (!($c = invoice_ctx($in))) return NOT_ALLOWED;
    [, $tenantId, $inv] = $c;
    if (isset($in['desc'])) {
        $saved = act_invoice_save($in);
        if (isset($saved['error'])) return $saved;
        $inv = q_one('SELECT * FROM "Invoice" WHERE "id" = ?', [$inv['id']]);
    }
    $r = invoice_issue($inv);
    if (is_array($r)) return $r;
    return ['success' => "Issued as $r", 'redirect' => '/invoices/' . $inv['id']];
}

function act_invoice_paid(array $in): ?array
{
    if (!($c = invoice_ctx($in))) return NOT_ALLOWED;
    [, , $inv] = $c;
    if (!in_array($inv['status'], ['issued', 'paid'], true)) return ['error' => 'Only issued invoices can be marked paid'];
    $paid = $inv['status'] === 'issued';
    db_update('Invoice', ['status' => $paid ? 'paid' : 'issued', 'paidAt' => $paid ? now_iso() : null], '"id" = ?', [$inv['id']]);
    return ['success' => $paid ? 'Marked as paid' : 'Marked as unpaid'];
}

/** Void an issued invoice (its number stays used) or delete a draft; either way its time can be invoiced again. */
function act_invoice_void(array $in): ?array
{
    if (!($c = invoice_ctx($in))) return NOT_ALLOWED;
    [, , $inv] = $c;
    if ($inv['status'] === 'void') return null;
    db_tx(function () use ($inv) {
        q('UPDATE "TimeEntry" SET "invoiceId" = NULL WHERE "invoiceId" = ?', [$inv['id']]);
        if ($inv['status'] === 'draft') q('DELETE FROM "Invoice" WHERE "id" = ?', [$inv['id']]);
        else db_update('Invoice', ['status' => 'void'], '"id" = ?', [$inv['id']]);
    });
    return $inv['status'] === 'draft' ? ['success' => 'Draft deleted', 'redirect' => '/invoices'] : ['success' => 'Invoice voided — its time can be invoiced again'];
}

/** Email an issued invoice to the organization's invoice address (HTML copy + link for its managers). */
function act_invoice_email(array $in): ?array
{
    if (!($c = invoice_ctx($in))) return NOT_ALLOWED;
    [, $tenantId, $inv] = $c;
    if ($inv['status'] === 'draft' || $inv['status'] === 'void') return ['error' => 'Issue the invoice first'];
    $cust = json_decode((string) $inv['customer'], true) ?: [];
    $to = strtolower(trim((string) ($in['to'] ?? ''))) ?: (string) ($cust['email'] ?? '');
    if (!is_valid_email($to)) return ['error' => 'Add an invoice email for the organization (or type one)'];
    $title = invoice_title($inv);
    $html = invoice_document_html($inv, true);
    $r = send_tenant_email($tenantId, ['to' => $to, 'subject' => "$title {$inv['number']} · " . money((float) $inv['total'], $inv['currency']),
        'html' => notify_email_layout($tenantId, $html . notify_button(app_url() . '/invoices/' . $inv['id'], 'Open in the portal'), true)]);
    if (!empty($r['skipped'])) return ['error' => 'Email isn’t set up for this workspace (Settings → Notifications & email)'];
    return $r['ok'] ? ['success' => "Sent to $to"] : ['error' => (string) ($r['error'] ?? 'Send failed')];
}
