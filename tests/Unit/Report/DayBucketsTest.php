<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use Tests\TestCase;
use App\Services\Report\DayBuckets;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The fold from daily rows into weekly / monthly period buckets, and the two
 * claims the feature rests on. The THRESHOLD — which range length is delivered
 * daily, weekly, monthly — is the switch between the shape that produced an
 * empty year-long report and the shape that fixes it. The ARITHMETIC is computed
 * here in PHP against a known fixture, because the design is that the model
 * reads these figures rather than working them out.
 */
final class DayBucketsTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string}>
     */
    public static function ranges(): iterable
    {
        yield 'a week is daily' => [7, DayBuckets::DAILY];
        yield 'a quarter is still daily' => [92, DayBuckets::DAILY];
        yield 'one day past a quarter is weekly' => [93, DayBuckets::WEEKLY];
        yield 'half a year is weekly' => [182, DayBuckets::WEEKLY];
        yield 'the weekly ceiling is weekly' => [210, DayBuckets::WEEKLY];
        yield 'past the weekly ceiling is monthly' => [211, DayBuckets::MONTHLY];
        yield 'a year is monthly' => [365, DayBuckets::MONTHLY];
    }

    #[DataProvider('ranges')]
    public function test_the_granularity_is_chosen_by_range_length(int $days, string $expected): void
    {
        self::assertSame($expected, DayBuckets::granularityFor($days));
    }

    public function test_the_thresholds_are_configurable(): void
    {
        config()->set('health.report.buckets.daily_max_days', 30);
        config()->set('health.report.buckets.weekly_max_days', 120);

        self::assertSame(DayBuckets::DAILY, DayBuckets::granularityFor(30));
        self::assertSame(DayBuckets::WEEKLY, DayBuckets::granularityFor(31));
        self::assertSame(DayBuckets::WEEKLY, DayBuckets::granularityFor(120));
        self::assertSame(DayBuckets::MONTHLY, DayBuckets::granularityFor(121));
    }

    public function test_an_empty_table_folds_to_nothing(): void
    {
        self::assertSame([], (new DayBuckets)->build([], DayBuckets::MONTHLY));
    }

    /**
     * The monthly fold against a fixture straddling a month boundary, so the
     * split is under test as well as the stats.
     */
    public function test_a_monthly_bucket_summarises_its_days(): void
    {
        $rows = [
            // January: two days, one of them unscored.
            $this->row('2026-01-30', score: 40, band: 'high_stress', hrv: 30.0, sleep: 6.0, covered: true),
            $this->row('2026-01-31', score: null, band: null, hrv: null, sleep: 7.0, covered: false,
                training: [['type' => 'Run', 'minutes' => 30]]),

            // February: three scored days.
            $this->row('2026-02-01', score: 50, band: 'calm', hrv: 40.0, sleep: 8.0, covered: true,
                training: [['type' => 'Yoga', 'minutes' => 60]]),
            $this->row('2026-02-02', score: 60, band: 'calm', hrv: 50.0, sleep: 7.0, covered: true),
            $this->row('2026-02-03', score: 70, band: 'steady', hrv: 60.0, sleep: 6.0, covered: true,
                training: [['type' => 'Run', 'minutes' => 20], ['type' => 'Walk', 'minutes' => 15]]),
        ];

        $buckets = (new DayBuckets)->build($rows, DayBuckets::MONTHLY);

        self::assertCount(2, $buckets);

        [$jan, $feb] = $buckets;

        // The span is the range's, not the calendar month's: January starts here
        // on the 30th.
        self::assertSame('2026-01', $jan['period']);
        self::assertSame('January 2026', $jan['label']);
        self::assertSame('2026-01-30', $jan['starts']);
        self::assertSame('2026-01-31', $jan['ends']);
        self::assertSame(2, $jan['days_in_period']);

        // Stress: the mean is over the days that HAD a score, with the count
        // beside it — January's unscored day is stated, not left to be
        // subtracted out.
        self::assertSame(1, $jan['stress_score']['n']);
        self::assertSame(40.0, $jan['stress_score']['mean']);
        self::assertSame(1, $jan['stress_unscored_days']);
        self::assertSame(['high_stress' => 1], $jan['stress_bands']);

        self::assertSame(3, $feb['stress_score']['n']);
        self::assertSame(60.0, $feb['stress_score']['mean']);
        self::assertSame(60.0, $feb['stress_score']['median']);
        self::assertSame(50.0, $feb['stress_score']['min']);
        self::assertSame(70.0, $feb['stress_score']['max']);
        self::assertSame(0, $feb['stress_unscored_days']);
        self::assertSame(['calm' => 2, 'steady' => 1], $feb['stress_bands']);

        // HRV skips the null day rather than counting it as a zero.
        self::assertSame(1, $jan['hrv_ms']['n']);
        self::assertSame(3, $feb['hrv_ms']['n']);
        self::assertSame(50.0, $feb['hrv_ms']['mean']);

        // Sleep, at its own precision.
        self::assertSame(6.5, $jan['sleep_hours']['mean']);
        self::assertSame(7.0, $feb['sleep_hours']['mean']);
        self::assertSame(6.0, $feb['sleep_hours']['min']);
        self::assertSame(8.0, $feb['sleep_hours']['max']);

        // Coverage is folded to a day-count with a percentage.
        self::assertSame(1, $jan['days_with_full_metric_coverage']['n']);
        self::assertSame(50.0, $jan['days_with_full_metric_coverage']['pct']);
        self::assertSame(3, $feb['days_with_full_metric_coverage']['n']);
        self::assertSame(100.0, $feb['days_with_full_metric_coverage']['pct']);

        // Training is folded to counts, not the sessions themselves.
        self::assertSame(1, $jan['training_days']);
        self::assertSame(1, $jan['training_sessions']);
        self::assertSame(30, $jan['training_minutes_total']);
        self::assertSame(2, $feb['training_days']);
        self::assertSame(3, $feb['training_sessions']);
        self::assertSame(95, $feb['training_minutes_total']);
    }

    /**
     * The weekly fold, keyed on the ISO week so the turn of a year lands in the
     * week it belongs to rather than being split by the calendar.
     */
    public function test_a_weekly_bucket_groups_by_iso_week(): void
    {
        $rows = [
            $this->row('2026-01-05', score: 50, band: 'calm', hrv: 40.0, sleep: 7.0, covered: true), // ISO week 2, Mon
            $this->row('2026-01-06', score: 60, band: 'calm', hrv: 44.0, sleep: 7.0, covered: true), // ISO week 2, Tue
            $this->row('2026-01-12', score: 30, band: 'high_stress', hrv: 25.0, sleep: 5.0, covered: false), // ISO week 3, Mon
        ];

        $buckets = (new DayBuckets)->build($rows, DayBuckets::WEEKLY);

        self::assertCount(2, $buckets);

        [$week2, $week3] = $buckets;

        self::assertSame('2026-W02', $week2['period']);
        self::assertSame('Week of 5 Jan 2026', $week2['label']);
        self::assertSame('2026-01-05', $week2['starts']);
        self::assertSame('2026-01-06', $week2['ends']);
        self::assertSame(2, $week2['days_in_period']);
        self::assertSame(55.0, $week2['stress_score']['mean']);

        self::assertSame('2026-W03', $week3['period']);
        self::assertSame(1, $week3['days_in_period']);
        self::assertSame(30.0, $week3['stress_score']['mean']);
    }

    /**
     * A folded bucket only carries the columns its rows carried: rows reaching
     * this class are already slimmed to the focus, so a stress fold has nothing
     * to say about food and must not invent a key for it.
     */
    public function test_a_bucket_only_carries_the_columns_its_rows_have(): void
    {
        $rows = [
            ['date' => '2026-03-01', 'weekday' => 'Sun', 'stress_score' => 55, 'sleep_hours' => 7.0],
            ['date' => '2026-03-02', 'weekday' => 'Mon', 'stress_score' => 45, 'sleep_hours' => 6.0],
        ];

        $bucket = (new DayBuckets)->build($rows, DayBuckets::MONTHLY)[0];

        self::assertArrayHasKey('stress_score', $bucket);
        self::assertArrayHasKey('sleep_hours', $bucket);

        // No food columns were in the rows, so none are in the bucket.
        self::assertArrayNotHasKey('meals_logged', $bucket);
        self::assertArrayNotHasKey('weight_kg', $bucket);
        self::assertArrayNotHasKey('training_days', $bucket);

        // The raw date and weekday are replaced by the period, not carried into it.
        self::assertArrayNotHasKey('date', $bucket);
        self::assertArrayNotHasKey('weekday', $bucket);
    }

    /**
     * A numeric column that had no data in a period is present with a zero count
     * and null stats, not silently dropped — the reader has to be able to tell a
     * period where nothing was measured from one that was never tracked.
     */
    public function test_a_column_with_no_data_in_a_period_is_a_null_series(): void
    {
        $rows = [
            $this->row('2026-04-01', score: null, band: null, hrv: null, sleep: 7.0, covered: true),
            $this->row('2026-04-02', score: null, band: null, hrv: null, sleep: 8.0, covered: true),
        ];

        $bucket = (new DayBuckets)->build($rows, DayBuckets::MONTHLY)[0];

        self::assertSame(0, $bucket['stress_score']['n']);
        self::assertNull($bucket['stress_score']['mean']);
        self::assertSame(2, $bucket['stress_unscored_days']);
    }

    /**
     * One stress-shaped day row, with only the columns a stress focus keeps.
     *
     * @param  list<array<string, mixed>>  $training
     * @return array<string, mixed>
     */
    private function row(
        string $date,
        ?int $score,
        ?string $band,
        ?float $hrv,
        ?float $sleep,
        bool $covered,
        array $training = [],
    ): array {
        return [
            'date'                 => $date,
            'weekday'              => 'Xxx',
            'stress_score'         => $score,
            'stress_band'          => $band,
            'hrv_ms'               => $hrv,
            'resting_hr_bpm'       => 55.0,
            'sleep_hours'          => $sleep,
            'exercise_minutes'     => 20,
            'training'             => $training,
            'metric_coverage_full' => $covered,
        ];
    }
}
