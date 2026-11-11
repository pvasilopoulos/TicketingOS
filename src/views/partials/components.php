<?php
declare(strict_types=1);

/* Reusable UI building blocks (ported from components/ui/* and components/app/*). */

const INPUT_BASE = 'w-full rounded-lg border border-border bg-surface px-3 text-sm text-fg placeholder:text-muted focus:outline-none focus:ring-2 focus:ring-brand/40 focus:border-brand transition disabled:opacity-50';
const INPUT_CLS = INPUT_BASE . ' h-10';
const SELECT_CLS = INPUT_BASE . ' h-10 cursor-pointer';
const LABEL_CLS = 'block text-sm font-medium text-fg mb-1.5';

const BTN_BASE = 'inline-flex items-center justify-center rounded-lg font-medium transition-colors cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/50 disabled:opacity-50 disabled:pointer-events-none whitespace-nowrap';
const BTN_VARIANTS = [
    'primary' => 'bg-brand text-brand-fg hover:opacity-90 shadow-sm shadow-brand/20',
    'secondary' => 'bg-surface-2 text-fg hover:bg-border/60',
    'outline' => 'border border-border bg-surface text-fg hover:bg-surface-2',
    'ghost' => 'text-fg hover:bg-surface-2',
    'danger' => 'bg-red-600 text-white hover:bg-red-700 shadow-sm',
];
const BTN_SIZES = [
    'sm' => 'h-8 px-3 text-xs gap-1.5',
    'md' => 'h-10 px-4 text-sm gap-2',
    'lg' => 'h-11 px-6 text-sm gap-2',
    'icon' => 'h-9 w-9',
];

const ICON_BTN_DANGER = 'inline-flex h-8 w-8 items-center justify-center rounded-lg text-muted hover:bg-red-50 dark:hover:bg-red-500/10 hover:text-red-600 transition-colors cursor-pointer disabled:opacity-30 disabled:hover:bg-transparent disabled:hover:text-muted';
const SMALL_SELECT = 'h-8 rounded-md border border-border bg-surface px-1.5 text-xs text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer';
const FILTER_SELECT = 'h-9 rounded-lg border border-border bg-surface px-2.5 text-sm text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer';

function btn_cls(string $variant = 'primary', string $size = 'md', string $extra = ''): string
{
    return cx(BTN_BASE, BTN_VARIANTS[$variant], BTN_SIZES[$size], $extra);
}

function avatar(string $name, ?string $color = '#6366f1', int $size = 36, string $class = ''): string
{
    $color = preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $color) ? $color : '#6366f1';
    return '<span class="' . e(cx('inline-flex shrink-0 items-center justify-center rounded-full font-semibold text-white select-none', $class)) . '"'
        . ' style="background-color:' . $color . ';width:' . $size . 'px;height:' . $size . 'px;font-size:' . round($size * 0.38, 2) . 'px" title="' . e($name) . '">'
        . e(initials($name)) . '</span>';
}

function safe_color(?string $c, string $fallback = '#94a3b8'): string
{
    return preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $c) ? $c : $fallback;
}

function status_badge(string $label, string $color): string
{
    $c = safe_color($color);
    return '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium dark:brightness-125" style="background-color:' . $c . '22;color:' . $c . '">'
        . '<span class="h-1.5 w-1.5 rounded-full" style="background-color:' . $c . '"></span>' . e($label) . '</span>';
}

function priority_badge(string $label, string $color): string
{
    $c = safe_color($color);
    return '<span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium dark:brightness-125" style="background-color:' . $c . '22;color:' . $c . '">' . e($label) . '</span>';
}

function due_badge(?string $dueAt, bool $isClosed): string
{
    $info = due_info($dueAt, $isClosed);
    if (!$info) return '';
    $tones = [
        'overdue' => 'bg-red-100 dark:bg-red-500/15 text-red-700 dark:text-red-300',
        'soon' => 'bg-amber-100 dark:bg-amber-500/15 text-amber-700 dark:text-amber-300',
        'ok' => 'bg-slate-100 dark:bg-slate-500/15 text-slate-600 dark:text-slate-400',
    ];
    return '<span class="' . cx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium', $tones[$info['tone']]) . '">'
        . icon('clock', 11) . e($info['text']) . '</span>';
}

function page_header(string $title, string $description = '', string $actionHtml = ''): string
{
    return '<div class="mb-6 flex flex-wrap items-start justify-between gap-3"><div>'
        . '<h1 class="text-2xl font-bold tracking-tight">' . e($title) . '</h1>'
        . ($description ? '<p class="mt-1 text-sm text-muted">' . e($description) . '</p>' : '')
        . '</div>' . $actionHtml . '</div>';
}

function card_header(string $titleHtml, string $actionHtml = ''): string
{
    return '<div class="flex items-center justify-between border-b border-border px-5 py-3.5">'
        . '<h3 class="text-sm font-semibold">' . $titleHtml . '</h3>' . $actionHtml . '</div>';
}

function titled(string $icon, string $text): string
{
    return '<span class="inline-flex items-center gap-2">' . icon($icon, 16) . e($text) . '</span>';
}

function alert_error(string $msg): string
{
    return '<div class="rounded-lg bg-red-50 dark:bg-red-500/10 border border-red-200 dark:border-red-500/30 px-3 py-2 text-sm text-red-700 dark:text-red-300">' . e($msg) . '</div>';
}

function alert_success(string $msg, bool $withIcon = false): string
{
    return '<div class="flex items-center gap-2 rounded-lg bg-emerald-50 dark:bg-emerald-500/10 border border-emerald-200 dark:border-emerald-500/30 px-3 py-2 text-sm text-emerald-700 dark:text-emerald-300">'
        . ($withIcon ? icon('circle-check', 15) : '') . e($msg) . '</div>';
}

/** Error / success banner for a form's flash state. */
function banner(?array $state, bool $withIcon = false): string
{
    if (!$state) return '';
    if (!empty($state['error'])) return alert_error($state['error']);
    if (!empty($state['success'])) return alert_success($state['success'], $withIcon);
    return '';
}

function inline_error(?array $state, string $class = ''): string
{
    if (empty($state['error'])) return '';
    return '<p class="' . e(cx('text-sm text-red-600 dark:text-red-400', $class)) . '">' . e($state['error']) . '</p>';
}

function inline_success(?array $state, string $class = ''): string
{
    if (empty($state['success'])) return '';
    return '<p class="' . e(cx('text-sm text-emerald-600 dark:text-emerald-400', $class)) . '">' . e($state['success']) . '</p>';
}

function select_options(array $options, ?string $selected): string
{
    $h = '';
    foreach ($options as $value => $label) {
        $h .= '<option value="' . e($value) . '"' . ((string) $value === (string) $selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    return $h;
}

/** Dropdown: trigger button html + menu html. Behaviour lives in app.js. */
function dropdown(string $triggerHtml, string $menuHtml, int $width = 280, string $align = 'right', bool $mobileSheet = false): string
{
    // $mobileSheet: on phones the menu spans the screen width just below its trigger
    // (fixed + top:auto keeps the static vertical position) instead of overflowing.
    return '<div class="relative" data-dropdown>' . $triggerHtml
        . '<div data-dropdown-menu hidden style="width:' . $width . 'px" class="' . cx('absolute z-50 mt-2 rounded-xl border border-border bg-surface shadow-lg shadow-black/5 animate-in', $align === 'right' ? 'right-0' : 'left-0',
            $mobileSheet ? 'max-sm:fixed max-sm:inset-x-3 max-sm:w-auto! max-sm:max-h-[75vh] max-sm:overflow-y-auto max-sm:shadow-2xl max-sm:shadow-black/20' : '') . '">'
        . $menuHtml . '</div></div>';
}

function theme_toggle(): string
{
    return '<button type="button" data-theme-toggle aria-label="Toggle theme" class="inline-flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer">'
        . '<span class="dark:hidden">' . icon('moon', 18) . '</span><span class="hidden dark:inline">' . icon('sun', 18) . '</span></button>';
}

/** One row in a ticket list. */
function ticket_list_item(array $t): string
{
    $h = '<a href="/tickets/' . e($t['id']) . '" class="flex items-center gap-3 px-4 py-3.5 hover:bg-surface-2 active:bg-surface-2 transition-colors sm:gap-4 sm:px-5">';
    $h .= avatar($t['requester']['name'], $t['requester']['color'], 38, 'max-sm:self-start');
    $h .= '<div class="min-w-0 flex-1"><div class="flex items-center gap-2"><span class="truncate font-medium text-fg">' . e($t['subject']) . '</span></div>';
    $h .= '<div class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-muted">';
    $h .= '<span class="font-mono">#' . (int) $t['number'] . '</span><span>·</span><span>' . e($t['requester']['name']) . '</span>';
    if ($t['category']) {
        $h .= '<span>·</span><span class="inline-flex items-center gap-1"><span class="h-2 w-2 rounded-full" style="background-color:' . safe_color($t['category']['color']) . '"></span>' . e($t['category']['name']) . '</span>';
    }
    $h .= '<span>·</span><span>' . e(time_ago($t['createdAt'])) . '</span>';
    if ($t['commentCount'] > 0) {
        $h .= '<span class="inline-flex items-center gap-1">' . icon('message-square', 12) . (int) $t['commentCount'] . '</span>';
    }
    $h .= '</div>';
    // Mobile: badges move under the title instead of disappearing.
    $h .= '<div class="mt-1.5 flex flex-wrap items-center gap-1.5 sm:hidden">'
        . status_badge($t['status']['label'], $t['status']['color'])
        . priority_badge($t['priority']['label'], $t['priority']['color'])
        . due_badge($t['dueAt'], $t['status']['isClosed']) . '</div>';
    $h .= '</div>';
    $h .= '<div class="hidden items-center gap-2 sm:flex">' . due_badge($t['dueAt'], $t['status']['isClosed'])
        . priority_badge($t['priority']['label'], $t['priority']['color'])
        . status_badge($t['status']['label'], $t['status']['color']) . '</div>';
    $h .= $t['assignee']
        ? avatar($t['assignee']['name'], $t['assignee']['color'], 30, 'hidden md:inline-flex')
        : '<span class="hidden h-[30px] w-[30px] rounded-full border border-dashed border-border md:inline-block"></span>';
    return $h . '</a>';
}

/**
 * Pagination bar: "1–50 of 20,000", page numbers (with gaps), first/prev/next/last
 * and an optional per-page selector. $url(['page' => n]) builds each link.
 */
function pager(int $page, int $pages, int $total, int $perPage, callable $url, array $o = []): string
{
    $from = $total ? ($page - 1) * $perPage + 1 : 0;
    $to = min($total, $page * $perPage);
    $noun = $o['noun'] ?? ['ticket', 'tickets'];
    $btn = 'inline-flex h-9 min-w-9 items-center justify-center rounded-lg px-2 text-sm font-medium transition-colors';
    $link = function (int $p, string $label, string $aria, bool $disabled = false, bool $current = false) use ($url, $btn): string {
        if ($current) return '<span aria-current="page" class="' . $btn . ' bg-brand text-brand-fg">' . $label . '</span>';
        if ($disabled) return '<span aria-disabled="true" class="' . $btn . ' text-muted opacity-40">' . $label . '</span>';
        return '<a href="' . e($url(['page' => $p])) . '" aria-label="' . e($aria) . '" class="' . $btn . ' text-fg hover:bg-surface-2 active:bg-surface-2">' . $label . '</a>';
    };

    $h = '<div class="' . e(cx('flex flex-wrap items-center justify-between gap-x-4 gap-y-2', $o['class'] ?? '')) . '">';
    $h .= '<p class="text-sm text-muted"><span class="font-medium text-fg tabular-nums">' . number_format($from) . '–' . number_format($to) . '</span> of <span class="font-medium text-fg tabular-nums">' . number_format($total) . '</span> ' . ($total === 1 ? $noun[0] : $noun[1]) . '</p>';

    $h .= '<div class="flex items-center gap-2">';
    if (!empty($o['perPageOptions'])) {
        $h .= '<label class="hidden items-center gap-1.5 text-sm text-muted sm:flex">Show'
            . '<select data-per-page class="h-9 rounded-lg border border-border bg-surface px-2 text-sm text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer">';
        foreach ($o['perPageOptions'] as $n) {
            $h .= '<option value="' . e($url(['per' => $n, 'page' => null])) . '"' . ($n === $perPage ? ' selected' : '') . '>' . $n . '</option>';
        }
        $h .= '</select></label>';
    }
    if ($pages > 1) {
        $h .= '<nav class="flex items-center gap-0.5 rounded-xl border border-border bg-surface p-0.5" aria-label="Pagination">';
        $h .= '<span class="hidden sm:contents">' . $link(1, icon('chevrons-left', 16), 'First page', $page <= 1) . '</span>';
        $h .= $link($page - 1, icon('chevron-left', 16), 'Previous page', $page <= 1);
        // Window of page numbers around the current page (fewer on phones).
        $window = [1, $pages];
        for ($i = $page - 2; $i <= $page + 2; $i++) if ($i >= 1 && $i <= $pages) $window[] = $i;
        $window = array_values(array_unique($window));
        sort($window);
        $prev = 0;
        foreach ($window as $p) {
            $near = abs($p - $page) <= 1 || $p === 1 || $p === $pages;
            if ($prev && $p - $prev > 1) $h .= '<span class="px-1 text-sm text-muted">…</span>';
            $h .= '<span class="' . ($near ? 'contents' : 'hidden sm:contents') . '">' . $link($p, number_format($p), "Page $p", false, $p === $page) . '</span>';
            $prev = $p;
        }
        $h .= $link($page + 1, icon('chevron-right', 16), 'Next page', $page >= $pages);
        $h .= '<span class="hidden sm:contents">' . $link($pages, icon('chevrons-right', 16), 'Last page', $page >= $pages) . '</span>';
        $h .= '</nav>';
    }
    return $h . '</div></div>';
}

/** File picker with a removable list (behaviour in app.js). */
function file_input(string $name = 'files'): string
{
    return '<div data-file-input>'
        . '<button type="button" data-file-pick class="inline-flex items-center gap-1.5 rounded-lg border border-border bg-surface px-3 py-1.5 text-sm text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer">'
        . icon('paperclip', 15) . ' Attach files</button>'
        . '<input type="file" name="' . e($name) . '[]" multiple class="hidden">'
        . '<ul data-file-list hidden class="mt-2 space-y-1.5"></ul>'
        . '<p data-file-too-big hidden class="mt-1 text-xs text-red-600 dark:text-red-400">Some files exceed the 10&nbsp;MB limit and will be skipped.</p>'
        . '</div>';
}

function tb_button(string $cmd, string $icon, string $label, string $extra = ''): string
{
    return '<button type="button" data-cmd="' . e($cmd) . '" title="' . e($label) . '" aria-label="' . e($label) . '" ' . $extra
        . ' class="inline-flex h-8 w-8 items-center justify-center rounded-md transition-colors cursor-pointer disabled:opacity-40 disabled:cursor-default text-muted hover:bg-surface-2 hover:text-fg data-[active=true]:bg-brand/15 data-[active=true]:text-brand">'
        . icon($icon, 16) . '</button>';
}

function tb_divider(): string
{
    return '<span class="mx-1 h-5 w-px bg-border"></span>';
}

/**
 * Rich text editor (contenteditable + toolbar). Submits sanitized-on-server
 * HTML in a hidden input named $name. Behaviour lives in editor.js.
 */
function rich_editor(string $name, array $o = []): string
{
    $placeholder = $o['placeholder'] ?? 'Write here…';
    $minHeight = (int) ($o['minHeight'] ?? 140);
    $html = $o['html'] ?? '';
    $mentions = $o['mentions'] ?? [];
    $canned = $o['canned'] ?? [];

    $tb = '<div class="flex items-center gap-0.5 overflow-x-auto overscroll-x-contain border-b border-border px-2 py-1.5 [scrollbar-width:none] sm:flex-wrap sm:overflow-visible [&>*]:shrink-0" data-rte-toolbar>';
    $tb .= '<select data-block title="Text style" class="mr-1 h-8 rounded-md border border-border bg-surface px-1.5 text-xs text-fg focus:outline-none focus:ring-2 focus:ring-brand/40 cursor-pointer">'
        . '<option value="p">Paragraph</option><option value="h1">Heading 1</option><option value="h2">Heading 2</option><option value="h3">Heading 3</option></select>';
    $tb .= tb_divider();
    $tb .= tb_button('bold', 'bold', 'Bold') . tb_button('italic', 'italic', 'Italic') . tb_button('underline', 'underline', 'Underline')
        . tb_button('strikeThrough', 'strikethrough', 'Strikethrough') . tb_button('code', 'code', 'Inline code');
    $tb .= tb_divider();
    foreach ([['foreColor', 'baseline', 'Text color (double-click to reset)'], ['hiliteColor', 'highlighter', 'Highlight color (double-click to remove)']] as [$cmd, $ic, $lbl]) {
        $tb .= '<span class="relative inline-flex" title="' . e($lbl) . '"><span class="inline-flex h-8 items-center justify-center rounded-md px-1 text-muted hover:bg-surface-2 hover:text-fg transition-colors">'
            . icon($ic, 16) . '<span data-swatch class="ml-0.5 h-1 w-4 rounded-full" style="outline:1px solid var(--border)"></span></span>'
            . '<input type="color" data-color="' . $cmd . '" aria-label="' . e($lbl) . '" value="#000000" class="absolute inset-0 cursor-pointer opacity-0"></span>';
    }
    $tb .= tb_divider();
    $tb .= tb_button('insertUnorderedList', 'list', 'Bullet list') . tb_button('insertOrderedList', 'list-ordered', 'Numbered list')
        . tb_button('taskList', 'list-todo', 'Task list') . tb_button('blockquote', 'quote', 'Quote')
        . tb_button('pre', 'code-xml', 'Code block') . tb_button('insertHorizontalRule', 'minus', 'Divider');
    $tb .= tb_divider();
    $tb .= tb_button('justifyLeft', 'align-left', 'Align left') . tb_button('justifyCenter', 'align-center', 'Align center') . tb_button('justifyRight', 'align-right', 'Align right');
    $tb .= tb_divider();
    $tb .= tb_button('link', 'link', 'Link') . tb_button('image', 'image-plus', 'Insert image') . tb_button('table', 'table', 'Insert table');
    if ($canned) {
        $items = '';
        foreach ($canned as $i => $c) {
            $items .= '<button type="button" data-canned="' . $i . '" data-dropdown-close class="block w-full truncate px-3 py-2 text-left text-sm hover:bg-surface-2 transition-colors cursor-pointer">' . e($c['title']) . '</button>';
        }
        $tb .= dropdown(
            '<button type="button" data-dropdown-trigger title="Insert canned response" aria-label="Insert canned response" class="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted hover:bg-surface-2 hover:text-fg transition-colors cursor-pointer">' . icon('message-square-plus', 16) . '</button>',
            '<div class="max-h-72 overflow-y-auto py-1">' . $items . '</div>',
            260,
            'right',
            true,
        );
    }
    $tb .= '<span data-table-tools hidden class="inline-flex items-center gap-0.5">' . tb_divider()
        . tb_button('addColumn', 'columns-3', 'Add column') . tb_button('addRow', 'rows-3', 'Add row') . tb_button('deleteTable', 'trash-2', 'Delete table') . '</span>';
    $tb .= tb_divider();
    $tb .= tb_button('undo', 'undo-2', 'Undo') . tb_button('redo', 'redo-2', 'Redo');
    $tb .= '</div>';

    return '<div data-rte data-mentions="' . json_attr($mentions) . '" data-canned-list="' . json_attr(array_map(fn ($c) => $c['body'], $canned)) . '"'
        . ' class="rounded-lg border border-border bg-surface focus-within:ring-2 focus-within:ring-brand/40 focus-within:border-brand transition">'
        . $tb
        . '<div class="relative px-3 py-2">'
        . '<div data-rte-input contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="' . e($placeholder) . '" class="rich tiptap-input focus:outline-none" style="min-height:' . $minHeight . 'px">' . $html . '</div>'
        . '<span data-rte-uploading hidden class="absolute right-3 top-2 rounded bg-surface-2 px-2 py-0.5 text-xs text-muted">Uploading image…</span>'
        . '</div>'
        . '<input type="file" data-rte-file accept="image/png,image/jpeg,image/gif,image/webp" class="hidden">'
        . '<input type="hidden" name="' . e($name) . '" value="' . e($html) . '" data-rte-value>'
        . '</div>';
}

/** SVG area chart for the 14-day trend. */
function trend_chart(array $days): string
{
    // The plot is an SVG stretched to the box; axis labels and hover targets are
    // HTML positioned in percentages so text never distorts.
    $w = 1000; $h = 200;
    $max = max(1, max(array_column($days, 'count')));
    $ticks = $max <= 4 ? range(0, $max) : array_map(fn ($i) => (int) round($max * $i / 4), range(0, 4));
    $ticks = array_values(array_unique($ticks));
    $n = count($days);
    $x = fn ($i) => $n > 1 ? $i * $w / ($n - 1) : 0;
    $y = fn ($v) => $h * (1 - $v / $max);

    $html = '<div class="relative h-full w-full text-[11px] text-muted" role="img" aria-label="Tickets created per day">';
    $html .= '<div class="absolute left-8 right-3 top-4 bottom-7">';
    foreach ($ticks as $t) {
        $html .= '<span class="absolute -left-2 -translate-x-full -translate-y-1/2" style="top:' . round((1 - $t / $max) * 100, 2) . '%">' . $t . '</span>';
    }
    foreach ($days as $i => $d) {
        if (!($i === $n - 1 || ($i % 2 === 0 && $i < $n - 2))) continue;
        $tx = $i === 0 ? '' : ($i === $n - 1 ? '-translate-x-full' : '-translate-x-1/2');
        $html .= '<span class="absolute top-full mt-2 whitespace-nowrap ' . $tx . '" style="left:' . round($n > 1 ? $i / ($n - 1) * 100 : 0, 2) . '%">' . e($d['date']) . '</span>';
    }
    $html .= '<div class="absolute inset-0 flex">';
    foreach ($days as $d) {
        $html .= '<span class="h-full flex-1 hover:bg-brand/5" title="' . e($d['date']) . ': ' . $d['count'] . ' ' . ($d['count'] === 1 ? 'ticket' : 'tickets') . '"></span>';
    }
    $html .= '</div>';

    $pts = [];
    foreach ($days as $i => $d) $pts[] = [$x($i), $y($d['count'])];
    // Smooth (monotone-ish) path using cubic segments.
    $line = 'M' . round($pts[0][0], 1) . ',' . round($pts[0][1], 1);
    for ($i = 1; $i < $n; $i++) {
        [$x0, $y0] = $pts[$i - 1];
        [$x1, $y1] = $pts[$i];
        $cx = ($x0 + $x1) / 2;
        $line .= ' C' . round($cx, 1) . ',' . round($y0, 1) . ' ' . round($cx, 1) . ',' . round($y1, 1) . ' ' . round($x1, 1) . ',' . round($y1, 1);
    }
    $area = $line . ' L' . round($pts[$n - 1][0], 1) . ',' . $h . ' L' . round($pts[0][0], 1) . ',' . $h . ' Z';

    $svg = '<svg viewBox="0 0 ' . $w . ' ' . $h . '" class="pointer-events-none absolute inset-0 h-full w-full overflow-visible" preserveAspectRatio="none" aria-hidden="true">';
    $svg .= '<defs><linearGradient id="trendg" x1="0" y1="0" x2="0" y2="1"><stop offset="0%" stop-color="#6366f1" stop-opacity="0.35"/><stop offset="100%" stop-color="#6366f1" stop-opacity="0"/></linearGradient></defs>';
    $svg .= '<path d="' . $area . '" fill="url(#trendg)"/>';
    $svg .= '<path d="' . $line . '" fill="none" stroke="#6366f1" stroke-width="2" vector-effect="non-scaling-stroke"/>';
    $svg .= '</svg>';
    return $html . $svg . '</div></div>';
}

/** SVG donut chart with legend for the status breakdown. */
function status_pie(array $data): string
{
    $total = array_sum(array_column($data, 'value'));
    $r = 70; $cx = 100; $cy = 90; $sw = 30;
    $circ = 2 * M_PI * $r;
    $gap = count($data) > 1 ? 2 : 0;
    $svg = '<svg viewBox="0 0 200 180" class="mx-auto h-44 w-full" role="img" aria-label="Tickets by status">';
    $offset = 0;
    foreach ($data as $d) {
        $len = $total ? $d['value'] / $total * $circ : 0;
        $dash = max(0, $len - $gap);
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $r . '" fill="none" stroke="' . safe_color($d['color']) . '" stroke-width="' . $sw . '"'
            . ' stroke-dasharray="' . round($dash, 2) . ' ' . round($circ - $dash, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 ' . $cx . ' ' . $cy . ')">'
            . '<title>' . e($d['name']) . ': ' . $d['value'] . '</title></circle>';
        $offset += $len;
    }
    $svg .= '<text x="' . $cx . '" y="' . ($cy + 8) . '" text-anchor="middle" style="fill:var(--fg);font-size:24px;font-weight:700">' . $total . '</text></svg>';
    $legend = '<div class="flex flex-wrap justify-center gap-x-4 gap-y-1 px-4 pb-4 text-xs text-muted">';
    foreach ($data as $d) {
        $legend .= '<span class="inline-flex items-center gap-1.5"><span class="h-2.5 w-2.5 rounded-full" style="background-color:' . safe_color($d['color']) . '"></span>' . e($d['name']) . '</span>';
    }
    return '<div class="w-full pt-4">' . $svg . $legend . '</div></div>';
}
