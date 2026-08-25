<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\DailySummary;

/**
 * Where a day still in progress is HEADING — the headline only, never a
 * stored number. The measured card sets a finished food log against a
 * burn that's two thirds accrued, so today reads "Surplus ≈ 1,270" at
 * seven in the evening and mellows to the truth by midnight — every
 * figure correct, the day it describes not yet real.
 *
 * Resting burn makes the fix honest: on complete days it's near enough a
 * constant, so the rest of today's is stated from the MEDIAN of recent
 * complete days (see config/health.php `pace` for the window and why a
 * median). ACTIVE IS NEVER PROJECTED — an afternoon walk isn't a
 * constant, and inventing one would invent the finding, not the clock.
 *
 * Nothing here is written to `daily_summaries`, so BucketSelector's rule
 * against inventing measurements is untouched — this is a
 * presentation-layer statement, and `projected` is the flag that makes
 * the card say "On pace for", with an ≈, above the unchanged measured
 * so-far breakdown.
 */
final class DayPace
{
    /**
     * The projected balance for a day in progress, or null when it cannot be
     * said honestly.
     *
     * Null without both halves of the burn (the same gate the measured balance
     * uses — half a subtraction is not a balance) and null below `min_days` of
     * history, where the card is left exactly as it is.
     *
     * @return array{direction: string, lo: float, hi: float, min: float, max: float, provisional: bool, projected: bool, expectedResting: float, restingRemaining: float, basisDays: int}|null
     */
    public function project(DailySummary $summary): ?array
    {
        $measuredOut = $summary->totalKcalOut();
        $resting = $summary->resting_kcal === null ? null : (float) $summary->resting_kcal;

        if ($measuredOut === null || $resting === null) {
            return null;
        }

        $basis = $this->recentCompleteResting($summary);

        if (count($basis) < (int) config('health.pace.min_days')) {
            return null;
        }

        $expected = $this->median($basis);

        // Clamped: a day that has already rested past a typical one has nothing
        // left to come, and a negative remainder would project the burn DOWN.
        $remaining = max(0.0, $expected - $resting);

        $balance = EnergyBalance::from(
            kcalOut: $measuredOut + $remaining,
            kcalInMin: $this->number($summary->kcal_in_min),
            kcalInMid: $this->number($summary->kcal_in_mid),
            kcalInMax: $this->number($summary->kcal_in_max),
            // Still true — the DAY is in progress. `projected` upgrades the
            // wording from "so far" to "on pace"; a renderer that ignores
            // the new flag would understate the claim, not overstate it.
            provisional: true,
        );

        if ($balance === null) {
            return null;
        }

        return $balance->toArray() + [
            'projected'        => true,
            'expectedResting'  => round($expected),
            'restingRemaining' => round($remaining),
            'basisDays'        => count($basis),
        ];
    }

    /**
     * Resting burn on the most recent complete days BEFORE this one.
     * `has_full_metric_coverage` is the whole filter: a half-covered day's
     * resting figure is a floor, and a median of floors is a floor wearing
     * a median's authority. Strictly earlier days, so today's own partial
     * figure can never vote on what today should reach.
     *
     * @return list<float>
     */
    private function recentCompleteResting(DailySummary $summary): array
    {
        $rows = DailySummary::query()
            ->where('has_full_metric_coverage', true)
            ->whereNotNull('resting_kcal')
            ->where('local_date', '<', $summary->local_date->toDateString())
            ->orderByDesc('local_date')
            ->limit((int) config('health.pace.basis_days'))
            ->pluck('resting_kcal');

        $out = [];

        foreach ($rows as $kcal) {
            $out[] = (float) $kcal;
        }

        return $out;
    }

    /**
     * @param  list<float>  $values
     */
    private function median(array $values): float
    {
        sort($values);

        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $values[$middle]
            : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }
}
