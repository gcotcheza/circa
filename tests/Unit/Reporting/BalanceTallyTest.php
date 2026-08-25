<?php

declare(strict_types=1);

namespace Tests\Unit\Reporting;

use Tests\TestCase;
use App\Services\Reporting\BalanceTally;
use App\Services\Reporting\EnergyBalance;

/**
 * The energy chart's one-line caption.
 *
 * A sentence like this lies in four ways: counting a day it cannot see the whole
 * of; resolving a day whose intake range straddles its burn to whichever side
 * the midpoint fell on; reporting a majority without its denominator; making a
 * statement about a window out of one day in it. Each is a test here. The
 * wording is asserted with them — the strings are cheap to update, and a diff
 * changing the register of the app's one machine-written line SHOULD be visible
 * in review.
 */
final class BalanceTallyTest extends TestCase
{
    public function test_a_window_that_went_over_every_day_says_all(): void
    {
        $tally = BalanceTally::from([
            self::surplus(),
            self::surplus(),
            self::surplus(),
        ]);

        self::assertSame(3, $tally->days);
        self::assertSame(3, $tally->surplus);
        self::assertSame('Ate over your burn on all 3 fully logged days.', $tally->sentence);
    }

    public function test_a_window_that_went_under_every_day_says_all(): void
    {
        $tally = BalanceTally::from([self::deficit(), self::deficit()]);

        self::assertSame('Ate under your burn on all 2 fully logged days.', $tally->sentence);
    }

    /**
     * The real week this was written in: 2 surplus days, 1 deficit, out of 7
     * days of which 3 carried a complete food log.
     */
    public function test_a_majority_is_reported_with_its_denominator(): void
    {
        $tally = BalanceTally::from([self::surplus(), self::deficit(), self::surplus()]);

        self::assertSame('Ate over your burn on 2 of the 3 fully logged days.', $tally->sentence);
        self::assertSame(2, $tally->surplus);
        self::assertSame(1, $tally->deficit);
    }

    public function test_a_majority_the_other_way_reads_the_same_way_round(): void
    {
        $tally = BalanceTally::from([
            self::deficit(),
            self::deficit(),
            self::surplus(),
            self::deficit(),
        ]);

        self::assertSame('Ate under your burn on 3 of the 4 fully logged days.', $tally->sentence);
    }

    /** An even split is not a majority, and is not rounded into one. */
    public function test_an_even_split_names_both_sides(): void
    {
        $tally = BalanceTally::from([
            self::surplus(),
            self::deficit(),
            self::surplus(),
            self::deficit(),
        ]);

        self::assertSame(
            'Ate over your burn on 2 of the 4 fully logged days and under on 2.',
            $tally->sentence,
        );
    }

    /**
     * A DAY WHOSE INTAKE RANGE COVERS ITS BURN IS NOT A DAY THAT BROKE EVEN: it
     * is one where the estimate is too wide to say which side of maintenance it
     * landed on. EnergyBalance already refuses to guess, and this must not undo
     * that by counting the day toward the nearer side.
     */
    public function test_days_too_close_to_call_are_counted_as_such_and_not_split(): void
    {
        $tally = BalanceTally::from([self::balanced(), self::balanced(), self::balanced()]);

        self::assertSame(3, $tally->balanced);
        self::assertSame(0, $tally->surplus);
        self::assertSame(0, $tally->deficit);
        self::assertSame(
            "Too close to call on all 3 fully logged days — each day's intake range covers its burn.",
            $tally->sentence,
        );
    }

    /** And they stay out of the majority, without leaving the denominator. */
    public function test_an_unknowable_day_still_counts_toward_the_denominator(): void
    {
        $tally = BalanceTally::from([self::surplus(), self::balanced(), self::surplus()]);

        // Not "2 of 2": the third day was measured and logged but could not be
        // decided, and dropping it would quietly improve the record.
        self::assertSame('Ate over your burn on 2 of the 3 fully logged days.', $tally->sentence);
        self::assertSame(3, $tally->days);
    }

    /**
     * ONE DAY IS NOT A WINDOW. "Ate over your burn on 1 of 1 fully logged days"
     * makes a week out of a Tuesday, and it would appear constantly: a typical
     * week here has three complete logs, a bad one has one.
     */
    public function test_a_single_day_gets_the_counts_but_no_sentence(): void
    {
        $tally = BalanceTally::from([self::surplus()]);

        self::assertSame(1, $tally->days);
        self::assertSame(1, $tally->surplus);
        self::assertNull($tally->sentence);
    }

    public function test_a_window_with_nothing_logged_says_nothing(): void
    {
        $tally = BalanceTally::from([]);

        self::assertSame(0, $tally->days);
        self::assertNull($tally->sentence);
        self::assertSame(
            ['days' => 0, 'surplus' => 0, 'deficit' => 0, 'balanced' => 0, 'sentence' => null],
            $tally->toArray(),
        );
    }

    /** The gate is configurable upward, and cannot be argued below two. */
    public function test_the_minimum_is_configurable_but_floored(): void
    {
        $two = [self::surplus(), self::surplus()];

        self::assertNotNull(BalanceTally::from($two)->sentence);
        self::assertNull(BalanceTally::from($two, 3)->sentence);

        // A window of one can never produce a sentence, whatever config says.
        config()->set('health.trends.balance_min_days', 1);

        self::assertNull(BalanceTally::from([self::surplus()])->sentence);
    }

    // -----------------------------------------------------------------------

    /** Burned 2 000, ate 2 200: over the burn, and knowably so. */
    private static function surplus(): EnergyBalance
    {
        $balance = EnergyBalance::from(2000.0, 2150.0, 2200.0, 2250.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::SURPLUS, $balance->direction);

        return $balance;
    }

    /** Burned 2 000, ate 1 500. */
    private static function deficit(): EnergyBalance
    {
        $balance = EnergyBalance::from(2000.0, 1450.0, 1500.0, 1550.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::DEFICIT, $balance->direction);

        return $balance;
    }

    /** Burned 2 000, ate 1 800–2 200: the range covers the burn. */
    private static function balanced(): EnergyBalance
    {
        $balance = EnergyBalance::from(2000.0, 1800.0, 2000.0, 2200.0, false);

        self::assertNotNull($balance);
        self::assertSame(EnergyBalance::BALANCED, $balance->direction);

        return $balance;
    }
}
