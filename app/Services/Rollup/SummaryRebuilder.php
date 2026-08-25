<?php

declare(strict_types=1);

namespace App\Services\Rollup;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use App\Models\DailySummary;
use App\Jobs\RebuildDailySummary;
use App\Services\Tdee\TdeeTrigger;

/**
 * The one place that decides HOW a dirty date gets rebuilt.
 *
 * Two callers, two answers, and the difference matters:
 *
 *  - `queue()` — ingest. A payload lands, one or two dates are dirty,
 *    nobody's watching. Delayed + unique, so a hundred batched payloads
 *    covering the same day produce one rebuild.
 *  - `now()` — the user just saved a meal and is about to be redirected to
 *    the page showing the total. A 30-second delay would render the
 *    intake they just typed as if it hadn't been saved, reading as a bug.
 *    The rebuild is a handful of queries; inline is cheaper than
 *    explaining the staleness.
 *
 * Both paths run the same builder, so they cannot drift.
 */
final class SummaryRebuilder
{
    public function __construct(
        private readonly DailySummaryBuilder $builder = new DailySummaryBuilder,
        private readonly TdeeTrigger $tdee = new TdeeTrigger,
    ) {}

    /**
     * @param  iterable<string|CarbonInterface>|string|CarbonInterface  $dates
     */
    public function queue(iterable|string|CarbonInterface $dates): void
    {
        foreach ($this->normalise($dates) as $date) {
            RebuildDailySummary::dispatch($date)
                ->delay(CarbonImmutable::now()->addSeconds(
                    (int) config('health.rollup.rebuild_delay_seconds', 30)
                ));
        }
    }

    public function now(string|CarbonInterface $date): DailySummary
    {
        $summary = $this->builder->build($date);

        /*
         * Same TDEE trigger the queued path fires (step 7): saving dinner
         * flips `is_complete_log`, and that day is usually inside the
         * estimated window.
         *
         * The RECOMPUTE stays queued and delayed even though the rebuild is
         * inline — the trends page derives its own estimate on render, so
         * nothing on screen waits for this; it only keeps `tdee_estimates`
         * current, and a least-squares fit doesn't belong in this redirect.
         *
         * Safe to dispatch here: every caller of now() is outside its write
         * transaction by the time it gets here (MealWriter, ProposalWriter
         * commit before returning), so the job can't read uncommitted rows.
         */
        $this->tdee->afterRebuild($summary->local_date->toDateString());

        return $summary;
    }

    /**
     * @param  iterable<string|CarbonInterface>|string|CarbonInterface  $dates
     * @return list<string>
     */
    private function normalise(iterable|string|CarbonInterface $dates): array
    {
        if (is_string($dates) || $dates instanceof CarbonInterface) {
            $dates = [$dates];
        }

        $normalised = [];

        foreach ($dates as $date) {
            $normalised[] = $date instanceof CarbonInterface ? $date->toDateString() : $date;
        }

        return array_values(array_unique($normalised));
    }
}
