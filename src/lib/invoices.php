<?php
declare(strict_types=1);

/*
 * Invoices from billable time. A draft collects an organization's billable time
 * for a period (one line per ticket at the organization's hourly rate); you can
 * edit the lines, then issue it (it gets the next number of its series and is
 * locked), mark it paid, or void it (its time can be invoiced again).
 *
 * In Greece an invoice is only valid once it's issued through myDATA / a certified
 * e-invoicing provider. Until that's connected, the document title can be set to
 * "Pro forma" or "Billing statement" (Settings → Invoices).
 */

const INVOICE_STATUSES = ['draft' => 'Draft', 'issued' => 'Issued', 'paid' => 'Paid', 'void' => 'Void'];
const INVOICE_TITLES = ['invoice' => 'Invoice', 'proforma' => 'Pro forma invoice', 'statement' => 'Billing statement'];
const VAT_RATES = [24, 17, 13, 9, 6, 4, 0];

function invoice_settings(string $tenantId): array
{
    $s = tenant_setting($tenantId, 'invoices', []);
    $tenantName = (string) q_val('SELECT "name" FROM "Tenant" WHERE "id" = ?', [$tenantId]);
    return [
        'title' => isset(INVOICE_TITLES[$s['title'] ?? '']) ? $s['title'] : 'statement',
        'series' => mb_substr(trim((string) ($s['series'] ?? '')), 0, 12) ?: 'A',
        'startNumber' => max(1, (int) ($s['startNumber'] ?? 1)),
        'vatRate' => in_array((int) ($s['vatRate'] ?? 24), VAT_RATES, true) ? (int) ($s['vatRate'] ?? 24) : 24,
        'dueDays' => max(0, min(180, (int) ($s['dueDays'] ?? 15))),
        'grouping' => ($s['grouping'] ?? 'ticket') === 'single' ? 'single' : 'ticket',
        'seller' => [
            'name' => (string) ($s['seller']['name'] ?? $tenantName), 'vat' => (string) ($s['seller']['vat'] ?? ''), 'taxOffice' => (string) ($s['seller']['taxOffice'] ?? ''),
            'activity' => (string) ($s['seller']['activity'] ?? ''), 'address' => (string) ($s['seller']['address'] ?? ''), 'phone' => (string) ($s['seller']['phone'] ?? ''),
            'email' => (string) ($s['seller']['email'] ?? ''), 'iban' => (string) ($s['seller']['iban'] ?? ''),
        ],
        'notes' => (string) ($s['notes'] ?? ''),
    ];
}

/** An organization's billing details (legal name, ΑΦΜ, ΔΟΥ, address, invoice email, VAT override). */
function org_billing(array $org): array
{
    $b = json_decode((string) ($org['billing'] ?? ''), true) ?: [];
    $vat = isset($b['vatRate']) && $b['vatRate'] !== '' && in_array((int) $b['vatRate'], VAT_RATES, true) ? (int) $b['vatRate'] : null;
    return [
        'legalName' => (string) ($b['legalName'] ?? ''), 'vat' => (string) ($b['vat'] ?? ''), 'taxOffice' => (string) ($b['taxOffice'] ?? ''),
        'activity' => (string) ($b['activity'] ?? ''), 'address' => (string) ($b['address'] ?? ''), 'email' => (string) ($b['email'] ?? ''), 'vatRate' => $vat,
    ];
}

/** Recalculate totals from the lines (amounts rounded to cents). */
function invoice_totals(array $lines, float $vatRate): array
{
    $sub = 0.0;
    foreach ($lines as $l) $sub += round((float) $l['qty'] * (float) $l['price'], 2);
    $sub = round($sub, 2);
    $vat = round($sub * $vatRate / 100, 2);
    return ['subtotal' => $sub, 'vatAmount' => $vat, 'total' => round($sub + $vat, 2)];
}

/** Clean lines from a form / JSON: [{description, qty, unit, price}]. */
function invoice_clean_lines(array $raw): array
{
    $out = [];
    foreach ($raw as $l) {
        $d = mb_substr(trim((string) ($l['description'] ?? '')), 0, 300);
        $q = round((float) str_replace(',', '.', (string) ($l['qty'] ?? 0)), 2);
        $p = round((float) str_replace(',', '.', (string) ($l['price'] ?? 0)), 2);
        if ($d === '' && $q == 0.0 && $p == 0.0) continue;
        $out[] = ['description' => $d, 'qty' => $q, 'unit' => mb_substr(trim((string) ($l['unit'] ?? 'h')), 0, 10) ?: 'h', 'price' => $p];
    }
    return array_slice($out, 0, 200);
}

/** Billable, not yet invoiced time of an organization in a period (by the requester's first organization, like the billing report). */
function invoice_uninvoiced_entries(string $tenantId, string $orgId, string $from, string $to): array
{
    $rows = q_all('SELECT te.*, t."number", t."subject", t."requesterId" FROM "TimeEntry" te JOIN "Ticket" t ON t."id" = te."ticketId"
        WHERE te."tenantId" = ? AND te."billable" = 1 AND te."invoiceId" IS NULL AND te."workedAt" >= ? AND te."workedAt" <= ?
          AND t."requesterId" IN (SELECT "userId" FROM "UserOrganization" WHERE "organizationId" = ?) ORDER BY t."number", te."workedAt"', [$tenantId, $from, $to, $orgId]);
    // A ticket is billed to the requester's first organization (same rule as the ticket's Time card).
    return array_values(array_filter($rows, fn ($e) => ticket_billing_org(['requesterId' => $e['requesterId']]) === $orgId));
}

/** Create a draft for an organization and period from its uninvoiced billable time. Returns the invoice id or an error. */
function invoice_create_draft(string $tenantId, array $org, string $from, string $to, array $user): string|array
{
    $cfg = invoice_settings($tenantId);
    $entries = invoice_uninvoiced_entries($tenantId, $org['id'], $from, $to);
    $rate = org_hourly_rate($tenantId, $org['id']);
    if ($entries && $rate === null) return ['error' => 'Set an hourly rate for ' . $org['name'] . ' (or a workspace default rate) first'];
    $lines = [];
    if ($cfg['grouping'] === 'single' && $entries) {
        $min = array_sum(array_map(fn ($e) => (int) $e['minutes'], $entries));
        $lines[] = ['description' => 'Support services ' . date('j M Y', strtotime($from)) . ' – ' . date('j M Y', strtotime($to)) . ' (' . count(array_unique(array_column($entries, 'ticketId'))) . ' tickets)',
            'qty' => round($min / 60, 2), 'unit' => 'h', 'price' => (float) $rate];
    } else {
        $byTicket = [];
        foreach ($entries as $e) {
            $byTicket[$e['ticketId']] ??= ['description' => 'Ticket #' . $e['number'] . ' — ' . mb_substr($e['subject'], 0, 120), 'min' => 0];
            $byTicket[$e['ticketId']]['min'] += (int) $e['minutes'];
        }
        foreach ($byTicket as $t) $lines[] = ['description' => $t['description'], 'qty' => round($t['min'] / 60, 2), 'unit' => 'h', 'price' => (float) $rate];
    }
    $b = org_billing($org);
    $vatRate = $b['vatRate'] ?? $cfg['vatRate'];
    $now = now_iso();
    $id = cuid();
    $tot = invoice_totals($lines, (float) $vatRate);
    db_tx(function () use ($id, $tenantId, $org, $from, $to, $lines, $vatRate, $tot, $cfg, $user, $now, $entries) {
        db_insert('Invoice', [
            'id' => $id, 'tenantId' => $tenantId, 'organizationId' => $org['id'], 'series' => $cfg['series'], 'seq' => null, 'number' => null,
            'status' => 'draft', 'issueDate' => date('Y-m-d'), 'dueDate' => date('Y-m-d', strtotime('+' . $cfg['dueDays'] . ' days')),
            'periodFrom' => $from, 'periodTo' => $to, 'currency' => billing_settings($tenantId)['currency'], 'vatRate' => $vatRate,
            'subtotal' => $tot['subtotal'], 'vatAmount' => $tot['vatAmount'], 'total' => $tot['total'],
            'lines' => json_encode($lines, JSON_UNESCAPED_UNICODE), 'notes' => $cfg['notes'], 'customer' => null, 'seller' => null,
            'createdById' => $user['id'], 'createdAt' => $now, 'issuedAt' => null, 'paidAt' => null,
        ]);
        // The time is reserved for this invoice so it isn't billed twice (released if the draft is deleted or voided).
        foreach (array_chunk(array_column($entries, 'id'), 500) as $ids) q('UPDATE "TimeEntry" SET "invoiceId" = ? WHERE "id" IN (' . in_ph($ids) . ')', [$id, ...$ids]);
    });
    return $id;
}

/** Issue a draft: next number of its series, snapshot of seller and customer details, locked from then on. */
function invoice_issue(array $inv): string|array
{
    if ($inv['status'] !== 'draft') return ['error' => 'Only drafts can be issued'];
    $lines = json_decode((string) $inv['lines'], true) ?: [];
    if (!$lines) return ['error' => 'Add at least one line'];
    $tenantId = $inv['tenantId'];
    $cfg = invoice_settings($tenantId);
    $org = q_one('SELECT * FROM "Organization" WHERE "id" = ?', [$inv['organizationId']]);
    $b = org_billing($org ?: []);
    if ($cfg['seller']['vat'] === '') return ['error' => 'Add your company’s VAT number (ΑΦΜ) in Settings → Invoices first'];
    return db_tx(function () use ($inv, $tenantId, $cfg, $org, $b) {
        $max = (int) q_val('SELECT MAX("seq") FROM "Invoice" WHERE "tenantId" = ? AND "series" = ?', [$tenantId, $inv['series']]);
        $seq = max($max + 1, $cfg['startNumber']);
        db_update('Invoice', [
            'status' => 'issued', 'seq' => $seq, 'number' => $inv['series'] . '-' . $seq, 'issuedAt' => now_iso(),
            'seller' => json_encode($cfg['seller'], JSON_UNESCAPED_UNICODE),
            'customer' => json_encode(['name' => $b['legalName'] ?: ($org['name'] ?? ''), 'orgName' => $org['name'] ?? ''] + $b, JSON_UNESCAPED_UNICODE),
        ], '"id" = ? AND "status" = ?', [$inv['id'], 'draft']);
        return $inv['series'] . '-' . $seq;
    });
}

/** Document title for printing. */
function invoice_title(array $inv): string
{
    return INVOICE_TITLES[invoice_settings($inv['tenantId'])['title']];
}

/** Can this person see invoices of this organization? Staff with the permission, or the organization's managers. */
function invoice_visible(array $inv, array $user): bool
{
    if (is_staff($user['role'])) return can($user, 'invoices.manage');
    if ($inv['status'] === 'draft') return false;
    return (bool) q_val('SELECT 1 FROM "UserOrganization" WHERE "userId" = ? AND "organizationId" = ? AND "isManager" = 1', [$user['id'], $inv['organizationId']]);
}

/**
 * The invoice as a self-contained HTML document part (inline styles): used on screen,
 * for printing / PDF and in the email.
 */
function invoice_document_html(array $inv, bool $email = false): string
{
    $tenantId = $inv['tenantId'];
    $cfg = invoice_settings($tenantId);
    $seller = json_decode((string) $inv['seller'], true) ?: $cfg['seller'];
    $org = q_one('SELECT * FROM "Organization" WHERE "id" = ?', [$inv['organizationId']]) ?: ['name' => ''];
    $cust = json_decode((string) $inv['customer'], true) ?: (['name' => org_billing($org)['legalName'] ?: $org['name']] + org_billing($org));
    $lines = json_decode((string) $inv['lines'], true) ?: [];
    $cur = $inv['currency'];
    $d = fn (?string $v) => $v ? date('d/m/Y', strtotime($v)) : '';
    $num = fn (float $v) => number_format($v, 2, ',', '.');
    $muted = 'color:#64748b';
    $party = function (array $p, bool $isSeller) use ($muted): string {
        $rows = [];
        if (!empty($p['activity'])) $rows[] = e($p['activity']);
        if (!empty($p['address'])) $rows[] = nl2br(e($p['address']), false);
        $tax = trim((!empty($p['vat']) ? 'VAT no. (ΑΦΜ) ' . e($p['vat']) : '') . (!empty($p['taxOffice']) ? ' · Tax office (ΔΟΥ) ' . e($p['taxOffice']) : ''), ' ·');
        if ($tax !== '') $rows[] = $tax;
        if ($isSeller) foreach (['phone', 'email'] as $k) if (!empty($p[$k])) $rows[] = e($p[$k]);
        return '<div style="font-size:15px;font-weight:700;color:#0f172a">' . e((string) ($p['name'] ?? '')) . '</div><div style="margin-top:4px;font-size:12px;line-height:1.55;' . $muted . '">' . implode('<br>', $rows) . '</div>';
    };
    $title = INVOICE_TITLES[$cfg['title']];
    $stamp = ['paid' => ['PAID', '#059669'], 'void' => ['VOID', '#dc2626'], 'draft' => ['DRAFT', '#d97706']][$inv['status']] ?? null;
    $h = '<div style="font-family:system-ui,-apple-system,\'Segoe UI\',Roboto,sans-serif;color:#0f172a;max-width:780px;margin:0 auto">';
    $h .= '<table role="presentation" style="width:100%;border-collapse:collapse"><tr><td style="vertical-align:top;padding:0 16px 0 0">' . $party($seller, true) . '</td>'
        . '<td style="vertical-align:top;text-align:right;white-space:nowrap"><div style="font-size:20px;font-weight:700;letter-spacing:-.01em">' . e($title) . '</div>'
        . '<div style="margin-top:4px;font-size:14px;font-weight:600">' . ($inv['number'] ? 'No. ' . e($inv['number']) : 'Not issued yet') . '</div>'
        . '<div style="margin-top:6px;font-size:12px;line-height:1.6;' . $muted . '">Date: ' . $d($inv['issueDate']) . '<br>Due: ' . $d($inv['dueDate']) . '<br>Period: ' . $d($inv['periodFrom']) . ' – ' . $d($inv['periodTo']) . '</div>'
        . ($stamp ? '<div style="display:inline-block;margin-top:8px;padding:3px 10px;border:2px solid ' . $stamp[1] . ';border-radius:6px;color:' . $stamp[1] . ';font-size:12px;font-weight:800;letter-spacing:.08em">' . $stamp[0] . '</div>' : '')
        . '</td></tr></table>';
    $h .= '<div style="margin:22px 0 18px;padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px"><div style="margin-bottom:6px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;' . $muted . '">Bill to</div>' . $party($cust, false) . '</div>';
    $th = 'padding:8px 10px;font-size:11px;font-weight:700;letter-spacing:.04em;text-transform:uppercase;color:#64748b;border-bottom:2px solid #e2e8f0';
    $h .= '<table style="width:100%;border-collapse:collapse;font-size:13px"><thead><tr><th style="' . $th . ';text-align:left">Description</th><th style="' . $th . ';text-align:right">Qty</th><th style="' . $th . ';text-align:left">Unit</th><th style="' . $th . ';text-align:right">Price</th><th style="' . $th . ';text-align:right">Amount</th></tr></thead><tbody>';
    foreach ($lines as $l) {
        $td = 'padding:8px 10px;border-bottom:1px solid #f1f5f9;vertical-align:top';
        $h .= '<tr><td style="' . $td . '">' . e($l['description']) . '</td><td style="' . $td . ';text-align:right;white-space:nowrap">' . $num((float) $l['qty']) . '</td><td style="' . $td . '">' . e($l['unit'] === 'h' ? 'hours' : $l['unit']) . '</td>'
            . '<td style="' . $td . ';text-align:right;white-space:nowrap">' . $num((float) $l['price']) . '</td><td style="' . $td . ';text-align:right;white-space:nowrap">' . $num(round((float) $l['qty'] * (float) $l['price'], 2)) . '</td></tr>';
    }
    if (!$lines) $h .= '<tr><td colspan="5" style="padding:14px 10px;text-align:center;' . $muted . '">No lines</td></tr>';
    $h .= '</tbody></table>';
    $tr = fn (string $k, string $v, bool $big = false) => '<tr><td style="padding:5px 10px;text-align:right;' . ($big ? 'font-size:16px;font-weight:700;border-top:2px solid #0f172a' : 'font-size:13px;color:#475569') . '">' . $k . '</td><td style="padding:5px 10px;text-align:right;white-space:nowrap;' . ($big ? 'font-size:16px;font-weight:700;border-top:2px solid #0f172a' : 'font-size:13px') . '">' . $v . '</td></tr>';
    $h .= '<table role="presentation" style="margin:12px 0 0 auto;border-collapse:collapse;min-width:280px">'
        . $tr('Net amount', money((float) $inv['subtotal'], $cur)) . $tr('VAT ' . (int) $inv['vatRate'] . '%', money((float) $inv['vatAmount'], $cur)) . $tr('Total', money((float) $inv['total'], $cur), true) . '</table>';
    $foot = [];
    if (!empty($seller['iban'])) $foot[] = 'Payment by bank transfer to IBAN <strong style="color:#0f172a">' . e($seller['iban']) . '</strong>' . ($inv['number'] ? ' — reference ' . e($inv['number']) : '') . ', by ' . $d($inv['dueDate']) . '.';
    if (trim((string) $inv['notes']) !== '') $foot[] = nl2br(e((string) $inv['notes']), false);
    if ($cfg['title'] !== 'invoice') $foot[] = '<em>This document is not a tax invoice.</em>';
    if ($foot) $h .= '<div style="margin-top:22px;padding-top:14px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.6;' . $muted . '">' . implode('<br>', $foot) . '</div>';
    return $h . '</div>';
}
