<?php
declare(strict_types=1);

/*
 * App version. The number lives in the VERSION file at the project root and the
 * release notes in CHANGELOG.md ("## [1.9.0] - 2026-11-12", "### Added", "- item").
 * To release: bump VERSION, add a CHANGELOG section, commit, tag vX.Y.Z.
 */

define('APP_VERSION', (function (): string {
    $v = trim((string) @file_get_contents(ROOT . '/VERSION'));
    return preg_match('/^\d+\.\d+\.\d+([-+][0-9A-Za-z.-]+)?$/', $v) ? $v : '0.0.0';
})());

/** Releases from CHANGELOG.md, newest first: [['version','date','sections' => [title => [items]]], ...] */
function app_changelog(): array
{
    static $out = null;
    if ($out !== null) return $out;
    $out = [];
    $md = (string) @file_get_contents(ROOT . '/CHANGELOG.md');
    $cur = null;
    $sec = null;
    foreach (preg_split('/\R/', $md) as $line) {
        if (preg_match('/^## \[([^\]]+)\](?:\s*-\s*(\S+))?/', $line, $m)) {
            if ($cur) $out[] = $cur;
            $cur = ['version' => $m[1], 'date' => $m[2] ?? '', 'sections' => []];
            $sec = null;
        } elseif ($cur && preg_match('/^### (.+)$/', $line, $m)) {
            $sec = trim($m[1]);
            $cur['sections'][$sec] = [];
        } elseif ($cur && preg_match('/^- (.+)$/', $line, $m)) {
            $cur['sections'][$sec ?? 'Changes'][] = trim($m[1]);
        } elseif ($cur && $sec && preg_match('/^\s{2,}(\S.*)$/', $line, $m) && $cur['sections'][$sec]) {
            // Continuation of the previous bullet.
            $cur['sections'][$sec][count($cur['sections'][$sec]) - 1] .= ' ' . trim($m[1]);
        }
    }
    if ($cur) $out[] = $cur;
    return $out;
}

/** The release entry for a version (default: the running one), or null. */
function app_release(?string $version = null): ?array
{
    foreach (app_changelog() as $r) if ($r['version'] === ($version ?? APP_VERSION)) return $r;
    return null;
}

/** Changelog text to HTML: escapes everything, then **bold**, `code` and [links](https://…). */
function changelog_inline(string $text): string
{
    $h = e($text);
    $h = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $h);
    $h = preg_replace('/`([^`]+)`/', '<code class="rounded bg-surface-2 px-1 py-0.5 text-[0.85em]">$1</code>', $h);
    return preg_replace('/\[([^\]]+)\]\((https?:\/\/[^)\s]+)\)/', '<a href="$2" target="_blank" rel="noopener" class="text-brand underline">$1</a>', $h);
}
