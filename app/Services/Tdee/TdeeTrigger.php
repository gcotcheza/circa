<?php

declare(strict_types=1);

namespace App\Services\Tdee;

use App\Jobs\RecomputeTdee;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Decides whether a freshly-rebuilt day is one the estimate cares about.
 *
 * Every `daily_summaries` rebuild can flip `is_complete_log` or write a
 * weigh-in, the estimator's two inputs — but most rebuilds (an April
 * backfill, a six-week-old meal correction, a payload replay) touch dates
 * outside the window, so recomputing for them would just reproduce the same
 * result.
 *
 * The test is membership of the CURRENT window, not "did the numbers
 * change": comparing before/after would need the rebuild path to hand over
 * both — a saving the job's unique lock already gives — and would silently
 * break the day a new column joins the summary. Window membership needs
 * only the date, which is all every caller has.
 */
final class TdeeTrigger
{
    /** Queue a recompute if this date is inside the window being estimated. */
    public function afterRebuild(string|CarbonInterface $date): bool
    {
        $date = $date instanceof CarbonInterface ? $date->toDateString() : $date;

        if (! TdeeWindow::current()->contains($date)) {
            return false;
        }

        RecomputeTdee::dispatch()->delay(
            CarbonImmutable::now()->addSeconds(
                (int) config('health.tdee.recompute_delay_seconds', 60)
            )
        );

        return true;
    }
}
