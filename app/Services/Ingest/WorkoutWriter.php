<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;

/**
 * Upsert for `workouts`, keyed on `apple_uuid`.
 *
 * Simpler than the other two writers because a workout needs no defence of a
 * synthesised identity against partial restatement: HealthMetricWriter needs
 * GREATEST since an hourly bucket resends PARTIAL then fuller, and
 * SleepSessionWriter needs a completeness predicate since a "Since Last
 * Sync" export clips a night's front and restates it shorter.
 *
 * HealthKit hands over its own UUID, and a workout exports as a finished
 * whole — not accumulating, not clipped to the window, a second copy is the
 * same session. So: upsert on the uuid, last-writer-wins on every other
 * column — a restatement (Watch finishing processing, a corrected distance)
 * is a correction and should land. That's what makes manual backfill safe:
 * two years across 86 overlapping payloads, and the same session arriving
 * three times writes one row then changes nothing.
 *
 * The DO UPDATE carries a whole-row `IS DISTINCT FROM` guard, so re-parsing
 * an unchanged workout writes nothing — Postgres skips the update, RETURNING
 * emits nothing, counted `unchanged` — the same idempotence proof the other
 * writers give, making `ingest:replay` a provable no-op. Row-wise IS
 * DISTINCT FROM handles NULLs correctly, which matters: 41 of 100 captured
 * workouts have no distance, 48 no elevation, 41 no `isIndoor`.
 */
final class WorkoutWriter extends ChunkedUpsert
{
    /** Order matches WorkoutRow::bindings(). */
    private const COLUMNS = [
        'apple_uuid',
        'type',
        'started_at',
        'ended_at',
        'duration_s',
        'distance_km',
        'active_kcal',
        'avg_hr',
        'max_hr',
        'step_count',
        'elevation_up_m',
        'is_indoor',
        'intensity',
        'is_implausible',
        'device_utc_offset_minutes',
        'extras',
        'ingested_at',
    ];

    /** Everything except the key and ingested_at: the comparison set. */
    private const COMPARED = [
        'type',
        'started_at',
        'ended_at',
        'duration_s',
        'distance_km',
        'active_kcal',
        'avg_hr',
        'max_hr',
        'step_count',
        'elevation_up_m',
        'is_indoor',
        'intensity',
        'is_implausible',
        'device_utc_offset_minutes',
        'extras',
    ];

    /**
     * 17 bindings a row, so 400 rows is 6 800 parameters — comfortably inside
     * Postgres's 65 535 ceiling, and larger than any payload observed (the
     * biggest carries two workouts).
     */
    private const CHUNK = 400;

    /**
     * @param  list<WorkoutRow>  $rows
     */
    public function write(array $rows, CarbonImmutable $ingestedAt): UpsertStats
    {
        $stats = new UpsertStats;

        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            $stats->add($this->upsert($chunk, $ingestedAt));
        }

        return $stats;
    }

    /**
     * @param  list<WorkoutRow>  $rows
     */
    private function upsert(array $rows, CarbonImmutable $ingestedAt): UpsertStats
    {
        $sql = sprintf(
            'insert into workouts (%s) values %s
             on conflict (apple_uuid) do update set %s
             where (%s) IS DISTINCT FROM (%s)
             returning (xmax = 0) as inserted',
            implode(', ', self::COLUMNS),
            self::placeholders(count(self::COLUMNS), count($rows)),
            self::assignFromExcluded([...self::COMPARED, 'ingested_at']),
            self::qualify('workouts', self::COMPARED),
            self::qualify('excluded', self::COMPARED),
        );

        return $this->runReturning($sql, self::bindings($rows, $ingestedAt), count($rows));
    }
}
