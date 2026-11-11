<?php
declare(strict_types=1);

/*
 * Allowlist HTML sanitizer for user-authored rich text (same policy as the
 * original sanitize-html configuration). Disallowed tags are unwrapped (their
 * text is kept); script-like tags are dropped entirely.
 */

const SAN_TAGS = [
    'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'strike', 'mark', 'span',
    'h1', 'h2', 'h3', 'a', 'hr',
    'ul', 'ol', 'li', 'blockquote', 'code', 'pre', 'div',
    'img',
    'table', 'thead', 'tbody', 'tr', 'th', 'td', 'colgroup', 'col',
];

const SAN_ATTRS = [
    'a' => ['href', 'target', 'rel'],
    'p' => ['style'], 'h1' => ['style'], 'h2' => ['style'], 'h3' => ['style'],
    'div' => ['style'],
    'span' => ['style', 'data-type', 'data-id', 'data-label', 'class'],
    'mark' => ['style', 'data-color'],
    'img' => ['src', 'alt'],
    'table' => ['style'],
    'col' => ['style', 'span'],
    'th' => ['colspan', 'rowspan', 'colwidth', 'style'],
    'td' => ['colspan', 'rowspan', 'colwidth', 'style'],
    'ul' => ['data-type'],
    'li' => ['data-checked', 'data-type', 'style'],
];

// Tags whose content is discarded together with the tag.
const SAN_DROP = ['script', 'style', 'textarea', 'option', 'noscript', 'iframe', 'object', 'embed', 'template', 'svg', 'math', 'head', 'title', 'select', 'input', 'button'];

function san_style(string $style): string
{
    $color = '/^(#(0x)?[0-9a-fA-F]{3,8}|rgba?\([\d\s.,%]+\))$/';
    $size = '/^\d+(\.\d+)?(px|%)$/';
    $rules = [
        'text-align' => '/^(left|right|center|justify)$/',
        'color' => $color,
        'background-color' => $color,
        'width' => $size,
        'min-width' => $size,
    ];
    $out = [];
    foreach (explode(';', $style) as $decl) {
        if (!str_contains($decl, ':')) continue;
        [$prop, $val] = array_map('trim', explode(':', $decl, 2));
        $prop = strtolower($prop);
        $val = trim(preg_replace('/\s*!important\s*$/i', '', $val));
        if (isset($rules[$prop]) && preg_match($rules[$prop], $val)) $out[] = "$prop:$val";
    }
    return implode(';', $out);
}

function san_url(string $url, bool $isImg): ?string
{
    $url = trim(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');
    if ($url === '') return null;
    if (preg_match('/^([a-zA-Z][a-zA-Z0-9+.-]*):/', $url, $m)) {
        $scheme = strtolower($m[1]);
        $allowed = $isImg ? ['http', 'https'] : ['http', 'https', 'mailto'];
        return in_array($scheme, $allowed, true) ? $url : null;
    }
    if (str_starts_with($url, '//')) return $url; // protocol-relative
    return $url; // relative URL (e.g. /api/attachments/…)
}

function sanitize_rich_html(?string $html): string
{
    $html = (string) $html;
    if (trim($html) === '') return '';

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    $root = $doc->getElementById('__root');
    if (!$root) return '';
    san_walk($root);

    $out = '';
    foreach ($root->childNodes as $child) $out .= $doc->saveHTML($child);
    return trim($out);
}

function san_walk(DOMNode $node): void
{
    $children = [];
    foreach ($node->childNodes as $c) $children[] = $c;

    foreach ($children as $child) {
        if ($child instanceof DOMComment || $child instanceof DOMProcessingInstruction) {
            $node->removeChild($child);
            continue;
        }
        if (!$child instanceof DOMElement) continue;

        $tag = strtolower($child->tagName);
        if (in_array($tag, SAN_DROP, true)) {
            $node->removeChild($child);
            continue;
        }
        if (!in_array($tag, SAN_TAGS, true)) {
            // Unwrap: sanitize the children first, then hoist them.
            san_walk($child);
            while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
            $node->removeChild($child);
            continue;
        }

        $allowed = SAN_ATTRS[$tag] ?? [];
        $attrs = [];
        foreach ($child->attributes as $a) $attrs[] = $a->name;
        foreach ($attrs as $name) {
            $lname = strtolower($name);
            $val = $child->getAttribute($name);
            if (!in_array($lname, $allowed, true)) {
                $child->removeAttribute($name);
                continue;
            }
            if ($lname === 'style') {
                $clean = san_style($val);
                $clean === '' ? $child->removeAttribute($name) : $child->setAttribute($name, $clean);
            } elseif ($lname === 'href' || $lname === 'src') {
                $u = san_url($val, $tag === 'img');
                $u === null ? $child->removeAttribute($name) : $child->setAttribute($name, $u);
            }
        }
        if ($tag === 'img' && !$child->hasAttribute('src')) {
            $node->removeChild($child);
            continue;
        }
        if ($tag === 'a') {
            $child->setAttribute('target', '_blank');
            $child->setAttribute('rel', 'noopener noreferrer nofollow');
        }
        san_walk($child);
    }
}

/** Plain-text projection (for length validation / previews). */
function html_to_text(?string $html): string
{
    $t = strip_tags(preg_replace('/<(br|\/p|\/div|\/li|\/h\d)[^>]*>/i', ' $0', (string) $html));
    $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim(preg_replace('/\s+/u', ' ', $t));
}
