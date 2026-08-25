<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use Tests\TestCase;
use App\Services\Reporting\EnergyBalance;

/**
 * Burn minus intake, in the four states a day can be in.
 *
 * The display this replaces read "Surplus -143–-49 kcal" — three sign-like
 * glyphs in a row, with the larger surplus written as the smaller number. Every
 * decision that goes into fixing that lives in EnergyBalance rather than in the
 * component, because a Vue template has nowhere to answer "what happens at
 * exactly zero".
 */
final class EnergyBalanceTest extends TestCase
{
    public function test_a_band_wholly_above_zero_is_a_deficit(): void
    {
        // Burned 2 400, ate 1 900-2 100 -> 300-500 kcal of deficit.
        $balance = EnergyBalance::from(2400.0, 1900.0, 2000.0, 2100.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::DEFICIT, $balance->direction);
        self::assertSame(300.0, $balance->lo);
        self::assertSame(500.0, $balance->hi);
        self::assertFalse($balance->provisional);
    }

    /**
     * The reported case, and the one the old display mangled. The band is
     * -143..-49; as a QUANTITY OF SURPLUS that is 49 to 143 kcal, small end
     * first — the ends swap, which is why this is not a formatter in the browser.
     */
    public function test_a_band_wholly_below_zero_is_a_surplus_with_its_ends_swapped(): void
    {
        $balance = EnergyBalance::from(2000.0, 2049.0, 2100.0, 2143.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::SURPLUS, $balance->direction);
        self::assertSame(49.0, $balance->lo);
        self::assertSame(143.0, $balance->hi);

        // The signed band survives for the card that wants to show it.
        self::assertSame(-143.0, $balance->min);
        self::assertSame(-49.0, $balance->max);
    }

    /**
     * Straddling zero is a finding, not a rounding problem: on these numbers it
     * is not knowable which side of maintenance the day landed on, and naming a
     * direction anyway would invent the one thing the data does not say.
     */
    public function test_a_band_that_straddles_zero_is_balanced(): void
    {
        $balance = EnergyBalance::from(2000.0, 1920.0, 2015.0, 2050.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::BALANCED, $balance->direction);
        self::assertSame(-50.0, $balance->min);
        self::assertSame(80.0, $balance->max);

        // Magnitudes are meaningless for a band with no side, so nothing is left
        // lying around to be rendered by accident.
        self::assertSame(0.0, $balance->lo);
        self::assertSame(0.0, $balance->hi);
    }

    /** A day with no uncertainty at all is one number, not a range of one. */
    public function test_a_zero_width_band_has_equal_ends(): void
    {
        $balance = EnergyBalance::from(2400.0, 2000.0, 2000.0, 2000.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::DEFICIT, $balance->direction);
        self::assertSame(400.0, $balance->lo);
        self::assertSame(400.0, $balance->hi);
    }

    /** Today's burn is not finished, and the card says so. */
    public function test_a_day_in_progress_is_marked_provisional(): void
    {
        $balance = EnergyBalance::from(1200.0, 800.0, 900.0, 1000.0, true);

        self::assertNotNull($balance);
        self::assertTrue($balance->provisional);
    }

    /**
     * An exact zero is not a direction. `min > 0` and `max < 0` are strict on
     * purpose: landing exactly on maintenance is the balanced case, and calling
     * a band of 0–400 a deficit while printing a 0 as one of its ends is a screen
     * arguing with itself.
     */
    public function test_a_band_touching_zero_is_balanced(): void
    {
        $balance = EnergyBalance::from(2000.0, 1600.0, 1800.0, 2000.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::BALANCED, $balance->direction);
        self::assertSame(0.0, $balance->min);
        self::assertSame(400.0, $balance->max);
    }

    /** Rounding happens BEFORE the direction is chosen, so word and figures agree. */
    public function test_a_sub_kcal_band_rounds_before_it_is_named(): void
    {
        // Raw band 0.4 .. 50.4: "wholly above zero" only until it is rendered whole.
        $balance = EnergyBalance::from(2000.4, 1950.0, 1975.0, 2000.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::BALANCED, $balance->direction);
        self::assertSame(0.0, $balance->min);
        self::assertSame(50.0, $balance->max);
    }

    /** Half a subtraction is not a balance. */
    public function test_a_missing_half_has_nothing_to_say(): void
    {
        self::assertNull(EnergyBalance::from(null, 1900.0, 2000.0, 2100.0, false));
        self::assertNull(EnergyBalance::from(2400.0, null, null, null, false));
    }

    /** A summary with a mid and no band is a legal day, and still subtracts. */
    public function test_a_mid_with_no_band_falls_back_to_the_mid(): void
    {
        $balance = EnergyBalance::from(2400.0, null, 2000.0, null, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::DEFICIT, $balance->direction);
        self::assertSame(400.0, $balance->lo);
        self::assertSame(400.0, $balance->hi);
    }
}
