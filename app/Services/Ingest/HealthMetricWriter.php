<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;

/**
 * The upsert. This is where the spec's "direction v1 left unspecified" lives.
 *
 * The trailing hour of every export is partial (10:20 sync reports 400
 * steps for the 10:00 bucket, 11:05 sync reports 1200 for the same bucket —
 * both true, the second more complete), so two conflict directions share
 * one unique key: CUMULATIVE columns (steps, distance, energy, elapsed
 * minutes) use `value = GREATEST(excluded.value, value) ... WHERE
 * excluded.value > value` — monotonic, since a smaller re-send is a less
 * complete view of the same hour and must not overwrite the fuller one
 * downward. Everything else (instant samples, avg/min/max) uses `value =
 * excluded.value ... WHERE value IS DISTINCT FROM excluded.value` — last
 * writer wins, since a restated weight or heart rate is a correction and
 * GREATEST would ratchet a spurious high reading in permanently.
 *
 * Both carry a WHERE on the DO UPDATE, which isn't an optimisation — it is
 * what leaves a refused row unwritten and therefore countable as
 * `unchanged`; ChunkedUpsert::runReturning() explains how RETURNING reports
 * that.
 *
 * Rows are batched: ~250 datapoints per hourly payload and 27,000 across a
 * full replay is enough that a statement per row is felt, and 400 rows x
 * 10 bindings sits comfortably inside Postgres's 65,535 parameter ceiling.
 */
final class HealthMetricWriter extends ChunkedUpsert
{
    /** Order matches MetricRow::bindings(). */
    private const COLUMNS = [
        'metric',
        'aggregation',
        'period',
        'value',
        'unit',
        'started_at',
        'ended_at',
        'device_utc_offset_minutes',
        'source_id',
        'ingested_at',
    ];

    /** The six-column unique key, inferred rather than named. */
    private const CONFLICT_TARGET = 'metric, aggregation, period, started_at, ended_at, source_id';

    private const CHUNK = 400;

    /**
     * @param  list<MetricRow>  $rows
     */
    public function write(array $rows, CarbonImmutable $ingestedAt): UpsertStats
    {
        $stats = new UpsertStats;

        if ($rows === []) {
            return $stats;
        }

        // Split by upsert direction — one statement can't hold two ON
        // CONFLICT actions.
        $cumulative = [];
        $lastWriterWins = [];

        foreach ($rows as $row) {
            if ($row->cumulative) {
                $cumulative[] = $row;
            } else {
                $lastWriterWins[] = $row;
            }
        }

        foreach (array_chunk($cumulative, self::CHUNK) as $chunk) {
            $stats->add($this->upsert($chunk, greatest: true, ingestedAt: $ingestedAt));
        }

        foreach (array_chunk($lastWriterWins, self::CHUNK) as $chunk) {
            $stats->add($this->upsert($chunk, greatest: false, ingestedAt: $ingestedAt));
        }

        return $stats;
    }

    /**
     * @param  list<MetricRow>  $rows
     */
    private function upsert(array $rows, bool $greatest, CarbonImmutable $ingestedAt): UpsertStats
    {
        $set = $greatest
            ? 'value = greatest(excluded.value, health_metrics.value)'
            : 'value = excluded.value';

        // The guard that makes a no-op observable. `IS DISTINCT FROM` not
        // `<>`, since either side could be NULL and NULL <> NULL is NULL,
        // which would silently skip every row.
        $where = $greatest
            ? 'excluded.value > health_metrics.value'
            : 'health_metrics.value IS DISTINCT FROM excluded.value
                OR health_metrics.unit IS DISTINCT FROM excluded.unit';

        $sql = sprintf(
            'insert into health_metrics (%s) values %s
             on conflict (%s) do update set
                 %s,
                 unit = excluded.unit,
                 device_utc_offset_minutes = excluded.device_utc_offset_minutes,
                 ingested_at = excluded.ingested_at
             where %s
             returning (xmax = 0) as inserted',
            implode(', ', self::COLUMNS),
            self::placeholders(count(self::COLUMNS), count($rows)),
            self::CONFLICT_TARGET,
            $set,
            $where,
        );

        return $this->runReturning($sql, self::bindings($rows, $ingestedAt), count($rows));
    }
}
