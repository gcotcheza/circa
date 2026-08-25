<?php

declare(strict_types=1);

namespace App\Services\Report;

use Throwable;
use App\Services\Tdee\Ols;
use Carbon\CarbonImmutable;
use App\Services\Stress\Robust;
use App\Services\Stress\StressAnalysis;

/**
 * The three long-window comparisons that the daily stress score cannot make.
 *
 * `stress_daily` scores a day against the SIXTY DAYS BEHIND IT — the right
 * yardstick for "was yesterday unusual for me?", and structurally incapable
 * of seeing slow decline, since the yardstick moves with the thing it
 * measures. An HRV sinking 1.5% a month drops 18% over a year, yet every one
 * of those days scores about 50 because each is ordinary next to the eight
 * weeks before it — a relative measure cannot see absolute drift. A report
 * is the only place in this app that looks at a long window on purpose, so
 * three comparisons are computed here, deterministically, as finished
 * numbers:
 *
 *   1. YEAR-ON-YEAR       this window's HRV against the SAME CALENDAR WINDOW
 *                         one year ago, not "the previous 90 days" — this
 *                         user's HRV has a visible summer, and a rolling
 *                         lookback would report that seasonal swing as a
 *                         trend every autumn and a recovery every spring.
 *
 *   2. THE ROLLING TREND  a least-squares slope through the last eight weeks
 *                         of daily HRV, in percent per 30 days. Eight whole
 *                         weeks so every weekday appears equally often, or
 *                         someone whose Saturdays differ could tilt the line
 *                         by which day the window happens to start on.
 *
 *   3. RESTING HEART RATE this window's median against the last year's — the
 *                         other half of the same story, and the one moving
 *                         opposite HRV: a resting rate creeping up while HRV
 *                         creeps down is a stronger signal than either alone,
 *                         and neither the stress page (60 days) nor the
 *                         resting-HR tile (90 days) can say it alone.
 *
 * Everything here is gated, and a gate returns null rather than a number: a
 * year-ago window with four readings has no median, a slope through eleven
 * days is a picture of eleven days. Each comparison carries the count behind
 * it, and the model is told a null means "not enough data", never zero.
 *
 * The slope is fitted in LOG units because `StressAnalysis` keeps daily HRV
 * as ln(ms) with the circadian shape already subtracted — the space the
 * quantity is actually normal in. A slope of `b` per day there is a constant
 * PERCENTAGE per day (exp(30b) − 1 is the 30-day change), which is both the
 * honest model for a multiplicative quantity and the form a person can read;
 * fitting raw milliseconds would make the same decline look steeper at the
 * start of the window than at the end.
 */
final readonly class BaselineDrift
{
    private function __construct(
        /** @var array<string, mixed> */
        public array $yearOnYear,
        /** @var array<string, mixed> */
        public array $trend,
        /** @var array<string, mixed> */
        public array $restingHeartRate,
    ) {}

    /**
     * @param  array<string, float>  $restingByDate  local date => bpm, over at
     *                                               least the resting window
     */
    public static function measure(
        ReportRange $range,
        StressAnalysis $analysis,
        array $restingByDate,
        CarbonImmutable $today,
    ): self {
        /** @var array<string, mixed> $config */
        $config = config('health.report.drift');

        return new self(
            yearOnYear: self::yearOnYear($range, $analysis, (int) ($config['year_offset_days'] ?? 365)),
            trend: self::trend(
                $range,
                $analysis,
                (int) ($config['trend_days'] ?? 56),
                (int) ($config['trend_min_days'] ?? 37),
            ),
            restingHeartRate: self::resting(
                $range,
                $restingByDate,
                (int) ($config['resting_median_days'] ?? 365),
                (int) ($config['min_readings'] ?? 20),
                $today,
            ),
        );
    }

    /**
     * This window's typical HRV against the same window a year ago.
     *
     * Both sides are the MEDIAN, not the mean: one 200 ms artefact — and this
     * user's history has them — drags a seven-day mean by several percent,
     * which is the whole size of the effect being looked for.
     *
     * @return array<string, mixed>
     */
    private static function yearOnYear(ReportRange $range, StressAnalysis $analysis, int $offsetDays): array
    {
        $ago = $range->shifted($offsetDays);

        $now = $analysis->levelsBetween($range->start, $range->end);
        $then = $analysis->levelsBetween($ago->start, $ago->end);

        $nowMedian = Robust::median($now);
        $thenMedian = Robust::median($then);

        $comparable = $nowMedian !== null && $thenMedian !== null;

        return [
            'window'            => ['start' => $range->start, 'end' => $range->end],
            'comparison_window' => ['start' => $ago->start, 'end' => $ago->end],
            'offset_days'       => $offsetDays,

            // Back in milliseconds so a reader recognises HRV, not logarithms.
            'hrv_ms_now'      => $nowMedian === null ? null : round(exp($nowMedian), 1),
            'hrv_ms_year_ago' => $thenMedian === null ? null : round(exp($thenMedian), 1),

            // exp(a − b) − 1 from the log medians, not from the rounded ms
            // figures above, so the percentage can't disagree with itself.
            'change_pct' => $comparable
                ? round((exp($nowMedian - $thenMedian) - 1) * 100, 1)
                : null,

            'days_now'      => count($now),
            'days_year_ago' => count($then),

            // Stated rather than implied, so the report can say out loud
            // that this is two medians of seven numbers, not more.
            'comparable' => $comparable,
        ];
    }

    /**
     * The rolling-baseline slope: is the eight-week trend rising or sinking?
     *
     * `Ols::fit` is borrowed from the TDEE estimator rather than
     * reimplemented — its docblock talks about weight, but the class itself
     * is an uncoupled textbook least-squares fit, and a second copy of the
     * same twenty lines is a second place for a sign error to live.
     *
     * @return array<string, mixed>
     */
    private static function trend(
        ReportRange $range,
        StressAnalysis $analysis,
        int $windowDays,
        int $minDays,
    ): array {
        $tz = (string) config('health.timezone');

        $end = CarbonImmutable::parse($range->end, $tz)->startOfDay();
        $start = $end->subDays(max(1, $windowDays) - 1);

        $points = [];

        foreach ($analysis->scoredDays() as $day) {
            if ($day->date < $start->toDateString() || $day->date > $end->toDateString()) {
                continue;
            }

            if ($day->hrvMs === null || $day->hrvMs <= 0) {
                continue;
            }

            // x in days from the window's start (no float error, unlike a
            // Unix timestamp / 86400), y in log-ms — the space a percentage
            // slope lives in.
            $points[] = [
                (float) StressAnalysis::epochDay($day->date),
                log($day->hrvMs),
            ];
        }

        $n = count($points);

        $base = [
            'window'            => ['start' => $start->toDateString(), 'end' => $end->toDateString()],
            'window_days'       => $windowDays,
            'days_with_a_level' => $n,
            'min_days_required' => $minDays,
        ];

        if ($n < $minDays) {
            return $base + [
                'change_pct_per_30d' => null,
                'direction'          => null,
                // Not "flat" — an unfitted window has no direction at all,
                // and the absence of a slope must not read as a flat one.
                'note' => 'Not enough scored days in this window to fit a trend.',
            ];
        }

        try {
            $fit = Ols::fit($points);
        } catch (Throwable) {
            // Every x identical — impossible above the gate, but the
            // alternative to handling it is a 500 in a queue worker.
            return $base + ['change_pct_per_30d' => null, 'direction' => null, 'note' => 'The trend could not be fitted.'];
        }

        $changePer30 = (exp($fit->slope * 30) - 1) * 100;

        /*
         * Direction is decided against the FIT'S OWN UNCERTAINTY, not zero —
         * calling a −0.2%/month drift "sinking" just because the point
         * estimate is negative is the same error as reading a coin's first
         * two flips as a bias. Two standard errors over thirty days is
         * roughly a 95% interval; if it spans zero, the honest word is
         * "flat", and the interval travels with the answer so the model can
         * say how sure the number is instead of taking it on faith.
         */
        $marginPer30 = (exp($fit->standardError * 30 * 2) - 1) * 100;

        return $base + [
            'change_pct_per_30d'      => round($changePer30, 1),
            'uncertainty_pct_per_30d' => round(abs($marginPer30), 1),
            'direction'               => match (true) {
                abs($changePer30) <= abs($marginPer30) => 'flat',
                $changePer30 > 0                       => 'rising',
                default                                => 'sinking',
            },
            'note' => null,
        ];
    }

    /**
     * Resting heart rate: this window's median against the last year's.
     *
     * The year window ENDS at the report's end date, not today, so a report
     * about March compares against the year up to March — regenerated in
     * December, it would otherwise measure March against a baseline March
     * hadn't lived through yet.
     *
     * @param  array<string, float>  $byDate
     * @return array<string, mixed>
     */
    private static function resting(
        ReportRange $range,
        array $byDate,
        int $medianDays,
        int $minReadings,
        CarbonImmutable $today,
    ): array {
        $tz = (string) config('health.timezone');

        $end = CarbonImmutable::parse($range->end, $tz)->startOfDay();

        if ($end->greaterThan($today)) {
            $end = $today;
        }

        $baselineStart = $end->subDays(max(1, $medianDays) - 1)->toDateString();
        $baselineEnd = $end->toDateString();

        $window = [];
        $baseline = [];

        foreach ($byDate as $date => $bpm) {
            if ($date >= $baselineStart && $date <= $baselineEnd) {
                $baseline[] = $bpm;
            }

            if ($date >= $range->start && $date <= $range->end) {
                $window[] = $bpm;
            }
        }

        $windowMedian = Robust::median($window);
        $baselineMedian = Robust::median($baseline);

        // The window's readings are a SUBSET of the baseline's, so the
        // baseline gate is the binding one — but both are checked, since a
        // three-day report can clear a year-long baseline gate while its own
        // two readings don't have a median worth comparing.
        $comparable = $windowMedian !== null
            && $baselineMedian !== null
            && count($baseline) >= $minReadings
            && count($window) >= min(2, $minReadings);

        return [
            'window'          => ['start' => $range->start, 'end' => $range->end],
            'baseline_window' => ['start' => $baselineStart, 'end' => $baselineEnd],
            'baseline_days'   => $medianDays,

            // Whole bpm — Apple exports integers, and a decimal place would
            // be precision this measurement never had.
            'bpm_now'             => $windowMedian === null ? null : round($windowMedian),
            'bpm_baseline_median' => $baselineMedian === null ? null : round($baselineMedian),
            'delta_bpm'           => $comparable ? round($windowMedian - $baselineMedian, 1) : null,

            'readings_now'          => count($window),
            'readings_baseline'     => count($baseline),
            'min_readings_required' => $minReadings,
            'comparable'            => $comparable,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'what_this_is' => 'Long-window comparisons the daily stress score cannot make. '
                .'That score measures each day against the 60 days behind it, so a slow drift in '
                .'either direction moves the yardstick with it and stays invisible there.',
            'hrv_year_on_year'   => $this->yearOnYear,
            'hrv_rolling_trend'  => $this->trend,
            'resting_heart_rate' => $this->restingHeartRate,
        ];
    }
}
