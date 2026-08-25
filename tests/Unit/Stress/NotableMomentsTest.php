<?php

declare(strict_types=1);

namespace Tests\Unit\Stress;

use Tests\TestCase;
use App\Services\Stress\HrvSample;
use App\Services\Stress\HourlyBaseline;
use App\Services\Stress\NotableMoments;
use App\Services\Stress\CircadianProfile;

/**
 * The honest replacement for "overload zone — Today at 1:41 AM, HRV 16".
 *
 * THE POOL: fifty residuals, ten each at ln(33) + {-0.2, -0.1, 0, +0.1, +0.2}.
 * Median exactly ln(33), MAD exactly 0.1, so the scaled spread is exactly
 * 1.4826 x 0.1 = 0.14826 — the estimator's own construction, so the z a planted
 * reading must return is arithmetic and appears nowhere in the fixture. A reading
 * at 33 x exp(-3 x 0.14826) ms is exactly three personal sigma low, and the class
 * is never told so.
 */
final class NotableMomentsTest extends TestCase
{
    private const BASE = 33.0;

    private const SIGMA = 0.14826;

    private const DATE = '2026-06-17';

    public function test_it_finds_a_planted_outlier_and_recovers_its_z(): void
    {
        $moments = NotableMoments::find(
            week: [
                self::reading(2, 0.0),
                self::reading(3, -3.0),
                self::reading(4, 0.5),
            ],
            baseline: self::baseline(),
            minZ: 2.5,
            max: 3,
        );

        self::assertCount(1, $moments);
        self::assertSame(self::DATE, $moments[0]->date);
        self::assertSame(3, $moments[0]->hour);
        self::assertEqualsWithDelta(-3.0, $moments[0]->z, 1e-9);
        self::assertTrue($moments[0]->isLow());
        self::assertSame('low', $moments[0]->toArray()['direction']);
    }

    public function test_a_flat_week_produces_nothing_rather_than_a_headline(): void
    {
        // Six ordinary readings, the most extreme 1.5 sigma out. The old app
        // would still find its two "moments" here, because it always finds two.
        $moments = NotableMoments::find(
            week: [
                self::reading(0, 0.4),
                self::reading(1, -1.5),
                self::reading(2, 0.9),
                self::reading(3, -0.2),
                self::reading(4, 1.5),
                self::reading(5, -1.1),
            ],
            baseline: self::baseline(),
            minZ: 2.5,
            max: 3,
        );

        self::assertSame([], $moments);
    }

    public function test_one_dip_is_reported_once_at_its_worst_hour(): void
    {
        /*
         * A dip lasts longer than an hour, arriving as a run past the threshold;
         * listing three reports one event thrice and crowds out six days.
         */
        $moments = NotableMoments::find(
            week: [
                self::reading(1, -2.8),
                self::reading(2, -3.6),
                self::reading(3, -2.9),
            ],
            baseline: self::baseline(),
            minZ: 2.5,
            max: 3,
        );

        self::assertCount(1, $moments);
        self::assertSame(2, $moments[0]->hour);
        self::assertEqualsWithDelta(-3.6, $moments[0]->z, 1e-9);
    }

    public function test_a_week_with_both_directions_never_shows_only_one_of_them(): void
    {
        /*
         * Ranked by |z| alone the two lows take both slots and the week reads as
         * unrelieved — a reader inferring a bad week from an artefact of sorting.
         */
        $moments = NotableMoments::find(
            week: [
                self::reading(1, -4.0, '2026-06-15'),
                self::reading(1, -3.5, '2026-06-16'),
                self::reading(1, 2.8, '2026-06-17'),
            ],
            baseline: self::baseline(),
            minZ: 2.5,
            max: 2,
        );

        self::assertCount(2, $moments);

        $directions = array_map(static fn ($m): string => $m->toArray()['direction'], $moments);

        self::assertContains('low', $directions);
        self::assertContains('high', $directions);

        // Chronological for reading, whatever order they were ranked in.
        self::assertSame(['2026-06-15', '2026-06-17'], array_column(
            array_map(static fn ($m): array => $m->toArray(), $moments),
            'date',
        ));
    }

    public function test_an_hour_with_no_established_shape_produces_no_moment(): void
    {
        /*
         * The sentence this prints is "unusual FOR YOU AT THAT HOUR". Without a
         * circadian offset for the hour there is no honest way to finish it, so
         * the reading is passed over rather than labelled off the day's middle.
         */
        $nightOnly = self::profile([0, 1, 2, 3, 4, 5]);

        self::assertTrue($nightOnly->isEstimatedFor(3));
        self::assertFalse($nightOnly->isEstimatedFor(14));

        $moments = NotableMoments::find(
            week: [self::reading(14, -4.0)],
            baseline: HourlyBaseline::from(self::pool(), $nightOnly, 0.03),
            minZ: 2.5,
            max: 3,
        );

        self::assertSame([], $moments);

        // The same excursion at an hour the profile DOES know is reported.
        $known = NotableMoments::find(
            week: [self::reading(3, -4.0)],
            baseline: HourlyBaseline::from(self::pool(), $nightOnly, 0.03),
            minZ: 2.5,
            max: 3,
        );

        self::assertCount(1, $known);
    }

    public function test_the_typical_range_is_the_middle_half_of_the_pool(): void
    {
        $moment = NotableMoments::find(
            week: [self::reading(3, -3.0)],
            baseline: self::baseline(),
            minZ: 2.5,
            max: 3,
        )[0];

        // Half-width 0.1 in log units either side of ln(33): 29.9 and 36.5 ms —
        // the pool's interquartile range by construction, NOT a confidence interval.
        self::assertEqualsWithDelta(self::BASE, $moment->typicalMs, 1e-9);
        self::assertEqualsWithDelta(self::BASE * exp(-0.1), $moment->typicalLowMs, 1e-9);
        self::assertEqualsWithDelta(self::BASE * exp(0.1), $moment->typicalHighMs, 1e-9);

        self::assertSame(30.0, $moment->toArray()['typicalLowMs']);
        self::assertSame(36.0, $moment->toArray()['typicalHighMs']);
    }

    public function test_the_typical_range_moves_with_the_hour_of_the_day(): void
    {
        /*
         * The point of the exercise. A profile whose overnight hours run a third
         * above its daytime ones must say so: 16 ms at 04:00 is a bigger
         * deviation than 16 ms at 14:00, and one "typical" band for both is the
         * flat-baseline mistake this feature removes.
         */
        $shaped = self::shapedProfile();

        $night = NotableMoments::find(
            week: [self::reading(4, -3.5)],
            baseline: HourlyBaseline::from(self::pool(), $shaped, 0.03),
            minZ: 2.5,
            max: 3,
        );

        $afternoon = NotableMoments::find(
            week: [self::reading(14, -3.5)],
            baseline: HourlyBaseline::from(self::pool(), $shaped, 0.03),
            minZ: 2.5,
            max: 3,
        );

        self::assertCount(1, $night);
        self::assertCount(1, $afternoon);

        // 40 ms overnight against 30 in the day, exactly as seeded.
        self::assertEqualsWithDelta(
            40.0 / 30.0,
            $night[0]->typicalMs / $afternoon[0]->typicalMs,
            1e-9,
        );
    }

    public function test_an_empty_pool_is_silence_and_not_a_division_by_zero(): void
    {
        // No pool, no yardstick, no baseline object — never a spread of zero.
        self::assertNull(HourlyBaseline::from([], self::profile(), 0.03));

        self::assertSame([], NotableMoments::find(
            week: [self::reading(3, -3.0)],
            baseline: null,
            minZ: 2.5,
            max: 3,
        ));
    }

    /** The yardstick every case here measures against. */
    private static function baseline(): HourlyBaseline
    {
        $baseline = HourlyBaseline::from(self::pool(), self::profile(), 0.03);
        self::assertNotNull($baseline, 'the fixture pool is never empty, so from() never returns null here');

        return $baseline;
    }

    /** A reading a chosen number of personal sigma from the pool's middle. */
    private static function reading(int $hour, float $z, string $date = self::DATE): HrvSample
    {
        return HrvSample::make($date, $hour, self::BASE * exp($z * self::SIGMA));
    }

    /**
     * Median ln(33), MAD exactly 0.1 — see the class docblock.
     *
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
     * Corrects nothing but KNOWS the hours it was given — "no correction needed"
     * versus "no idea", the distinction `isEstimatedFor` exists to make.
     *
     * @param  list<int>  $hours
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
                static fn (int $hour): HrvSample => HrvSample::make(
                    $date,
                    $hour,
                    // range(0, 23): $hour is never negative, so this is just "the night hours".
                    $hour <= 5 ? 40.0 : 30.0,
                ),
                range(0, 23),
            );
        }

        return CircadianProfile::from($byDate);
    }
}
