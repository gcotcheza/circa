<?php

declare(strict_types=1);

namespace Tests\Unit\Rollup;

use PHPUnit\Framework\TestCase;
use App\Services\Rollup\IntakeBand;

/**
 * The quadrature band, in isolation.
 *
 * SPEC.md: "combine per-item ranges in quadrature (RSS of half-widths) around a
 * midpoint; linear min/max sums are uselessly wide." These tests stop someone
 * "simplifying" that back into a linear sum, which would look correct.
 */
final class IntakeBandTest extends TestCase
{
    public function test_half_widths_combine_in_quadrature_not_linearly(): void
    {
        // Three items, each 300 ± 40 kcal.
        $band = IntakeBand::fromRanges([
            ['min' => 260.0, 'max' => 340.0],
            ['min' => 260.0, 'max' => 340.0],
            ['min' => 260.0, 'max' => 340.0],
        ]);

        self::assertSame(900.0, $band->mid);

        // Linear would be ±120. Quadrature is sqrt(3) * 40 = 69.28.
        $expected = sqrt(3) * 40;

        self::assertEqualsWithDelta(900.0 - $expected, $band->min, 0.001);
        self::assertEqualsWithDelta(900.0 + $expected, $band->max, 0.001);

        // The point: materially narrower than the linear sum.
        self::assertLessThan(120.0, $band->max - $band->mid);
    }

    public function test_a_certain_item_contributes_no_width(): void
    {
        // A typed manual entry: min == max.
        $band = IntakeBand::fromRanges([
            ['min' => 240.0, 'max' => 240.0],
            ['min' => 500.0, 'max' => 500.0],
        ]);

        self::assertSame(740.0, $band->mid);
        self::assertSame(740.0, $band->min);
        self::assertSame(740.0, $band->max);
    }

    public function test_one_wide_item_dominates_the_band(): void
    {
        // RSS is dominated by the largest term, which is what makes the band
        // useful: pinning down small items barely helps if one is a guess.
        $band = IntakeBand::fromRanges([
            ['min' => 100.0, 'max' => 500.0],  // half-width 200
            ['min' => 195.0, 'max' => 205.0],  // half-width 5
        ]);

        self::assertSame(500.0, $band->mid);
        self::assertEqualsWithDelta(sqrt(200 ** 2 + 5 ** 2), $band->max - 500.0, 0.001);
    }

    public function test_the_lower_bound_is_clamped_at_zero(): void
    {
        // One very uncertain item would otherwise floor negative, and a negative
        // intake is not a thing.
        $band = IntakeBand::fromRanges([['min' => 0.0, 'max' => 1000.0]]);

        self::assertSame(500.0, $band->mid);
        self::assertSame(0.0, $band->min);
    }

    public function test_no_items_is_no_band_rather_than_a_zero(): void
    {
        $band = IntakeBand::fromRanges([]);

        self::assertTrue($band->isEmpty());
        self::assertNull($band->mid);

        // Nothing logged is not "ate nothing", and a 0 in kcal_in_mid would feed
        // the TDEE mean as if it were.
        self::assertNull($band->min);
        self::assertNull($band->max);
    }
}
