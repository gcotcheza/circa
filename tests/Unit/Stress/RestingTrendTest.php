<?php

declare(strict_types=1);

namespace Tests\Unit\Stress;

use PHPUnit\Framework\TestCase;
use App\Services\Stress\RestingTrend;

/**
 * The resting-heart-rate tile, and the thing easiest to get quietly wrong:
 * what "the day before" means when the Watch skipped four of them.
 */
final class RestingTrendTest extends TestCase
{
    public function test_the_previous_reading_is_the_previous_one_that_exists_and_it_is_named(): void
    {
        // Gaps are ordinary (930 resting values over ~1 200 days), so the delta
        // carries its date: "+3 vs 16 Jun", never a false "yesterday".
        $trend = RestingTrend::from([
            '2026-06-12' => 61.0,
            '2026-06-16' => 59.0,
            '2026-06-20' => 62.0,
        ], windowDays: 90);

        self::assertSame('2026-06-20', $trend->latestDate);
        self::assertSame(62.0, $trend->latestBpm);
        self::assertSame('2026-06-16', $trend->previousDate);
        self::assertEqualsWithDelta(3.0, (float) $trend->delta, 1e-9);
        self::assertSame(61.0, $trend->medianBpm);
        self::assertSame(3, $trend->days);
    }

    public function test_it_does_not_depend_on_the_order_it_was_handed(): void
    {
        $trend = RestingTrend::from([
            '2026-06-20' => 62.0,
            '2026-06-12' => 61.0,
            '2026-06-16' => 59.0,
        ], windowDays: 90);

        self::assertSame('2026-06-20', $trend->latestDate);
        self::assertSame('2026-06-16', $trend->previousDate);
    }

    public function test_the_median_ignores_one_bad_fortnight(): void
    {
        // A fever week at 78-82 against low sixties stays in a mean for months.
        $byDate = [];

        for ($day = 1; $day <= 20; $day++) {
            $byDate[sprintf('2026-05-%02d', $day)] = $day <= 5 ? 80.0 : 60.0;
        }

        $trend = RestingTrend::from($byDate, windowDays: 90);

        self::assertSame(60.0, $trend->medianBpm);
        self::assertSame(20, $trend->days);
    }

    public function test_a_single_reading_has_a_value_but_no_delta(): void
    {
        $trend = RestingTrend::from(['2026-06-20' => 62.0], windowDays: 90);

        self::assertTrue($trend->hasReading());
        self::assertSame(62.0, $trend->latestBpm);
        self::assertNull($trend->previousDate);
        self::assertNull($trend->delta);
        self::assertSame(62.0, $trend->medianBpm);
    }

    public function test_no_readings_at_all_is_null_rather_than_zero(): void
    {
        $trend = RestingTrend::from([], windowDays: 90);

        self::assertFalse($trend->hasReading());
        self::assertNull($trend->latestBpm);
        self::assertNull($trend->medianBpm);
        self::assertNull($trend->delta);
        self::assertSame(0, $trend->days);
        self::assertSame(90, $trend->toArray()['windowDays']);
    }

    public function test_the_tile_gets_whole_beats_per_minute(): void
    {
        // Two sources on a day average to a half; the Watch never reports one.
        $array = RestingTrend::from([
            '2026-06-19' => 59.5,
            '2026-06-20' => 62.5,
        ], windowDays: 90)->toArray();

        self::assertSame(63.0, $array['latestBpm']);
        self::assertSame(60.0, $array['previousBpm']);
        self::assertSame(3.0, $array['delta']);
    }
}
