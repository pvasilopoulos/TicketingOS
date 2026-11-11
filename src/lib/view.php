<?php
declare(strict_types=1);

require VIEWS . '/partials/components.php';
require VIEWS . '/partials/auth_ui.php';

/**
 * Render a page template inside a layout.
 *   render('pages/dashboard', ['x' => 1], 'app')
 * The page template may set $title; the layout receives $content.
 */
function render(string $template, array $vars = [], string $layout = 'base'): string
{
    $vars['__layout'] = $layout;
    $content = render_partial($template, $vars);
    if ($layout === 'none') return $content;
    $vars['content'] = $content;
    return render_partial("layouts/$layout", $vars);
}

function render_partial(string $template, array $vars = []): string
{
    extract($vars, EXTR_SKIP);
    ob_start();
    try {
        require VIEWS . "/$template.php";
    } catch (Throwable $e) {
        ob_end_clean();
        throw $e;
    }
    return ob_get_clean();
}

function view(string $template, array $vars = [], string $layout = 'base'): never
{
    header('Content-Type: text/html; charset=utf-8');
    echo render($template, $vars, $layout);
    throw new HttpExit();
}

/** Get/set the document title for the current page. */
function page_title(?string $set = null): ?string
{
    static $title = null;
    if ($set !== null) $title = $set;
    return $title;
}

/** Common hidden inputs for a mutating form. */
function form_meta(string $form = '', ?string $back = null): string
{
    $back ??= $_SERVER['REQUEST_URI'] ?? '/';
    return csrf_field()
        . '<input type="hidden" name="_form" value="' . e($form) . '">'
        . '<input type="hidden" name="_back" value="' . e($back) . '">';
}

/** Current query string params (for filter links). */
function query_params(): array
{
    $out = [];
    foreach ($_GET as $k => $v) if (is_string($v) && $v !== '') $out[$k] = $v;
    return $out;
}
