<?php
declare(strict_types=1);

/*
 * Generate the PWA / home-screen icons with GD (life-buoy mark on brand colour).
 *   php bin/make-icons.php
 * Writes public/app-icons/*.png
 */

if (!function_exists('imagecreatetruecolor')) {
    fwrite(STDERR, "The GD extension is required (enable extension=gd).\n");
    exit(1);
}

$out = dirname(__DIR__) . '/public/app-icons';
if (!is_dir($out)) mkdir($out, 0775, true);

/**
 * @param float $glyph  glyph diameter as a fraction of the canvas
 * @param float $radius corner radius fraction (0 = full-bleed square for maskable)
 */
function draw_icon(int $size, float $glyph, float $radius, bool $mono = false): GdImage
{
    $ss = 4; // supersample for smooth edges
    $S = $size * $ss;
    $im = imagecreatetruecolor($S, $S);
    imagesavealpha($im, true);
    imagealphablending($im, true);
    $clear = imagecolorallocatealpha($im, 0, 0, 0, 127);
    imagefill($im, 0, 0, $clear);

    $brand = $mono ? imagecolorallocatealpha($im, 0, 0, 0, 127) : imagecolorallocate($im, 0x4f, 0x46, 0xe5);
    $white = imagecolorallocate($im, 255, 255, 255);
    $light = $mono ? $white : imagecolorallocate($im, 0xc7, 0xd2, 0xfe);

    if (!$mono) {
        // Rounded-rect background.
        $r = (int) ($S * $radius);
        imagefilledrectangle($im, $r, 0, $S - $r - 1, $S - 1, $brand);
        imagefilledrectangle($im, 0, $r, $S - 1, $S - $r - 1, $brand);
        if ($r > 0) {
            foreach ([[$r, $r], [$S - $r - 1, $r], [$r, $S - $r - 1], [$S - $r - 1, $S - $r - 1]] as [$cx, $cy]) {
                imagefilledellipse($im, $cx, $cy, $r * 2, $r * 2, $brand);
            }
        }
    }

    // Life buoy: ring split into 4 white / 4 light segments.
    $c = intdiv($S, 2);
    $outer = (int) ($S * $glyph);
    $inner = (int) ($outer * 0.5);
    for ($i = 0; $i < 8; $i++) {
        $color = $i % 2 === 0 ? $white : $light;
        imagefilledarc($im, $c, $c, $outer, $outer, $i * 45 - 22, $i * 45 + 23, $color, IMG_ARC_PIE);
    }
    // Punch the hole.
    $hole = $mono ? imagecolorallocatealpha($im, 0, 0, 0, 127) : $brand;
    imagealphablending($im, false);
    imagefilledellipse($im, $c, $c, $inner, $inner, $hole);
    imagealphablending($im, true);

    $dst = imagecreatetruecolor($size, $size);
    imagesavealpha($dst, true);
    imagealphablending($dst, false);
    imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
    imagecopyresampled($dst, $im, 0, 0, 0, 0, $size, $size, $S, $S);
    return $dst;
}

$targets = [
    'icon-192.png' => [192, 0.58, 0.22],
    'icon-512.png' => [512, 0.58, 0.22],
    'maskable-512.png' => [512, 0.46, 0.0],   // glyph inside the 80% safe zone
    'apple-touch-icon.png' => [180, 0.56, 0.0], // iOS rounds the corners itself
];
foreach ($targets as $file => [$size, $glyph, $radius]) {
    imagepng(draw_icon($size, $glyph, $radius), "$out/$file", 9);
    echo "public/app-icons/$file\n";
}
// Monochrome badge for the Android status bar.
imagepng(draw_icon(96, 0.86, 0, true), "$out/badge-96.png", 9);
echo "public/app-icons/badge-96.png\n";
