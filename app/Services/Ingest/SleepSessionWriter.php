<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;

/**
 * Upsert for `sleep_sessions`, keyed (night_date, source_id).
 *
 * A night is restated as a whole — HAE recomputes the stage breakdown as
 * more of it syncs — so there's no cumulative direction to preserve column
 * by column. Two sources may report the same night (Watch and AutoSleep
 * both appear in captured payloads); they get a row each and the read side
 * picks by priority.
 *
 * NOT last-writer-wins: it was, and it lost most of a night. "Since Last
 * Sync" exports clip `sleep_analysis` to the export window, so the SAME
 * night comes back repeatedly, each time starting later:
 *
 *   payload 114, 08:36  00:33 -> 08:02  totalSleep 7.02 h   the whole night
 *   payload 115, 10:54  04:16 -> 08:02  totalSleep 3.64 h   clipped at the front
 *   payload 116, 14:38  06:41 -> 08:02  totalSleep 1.24 h   clipped further
 *   payloads 117, 118   06:41 -> 08:02  totalSleep 1.24 h   byte-identical resends
 *
 * Under last-writer-wins the stored night walked 7.02 -> 3.64 -> 1.24 and
 * the day card read "1h 14m", though every payload was honest. Same
 * partial-window problem `HealthMetricWriter` defends against with
 * GREATEST on cumulative values, generalised here to a whole record.
 *
 * THE COMPLETENESS PREDICATE: an incoming night replaces the stored one
 * only if it's no less complete on EITHER axis:
 *
 *   1. volume   — `total_sleep_minutes` >= stored. Clipping can only
 *                 remove sleep, so a smaller total is by construction a
 *                 narrower view. `>=` not `>`: Apple re-scores a night's
 *                 stages after the fact, and a same-total re-scoring must
 *                 still land.
 *
 *   2. coverage — the incoming window must not be a strict sub-interval of
 *                 the stored one (start no earlier AND end no later,
 *                 windows differing) — exactly the shape of a clipped
 *                 export, vetoed outright so a truncation whose total ties
 *                 can't slip past rule 1.
 *
 * Anything else — wider, later, bigger, or identical with different
 * stages — is a real restatement and is taken: the night can grow or be
 * re-scored, but never shrink.
 *
 * NULLs: totals compare via `coalesce(..., -1)` (absent ranks below any
 * real duration; two absent totals fall through to rule 2), the window via
 * `coalesce(..., false)` (a missing boundary can't prove truncation, so
 * rule 1 governs alone). All 113 captured records carry all four
 * boundaries and a total — this is for the export that doesn't.
 *
 * Mirrored in PHP as `SleepRow::supersedes()`, which collapses duplicate
 * nights inside one payload; `SleepTruncationTest` drives both over the
 * sequence above.
 *
 * On top of the predicate, the DO UPDATE keeps its whole-row `IS DISTINCT
 * FROM` guard (NULL-safe, since most columns are legitimately null) so
 * re-parsing an unchanged night writes nothing — same idempotence proof as
 * HealthMetricWriter. A refused truncation lands the same way: no tuple
 * written, RETURNING empty, counted `unchanged`.
 */
final class SleepSessionWriter extends ChunkedUpsert
{
    /** Order matches SleepRow::bindings(). */
    private const COLUMNS = [
        'night_date',
        'source_id',
        'in_bed_start',
        'in_bed_end',
        'sleep_start',
        'sleep_end',
        'rem_minutes',
        'core_minutes',
        'deep_minutes',
        'awake_minutes',
        'asleep_minutes',
        'in_bed_minutes',
        'total_sleep_minutes',
        'device_utc_offset_minutes',
        'ingested_at',
    ];

    /** Everything except the key and ingested_at: the comparison set. */
    private const COMPARED = [
        'in_bed_start',
        'in_bed_end',
        'sleep_start',
        'sleep_end',
        'rem_minutes',
        'core_minutes',
        'deep_minutes',
        'awake_minutes',
        'asleep_minutes',
        'in_bed_minutes',
        'total_sleep_minutes',
        'device_utc_offset_minutes',
    ];

    private const CHUNK = 200;

    /**
     * @param  list<SleepRow>  $rows
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
     * @param  list<SleepRow>  $rows
     */
    private function upsert(array $rows, CarbonImmutable $ingestedAt): UpsertStats
    {
        // Rule 1: volume never shrinks. NULL ranks below every real duration.
        $volume = 'coalesce(excluded.total_sleep_minutes, -1)
                   >= coalesce(sleep_sessions.total_sleep_minutes, -1)';

        // Rule 2: coverage never shrinks. True only when the incoming window
        // is demonstrably a strict sub-interval of the stored one; NULL (an
        // absent boundary) doesn't prove truncation, so it coalesces to false.
        $truncation = 'coalesce(
                (excluded.sleep_start, excluded.sleep_end)
                    IS DISTINCT FROM (sleep_sessions.sleep_start, sleep_sessions.sleep_end)
                and excluded.sleep_start >= sleep_sessions.sleep_start
                and excluded.sleep_end <= sleep_sessions.sleep_end,
            false)';

        $sql = sprintf(
            'insert into sleep_sessions (%s) values %s
             on conflict (night_date, source_id) do update set %s
             where (%s) IS DISTINCT FROM (%s)
               and %s
               and not %s
             returning (xmax = 0) as inserted',
            implode(', ', self::COLUMNS),
            self::placeholders(count(self::COLUMNS), count($rows)),
            self::assignFromExcluded([...self::COMPARED, 'ingested_at']),
            self::qualify('sleep_sessions', self::COMPARED),
            self::qualify('excluded', self::COMPARED),
            $volume,
            $truncation,
        );

        return $this->runReturning($sql, self::bindings($rows, $ingestedAt), count($rows));
    }
}
