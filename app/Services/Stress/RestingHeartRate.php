<?php

declare(strict_types=1);

namespace App\Services\Stress;

use Illuminate\Support\Facades\DB;

/**
 * Read resting heart rate out of `health_metrics`, one value per local day.
 *
 * Doesn't go through BucketSelector, unlike HrvSamples: BucketSelector
 * exists to stop a metric being double-COUNTED, but resting heart rate
 * isn't summed or meaningfully hourly — Apple derives ONE value per day
 * and exports it as a nominal hour-long bucket (930 rows across 1,200
 * production days, each alone on its date), so there's no interval to
 * resolve and greedy selection over a set of one would be ceremony, not
 * safety. What IS guarded against is a second source writing the same
 * metric: the day's value is the MEDIAN of whatever arrived, the identity
 * function today but the middle opinion rather than a doubled one on a
 * two-source day. Grouped on `local_date`, the Postgres generated column,
 * exactly as HrvSamples and DailySummaryBuilder are, so a resting-HR day
 * and a stress day are the same day without PHP and Postgres agreeing
 * about midnight.
 */
final class RestingHeartRate
{
    /**
     * Beats per minute for every local date in [$start, $end] that has one.
     *
     * Dates with no reading are ABSENT, not present-and-null: a missing
     * resting rate is a gap, and the caller decides what a gap means.
     *
     * @return array<string, float> keyed by local date, chronological
     */
    public function between(string $start, string $end): array
    {
        $metric = (string) config('health.stress.digest.resting_metric', 'resting_heart_rate');

        $rows = DB::table('health_metrics')
            ->select(['local_date', 'value'])
            ->where('metric', $metric)
            // A zero or negative resting heart rate is a corrupt row, not
            // a remarkable morning — dropped, exactly as HrvSample drops a
            // non-positive HRV.
            ->where('value', '>', 0)
            ->whereBetween('local_date', [$start, $end])
            ->orderBy('local_date')
            ->get();

        /** @var array<string, list<float>> $byDate */
        $byDate = [];

        foreach ($rows as $row) {
            $byDate[(string) $row->local_date][] = (float) $row->value;
        }

        $out = [];

        foreach ($byDate as $date => $values) {
            $median = Robust::median($values);

            if ($median !== null) {
                $out[$date] = $median;
            }
        }

        ksort($out);

        return $out;
    }
}
