<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Source;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Database\Factories\SourceFactory;

/**
 * Synthetic HRV for the stress estimator.
 *
 * WHY SYNTHETIC AND NOT A SLICE OF PRODUCTION. Same reasoning as TdeeFixture,
 * from the opposite direction: the 13 210 real HRV readings are the wrong thing
 * to assert on, because nobody knows what this user's stress score on 14 March
 * 2025 SHOULD have been — such a test only detects change. Fixing the circadian
 * shape and the day-to-day spread makes the z-score arithmetic instead: a day
 * one personal sigma above a baseline whose sigma the estimator was never told
 * must come back z = 1. The real data is what the constants were CHOSEN against
 * (see the PR); `stress:rebuild --all` on production is the acceptance test for
 * the distribution they produce, and neither of those is a unit test.
 */
final class StressFixture
{
    public const TZ = 'Europe/Amsterdam';

    public const METRIC = 'heart_rate_variability';

    /** Every hour of the day, the shape a well-worn Watch produces. */
    public const ALL_HOURS = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22, 23];

    /** The overnight-only shape of this user's first seven months: asleep, and almost nothing else. */
    public const NIGHT_HOURS = [0, 1, 2, 3, 4, 5];

    public const EVENING_HOURS = [18, 19, 20, 21, 22, 23];

    /**
     * Hourly readings for `$count` consecutive days ending on `$endDate`.
     *
     * @param  callable(int $dayIndex, int $hour, string $date): (float|null)  $value
     *                                                                                 milliseconds, or null for an hour the Watch was off.
     *                                                                                 Index 0 is the OLDEST day.
     * @param  list<int>  $hours
     * @return int rows written
     */
    public static function days(
        string $endDate,
        int $count,
        callable $value,
        array $hours = self::ALL_HOURS,
    ): int {
        $end = CarbonImmutable::parse($endDate, self::TZ)->startOfDay();

        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $day = $end->subDays($count - 1 - $i);
            $date = $day->toDateString();

            foreach ($hours as $hour) {
                $ms = $value($i, $hour, $date);

                if ($ms === null) {
                    continue;
                }

                $local = self::localHour($date, $hour);

                if ($local !== null) {
                    $rows[] = self::row($local, $ms);
                }
            }
        }

        return self::insert($rows);
    }

    /**
     * One day of readings at explicit hours: [hour => ms].
     *
     * @param  array<int, float>  $byHour
     */
    public static function day(string $date, array $byHour): int
    {
        $rows = [];

        foreach ($byHour as $hour => $ms) {
            $local = self::localHour($date, $hour);

            if ($local !== null) {
                $rows[] = self::row($local, $ms);
            }
        }

        return self::insert($rows);
    }

    /**
     * Resting heart rate: one value per local date, as Apple exports it and as
     * RestingHeartRate reads it. Written at local NOON, because midnight is the
     * one hour where a timezone mistake either way slides the row onto the wrong
     * `local_date` — and the failure would read as "the delta is against the
     * wrong date", the very bug the tests using this must be able to trust.
     *
     * @param  array<string, float>  $byDate  local date => bpm
     * @return int rows written
     */
    public static function resting(array $byDate): int
    {
        $rows = [];

        foreach ($byDate as $date => $bpm) {
            $local = self::localHour($date, 12);

            if ($local !== null) {
                $rows[] = self::row($local, $bpm, 'resting_heart_rate', 'count/min');
            }
        }

        return self::insert($rows);
    }

    /**
     * The instant a given local hour of a given local date starts, or null if
     * that hour does not exist.
     *
     * NOT `startOfDay()->addHours($hour)`: on 2026-03-29 Europe/Amsterdam skips
     * 01:59 to 03:00, so every reading after 02:00 would be written an hour late
     * and the last would spill onto the next day — a test spanning the last
     * Sunday in March would assert against data it did not describe, and fail
     * looking like an estimator bug. Building the local time and checking the
     * hour came back unchanged is exact: the nonexistent hour is skipped (that
     * Sunday genuinely has 23 readings) and in autumn the repeated 02:00
     * resolves to the first of the two, so that Sunday keeps 24.
     */
    private static function localHour(string $date, int $hour): ?CarbonImmutable
    {
        $local = CarbonImmutable::parse(
            sprintf('%s %02d:00:00', $date, $hour),
            self::TZ
        );

        return (int) $local->format('G') === $hour && $local->toDateString() === $date
            ? $local
            : null;
    }

    /** The Watch, created once and reused — every production HRV row is its. */
    public static function watch(): Source
    {
        static $cached = null;

        if ($cached instanceof Source && Source::query()->whereKey($cached->id)->exists()) {
            return $cached;
        }

        return $cached = Source::query()->firstOrCreate(
            ['raw_name' => SourceFactory::WATCH],
            [
                'name'        => SourceFactory::WATCH,
                'slug'        => Source::slugFor(SourceFactory::WATCH),
                'device_kind' => Source::kindFor(SourceFactory::WATCH),
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(
        CarbonImmutable $localStart,
        float $ms,
        string $metric = self::METRIC,
        string $unit = 'ms',
    ): array {
        return [
            'metric'      => $metric,
            'aggregation' => 'avg',
            'period'      => 'hour',
            'value'       => $ms,
            'unit'        => $unit,
            // timestamptz: handing Postgres the UTC instant is what lands the
            // generated `local_date` on the day meant, DST weeks included.
            'started_at'                => $localStart->utc()->toDateTimeString(),
            'ended_at'                  => $localStart->addHour()->utc()->toDateTimeString(),
            'device_utc_offset_minutes' => (int) ($localStart->utcOffset()),
            'source_id'                 => self::watch()->id,
            'ingested_at'               => CarbonImmutable::now()->toDateTimeString(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private static function insert(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        /*
         * DEDUPED ON started_at, AND THE REASON IS DAYLIGHT SAVING. On the
         * spring-forward Sunday Europe/Amsterdam has no 02:00, so two different
         * hours are one instant and `health_metrics_identity_unique` rightly
         * refuses the second; a 23-hour day is what that Sunday IS, and any
         * window wider than three months contains one. Keeping the first
         * occurrence gives what the Watch would have produced: 23 buckets in
         * March, 25 in October with two reading 02:00 local.
         */
        $unique = [];

        foreach ($rows as $row) {
            // Keyed on metric too, as `health_metrics_identity_unique` is: two
            // metrics at the same instant are two facts and both belong.
            $unique[$row['metric'].'|'.(string) $row['started_at']] ??= $row;
        }

        $rows = array_values($unique);

        // Chunked: 8 760 rows for a 365-day fixture, against Postgres' 65 535
        // parameter limit at one bind per column.
        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('health_metrics')->insert($chunk);
        }

        return count($rows);
    }
}
