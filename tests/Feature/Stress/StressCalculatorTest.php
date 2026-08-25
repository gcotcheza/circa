<?php

declare(strict_types=1);

namespace Tests\Feature\Stress;

use Tests\TestCase;
use App\Enums\StressBand;
use Carbon\CarbonImmutable;
use App\Services\Stress\Robust;
use Tests\Support\StressFixture;
use App\Services\Stress\StressScale;
use App\Services\Stress\StressAnalysis;
use App\Services\Stress\StressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Does the estimator recover a z-score it was never told?
 *
 * Every scenario hands it readings whose statistics are known by construction: a
 * baseline whose median and MAD are exact in log space, and one day placed a
 * chosen number of those MADs away from it. The z it must produce is then
 * arithmetic, not opinion, and it appears nowhere in the fixture.
 *
 * The MAD trick: sixty baseline days whose log-levels cycle through
 * (-0.2, -0.1, 0, +0.1, +0.2) have median exactly 0 and median-absolute-
 * deviation exactly 0.1, so the scaled spread is exactly 1.4826 x 0.1 = 0.14826,
 * and a day at +0.14826 is exactly one personal sigma above baseline.
 * `test_the_fixtures_baseline_really_does_have_the_spread_the_tests_assume` pins
 * that, so the rest of the file cannot quietly stop meaning what it says.
 */
final class StressCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-15';

    /** Sixty baseline days cycling through these log offsets: median 0, MAD 0.1. */
    private const CYCLE = [-0.2, -0.1, 0.0, 0.1, 0.2];

    /** 1.4826 x 0.1 — one personal sigma, in log units. */
    private const ONE_SIGMA = 0.14826;

    private const BASE_MS = 33.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', StressFixture::TZ));
    }

    public function test_a_day_one_personal_sigma_above_baseline_scores_82(): void
    {
        $this->seedBaselineWithFinalDayAt(self::ONE_SIGMA);

        $day = $this->analyse()->day(self::TODAY);

        self::assertTrue($day->hasScore());
        self::assertEqualsWithDelta(1.0, (float) $day->z, 1e-5);
        self::assertSame(60, $day->baselineDays);

        // 82 is the arithmetic, not a snapshot: 1 + 98 x Phi((1 + 0.7117)/1.8081).
        self::assertSame(82, $day->score);
        self::assertSame(StressBand::Great, $day->band);

        // The recovered baseline and spread are the fixture's own; neither was given.
        self::assertEqualsWithDelta(self::BASE_MS, (float) $day->baselineHrvMs, 1e-6);
        self::assertEqualsWithDelta(self::ONE_SIGMA, (float) $day->baselineSpreadLog, 1e-5);
    }

    public function test_an_ordinary_day_scores_the_baseline_anchor(): void
    {
        $this->seedBaselineWithFinalDayAt(0.0);

        $day = $this->analyse()->day(self::TODAY);

        self::assertEqualsWithDelta(0.0, (float) $day->z, 1e-5);
        self::assertSame(65, $day->score);
        self::assertSame(StressBand::Normal, $day->band);
    }

    public function test_a_day_a_sigma_below_baseline_is_pay_attention(): void
    {
        $this->seedBaselineWithFinalDayAt(-1.0 * self::ONE_SIGMA);

        $day = $this->analyse()->day(self::TODAY);

        self::assertEqualsWithDelta(-1.0, (float) $day->z, 1e-5);
        self::assertSame(44, $day->score);
        self::assertSame(StressBand::Attention, $day->band);
    }

    public function test_a_day_far_below_baseline_is_overload(): void
    {
        $this->seedBaselineWithFinalDayAt(-2.5 * self::ONE_SIGMA);

        $day = $this->analyse()->day(self::TODAY);

        self::assertEqualsWithDelta(-2.5, (float) $day->z, 1e-5);
        self::assertSame(StressBand::Overload, $day->band);
        self::assertSame((new StressScale)->score(-2.5), $day->score);
    }

    public function test_a_single_absurd_reading_does_not_move_the_day(): void
    {
        // 213 ms in an hour is a real artefact here: six times the day's level.
        StressFixture::days(
            self::TODAY,
            61,
            fn (int $i, int $hour): float => $i < 60
                ? self::BASE_MS * exp(self::CYCLE[$i % 5])
                : ($hour === 12 ? 213.0 : self::BASE_MS),
        );

        $day = $this->analyse()->day(self::TODAY);

        // The median never sees it: the day is exactly on baseline.
        self::assertSame(65, $day->score);
        self::assertEqualsWithDelta(self::BASE_MS, (float) $day->hrvMs, 1e-6);
        self::assertSame(24, $day->samples);

        // A mean of the same 24 readings: 40.5 ms = +0.2 log = +1.4 sigma = another band.
        $mean = (23 * self::BASE_MS + 213.0) / 24;
        $naiveZ = log($mean / self::BASE_MS) / self::ONE_SIGMA;

        self::assertSame(StressBand::Great, (new StressScale)->band($naiveZ));
    }

    public function test_the_baseline_ends_the_day_before_so_a_day_is_never_its_own_yardstick(): void
    {
        // A three-day baseline makes the exclusion visible: median(0, 0.1, 0.2)
        // is 0.1, but with today's +1.0 it would be 0.15.
        config()->set('health.stress.baseline_days', 3);
        config()->set('health.stress.min_baseline_days', 3);

        StressFixture::days(self::TODAY, 4, fn (int $i): float => self::BASE_MS * exp(
            [0.0, 0.1, 0.2, 1.0][$i]
        ));

        $day = $this->analyse()->day(self::TODAY);

        self::assertSame(3, $day->baselineDays);
        self::assertEqualsWithDelta(self::BASE_MS * exp(0.1), (float) $day->baselineHrvMs, 1e-6);
    }

    public function test_a_bad_week_does_not_become_the_new_normal(): void
    {
        // Sixty ordinary days, then a week three sigma down. A MEAN baseline
        // would drift toward the collapse and forgive it by day seven; a median
        // of sixty does not move for six bad days, so the seventh still reads bad.
        StressFixture::days(self::TODAY, 67, fn (int $i): float => self::BASE_MS * exp(
            $i >= 60 ? -3.0 * self::ONE_SIGMA : self::CYCLE[$i % 5]
        ));

        $analysis = $this->analyse();

        $first = $analysis->day(CarbonImmutable::parse(self::TODAY)->subDays(6)->toDateString());
        $last = $analysis->day(self::TODAY);

        self::assertEqualsWithDelta(-3.0, (float) $first->z, 1e-5);
        self::assertEqualsWithDelta(-3.0, (float) $last->z, 1e-5);
        self::assertSame(StressBand::Overload, $last->band);
    }

    public function test_the_baseline_spread_is_floored_so_an_unnaturally_flat_fortnight_cannot_explode(): void
    {
        config()->set('health.stress.min_spread_log', 0.03);

        // Sixty identical days: the MAD is exactly zero, and a naive divide turns
        // a 3% wobble into an infinite z.
        StressFixture::days(self::TODAY, 61, fn (int $i): float => self::BASE_MS * ($i === 60 ? 1.03 : 1.0));

        $day = $this->analyse()->day(self::TODAY);

        self::assertTrue($day->hasScore());
        self::assertFalse(is_nan((float) $day->z));
        self::assertEqualsWithDelta(0.03, (float) $day->baselineSpreadLog, 1e-12);
        self::assertEqualsWithDelta(log(1.03) / 0.03, (float) $day->z, 1e-5);
    }

    public function test_the_day_level_is_reported_in_milliseconds_and_covers_what_arrived(): void
    {
        $this->seedBaselineWithFinalDayAt(0.0);

        $day = $this->analyse()->day(self::TODAY);

        self::assertEqualsWithDelta(self::BASE_MS, (float) $day->hrvMs, 1e-6);
        self::assertEqualsWithDelta(24.0, $day->coveredHours, 1e-9);
    }

    public function test_epoch_days_are_exact_across_a_daylight_saving_boundary(): void
    {
        // Europe/Amsterdam springs forward 2026-03-29 (23 h) and back 2026-10-25
        // (25 h): a timestamp divided by 86400 lands on the wrong side of one.
        self::assertSame(1, StressAnalysis::epochDay('2026-03-30') - StressAnalysis::epochDay('2026-03-29'));
        self::assertSame(1, StressAnalysis::epochDay('2026-10-26') - StressAnalysis::epochDay('2026-10-25'));
        self::assertSame(0, StressAnalysis::epochDay('1970-01-01'));
        self::assertSame(365, StressAnalysis::epochDay('2026-06-15') - StressAnalysis::epochDay('2025-06-15'));
    }

    public function test_the_fixtures_baseline_really_does_have_the_spread_the_tests_assume(): void
    {
        // A test of the test: if this drifts, every assertion above measures
        // something other than what it claims to.
        $levels = [];

        for ($i = 0; $i < 60; $i++) {
            $levels[] = self::CYCLE[$i % 5];
        }

        self::assertSame(0.0, Robust::median($levels));
        self::assertEqualsWithDelta(self::ONE_SIGMA, (float) Robust::scaledMad($levels), 1e-12);
    }

    /**
     * Sixty cycling baseline days, then today at a chosen log offset — flat across
     * the clock, so only the baseline arithmetic is under test.
     */
    private function seedBaselineWithFinalDayAt(float $logOffset): void
    {
        StressFixture::days(self::TODAY, 61, fn (int $i): float => self::BASE_MS * exp(
            $i === 60 ? $logOffset : self::CYCLE[$i % 5]
        ));
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
