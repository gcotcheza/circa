<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use App\Models\DailySummary;

/**
 * Synthetic days for the TDEE estimator.
 *
 * The estimator cannot be proven against production data — two weigh-ins and no
 * complete-log days in the whole history — so correctness is established here,
 * against scenarios whose answer is arithmetic before the code runs: constant
 * intake, weight falling at an exact rate, one possible expenditure.
 */
final class TdeeFixture
{
    /**
     * A run of consecutive days ending on `$endDate`.
     *
     * @param  callable(int $index, string $date): array<string, mixed>  $attributes
     *                                                                                Index 0 is the OLDEST day; whatever it returns is merged
     *                                                                                over a blank day (no intake, no weigh-in, not complete).
     */
    public static function days(string $endDate, int $count, callable $attributes): void
    {
        $end = CarbonImmutable::parse($endDate);

        for ($i = 0; $i < $count; $i++) {
            $date = $end->subDays($count - 1 - $i)->toDateString();

            self::day($date, $attributes($i, $date));
        }
    }

    /**
     * Weigh-ins before the window, the ordinary case: the EMA is seeded from the
     * FULL history, so by the time a window is fitted the smoothing has caught
     * up with the trend.
     *
     * @param  callable(int $index): float  $weight  index 0 is the oldest.
     */
    public static function priorWeighIns(string $dayBefore, int $count, callable $weight): void
    {
        $last = CarbonImmutable::parse($dayBefore);

        for ($i = 0; $i < $count; $i++) {
            self::day(
                $last->subDays($count - 1 - $i)->toDateString(),
                ['weight_kg' => $weight($i)],
            );
        }
    }

    /**
     * `intake`/`band` are this helper's own shorthand, unset below before the
     * rest reaches the model; every other key is a real DailySummary column,
     * spelled out here so it type-checks as one against Larastan's
     * `model-property<DailySummary>` rather than a plain string key.
     *
     * @param  array{
     *     intake?: mixed, band?: mixed, weight_kg?: mixed, is_complete_log?: mixed,
     *     has_full_metric_coverage?: mixed, active_kcal_is_partial?: mixed,
     *     active_kcal?: mixed, resting_kcal?: mixed, kcal_in_min?: mixed,
     *     kcal_in_mid?: mixed, kcal_in_max?: mixed, rebuilt_at?: mixed,
     * }  $attributes
     */
    public static function day(string $date, array $attributes = []): DailySummary
    {
        $intake = $attributes['intake'] ?? null;
        $band = (float) ($attributes['band'] ?? 0);

        unset($attributes['intake'], $attributes['band']);

        $intakeColumns = $intake === null ? [
            'kcal_in_min' => null,
            'kcal_in_mid' => null,
            'kcal_in_max' => null,
        ] : [
            'kcal_in_min' => $intake - $band,
            'kcal_in_mid' => $intake,
            'kcal_in_max' => $intake + $band,
        ];

        $values = [
            'weight_kg'       => null,
            'is_complete_log' => false,
            ...$intakeColumns,
            ...$attributes,
        ];

        // A day may already exist: the daily view builds today's summary on
        // render, and a test correcting a day rewrites one on purpose.
        $existing = DailySummary::query()->find($date);

        if ($existing !== null) {
            $existing->update($values);

            return $existing->refresh();
        }

        return DailySummary::factory()->create(['local_date' => $date, ...$values]);
    }

    /**
     * What a naive implementation would report: (last − first) / days. Present so
     * tests can show the difference rather than assert the good answer is good —
     * this is the method the estimator exists to avoid.
     */
    public static function endpointTdee(float $intakeMean, string $from, string $to, float $kcalPerKg = 7700): float
    {
        $first = DailySummary::query()->findOrFail($from);
        $last = DailySummary::query()->findOrFail($to);

        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to));

        $slope = ((float) $last->weight_kg - (float) $first->weight_kg) / $days;

        return $intakeMean - $kcalPerKg * $slope;
    }
}
