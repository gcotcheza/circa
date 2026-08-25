<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\DailySummary;

/**
 * The exponentially-smoothed weight series — ONE implementation, two readers.
 *
 * The trends chart draws this series and step 7's TDEE estimator takes its
 * OLS slope; two implementations would eventually disagree about the
 * smoothing, and the app would draw one line while publishing a number
 * derived from a different one — a divergence nobody would ever notice,
 * since both would look plausible. Daily weight noise is ±1 kg, larger
 * than a week of real change, so raw points are a cloud with a trend
 * hidden in it, not a trend themselves.
 *
 * Two properties the estimator's tests assert: GAPS ARE SKIPPED, NEVER
 * INTERPOLATED (four days without a weigh-in are four days of no
 * information — an invented point becomes a fabricated slope, which
 * becomes a fabricated 300 kcal), and THE SERIES IS SEEDED FROM THE FULL
 * HISTORY, not a window, since an EMA started inside one carries a
 * start-up transient (~1/alpha points to catch up, biasing the fitted
 * slope toward zero) that seeding from everything ever weighed confines to
 * the user's very first weeks.
 */
final class WeightEma
{
    /**
     * date (Y-m-d) => smoothed weight, for every day that HAS a weigh-in.
     *
     * @return array<string, float>
     */
    public function series(?float $alpha = null): array
    {
        return $this->smooth($this->observations(), $alpha);
    }

    /**
     * date (Y-m-d) => the RAW weigh-in, oldest first, for every day that has
     * one. Split out from series() rather than duplicated in the chart's
     * service, per the reason at the top of this file: a second copy of
     * this query is how the picture and the estimate disagree about history.
     *
     * @return array<string, float>
     */
    public function observations(): array
    {
        $rows = DailySummary::query()
            ->whereNotNull('weight_kg')
            ->orderBy('local_date')
            ->get(['local_date', 'weight_kg']);

        $out = [];

        foreach ($rows as $row) {
            $out[$row->local_date->toDateString()] = (float) $row->weight_kg;
        }

        return $out;
    }

    /**
     * The smoothing itself — THE implementation, for weight and anything
     * else measured on the same scale trips (body fat and muscle mass carry
     * the same kind of noise, e.g. a 28.3% bioimpedance reading between two
     * 24.0% ones), so nothing duplicates it next to the chart. Keys are
     * dates only so results slot back alongside raw values; the recurrence
     * runs over the array's ORDER, and gaps between dates contribute
     * nothing — the property the estimator's tests assert.
     *
     * @param  array<string, float>  $byDate  oldest first
     * @return array<string, float>
     */
    public function smooth(array $byDate, ?float $alpha = null): array
    {
        $alpha ??= (float) config('health.trends.ema_alpha', 0.25);

        $ema = [];
        $previous = null;

        foreach ($byDate as $date => $value) {
            // Seed with the first observation, not zero — an EMA started at
            // 0 spends a dozen weigh-ins climbing out of a hole that was
            // never measured.
            $previous = $previous === null
                ? $value
                : $alpha * $value + (1 - $alpha) * $previous;

            $ema[$date] = round($previous, 3);
        }

        return $ema;
    }
}
