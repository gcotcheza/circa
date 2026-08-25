<?php

declare(strict_types=1);

namespace App\Console\Commands;

use GdImage;
use RuntimeException;
use Illuminate\Console\Command;

/**
 * See docs/rationale-app.md § "GeneratePwaIconsCommand: one drawn
 * geometry, not six exported files".
 */
final class GeneratePwaIconsCommand extends Command
{
    protected $signature = 'pwa:icons {--dir= : Output directory (default public/icons)}';

    protected $description = 'Draw the PWA icon set (SVG master + PNGs) from one geometry definition';

    private const CANVAS = 512;

    private const OVERSAMPLE = 4;

    // Teal-600, matching the Inertia progress bar.
    /** @var array{int<0, 255>, int<0, 255>, int<0, 255>} */
    private const INK = [0x0D, 0x94, 0x88];

    // Stone-50, the app's light background — so the plate is the page.
    /** @var array{int<0, 255>, int<0, 255>, int<0, 255>} */
    private const PLATE = [0xFA, 0xFA, 0xF9];

    // Fraction of the canvas, per cropping.
    private const RADIUS_PLAIN = 0.328;   // 168/512 — as large as looks right unmasked

    private const RADIUS_MASKABLE = 0.273; // 140/512 — inside the 40% safe circle with room

    // Units of plate radius from plate centre; asymmetric on purpose — a
    // symmetric zigzag reads as a chevron, not a pulse.
    /** @var list<array{float, float}> */
    private const TRACE = [
        [-0.82, 0.0],
        [-0.34, 0.0],
        [-0.15, -0.42],
        [0.06, 0.44],
        [0.26, 0.0],
        [0.82, 0.0],
    ];

    // In units of the plate radius.
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

        // SVG master first: what a human edits, and vector-preferring browsers get.
        $written[] = $this->writeSvg($dir.'/icon.svg', self::RADIUS_PLAIN);
        $written[] = $this->writeSvg($dir.'/icon-maskable.svg', self::RADIUS_MASKABLE);

        foreach ([192, 512] as $size) {
            $written[] = $this->writePng($dir."/icon-{$size}.png", $size, self::RADIUS_PLAIN);
            $written[] = $this->writePng($dir."/icon-maskable-{$size}.png", $size, self::RADIUS_MASKABLE);
        }

        // 180 px is the current @3x size. iOS masks with its own squircle
        // (PLAIN, not Android's circle) and must be opaque — a transparent
        // apple-touch-icon composites onto black.
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

        // Quality comes from 2048 px of hard-edged GD primitives, downsampled.
        imagecopyresampled($out, $canvas, 0, 0, 0, 0, $size, $size, $big, $big);

        imagepng($out, $path, 9);

        imagedestroy($canvas);
        imagedestroy($out);

        return $path;
    }

    /**
     * GD draws thick lines with butt caps and no joins; a filled circle at
     * each vertex fakes the round cap/join the SVG's `stroke-linejoin` gives.
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

    // `imagecolorallocate` returns false only on a full palette (impossible,
    // truecolor); turned into a named exception instead of a bare TypeError.
    /** @param  array{int<0, 255>, int<0, 255>, int<0, 255>}  $rgb */
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
