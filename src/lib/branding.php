<?php
declare(strict_types=1);

/*
 * Platform branding: the app's name and logo, set by a super admin
 * (Settings → Branding). Kept in storage/branding/ so it needs no database
 * change and survives code deploys (storage/ is never part of a deploy).
 */

const BRAND_DEFAULT_NAME = 'TicketingOS';
const BRAND_LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
const BRAND_LOGO_MAX = 1024 * 1024;

function brand_dir(): string
{
    return ROOT . '/storage/branding';
}

/** ['name' => string, 'logo' => ?filename, 'updatedAt' => ?iso] */
function branding(): array
{
    static $b = null;
    if ($b !== null) return $b;
    $raw = @file_get_contents(brand_dir() . '/branding.json');
    $data = $raw ? (json_decode($raw, true) ?: []) : [];
    $logo = isset($data['logo']) && is_string($data['logo']) && preg_match('/^logo\.(png|jpg|webp|svg)$/', $data['logo'])
        && is_file(brand_dir() . '/' . $data['logo']) ? $data['logo'] : null;
    $name = trim((string) ($data['name'] ?? ''));
    return $b = ['name' => $name !== '' ? $name : BRAND_DEFAULT_NAME, 'logo' => $logo, 'signup' => (bool) ($data['signup'] ?? false), 'updatedAt' => $data['updatedAt'] ?? null];
}

/**
 * May visitors create a new workspace at /register? Off unless a super admin
 * turns it on (Settings → Branding). A brand-new install with no workspace yet
 * stays open so the first account can be created.
 */
function signup_open(): bool
{
    return branding()['signup'] || !q_val('SELECT 1 FROM "Tenant" LIMIT 1');
}

function app_name(): string
{
    return branding()['name'];
}

/** URL of the uploaded logo (cache-busted), or null when none is set. */
function brand_logo_url(): ?string
{
    $logo = branding()['logo'];
    if (!$logo) return null;
    return '/brand/logo?v=' . (int) @filemtime(brand_dir() . '/' . $logo);
}

/** The logo mark: the uploaded image, or the default gradient ticket badge. */
function brand_mark(int $size = 36, string $radius = 'rounded-xl'): string
{
    $url = brand_logo_url();
    if ($url) {
        return '<img src="' . e($url) . '" alt="" width="' . $size . '" height="' . $size . '" class="' . $radius . ' shrink-0 object-contain" style="width:' . $size . 'px;height:' . $size . 'px">';
    }
    return '<span class="grid shrink-0 place-items-center ' . $radius . ' bg-gradient-to-br from-brand to-violet-600 text-white shadow-md shadow-brand/25" style="width:' . $size . 'px;height:' . $size . 'px">'
        . icon('ticket', (int) round($size / 2)) . '</span>';
}

function branding_save(array $data): void
{
    if (!is_dir(brand_dir())) mkdir(brand_dir(), 0775, true);
    $cur = branding();
    $out = ['name' => $data['name'] ?? $cur['name'], 'logo' => array_key_exists('logo', $data) ? $data['logo'] : $cur['logo'],
        'signup' => array_key_exists('signup', $data) ? (bool) $data['signup'] : $cur['signup'], 'updatedAt' => now_iso()];
    file_put_contents(brand_dir() . '/branding.json', json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/** Serve the logo file (public: shown on the sign-in page). */
function brand_logo_out(): never
{
    $logo = branding()['logo'];
    if (!$logo) abort(404);
    $path = brand_dir() . '/' . $logo;
    $type = array_search(pathinfo($logo, PATHINFO_EXTENSION), BRAND_LOGO_TYPES, true) ?: 'application/octet-stream';
    header('Content-Type: ' . $type);
    header('Cache-Control: public, max-age=31536000, immutable');
    // An SVG must never run scripts, even when opened directly.
    if ($type === 'image/svg+xml') header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox");
    header('Content-Length: ' . filesize($path));
    readfile($path);
    throw new HttpExit();
}
