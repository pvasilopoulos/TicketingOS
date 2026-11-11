<?php
declare(strict_types=1);

/** Inline Lucide SVG icon. */
function icon(string $name, int $size = 18, string $class = ''): string
{
    static $icons = null;
    $icons ??= require __DIR__ . '/icons_data.php';
    $inner = $icons[$name] ?? '';
    $cls = trim('lucide shrink-0 ' . $class);
    return '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="' . e($cls) . '" aria-hidden="true">' . $inner . '</svg>';
}
