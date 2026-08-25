<?php

declare(strict_types=1);

namespace App\Services\Stress;

use Illuminate\Support\Facades\DB;
use App\Services\Rollup\MetricBucket;
use App\Services\Rollup\BucketSelector;

/**
 * Read HRV out of `health_metrics`, overlap-resolved, grouped by local day.
 *
 * Goes through BucketSelector even though today's data is tidy: every one
 * of the 13 210 HRV rows is a one-hour bucket anchored exactly on the hour
 * from a single source (the Watch), so selection is currently a no-op here,
 * costing one pass per day. It runs anyway because the tidiness is a
 * property of the current export, not the schema — SPEC.md's "bucket
 * alignment is NOT always hourly" is about Health Auto Export's
 * since-last-sync mode, which anchors buckets at the previous sync instant
 * and produces partially overlapping rows (step_count already arrives that
 * way). The moment HRV is exported in that mode, or a second source starts
 * writing it, a naive read would double-count the contested hours and shift
 * the day's median; reusing the established selection keeps that day a
 * no-op too, rather than a bug found six months later in a score nobody can
 * reproduce.
 *
 * Grouping is by `local_date`, the Postgres generated column, exactly as
 * DailySummaryBuilder does: a bucket belongs to the day it STARTED in, so a
 * 23:00-00:00 reading is wholly yesterday's — which is also what lets the
 * day's score join against `daily_summaries` and `meals` without a second
 * opinion about where midnight is.
 */
final class HrvSamples
{
    public function __construct(private readonly BucketSelector $selector = new BucketSelector) {}

    /**
     * Accepted samples for every local date in [$start, $end], inclusive.
     *
     * Dates with no readings are ABSENT rather than present-and-empty: a
     * missing key is a day the Watch said nothing about, where an empty
     * array would invite treating it as a measured zero.
     *
     * @return array<string, list<HrvSample>> keyed by local date, chronological
     */
    public function between(string $start, string $end): array
    {
        $metric = (string) config('health.stress.metric', 'heart_rate_variability');
        $tz = (string) config('health.timezone');

        $rows = DB::table('health_metrics as hm')
            ->join('sources as s', 's.id', '=', 'hm.source_id')
            ->select([
                'hm.id',
                'hm.metric',
                'hm.source_id',
                's.device_kind',
                'hm.started_at',
                'hm.ended_at',
                'hm.value',
                'hm.local_date',
            ])
            ->where('hm.metric', $metric)
            ->whereBetween('hm.local_date', [$start, $end])
            ->orderBy('hm.local_date')
            ->orderBy('hm.started_at')
            ->get();

        /** @var array<string, list<MetricBucket>> $bucketsByDate */
        $bucketsByDate = [];

        foreach ($rows as $row) {
            $bucketsByDate[(string) $row->local_date][] = MetricBucket::fromRow($row);
        }

        $samples = [];

        foreach ($bucketsByDate as $date => $buckets) {
            $accepted = [];

            foreach ($this->selector->select($metric, $buckets)->accepted as $bucket) {
                $sample = HrvSample::fromBucket($bucket, $tz);

                if ($sample !== null) {
                    $accepted[] = $sample;
                }
            }

            if ($accepted !== []) {
                $samples[$date] = $accepted;
            }
        }

        ksort($samples);

        return $samples;
    }

    /**
     * Every sample in the set, flattened. The shape the profile and the heatmap
     * want, neither of which cares which day a reading came from.
     *
     * @param  array<string, list<HrvSample>>  $byDate
     * @return list<HrvSample>
     */
    public static function flatten(array $byDate): array
    {
        return array_merge(...array_values($byDate)) ?: [];
    }
}
