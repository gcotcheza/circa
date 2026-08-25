<?php

declare(strict_types=1);

namespace App\Console\Commands;

use GdImage;
use RuntimeException;
use Illuminate\Console\Command;

/**
 * `php artisan pwa:icons` — draw the whole home-screen icon set from one
 * definition.
 *
 * A command rather than committed PNGs: six hand-exported files at six sizes
 * and two croppings (plain, maskable) drift, one re-cut with a slightly
 * different margin. Geometry is written down ONCE, in the constants below,
 * and every file — including the SVG master — is drawn from it; regenerating
 * is `php artisan pwa:icons`, and the diff is either empty or intentional.
 *
 * No SVG rasteriser is installed here, and adding one for six icons would be
 * a new deploy dependency; ext-gd is already required (step 5's photo
 * re-encode), so PNGs are drawn with GD at 4x the output and downsampled
 * with `imagecopyresampled` — that supersampling IS the antialiasing, since
 * GD's `imageantialias()` doesn't apply to thick lines or filled ellipses,
 * which is all this icon is made of.
 *
 * THE DESIGN: a white plate from above, a heart-rate trace, on the app's
 * teal — two shapes, two colours, sized for a ~60 px home-screen icon where
 * a ring goes grey, a fork-and-knife is redundant, and a gradient fights the
 * OS's own rounding.
 *
 * TWO CROPPINGS rather than one `any maskable` file: a maskable icon crops to
 * a circle inscribed in 80% of the square, so its glyph must be smaller —
 * one file means either a wastefully small plain glyph or a maskable plate
 * shaved off on Android.
 */
final class GeneratePwaIconsCommand extends Command
{
    protected $signature = 'pwa:icons {--dir= : Output directory (default public/icons)}';

    protected $description = 'Draw the PWA icon set (SVG master + PNGs) from one geometry definition';

    /** The design canvas. Every constant below is in these units. */
    private const CANVAS = 512;

    /** Supersampling factor for the PNG rasteriser. */
    private const OVERSAMPLE = 4;

    /**
     * Teal-600 — the app's accent, the same one the Inertia progress bar uses.
     *
     * @var array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private const INK = [0x0D, 0x94, 0x88];

    /**
     * Stone-50 — the app's light background, so the plate is the page.
     *
     * @var array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private const PLATE = [0xFA, 0xFA, 0xF9];

    /** Plate radius, as a fraction of the canvas, per cropping. */
    private const RADIUS_PLAIN = 0.328;   // 168/512 — as large as looks right unmasked

    private const RADIUS_MASKABLE = 0.273; // 140/512 — inside the 40% safe circle with room

    /**
     * The trace, in units of the plate radius, relative to the plate centre.
     *
     * Flat, up, hard down, flat — the shape everybody reads as a pulse,
     * asymmetric on purpose since a symmetric zigzag reads as a chevron.
     *
     * @var list<array{float, float}>
     */
    private const TRACE = [
        [-0.82, 0.0],
        [-0.34, 0.0],
        [-0.15, -0.42],
        [0.06, 0.44],
        [0.26, 0.0],
        [0.82, 0.0],
    ];

    /** Trace stroke width, in units of the plate radius. */
    private const STROKE = 0.178;

    public function handle(): int
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->error('ext-gd is not loaded. Run this inside the app container.');

            return self::FAILURE;
        }

        $dir = (string) ($this->option('dir') ?? '') ?: public_path('icons');

        if (! is_dir($dir) && ! mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            $this->error("Could not create {$dir}.");

            return self::FAILURE;
        }

        $written = [];

        // SVG master first: the artefact a human edits numbers against, and
        // what's served to browsers that prefer vector icons.
        $written[] = $this->writeSvg($dir.'/icon.svg', self::RADIUS_PLAIN);
        $written[] = $this->writeSvg($dir.'/icon-maskable.svg', self::RADIUS_MASKABLE);

        foreach ([192, 512] as $size) {
            $written[] = $this->writePng($dir."/icon-{$size}.png", $size, self::RADIUS_PLAIN);
            $written[] = $this->writePng($dir."/icon-maskable-{$size}.png", $size, self::RADIUS_MASKABLE);
        }

        // iOS: 180 px is the current @3x size. iOS applies its own squircle
        // mask, so this uses PLAIN cropping (not Android's 80% circle) and
        // must be opaque — a transparent apple-touch-icon composites onto
        // black.
        $written[] = $this->writePng($dir.'/apple-touch-icon-180.png', 180, self::RADIUS_PLAIN);

        foreach ($written as $path) {
            $this->line(sprintf('  %-42s %s', basename($path), $this->humanBytes(filesize($path) ?: 0)));
        }

        $this->info(count($written).' icons written to '.$dir);

        return self::SUCCESS;
    }

    private function writeSvg(string $path, float $radiusFraction): string
    {
        $c = self::CANVAS / 2;
        $r = self::CANVAS * $radiusFraction;

        $d = '';

        foreach (self::TRACE as $i => [$x, $y]) {
            $d .= ($i === 0 ? 'M' : 'L')
                .$this->round($c + $x * $r).' '
                .$this->round($c + $y * $r).' ';
        }

        $svg = <<<SVG
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 512 512" width="512" height="512" role="img" aria-label="Health Tracker">
              <title>Health Tracker</title>
              <rect width="512" height="512" fill="{$this->hex(self::INK)}"/>
              <circle cx="{$this->round($c)}" cy="{$this->round($c)}" r="{$this->round($r)}" fill="{$this->hex(self::PLATE)}"/>
              <path d="{$this->trim($d)}" fill="none" stroke="{$this->hex(self::INK)}" stroke-width="{$this->round($r * self::STROKE)}" stroke-linecap="round" stroke-linejoin="round"/>
            </svg>

            SVG;

        file_put_contents($path, $svg);

        return $path;
    }

    /** @param  int<1, max>  $size  an icon edge; every caller passes a constant */
    private function writePng(string $path, int $size, float $radiusFraction): string
    {
        $big = self::CANVAS * self::OVERSAMPLE;

        $canvas = imagecreatetruecolor($big, $big);

        $ink = $this->color($canvas, self::INK);
        $plate = $this->color($canvas, self::PLATE);

        imagefilledrectangle($canvas, 0, 0, $big, $big, $ink);

        $c = $big / 2;
        $r = $big * $radiusFraction;

        imagefilledellipse($canvas, (int) round($c), (int) round($c), (int) round($r * 2), (int) round($r * 2), $plate);

        $this->stroke($canvas, $c, $r, $ink);

        $out = imagecreatetruecolor($size, $size);

        // The one place the quality comes from: 2048 px of hard-edged GD
        // primitives averaged down to 512 or 180.
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);

        imagepng($out, $path, 9);

        imagedestroy($canvas);
        imagedestroy($out);

        return $path;
    }

    /**
     * The trace, as thick segments plus a disc at every vertex.
     *
     * GD draws thick lines with butt ends and no joins — a bare polyline would
     * notch at each corner and chop off at the ends. A filled circle of the
     * stroke's diameter at every vertex IS a round cap and round join, the
     * same thing `stroke-linejoin="round"` means in the SVG above — keeping
     * both renderings identical.
     */
    private function stroke(GdImage $canvas, float $c, float $r, int $ink): void
    {
        $width = (int) round($r * self::STROKE);

        $points = array_map(
            static fn (array $p): array => [(int) round($c + $p[0] * $r), (int) round($c + $p[1] * $r)],
            self::TRACE,
        );

        imagesetthickness($canvas, $width);

        for ($i = 1; $i < count($points); $i++) {
            imageline($canvas, $points[$i - 1][0], $points[$i - 1][1], $points[$i][0], $points[$i][1], $ink);
        }

        imagesetthickness($canvas, 1);

        foreach ($points as [$x, $y]) {
            imagefilledellipse($canvas, $x, $y, $width, $width, $ink);
        }
    }

    /**
     * A palette entry, or a message saying which colour could not be had.
     *
     * `imagecolorallocate` returns false on a full palette — impossible on a
     * truecolor canvas. Handing that false to the next GD call is already
     * fatal under strict_types ("must be of type int, false given"); what
     * this buys is a named exception that says WHICH colour, instead of a
     * TypeError naming an argument position.
     *
     * @param  array{int<0, 255>, int<0, 255>, int<0, 255>}  $rgb
     */
    private function color(GdImage $canvas, array $rgb): int
    {
        $color = imagecolorallocate($canvas, ...$rgb);

        return $color === false
            ? throw new RuntimeException('GD could not allocate '.$this->hex($rgb).'.')
            : $color;
    }

    /** @param array{int, int, int} $rgb */
    private function hex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    private function round(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private function trim(string $d): string
    {
        return rtrim($d);
    }

    private function humanBytes(int $bytes): string
    {
        return $bytes < 1024 ? "{$bytes} B" : round($bytes / 1024, 1).' KB';
    }
}
