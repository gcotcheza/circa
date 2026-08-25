<?php

declare(strict_types=1);

namespace App\Services\Support;

use Carbon\CarbonImmutable;

/**
 * Local calendar dates as plain `YYYY-MM-DD` strings — the space
 * `daily_summaries.local_date`, `stress_daily.local_date` and
 * `meals.local_date` already live in, and the keys every per-day map in
 * this app is built on.
 */
final class LocalDates
{
    /**
     * Every date from $from to $to, chronological, both ends included.
     *
     * Walked a day at a time in a real timezone rather than counted off a
     * span: `addDay()` keeps the cursor's wall-clock midnight across a DST
     * boundary, so the 23- and 25-hour days each yield exactly one date.
     * Inclusive at both ends because a report covering "the last 7 days"
     * covers today and the six before it — off by one here is off by one in
     * every figure downstream.
     *
     * $tz defaults to the app's reporting timezone, which is what every
     * caller means except the stress calculator, told its zone by whoever
     * called it.
     *
     * @return list<string>
     */
    public static function inclusive(string $from, string $to, ?string $tz = null): array
    {
        $tz ??= (string) config('health.timezone');

        $cursor = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->startOfDay();

        $dates = [];

        while ($cursor <= $end) {
            $dates[] = $cursor->toDateString();
            $cursor = $cursor->addDay();
        }

        return $dates;
    }
}
