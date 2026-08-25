<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Meal;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;

/**
 * How far back there is anything to look at.
 *
 * The server answers this, not the browser: the day view and the stress
 * page each grew a date picker, and a picker needs a floor. Without one the
 * wheel spins back through 1970 — eighteen months of real history inside
 * fifty-six years of blank days, worse than pressing the arrow twelve times.
 *
 * The floor is a fact about the DATABASE, so it's read from the database —
 * hardcoding "2023-01-01" in a Vue component would be wrong the day the
 * first backfill lands and wrong again once retention trims the far end,
 * silently.
 *
 * Each answer is one aggregate over an indexed column (`health_metrics` has
 * an index on `local_date` and a composite on `(metric, local_date)`;
 * `meals` has one on `(local_date, eaten_at)`), so Postgres walks to the
 * first leaf and stops — cheap enough to leave uncached, since a cached
 * floor lies for the TTL after every import.
 */
final class HistorySpan
{
    /**
     * The first local date the DAY VIEW has anything to show.
     *
     * Two origins, since a day gets onto that screen exactly two independent
     * ways: a measurement arrived from the Watch, or a meal was logged.
     * Everything else the page draws derives from one of those or arrives in
     * the same export, so neither can be the earliest thing on its own.
     *
     * Null means an empty database — the picker is left unbounded rather than
     * pinned to today, so a fresh deploy that hasn't ingested yet doesn't
     * present itself as an app with no past.
     */
    public function day(): ?string
    {
        return $this->earliest([
            HealthMetric::query()->min('local_date'),
            Meal::query()->min('local_date'),
        ]);
    }

    /**
     * The first local date with an HRV reading.
     *
     * Narrower than `day()` on purpose: the stress page can only draw a week
     * it has HRV for, and HRV starts well after the first step count —
     * offering a year of weeks that are seven blanks by construction would
     * feel broken.
     */
    public function stress(): ?string
    {
        return $this->earliest([
            HealthMetric::query()
                ->where('metric', (string) config('health.stress.metric', 'heart_rate_variability'))
                ->min('local_date'),
        ]);
    }

    /**
     * The earliest of whatever the aggregates came back with, as YYYY-MM-DD.
     *
     * `min()` is typed `mixed` and the driver's rendering of a `date` column is
     * its own business, so each candidate goes through Carbon rather than being
     * trusted to already be the string the front end needs.
     *
     * @param  list<mixed>  $candidates
     */
    private function earliest(array $candidates): ?string
    {
        $dates = [];

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            $dates[] = CarbonImmutable::parse($candidate)->toDateString();
        }

        return $dates === [] ? null : min($dates);
    }
}
