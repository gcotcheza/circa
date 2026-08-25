<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use Tests\TestCase;
use App\Services\Support\LocalDates;

/**
 * The day walk every per-day map in this app is keyed on.
 *
 * Its two failure modes are silent: an exclusive end drops a day from every
 * report at once, and counting a span in hours instead of walking dates
 * loses or doubles the DST days.
 */
final class LocalDatesTest extends TestCase
{
    public function test_both_ends_are_included(): void
    {
        self::assertSame(
            ['2026-08-18', '2026-08-19', '2026-08-20'],
            LocalDates::inclusive('2026-08-18', '2026-08-20'),
        );
    }

    public function test_a_single_day_is_one_date(): void
    {
        self::assertSame(['2026-08-19'], LocalDates::inclusive('2026-08-19', '2026-08-19'));
    }

    /**
     * A backwards range is empty rather than an error or a reversed walk:
     * callers normalise order before they get here (ReportRange swaps the
     * ends), and an empty list is the honest answer for "no days".
     */
    public function test_a_backwards_range_is_empty(): void
    {
        self::assertSame([], LocalDates::inclusive('2026-08-20', '2026-08-18'));
    }

    /**
     * The clocks go forward on 2026-03-29 in Europe/Amsterdam: a 23-hour
     * day, which is still exactly one date.
     */
    public function test_the_short_dst_day_is_one_date(): void
    {
        self::assertSame(
            ['2026-03-28', '2026-03-29', '2026-03-30'],
            LocalDates::inclusive('2026-03-28', '2026-03-30', 'Europe/Amsterdam'),
        );
    }

    /** And back again on 2026-10-25: a 25-hour day, also one date. */
    public function test_the_long_dst_day_is_one_date(): void
    {
        self::assertSame(
            ['2026-10-24', '2026-10-25', '2026-10-26'],
            LocalDates::inclusive('2026-10-24', '2026-10-26', 'Europe/Amsterdam'),
        );
    }

    public function test_a_long_range_counts_every_day(): void
    {
        $dates = LocalDates::inclusive('2026-01-01', '2026-12-31');

        self::assertCount(365, $dates);
        self::assertSame('2026-01-01', $dates[0]);
        self::assertSame('2026-12-31', $dates[364]);
    }

    /** A leap year, where an arithmetic shortcut would be a day out. */
    public function test_february_29_is_walked(): void
    {
        self::assertSame(
            ['2028-02-28', '2028-02-29', '2028-03-01'],
            LocalDates::inclusive('2028-02-28', '2028-03-01'),
        );
    }

    /**
     * The zone is accepted and changes nothing about which dates come back,
     * for the plain YYYY-MM-DD inputs every caller passes.
     *
     * That is the contract, not a weakness of the test: this is a walk over
     * a calendar, so for a plain date the zone only decides where the
     * cursor's midnight sits — which is what keeps the DST days above at one
     * date each. Pinned against zones a day apart, so that a later "fix"
     * turning the helper into an instant conversion fails here instead of
     * silently shifting every per-day map in the app by one.
     */
    public function test_the_zone_never_shifts_the_calendar(): void
    {
        config()->set('health.timezone', 'Europe/Amsterdam');

        $expected = ['2026-08-18', '2026-08-19'];

        self::assertSame($expected, LocalDates::inclusive('2026-08-18', '2026-08-19'));
        self::assertSame($expected, LocalDates::inclusive('2026-08-18', '2026-08-19', 'Pacific/Auckland'));
        self::assertSame($expected, LocalDates::inclusive('2026-08-18', '2026-08-19', 'Pacific/Midway'));
        self::assertSame($expected, LocalDates::inclusive('2026-08-18', '2026-08-19', 'UTC'));
    }
}
