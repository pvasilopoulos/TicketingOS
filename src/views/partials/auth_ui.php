<?php
declare(strict_types=1);

/* Sign-in / sign-up form pieces (login, register, forgot, reset). */

/**
 * A large input with a leading icon. Options: id, name, label, icon, type, placeholder,
 * value, autocomplete, required (default true), aside (HTML right of the label),
 * reveal (password show/hide), strength (password strength meter), autofocus.
 */
function auth_field(array $o): string
{
    $type = $o['type'] ?? 'text';
    $id = $o['id'] ?? $o['name'];
    $attrs = 'id="' . e($id) . '" name="' . e($o['name']) . '" type="' . e($type) . '"'
        . (isset($o['placeholder']) ? ' placeholder="' . e($o['placeholder']) . '"' : '')
        . (isset($o['autocomplete']) ? ' autocomplete="' . e($o['autocomplete']) . '"' : '')
        . (isset($o['value']) && $o['value'] !== '' ? ' value="' . e($o['value']) . '"' : '')
        . (($o['required'] ?? true) ? ' required' : '') . (!empty($o['autofocus']) ? ' autofocus' : '')
        . (!empty($o['strength']) ? ' data-strength' : '');
    $pad = !empty($o['reveal']) ? 'pr-12' : 'pr-4';
    $h = '<div class="auth-field">'
        . '<div class="mb-1.5 flex items-center justify-between gap-2"><label for="' . e($id) . '" class="text-sm font-medium text-fg">' . e($o['label']) . '</label>' . ($o['aside'] ?? '') . '</div>'
        . '<div class="relative">'
        . '<span class="auth-ic pointer-events-none absolute left-4 top-1/2 -translate-y-1/2 text-muted transition-colors">' . icon($o['icon'], 18) . '</span>'
        . '<input ' . $attrs . ' class="h-12 w-full rounded-xl border border-border bg-surface/70 pl-11 ' . $pad . ' text-[15px] text-fg shadow-sm shadow-black/[0.02] placeholder:text-muted/70 transition focus:border-brand focus:bg-surface focus:outline-none focus:ring-4 focus:ring-brand/15">';
    if (!empty($o['reveal'])) {
        $h .= '<button type="button" data-reveal="' . e($id) . '" aria-label="Show password" class="absolute right-2 top-1/2 grid h-9 w-9 -translate-y-1/2 place-items-center rounded-lg text-muted transition-colors hover:bg-surface-2 hover:text-fg cursor-pointer">'
            . '<span data-reveal-show>' . icon('eye', 18) . '</span><span data-reveal-hide hidden>' . icon('eye-off', 18) . '</span></button>';
    }
    $h .= '</div>';
    if (!empty($o['strength'])) {
        $h .= '<div class="mt-2.5" data-strength-for="' . e($id) . '"><div class="grid grid-cols-4 gap-1.5">'
            . str_repeat('<span class="h-1 rounded-full bg-border transition-colors duration-300" data-bar></span>', 4)
            . '</div><p class="mt-1.5 text-xs text-muted" data-strength-label>Use 8+ characters with a mix of letters, numbers and symbols.</p></div>';
    }
    $h .= '<p class="mt-1.5 text-xs text-amber-600 dark:text-amber-400" data-caps-for="' . e($id) . '" hidden>Caps Lock is on</p>';
    return $h . '</div>';
}

/** Gradient submit button with a trailing arrow. */
function auth_submit(string $label): string
{
    return '<button type="submit" class="auth-btn inline-flex h-12 w-full items-center justify-center gap-2 rounded-xl text-[15px] font-semibold text-white cursor-pointer focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-brand/30 disabled:opacity-60">'
        . e($label) . icon('arrow-right', 17) . '</button>';
}

/** Icon badge + title + subtitle at the top of an auth card. */
function auth_head(string $icon, string $title, string $sub): string
{
    return '<div class="mb-7 text-center">'
        . '<span class="mx-auto mb-5 grid h-14 w-14 place-items-center rounded-2xl bg-gradient-to-br from-brand to-violet-600 text-white shadow-lg shadow-brand/30 ring-1 ring-white/20">' . icon($icon, 26) . '</span>'
        . '<h1 class="text-[1.75rem] font-bold leading-tight tracking-tight">' . e($title) . '</h1>'
        . '<p class="mt-2 text-[15px] text-muted">' . e($sub) . '</p></div>';
}
