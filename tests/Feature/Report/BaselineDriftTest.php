<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Enums\HealthReportKind;
use Tests\Support\StressFixture;
use App\Services\Report\ReportFacts;
use App\Services\Report\ReportRange;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The blind spot, and the three comparisons that close it.
 *
 * `stress_daily` scores a day against the sixty days behind it, so an HRV that
 * sinks steadily still scores about fifty every day — each one is ordinary
 * compared with the eight weeks before it. That is what "relative" means, and it
 * is why a year of genuine decline can pass under the stress page without a
 * single amber day. The first test demonstrates exactly that: a 17.5%
 * year-on-year fall the daily scores do not register at all, and a drift block
 * that does.
 *
 * Six readings a day rather than twenty-four: the estimator needs three to score
 * a day, the profile is flat by construction here, and fourteen months at
 * twenty-four an hour is ten thousand rows to prove what six an hour proves.
 */
final class BaselineDriftTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-10';

    private const START = '2026-08-03';

    private const END = '2026-08-09';

    /** Enough to cover the year-ago window plus its own 60-day baseline. */
    private const HISTORY_DAYS = 440;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 04:10:00', StressFixture::TZ));
    }

    /** HRV a sixth lower than the same week last year; every day scores ordinary. */
    public function test_it_sees_a_year_on_year_fall_that_the_daily_scores_cannot(): void
    {
        // 40 ms a year ago, 33 ms now, with the step well before the current
        // window so that the recent baseline has long since caught up.
        $this->seedHrv(fn (string $date): float => $date < '2026-04-01' ? 40.0 : 33.0);

        $snapshot = $this->assemble();

        $yoy = $snapshot['baseline_drift']['hrv_year_on_year'];

        self::assertTrue($yoy['comparable']);
        self::assertSame('2025-08-03', $yoy['comparison_window']['start']);
        self::assertSame('2025-08-09', $yoy['comparison_window']['end']);
        self::assertEqualsWithDelta(40.0, $yoy['hrv_ms_year_ago'], 0.1);
        self::assertEqualsWithDelta(33.0, $yoy['hrv_ms_now'], 0.1);

        // 33/40 - 1 = -17.5%.
        self::assertEqualsWithDelta(-17.5, $yoy['change_pct'], 0.1);

        /*
         * The point of the whole block: the daily score is blind to it. Every
         * day of the week sits mid-scale, measured against a baseline that
         * moved down with it four months ago.
         */
        foreach ($snapshot['days'] as $row) {
            self::assertGreaterThan(40, $row['stress_score'], "{$row['date']} should look ordinary");
            self::assertLessThan(90, $row['stress_score']);
        }
    }

    /**
     * The same window a year earlier, not a rolling lookback: this user's HRV
     * has a summer, and rolling would report the season as a trend every autumn.
     */
    public function test_the_comparison_window_is_the_same_calendar_week_a_year_earlier(): void
    {
        $this->seedHrv(static fn (): float => 33.0);

        $yoy = $this->assemble()['baseline_drift']['hrv_year_on_year'];

        self::assertSame(365, $yoy['offset_days']);
        self::assertSame(['start' => self::START, 'end' => self::END], $yoy['window']);
        self::assertSame(7, $yoy['days_now']);
        self::assertSame(7, $yoy['days_year_ago']);
        self::assertEqualsWithDelta(0.0, $yoy['change_pct'], 0.1);
    }

    /**
     * A gate returns null, not a number: an empty year-ago window has no median,
     * and reporting one would invent the comparison the block exists to make.
     */
    public function test_with_no_history_a_year_ago_the_comparison_is_not_made(): void
    {
        // Ninety days only: enough to score the week, nowhere near a year.
        $this->seedHrv(static fn (): float => 33.0, days: 90);

        $yoy = $this->assemble()['baseline_drift']['hrv_year_on_year'];

        self::assertFalse($yoy['comparable']);
        self::assertNull($yoy['change_pct']);
        self::assertNull($yoy['hrv_ms_year_ago']);
        self::assertSame(0, $yoy['days_year_ago']);

        // The current side is still reported: "this week was 33 ms, and there
        // is nothing to compare it with" is a useful sentence.
        self::assertEqualsWithDelta(33.0, $yoy['hrv_ms_now'], 0.1);
    }

    /**
     * The rolling slope, fitted in log units so the answer is a constant
     * percentage per month, not a millisecond figure meaning something
     * different at each end of the window.
     */
    public function test_it_fits_a_sinking_eight_week_trend_and_names_the_direction(): void
    {
        // A constant -0.001 per day in log space over the whole history.
        $anchor = CarbonImmutable::parse(self::END, StressFixture::TZ);

        $this->seedHrv(function (string $date) use ($anchor): float {
            $daysBack = (int) CarbonImmutable::parse($date, StressFixture::TZ)->diffInDays($anchor);

            return 33.0 * exp(0.001 * $daysBack);
        });

        $trend = $this->assemble()['baseline_drift']['hrv_rolling_trend'];

        self::assertSame('sinking', $trend['direction']);
        self::assertSame(56, $trend['window_days']);
        self::assertSame(56, $trend['days_with_a_level']);

        // exp(-0.001 x 30) - 1 = -2.96% per 30 days.
        self::assertEqualsWithDelta(-3.0, $trend['change_pct_per_30d'], 0.2);
        self::assertNull($trend['note']);
    }

    public function test_a_rising_trend_is_named_as_rising(): void
    {
        $anchor = CarbonImmutable::parse(self::END, StressFixture::TZ);

        $this->seedHrv(function (string $date) use ($anchor): float {
            $daysBack = (int) CarbonImmutable::parse($date, StressFixture::TZ)->diffInDays($anchor);

            return 33.0 * exp(-0.001 * $daysBack);
        });

        $trend = $this->assemble()['baseline_drift']['hrv_rolling_trend'];

        self::assertSame('rising', $trend['direction']);
        self::assertGreaterThan(0, $trend['change_pct_per_30d']);
    }

    /**
     * "Flat" is decided against the fit's own uncertainty, not against zero, so
     * a noisy window gets no direction read out of a meaningless estimate.
     */
    public function test_a_level_history_is_flat_rather_than_given_a_direction(): void
    {
        $this->seedHrv(static fn (): float => 33.0);

        $trend = $this->assemble()['baseline_drift']['hrv_rolling_trend'];

        self::assertSame('flat', $trend['direction']);
        self::assertEqualsWithDelta(0.0, $trend['change_pct_per_30d'], 0.2);
    }

    public function test_too_few_scored_days_produces_no_slope_and_says_why(): void
    {
        // Thirty days: the week scores, but the eight-week window is mostly empty.
        $this->seedHrv(static fn (): float => 33.0, days: 30);

        $trend = $this->assemble()['baseline_drift']['hrv_rolling_trend'];

        self::assertNull($trend['change_pct_per_30d']);
        // NOT "flat": a window that could not be fitted has no direction, and
        // reading an absent slope as a flat one is the mistake.
        self::assertNull($trend['direction']);
        self::assertStringContainsString('Not enough scored days', (string) $trend['note']);
    }

    /**
     * The other half, moving the opposite way: a resting rate creeping up while
     * HRV creeps down is a much stronger signal than either alone.
     */
    public function test_resting_heart_rate_is_compared_with_the_year_median(): void
    {
        $this->seedHrv(static fn (): float => 33.0);

        // A year at 52, and this week at 57.
        $resting = [];
        $cursor = CarbonImmutable::parse(self::END, StressFixture::TZ)->subDays(364);

        while ($cursor->toDateString() <= self::END) {
            $date = $cursor->toDateString();
            $resting[$date] = $date >= self::START ? 57.0 : 52.0;
            $cursor = $cursor->addDay();
        }

        StressFixture::resting($resting);

        $hr = $this->assemble()['baseline_drift']['resting_heart_rate'];

        self::assertTrue($hr['comparable']);
        self::assertSame(57.0, $hr['bpm_now']);
        self::assertSame(52.0, $hr['bpm_baseline_median']);
        self::assertSame(5.0, $hr['delta_bpm']);
        self::assertSame(7, $hr['readings_now']);
        self::assertSame(365, $hr['readings_baseline']);
    }

    public function test_too_few_resting_readings_is_not_a_comparison(): void
    {
        $this->seedHrv(static fn (): float => 33.0);

        StressFixture::resting([self::START => 55.0, self::END => 56.0]);

        $hr = $this->assemble()['baseline_drift']['resting_heart_rate'];

        self::assertFalse($hr['comparable']);
        self::assertNull($hr['delta_bpm']);
        self::assertSame(2, $hr['readings_baseline']);
        self::assertSame(20, $hr['min_readings_required']);
    }

    /**
     * The block explains itself in the snapshot, so the prompt can read WHY
     * these three comparisons exist without duplicating the reasoning.
     */
    public function test_the_block_states_what_it_is_for(): void
    {
        $this->seedHrv(static fn (): float => 33.0, days: 90);

        $drift = $this->assemble()['baseline_drift'];

        self::assertStringContainsString('60 days behind it', $drift['what_this_is']);
        self::assertArrayHasKey('hrv_year_on_year', $drift);
        self::assertArrayHasKey('hrv_rolling_trend', $drift);
        self::assertArrayHasKey('resting_heart_rate', $drift);
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(): array
    {
        return app(ReportFacts::class)->assemble(
            ReportRange::between(self::START, self::END),
            HealthReportKind::Weekly,
        );
    }

    /**
     * @param  callable(string $date): float  $ms
     */
    private function seedHrv(callable $ms, int $days = self::HISTORY_DAYS): void
    {
        StressFixture::days(
            self::END,
            $days,
            static fn (int $i, int $hour, string $date): float => $ms($date),
            StressFixture::NIGHT_HOURS,
        );
    }
}
