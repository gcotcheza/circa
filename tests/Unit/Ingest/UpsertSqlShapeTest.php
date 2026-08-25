<?php

declare(strict_types=1);

namespace Tests\Unit\Ingest;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Enums\MetricAggregation;
use App\Services\Ingest\SleepRow;
use App\Services\Ingest\MetricRow;
use App\Services\Ingest\UpsertRow;
use App\Services\Ingest\WorkoutRow;
use App\Services\Ingest\WorkoutWriter;
use Tests\Support\RecordingConnection;
use App\Services\Ingest\HealthMetricWriter;
use App\Services\Ingest\SleepSessionWriter;

/**
 * The four statements the ingest writers emit, pinned verbatim.
 *
 * These are hand-written SQL whose meaning is in its detail — `IS DISTINCT
 * FROM` rather than `<>`, `coalesce(..., -1)` ranking an absent total below
 * every real one, `greatest()` on the cumulative direction only, and the
 * `(xmax = 0)` trick that makes a refused row countable. None of that is
 * visible in a test that only checks which rows survived, so a refactor of
 * the scaffolding around them can quietly change semantics while every other
 * ingest test stays green. This one fails on the character.
 *
 * Bindings are asserted as a contract rather than as literals: whatever each
 * row yields, the statement must carry it flattened in row order, since the
 * tuples are anonymous and a reordering would fill the wrong columns
 * silently.
 */
final class UpsertSqlShapeTest extends TestCase
{
    private const SLEEP = <<<'SQL'
        insert into sleep_sessions (night_date, source_id, in_bed_start, in_bed_end, sleep_start, sleep_end, rem_minutes, core_minutes, deep_minutes, awake_minutes, asleep_minutes, in_bed_minutes, total_sleep_minutes, device_utc_offset_minutes, ingested_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     on conflict (night_date, source_id) do update set in_bed_start = excluded.in_bed_start, in_bed_end = excluded.in_bed_end, sleep_start = excluded.sleep_start, sleep_end = excluded.sleep_end, rem_minutes = excluded.rem_minutes, core_minutes = excluded.core_minutes, deep_minutes = excluded.deep_minutes, awake_minutes = excluded.awake_minutes, asleep_minutes = excluded.asleep_minutes, in_bed_minutes = excluded.in_bed_minutes, total_sleep_minutes = excluded.total_sleep_minutes, device_utc_offset_minutes = excluded.device_utc_offset_minutes, ingested_at = excluded.ingested_at
                     where (sleep_sessions.in_bed_start, sleep_sessions.in_bed_end, sleep_sessions.sleep_start, sleep_sessions.sleep_end, sleep_sessions.rem_minutes, sleep_sessions.core_minutes, sleep_sessions.deep_minutes, sleep_sessions.awake_minutes, sleep_sessions.asleep_minutes, sleep_sessions.in_bed_minutes, sleep_sessions.total_sleep_minutes, sleep_sessions.device_utc_offset_minutes) IS DISTINCT FROM (excluded.in_bed_start, excluded.in_bed_end, excluded.sleep_start, excluded.sleep_end, excluded.rem_minutes, excluded.core_minutes, excluded.deep_minutes, excluded.awake_minutes, excluded.asleep_minutes, excluded.in_bed_minutes, excluded.total_sleep_minutes, excluded.device_utc_offset_minutes)
                       and coalesce(excluded.total_sleep_minutes, -1)
                           >= coalesce(sleep_sessions.total_sleep_minutes, -1)
                       and not coalesce(
                        (excluded.sleep_start, excluded.sleep_end)
                            IS DISTINCT FROM (sleep_sessions.sleep_start, sleep_sessions.sleep_end)
                        and excluded.sleep_start >= sleep_sessions.sleep_start
                        and excluded.sleep_end <= sleep_sessions.sleep_end,
                    false)
                     returning (xmax = 0) as inserted
        SQL;

    private const WORKOUT = <<<'SQL'
        insert into workouts (apple_uuid, type, started_at, ended_at, duration_s, distance_km, active_kcal, avg_hr, max_hr, step_count, elevation_up_m, is_indoor, intensity, is_implausible, device_utc_offset_minutes, extras, ingested_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?), (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     on conflict (apple_uuid) do update set type = excluded.type, started_at = excluded.started_at, ended_at = excluded.ended_at, duration_s = excluded.duration_s, distance_km = excluded.distance_km, active_kcal = excluded.active_kcal, avg_hr = excluded.avg_hr, max_hr = excluded.max_hr, step_count = excluded.step_count, elevation_up_m = excluded.elevation_up_m, is_indoor = excluded.is_indoor, intensity = excluded.intensity, is_implausible = excluded.is_implausible, device_utc_offset_minutes = excluded.device_utc_offset_minutes, extras = excluded.extras, ingested_at = excluded.ingested_at
                     where (workouts.type, workouts.started_at, workouts.ended_at, workouts.duration_s, workouts.distance_km, workouts.active_kcal, workouts.avg_hr, workouts.max_hr, workouts.step_count, workouts.elevation_up_m, workouts.is_indoor, workouts.intensity, workouts.is_implausible, workouts.device_utc_offset_minutes, workouts.extras) IS DISTINCT FROM (excluded.type, excluded.started_at, excluded.ended_at, excluded.duration_s, excluded.distance_km, excluded.active_kcal, excluded.avg_hr, excluded.max_hr, excluded.step_count, excluded.elevation_up_m, excluded.is_indoor, excluded.intensity, excluded.is_implausible, excluded.device_utc_offset_minutes, excluded.extras)
                     returning (xmax = 0) as inserted
        SQL;

    private const METRIC_GREATEST = <<<'SQL'
        insert into health_metrics (metric, aggregation, period, value, unit, started_at, ended_at, device_utc_offset_minutes, source_id, ingested_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     on conflict (metric, aggregation, period, started_at, ended_at, source_id) do update set
                         value = greatest(excluded.value, health_metrics.value),
                         unit = excluded.unit,
                         device_utc_offset_minutes = excluded.device_utc_offset_minutes,
                         ingested_at = excluded.ingested_at
                     where excluded.value > health_metrics.value
                     returning (xmax = 0) as inserted
        SQL;

    private const METRIC_LAST_WRITER = <<<'SQL'
        insert into health_metrics (metric, aggregation, period, value, unit, started_at, ended_at, device_utc_offset_minutes, source_id, ingested_at) values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                     on conflict (metric, aggregation, period, started_at, ended_at, source_id) do update set
                         value = excluded.value,
                         unit = excluded.unit,
                         device_utc_offset_minutes = excluded.device_utc_offset_minutes,
                         ingested_at = excluded.ingested_at
                     where health_metrics.value IS DISTINCT FROM excluded.value
                        OR health_metrics.unit IS DISTINCT FROM excluded.unit
                     returning (xmax = 0) as inserted
        SQL;

    public function test_sleep_statement_is_unchanged(): void
    {
        $ingestedAt = self::ingestedAt();
        $rows = self::sleepRows();

        $calls = self::capture(fn (RecordingConnection $c) => (new SleepSessionWriter($c))->write($rows, $ingestedAt));

        self::assertCount(1, $calls);
        self::assertSame(self::SLEEP, $calls[0]['sql']);
        self::assertSame(self::flattened($rows, $ingestedAt), $calls[0]['bindings']);
        self::assertFalse($calls[0]['useReadPdo']);
    }

    public function test_workout_statement_is_unchanged(): void
    {
        $ingestedAt = self::ingestedAt();
        $rows = self::workoutRows();

        $calls = self::capture(fn (RecordingConnection $c) => (new WorkoutWriter($c))->write($rows, $ingestedAt));

        self::assertCount(1, $calls);
        self::assertSame(self::WORKOUT, $calls[0]['sql']);
        self::assertSame(self::flattened($rows, $ingestedAt), $calls[0]['bindings']);
        self::assertFalse($calls[0]['useReadPdo']);
    }

    /** One statement per direction, cumulative first — they cannot share an ON CONFLICT action. */
    public function test_metric_statements_are_unchanged_in_both_directions(): void
    {
        $ingestedAt = self::ingestedAt();
        $cumulative = self::cumulativeMetricRows();
        $lastWriterWins = self::lastWriterWinsMetricRows();

        $calls = self::capture(fn (RecordingConnection $c) => (new HealthMetricWriter($c))->write(
            [...$cumulative, ...$lastWriterWins],
            $ingestedAt,
        ));

        self::assertCount(2, $calls);

        self::assertSame(self::METRIC_GREATEST, $calls[0]['sql']);
        self::assertSame(self::flattened($cumulative, $ingestedAt), $calls[0]['bindings']);
        self::assertFalse($calls[0]['useReadPdo']);

        self::assertSame(self::METRIC_LAST_WRITER, $calls[1]['sql']);
        self::assertSame(self::flattened($lastWriterWins, $ingestedAt), $calls[1]['bindings']);
        self::assertFalse($calls[1]['useReadPdo']);
    }

    /** A chunk of one row must still produce exactly one tuple of placeholders. */
    public function test_one_row_produces_one_tuple(): void
    {
        $ingestedAt = self::ingestedAt();
        $rows = [self::workoutRows()[0]];

        $calls = self::capture(fn (RecordingConnection $c) => (new WorkoutWriter($c))->write($rows, $ingestedAt));

        self::assertCount(1, $calls);
        self::assertStringContainsString(
            'values (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'."\n",
            $calls[0]['sql'],
        );
        self::assertSame(self::flattened($rows, $ingestedAt), $calls[0]['bindings']);
    }

    /**
     * @param  callable(RecordingConnection): mixed  $write
     * @return list<array{sql: string, bindings: array<int, mixed>, useReadPdo: bool}>
     */
    private static function capture(callable $write): array
    {
        $connection = new RecordingConnection;

        $write($connection);

        return $connection->calls;
    }

    /**
     * @param  list<UpsertRow>  $rows
     * @return list<string|int|bool|null>
     */
    private static function flattened(array $rows, CarbonImmutable $ingestedAt): array
    {
        return array_merge(...array_map(
            static fn (UpsertRow $row): array => $row->bindings($ingestedAt),
            $rows,
        ));
    }

    private static function ingestedAt(): CarbonImmutable
    {
        return CarbonImmutable::parse('2026-08-20 07:00:00.000000+00:00');
    }

    /** @return list<SleepRow> */
    private static function sleepRows(): array
    {
        return [
            SleepRow::make(
                CarbonImmutable::parse('2026-08-18'),
                3,
                CarbonImmutable::parse('2026-08-18 22:10:00+02:00'),
                CarbonImmutable::parse('2026-08-19 07:02:00+02:00'),
                CarbonImmutable::parse('2026-08-18 22:33:00+02:00'),
                CarbonImmutable::parse('2026-08-19 07:02:00+02:00'),
                [
                    'rem_minutes'         => 1.5,
                    'core_minutes'        => 3.25,
                    'deep_minutes'        => null,
                    'awake_minutes'       => 0.4,
                    'asleep_minutes'      => 7.02,
                    'in_bed_minutes'      => 8.5,
                    'total_sleep_minutes' => 7.02,
                ],
                120,
            ),
            // Every optional column absent: the export that carries no
            // boundaries is exactly what the coalesce() rules are for.
            SleepRow::make(
                CarbonImmutable::parse('2026-08-19'),
                3,
                null,
                null,
                CarbonImmutable::parse('2026-08-19 23:00:00+02:00'),
                CarbonImmutable::parse('2026-08-20 06:30:00+02:00'),
                [
                    'rem_minutes'         => null,
                    'core_minutes'        => null,
                    'deep_minutes'        => null,
                    'awake_minutes'       => null,
                    'asleep_minutes'      => null,
                    'in_bed_minutes'      => null,
                    'total_sleep_minutes' => null,
                ],
                null,
            ),
        ];
    }

    /** @return list<WorkoutRow> */
    private static function workoutRows(): array
    {
        return [
            new WorkoutRow(
                'UUID-1',
                'Outdoor Run',
                CarbonImmutable::parse('2026-08-19 06:00:00+02:00'),
                CarbonImmutable::parse('2026-08-19 06:45:00+02:00'),
                '2700.000',
                '8.400',
                '540.000',
                142,
                171,
                6100,
                '55.000',
                false,
                '9.100',
                false,
                120,
                ['foo' => 'bar'],
            ),
            new WorkoutRow(
                'UUID-2',
                'Martial Arts',
                CarbonImmutable::parse('2026-08-19 18:00:00+02:00'),
                CarbonImmutable::parse('2026-08-19 19:30:00+02:00'),
                '5400.000',
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                null,
                false,
                120,
                [],
            ),
        ];
    }

    /** @return list<MetricRow> */
    private static function cumulativeMetricRows(): array
    {
        return [
            MetricRow::make('step_count', MetricAggregation::Sum, 'hour', 1200.0, 'count', CarbonImmutable::parse('2026-08-19 10:00:00+02:00'), 3),
        ];
    }

    /** @return list<MetricRow> */
    private static function lastWriterWinsMetricRows(): array
    {
        return [
            MetricRow::make('body_mass', MetricAggregation::Instant, 'instant', 81.4, 'kg', CarbonImmutable::parse('2026-08-19 08:12:00+02:00'), 3),
        ];
    }
}
