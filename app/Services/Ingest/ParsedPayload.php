<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * A payload turned into rows, before anything is written.
 *
 * Rows are already de-duplicated — not tidiness: a multi-row `INSERT ... ON
 * CONFLICT DO UPDATE` where two VALUES tuples share the conflict key fails
 * outright with "cannot affect row a second time," so this is what makes
 * batching possible, using the database's own rule (larger value for a
 * cumulative metric, later one otherwise).
 */
final readonly class ParsedPayload
{
    /**
     * @param  list<MetricRow>  $metricRows
     * @param  list<SleepRow>  $sleepRows
     * @param  list<WorkoutRow>  $workoutRows
     * @param  list<string>  $skipped  human-readable reasons, one per skipped datapoint
     */
    public function __construct(
        public array $metricRows,
        public array $sleepRows,
        public array $workoutRows,
        public int $datapoints,
        public int $metricBlocks,
        public array $skipped,
    ) {}

    public function isEmpty(): bool
    {
        return $this->metricRows === []
            && $this->sleepRows === []
            && $this->workoutRows === [];
    }

    /**
     * The local calendar days this payload touched — the input to step 3's
     * `RebuildDailySummary` dispatch.
     *
     * Derived from the rows rather than the export's date range, since
     * they differ: a "Since Last Sync" export at 00:20 carries yesterday's
     * last bucket and today's first, and a batched historical export
     * carries whatever backlog slice that batch held. Metric rows key on
     * the day their BUCKET STARTED, matching `health_metrics.local_date`
     * exactly (the rollup joins on that column, so any other rule would
     * dirty a day it doesn't read); sleep keys on HAE's own night date, the
     * column `sleep_sessions` is queried by.
     *
     * WORKOUTS ARE DELIBERATELY ABSENT: `daily_summaries` derives nothing
     * from `workouts`, since a session's active energy is already inside
     * the day's `active_kcal` — the Watch records that energy regardless of
     * a running workout (see the migration) — so a landing workout
     * invalidates no summary, and adding its date here would have queued a
     * hundred pointless rebuilds on the first backfill replay while
     * implying a dependency that must never exist.
     *
     * @return list<string>
     */
    public function dirtyLocalDates(string $timezone): array
    {
        $dates = [];

        foreach ($this->metricRows as $row) {
            $dates[$row->startedAt->setTimezone($timezone)->toDateString()] = true;
        }

        foreach ($this->sleepRows as $row) {
            $dates[$row->nightDate] = true;
        }

        return array_keys($dates);
    }
}
