<?php

declare(strict_types=1);

namespace Tests\Feature\Stress;

use Tests\TestCase;
use App\Enums\StressBand;
use Carbon\CarbonImmutable;
use Tests\Support\StressFixture;
use App\Services\Stress\StressScale;
use App\Services\Stress\StressAnalysis;
use App\Services\Stress\CircadianProfile;
use App\Services\Stress\StressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The honesty test: does the same day score the same whether the Watch was worn
 * overnight or in the evening?
 *
 * HRV is a clock before it is a mood. In this user's three years of readings the
 * median runs ~41 ms at 05:00 and ~28 ms at 23:00 — a 45% swing, every day, in a
 * healthy person doing nothing in particular. So a score comparing raw HRV
 * against a flat baseline reports the wearing pattern rather than the person:
 * worn to bed it says "great", worn through the evening "pay attention", both on
 * the same day. That is the failure mode of the app being replaced.
 *
 * The fixture builds exactly that trap: a known circadian shape (+0.2 log units
 * overnight, -0.2 in the evening), sixty days of ordinary variation behind it,
 * and two days IDENTICAL except for which hours the Watch was on. A correct
 * estimator scores them the same; a naive one puts them two bands apart, and the
 * test says by how much.
 */
final class CircadianBaselineTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-15';

    private const CYCLE = [-0.2, -0.1, 0.0, 0.1, 0.2];

    private const ONE_SIGMA = 0.14826;

    private const BASE_MS = 33.0;

    /** Overnight hours run this far above the day's own middle, in log units. */
    private const NIGHT_LIFT = 0.2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', StressFixture::TZ));
    }

    public function test_a_night_only_day_and_an_evening_only_day_at_the_same_level_score_the_same(): void
    {
        $this->seedCircadianHistory();

        $analysis = $this->analyse();

        $nightOnly = $analysis->day(CarbonImmutable::parse(self::TODAY)->subDay()->toDateString());
        $eveningOnly = $analysis->day(self::TODAY);

        // Both sit on the user's baseline once the clock is removed.
        self::assertEqualsWithDelta(0.0, (float) $nightOnly->z, 1e-5);
        self::assertEqualsWithDelta(0.0, (float) $eveningOnly->z, 1e-5);

        self::assertSame(65, $nightOnly->score);
        self::assertSame(65, $eveningOnly->score);
        self::assertSame(StressBand::Normal, $eveningOnly->band);

        // Six readings each — the difference is WHICH six.
        self::assertSame(6, $nightOnly->samples);
        self::assertSame(6, $eveningOnly->samples);
    }

    public function test_without_the_correction_the_same_two_days_would_be_two_bands_apart(): void
    {
        // The comparison the feature exists to win: raw HRV at the overnight
        // hours is +0.2 log units, i.e. 1.35 personal sigma and a different
        // band, while the identical evening day is 1.35 sigma the other way.
        $naiveZ = self::NIGHT_LIFT / self::ONE_SIGMA;

        $scale = new StressScale;

        self::assertSame(StressBand::Great, $scale->band($naiveZ));
        self::assertSame(StressBand::Attention, $scale->band(-$naiveZ));

        // Fifty of the ninety-eight available points, between two readings of
        // the same person on the same day.
        self::assertGreaterThan(45, $scale->score($naiveZ) - $scale->score(-$naiveZ));
    }

    public function test_the_profile_recovers_the_shape_it_was_built_from(): void
    {
        $this->seedCircadianHistory();

        $profile = $this->analyse()->profile();

        // Overnight: +0.2 log units, HRV about 22% above the day's own middle.
        // Evening the mirror image.
        foreach ([0, 1, 2, 3, 4, 5] as $hour) {
            self::assertEqualsWithDelta(self::NIGHT_LIFT, $profile->offsetFor($hour), 1e-5, "hour {$hour}");
        }

        foreach ([18, 19, 20, 21, 22, 23] as $hour) {
            self::assertEqualsWithDelta(-self::NIGHT_LIFT, $profile->offsetFor($hour), 1e-5, "hour {$hour}");
        }

        foreach ([9, 12, 15] as $hour) {
            self::assertEqualsWithDelta(0.0, $profile->offsetFor($hour), 1e-5, "hour {$hour}");
        }

        self::assertSame(24, $profile->estimatedHours());
    }

    public function test_the_profile_is_a_shape_and_carries_no_level_of_its_own(): void
    {
        // Every day here is lifted by a constant. A profile that absorbed any
        // of that level would leave the baseline measuring something other than
        // the person's HRV, and the two would drift apart silently.
        StressFixture::days(self::TODAY, 61, fn (int $i, int $hour): float => 100.0 * exp(
            self::CYCLE[$i % 5] + ($hour < 6 ? self::NIGHT_LIFT : 0.0)
        ));

        $profile = $this->analyse()->profile();

        $offsets = array_map(
            static fn (int $hour): float => 0.0,
            range(0, 23)
        );

        foreach (array_keys($offsets) as $hour) {
            $offsets[$hour] = $profile->offsetFor($hour);
        }

        self::assertEqualsWithDelta(0.0, array_sum($offsets) / 24, 1e-5);
    }

    public function test_an_hour_with_too_little_behind_it_gets_no_offset_rather_than_a_guess(): void
    {
        config()->set('health.stress.profile_min_hour_samples', 10);

        // Every hour but 13:00 is well covered; 13:00 has five readings in the
        // whole window, and guessing an offset from five points to subtract
        // from every future 13:00 is a systematic error nobody could see.
        StressFixture::days(
            self::TODAY,
            61,
            fn (int $i, int $hour): ?float => $hour === 13 && $i > 4
                ? null
                : self::BASE_MS * exp(self::CYCLE[$i % 5]),
        );

        $profile = $this->analyse()->profile();

        self::assertFalse($profile->isEstimatedFor(13));
        self::assertSame(0.0, $profile->offsetFor(13));
        self::assertTrue($profile->isEstimatedFor(9));
        self::assertSame(23, $profile->estimatedHours());
    }

    public function test_with_no_history_at_all_the_profile_corrects_nothing(): void
    {
        $profile = CircadianProfile::flat();

        self::assertSame(0, $profile->days);
        self::assertSame(0, $profile->estimatedHours());

        for ($hour = 0; $hour < 24; $hour++) {
            self::assertSame(0.0, $profile->offsetFor($hour));
        }
    }

    /**
     * Ninety days of ordinary life with a known circadian shape, then two days
     * differing only in when the Watch was worn.
     */
    private function seedCircadianHistory(): void
    {
        $shape = static fn (int $hour): float => match (true) {
            $hour < 6   => self::NIGHT_LIFT,
            $hour >= 18 => -self::NIGHT_LIFT,
            default     => 0.0,
        };

        // Days 0-89: full coverage, level cycling through the MAD fixture.
        StressFixture::days(
            CarbonImmutable::parse(self::TODAY)->subDays(2)->toDateString(),
            90,
            fn (int $i, int $hour): float => self::BASE_MS * exp(self::CYCLE[$i % 5] + $shape($hour)),
        );

        // Yesterday: overnight only, at exactly the baseline level.
        StressFixture::day(
            CarbonImmutable::parse(self::TODAY)->subDay()->toDateString(),
            array_combine(
                StressFixture::NIGHT_HOURS,
                array_map(
                    static fn (): float => self::BASE_MS * exp(self::NIGHT_LIFT),
                    StressFixture::NIGHT_HOURS
                ),
            ),
        );

        // Today: evening only, at exactly the same baseline level.
        StressFixture::day(
            self::TODAY,
            array_combine(
                StressFixture::EVENING_HOURS,
                array_map(
                    static fn (): float => self::BASE_MS * exp(-self::NIGHT_LIFT),
                    StressFixture::EVENING_HOURS
                ),
            ),
        );
    }

    private function analyse(): StressAnalysis
    {
        $from = CarbonImmutable::parse(self::TODAY)->subDays(10)->toDateString();

        return app(StressCalculator::class)->analyse(
            $from,
            self::TODAY,
            CarbonImmutable::parse(self::TODAY, StressFixture::TZ),
        );
    }
}
