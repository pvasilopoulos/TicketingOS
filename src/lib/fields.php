<?php
declare(strict_types=1);

/*
 * Custom ticket fields (Settings → Ticket fields).
 * Values live in TicketFieldValue as strings: dates are YYYY-MM-DD, checkboxes "1".
 */

const FIELD_TYPES = [
    'text' => ['Short text', 'type'],
    'textarea' => ['Long text', 'align-left'],
    'number' => ['Number', 'hash'],
    'date' => ['Date', 'clock'],
    'select' => ['Dropdown', 'list'],
    'checkbox' => ['Checkbox', 'circle-check'],
];

/** All fields of a tenant, in display order. */
function tenant_fields(string $tenantId): array
{
    static $cache = [];
    if (!isset($cache[$tenantId])) {
        $rows = q_all('SELECT * FROM "CustomField" WHERE "tenantId" = ? ORDER BY "order", "createdAt"', [$tenantId]);
        foreach ($rows as &$r) $r['opts'] = json_decode((string) ($r['options'] ?? '[]'), true) ?: [];
        $cache[$tenantId] = $rows;
    }
    return $cache[$tenantId];
}

/** Fields that apply to a category (fields without a category apply everywhere). */
function fields_for(string $tenantId, ?string $categoryId, bool $customer): array
{
    return array_values(array_filter(tenant_fields($tenantId), fn ($f) =>
        (!$f['categoryId'] || $f['categoryId'] === $categoryId) && (!$customer || (int) $f['customerVisible'])));
}

/** Current values of a ticket: key => value. */
function ticket_field_values(string $ticketId): array
{
    return array_column(q_all('SELECT f."key", v."value" FROM "TicketFieldValue" v JOIN "CustomField" f ON f."id" = v."fieldId" WHERE v."ticketId" = ?', [$ticketId]), 'value', 'key');
}

/**
 * Validate submitted values (cf[key] => value) for the given fields.
 * Returns [key => normalised value ('' clears)] or an error string.
 */
function validate_fields(array $fields, array $in, bool $requireAll = true): array|string
{
    $out = [];
    foreach ($fields as $f) {
        $k = $f['key'];
        if (!array_key_exists($k, $in) && !$requireAll) continue;
        $v = trim(is_array($in[$k] ?? null) ? '' : (string) ($in[$k] ?? ''));
        switch ($f['type']) {
            case 'number':
                if ($v !== '' && !is_numeric($v)) return "“{$f['label']}” must be a number";
                break;
            case 'date':
                if ($v !== '' && !preg_match('/^\d{4}-\d\d-\d\d$/', $v)) return "“{$f['label']}” must be a date (YYYY-MM-DD)";
                break;
            case 'select':
                if ($v !== '' && !in_array($v, $f['opts'], true)) return "Pick one of the options for “{$f['label']}”";
                break;
            case 'checkbox':
                $v = in_array($v, ['1', 'on', 'true', 'yes'], true) ? '1' : '';
                break;
            default:
                $v = mb_substr($v, 0, $f['type'] === 'textarea' ? 4000 : 255);
        }
        if ($requireAll && (int) $f['required'] && $v === '') return "“{$f['label']}” is required";
        $out[$k] = $v;
    }
    return $out;
}

/** Store values for a ticket; returns [label => [old, new]] for what changed. */
function save_field_values(string $ticketId, string $tenantId, array $values): array
{
    $byKey = key_by(tenant_fields($tenantId), 'key');
    $current = ticket_field_values($ticketId);
    $changed = [];
    foreach ($values as $k => $v) {
        if (!isset($byKey[$k])) continue;
        $old = $current[$k] ?? '';
        if ($old === $v) continue;
        $fid = $byKey[$k]['id'];
        q('DELETE FROM "TicketFieldValue" WHERE "ticketId" = ? AND "fieldId" = ?', [$ticketId, $fid]);
        if ($v !== '') db_insert('TicketFieldValue', ['ticketId' => $ticketId, 'fieldId' => $fid, 'value' => $v]);
        $changed[$byKey[$k]['label']] = [$old, $v];
    }
    return $changed;
}

/** Human display of a value. */
function field_display(array $f, ?string $v): string
{
    if ($v === null || $v === '') return '—';
    return match ($f['type']) {
        'checkbox' => 'Yes',
        'date' => format_date($v . 'T12:00:00.000Z'),
        default => $v,
    };
}

/** Form control for one field. $name e.g. "cf[order_no]". */
function field_input(array $f, ?string $value, string $cls = '', bool $disabled = false): string
{
    $name = 'cf[' . e($f['key']) . ']';
    $id = 'cf-' . e($f['key']);
    $req = (int) $f['required'] ? ' required' : '';
    $dis = $disabled ? ' disabled' : '';
    $cls = $cls ?: INPUT_CLS;
    $value = (string) $value;
    return match ($f['type']) {
        'textarea' => '<textarea id="' . $id . '" name="' . $name . '" rows="3"' . $req . $dis . ' class="' . str_replace(' h-10', '', $cls) . ' py-2">' . e($value) . '</textarea>',
        'number' => '<input id="' . $id . '" name="' . $name . '" type="number" step="any" value="' . e($value) . '"' . $req . $dis . ' class="' . $cls . '">',
        'date' => '<input id="' . $id . '" name="' . $name . '" type="date" value="' . e($value) . '"' . $req . $dis . ' class="' . $cls . '">',
        'select' => '<select id="' . $id . '" name="' . $name . '"' . $req . $dis . ' class="' . $cls . ' cursor-pointer">' . select_options(['' => '— Select —'] + array_combine($f['opts'], $f['opts']), $value) . '</select>',
        'checkbox' => '<label class="inline-flex items-center gap-2 text-sm cursor-pointer"><input type="hidden" name="' . $name . '" value=""><input id="' . $id . '" type="checkbox" name="' . $name . '" value="1"' . ($value === '1' ? ' checked' : '') . $dis . ' class="h-4 w-4 rounded border-border accent-brand"> ' . e($f['label']) . '</label>',
        default => '<input id="' . $id . '" name="' . $name . '" value="' . e($value) . '" maxlength="255"' . $req . $dis . ' class="' . $cls . '">',
    };
}

/** Fields block for create forms; each field shows only for its category (app.js → data-field-category). */
function fields_form_block(array $fields, array $values = []): string
{
    if (!$fields) return '';
    $h = '<div class="grid gap-5 sm:grid-cols-2" data-fields>';
    foreach ($fields as $f) {
        $wide = in_array($f['type'], ['textarea', 'checkbox'], true);
        $h .= '<div class="' . ($wide ? 'sm:col-span-2' : '') . '" data-field-category="' . e($f['categoryId'] ?? '') . '">';
        if ($f['type'] !== 'checkbox') {
            $h .= '<label for="cf-' . e($f['key']) . '" class="' . LABEL_CLS . '">' . e($f['label']) . ((int) $f['required'] ? ' <span class="text-red-500">*</span>' : '') . '</label>';
        }
        $h .= field_input($f, $values[$f['key']] ?? '') . '</div>';
    }
    return $h . '</div>';
}

/** Unique snake_case key from a label. */
function field_key_from(string $label, string $tenantId, ?string $exceptId = null): string
{
    $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label) ?: '')), '_') ?: 'field';
    $base = substr($base, 0, 40);
    $key = $base;
    $n = 2;
    while (q_val('SELECT 1 FROM "CustomField" WHERE "tenantId" = ? AND "key" = ?' . ($exceptId ? ' AND "id" <> ?' : ''), $exceptId ? [$tenantId, $key, $exceptId] : [$tenantId, $key])) {
        $key = $base . '_' . $n++;
    }
    return $key;
}
