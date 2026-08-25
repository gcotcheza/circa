<?php

declare(strict_types=1);

namespace Tests\Unit\Stress;

use Tests\TestCase;
use App\Services\Stress\HrvSample;
use App\Services\Stress\HourlyBaseline;
use App\Services\Stress\CircadianProfile;

/**
 * The yardstick two screens share, and the four ways it refuses to answer.
 *
 * Every pool is fifty residuals, ten each at ln(33) + {-0.2,-0.1,0,+0.1,+0.2}:
 * median ln(33), MAD 0.1, spread 1.4826 x 0.1 — a z here is arithmetic, not a snapshot.
 */
final class HourlyBaselineTest extends TestCase
{
    private const BASE = 33.0;

    private const SIGMA = 0.14826;

    public function test_it_recovers_the_centre_and_the_spread_it_was_never_told(): void
    {
        $baseline = self::baseline();

        self::assertEqualsWithDelta(log(self::BASE), $baseline->centre, 1e-9);
        self::assertEqualsWithDelta(self::SIGMA, $baseline->spread, 1e-9);
        self::assertSame(50, $baseline->samples);
    }

    public function test_a_reading_comes_back_in_personal_standard_deviations(): void
    {
        $baseline = self::baseline();

        $sample = HrvSample::make('2026-06-17', 3, self::BASE * exp(-2.0 * self::SIGMA));

        self::assertEqualsWithDelta(-2.0, (float) $baseline->zFor($sample), 1e-9);

        $better = HrvSample::make('2026-06-17', 3, self::BASE * exp(1.5 * self::SIGMA));

        self::assertEqualsWithDelta(1.5, (float) $baseline->zFor($better), 1e-9);
    }

    public function test_an_hour_the_profile_does_not_know_gets_no_answer_at_all(): void
    {
        // Not a z computed as if the offset were zero: every sentence built on
        // this says "for you at that hour", so null forces a different sentence.
        $nightOnly = self::profile([0, 1, 2, 3, 4, 5]);

        $baseline = HourlyBaseline::from(self::pool(), $nightOnly, 0.03);
        self::assertNotNull($baseline);

        self::assertTrue($baseline->knowsHour(3));
        self::assertFalse($baseline->knowsHour(14));

        self::assertNotNull($baseline->zForLevel(log(10.0), 3));
        self::assertNull($baseline->zForLevel(log(10.0), 14));
    }

    public function test_an_empty_pool_produces_no_baseline_rather_than_a_zero_spread(): void
    {
        self::assertNull(HourlyBaseline::from([], self::profile(), 0.03));
    }

    public function test_a_pool_of_identical_readings_is_floored_rather_than_infinitely_sensitive(): void
    {
        // MAD is exactly zero here; dividing by it would make a 2 ms wobble the
        // biggest event on record, so the daily baseline's `min_spread_log` floors it.
        $flat = array_fill(0, 50, log(self::BASE));

        $baseline = HourlyBaseline::from($flat, self::profile(), 0.03);

        self::assertNotNull($baseline);
        self::assertSame(0.03, $baseline->spread);

        self::assertEqualsWithDelta(1.0, (float) $baseline->zForLevel(log(self::BASE) + 0.03, 3), 1e-9);
    }

    public function test_the_typical_range_is_the_middle_half_and_moves_with_the_hour(): void
    {
        $baseline = self::baseline();

        [$low, $high] = $baseline->typicalRangeMs(3);

        // Half-width 0.1 in log units is the plain MAD, which for a symmetric
        // distribution IS the interquartile half-width: "half your readings".
        self::assertEqualsWithDelta(self::BASE * exp(-0.1), $low, 1e-9);
        self::assertEqualsWithDelta(self::BASE * exp(0.1), $high, 1e-9);
        self::assertEqualsWithDelta(self::BASE, $baseline->typicalMs(3), 1e-9);

        // Hours that differ must show through, or this is a flat baseline again.
        $shaped = HourlyBaseline::from(self::pool(), self::shapedProfile(), 0.03);
        self::assertNotNull($shaped);

        self::assertEqualsWithDelta(
            40.0 / 30.0,
            $shaped->typicalMs(4) / $shaped->typicalMs(14),
            1e-9,
        );
    }

    private static function baseline(): HourlyBaseline
    {
        $baseline = HourlyBaseline::from(self::pool(), self::profile(), 0.03);
        self::assertNotNull($baseline, 'the fixture pool is never empty, so from() never returns null here');

        return $baseline;
    }

    /**
     * @return list<float>
     */
    private static function pool(): array
    {
        $pool = [];

        foreach ([-0.2, -0.1, 0.0, 0.1, 0.2] as $offset) {
            for ($i = 0; $i < 10; $i++) {
                $pool[] = log(self::BASE) + $offset;
            }
        }

        return $pool;
    }

    /**
     * Corrects nothing but KNOWS its hours: "no correction needed" is not "no idea".
     *
     * @param  list<int>|null  $hours
     */
    private static function profile(?array $hours = null): CircadianProfile
    {
        $hours ??= range(0, 23);

        $byDate = [];

        for ($day = 1; $day <= 20; $day++) {
            $date = sprintf('2026-05-%02d', $day);

            $byDate[$date] = array_map(
                static fn (int $hour): HrvSample => HrvSample::make($date, $hour, self::BASE),
                $hours,
            );
        }

        return CircadianProfile::from($byDate);
    }

    /** Overnight hours at 40 ms, the rest of the day at 30. */
    private static function shapedProfile(): CircadianProfile
    {
        $byDate = [];

        for ($day = 1; $day <= 20; $day++) {
            $date = sprintf('2026-05-%02d', $day);

            $byDate[$date] = array_map(
                static fn (int $hour): HrvSample => HrvSample::make($date, $hour, $hour <= 5 ? 40.0 : 30.0),
                range(0, 23),
            );
        }

        return CircadianProfile::from($byDate);
    }
}
