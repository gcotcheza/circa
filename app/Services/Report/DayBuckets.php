<?php

declare(strict_types=1);

namespace App\Services\Report;

use Carbon\CarbonImmutable;

/**
 * The day table, folded into weekly or monthly periods for a long range.
 *
 * DayTable's one-row-per-date shape is right for a fortnight (fourteen
 * scannable rows) but wrong for a YEAR: 365 rows is too many tokens and the
 * wrong shape for "which months were more stressful," which twelve month
 * buckets answer directly. This wasn't hypothetical — a 365-day stress
 * report assembled ~136k input tokens of daily rows and came back with a
 * headline, one summary paragraph, and every structured section empty (the
 * model, handed a wall of rows, consolidated instead of reporting), while
 * the same focus over 182 days (~78k tokens) produced a full document. So
 * beyond a threshold the day table ships as PERIOD BUCKETS — the same mean/
 * median/min/max/count the window-level blocks carry, computed here per
 * period — shrinking the input back into the range that yields a rich
 * answer while handing the model the month/week structure directly.
 *
 * The model still does no arithmetic: every bucket figure is computed here
 * from the same daily rows, a bucket mean is as much a fact as a day's
 * score, and day-level cross-cuts the model can no longer eyeball stay in
 * `anchor_statistics`, assembled unchanged regardless of granularity.
 * Honesty survives the fold too — a period carries its own denominators
 * (scored days, watch coverage, the `n` behind every mean), the same
 * contract the day table and coverage block already run on. Dropped, and
 * not a loss: the free-text unscored-reason (a per-day note with no
 * per-month form), clock times for sleep and the last meal, and the named
 * workout list (a median bedtime across a month straddles midnight and
 * means nothing) — the counts that matter (scored days, sessions,
 * coverage) are kept.
 */
final class DayBuckets
{
    public const DAILY = 'daily';

    public const WEEKLY = 'weekly';

    public const MONTHLY = 'monthly';

    /**
     * Numeric columns, each with the precision a bucket mean is rounded to
     * — a daily stress score is a whole number, but a month's mean of
     * thirty isn't, so these precisions keep the SIGNAL a period comparison
     * rests on rather than the raw column's own precision.
     */
    private const NUMERIC = [
        'stress_score'         => 1,
        'stress_confidence'    => 2,
        'hrv_ms'               => 1,
        'hrv_readings'         => 1,
        'resting_hr_bpm'       => 1,
        'sleep_hours'          => 2,
        'sleep_awake_minutes'  => 0,
        'steps'                => 0,
        'exercise_minutes'     => 0,
        'distance_km'          => 2,
        'kcal_in_min'          => 0,
        'kcal_in_mid'          => 0,
        'kcal_in_max'          => 0,
        'kcal_out'             => 0,
        'active_kcal'          => 0,
        'resting_kcal'         => 0,
        'active_kcal_coverage' => 3,
        'weight_kg'            => 2,
        'meals_logged'         => 1,
    ];

    /**
     * Boolean day-flags folded into a day-count with a percentage, emitted
     * under the same key the coverage block uses so a reader meets the same
     * phrase in both places.
     */
    private const COVERAGE = [
        'metric_coverage_full'   => 'days_with_full_metric_coverage',
        'active_kcal_is_partial' => 'days_with_partial_watch_energy',
        'food_log_complete'      => 'complete_food_log_days',
    ];

    /** Categorical columns folded into a value => count histogram. */
    private const BANDS = [
        'stress_band' => 'stress_bands',
    ];

    /**
     * Which granularity a range of this many days is delivered at.
     *
     * A ceiling, not a preference — the daily one is deliberately the
     * quarter every unfocused/food report already tops out at, so nothing a
     * reader has seen before moves; only the longer ranges a focused report
     * unlocked get folded.
     */
    public static function granularityFor(int $days): string
    {
        /** @var array<string, mixed> $config */
        $config = config('health.report.buckets', []);

        $dailyMax = max(1, (int) ($config['daily_max_days'] ?? 92));
        $weeklyMax = max($dailyMax, (int) ($config['weekly_max_days'] ?? 210));

        return match (true) {
            $days <= $dailyMax  => self::DAILY,
            $days <= $weeklyMax => self::WEEKLY,
            default             => self::MONTHLY,
        };
    }

    /**
     * The daily rows, folded into a list of period buckets.
     *
     * Rows are the already-slimmed day table, so a bucket only carries the
     * columns the focus kept — the fold never re-widens a narrowed report.
     * Rows arrive chronological (DayTable builds them in date order) and
     * periods are contiguous, so grouping in one pass keeps them in order.
     *
     * @param  list<array<string, mixed>>  $rows  the slimmed day table
     * @return list<array<string, mixed>>
     */
    public function build(array $rows, string $granularity): array
    {
        if ($rows === []) {
            return [];
        }

        $tz = (string) config('health.timezone');

        /** @var array<string, non-empty-list<array<string, mixed>>> $groups */
        $groups = [];

        foreach ($rows as $row) {
            $key = $this->periodKey((string) $row['date'], $granularity, $tz);
            $groups[$key][] = $row;
        }

        $columns = array_keys($rows[0]);

        $buckets = [];

        foreach ($groups as $days) {
            $buckets[] = $this->bucket($days, $columns, $granularity, $tz);
        }

        return $buckets;
    }

    /**
     * One period, as a self-describing summary of its days.
     *
     * @param  non-empty-list<array<string, mixed>>  $days
     * @param  list<string>  $columns
     * @return array<string, mixed>
     */
    private function bucket(array $days, array $columns, string $granularity, string $tz): array
    {
        $dates = array_map(static fn (array $row): string => (string) $row['date'], $days);
        $start = min($dates);
        $end = max($dates);
        $count = count($days);

        $bucket = [
            'period'         => $this->periodKey($start, $granularity, $tz),
            'label'          => $this->label($start, $granularity, $tz),
            'starts'         => $start,
            'ends'           => $end,
            'days_in_period' => $count,
        ];

        foreach ($columns as $column) {
            if (isset(self::COVERAGE[$column])) {
                $n = 0;

                foreach ($days as $row) {
                    if (($row[$column] ?? false) === true) {
                        $n++;
                    }
                }

                $bucket[self::COVERAGE[$column]] = [
                    'n'   => $n,
                    'pct' => round($n / $count * 100, 1),
                ];

                continue;
            }

            if (isset(self::BANDS[$column])) {
                $counts = [];

                foreach ($days as $row) {
                    $band = $row[$column] ?? null;

                    if ($band !== null) {
                        $counts[(string) $band] = ($counts[(string) $band] ?? 0) + 1;
                    }
                }

                $bucket[self::BANDS[$column]] = $counts;

                continue;
            }

            if (isset(self::NUMERIC[$column])) {
                $values = [];

                foreach ($days as $row) {
                    $value = $row[$column] ?? null;

                    if ($value !== null && is_numeric($value)) {
                        $values[] = (float) $value;
                    }
                }

                $bucket[$column] = Series::summarise($values, self::NUMERIC[$column]);
            }

            // Every other column — date/weekday, clock times and free-text
            // reasons with no per-period form, the workout list (below),
            // food/supplement columns a lean focus never carries — is
            // deliberately not folded.
        }

        // The scored/unscored split a report qualifies every stress claim
        // by, stated as a count so the model never has to subtract it out.
        $stress = $bucket['stress_score'] ?? null;

        if (is_array($stress)) {
            $bucket['stress_unscored_days'] = $count - (int) $stress['n'];
        }

        if (in_array('training', $columns, true)) {
            $bucket += $this->training($days);
        }

        return $bucket;
    }

    /**
     * The workout column, folded to counts (days with a session, sessions,
     * total minutes) — not the sessions themselves, since a month of named
     * workouts is a list, not a summary.
     *
     * @param  list<array<string, mixed>>  $days
     * @return array<string, int>
     */
    private function training(array $days): array
    {
        $daysWith = 0;
        $sessions = 0;
        $minutes = 0;

        foreach ($days as $row) {
            $log = $row['training'] ?? [];

            if (! is_array($log) || $log === []) {
                continue;
            }

            $daysWith++;
            $sessions += count($log);

            foreach ($log as $workout) {
                if (is_array($workout) && is_numeric($workout['minutes'] ?? null)) {
                    $minutes += (int) $workout['minutes'];
                }
            }
        }

        return [
            'training_days'          => $daysWith,
            'training_sessions'      => $sessions,
            'training_minutes_total' => $minutes,
        ];
    }

    /**
     * The ISO week or the calendar month a date falls in, as a stable key.
     *
     * ISO week-year (`GGGG`) rather than calendar year, so late December
     * and early January land in the week they belong to instead of being
     * split by a year boundary the week straddles.
     */
    private function periodKey(string $date, string $granularity, string $tz): string
    {
        $day = CarbonImmutable::parse($date, $tz);

        return $granularity === self::WEEKLY
            ? $day->isoFormat('GGGG-[W]WW')
            : $day->format('Y-m');
    }

    private function label(string $date, string $granularity, string $tz): string
    {
        $day = CarbonImmutable::parse($date, $tz);

        return $granularity === self::WEEKLY
            ? 'Week of '.$day->startOfWeek(CarbonImmutable::MONDAY)->isoFormat('D MMM YYYY')
            : $day->isoFormat('MMMM YYYY');
    }
}
