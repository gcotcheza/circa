<?php

declare(strict_types=1);

namespace App\Services\Tdee;

use Carbon\CarbonImmutable;
use App\Models\TdeeEstimate;

/**
 * The one place that writes `tdee_estimates`.
 *
 * Only estimates that CLEAR THE GATE are written — "collecting data"
 * produces no row, and the table's emptiness is itself the honest
 * statement that there isn't enough evidence yet (production today: zero
 * rows, correctly).
 *
 * `computed_at` is touched ONLY WHEN THE ANSWER CHANGES. The estimate is
 * re-derived on every trends page load, nightly run and in-window rebuild;
 * stamping the clock every time would turn "as of 03:40 last night" into
 * "as of nine seconds ago" on an unchanged row. An unchanged recompute is
 * therefore a no-op, which also makes the whole path IDEMPOTENT — run
 * twice, get one row, unchanged — the property the scheduler depends on.
 */
final class TdeeRecorder
{
    public function __construct(private readonly TdeeEstimator $estimator = new TdeeEstimator) {}

    /**
     * Estimate the current window and persist it if it clears the gate.
     */
    public function record(?TdeeWindow $window = null): TdeeOutcome
    {
        $outcome = $this->estimator->estimate($window);

        if ($outcome instanceof EstimatedTdee) {
            $this->store($outcome);
        }

        return $outcome;
    }

    /**
     * Upsert on the step-1 unique key (window_start, window_end, method_version).
     */
    public function store(EstimatedTdee $estimate): TdeeEstimate
    {
        $key = [
            'window_start'   => $estimate->window()->startDate(),
            'window_end'     => $estimate->window()->endDate(),
            'method_version' => $estimate->methodVersion,
        ];

        $values = [
            'tdee_min'                => round($estimate->min(), 2),
            'tdee_mid'                => round($estimate->mid(), 2),
            'tdee_max'                => round($estimate->max(), 2),
            'intake_mean_kcal'        => round($estimate->intakeMean, 2),
            'weight_slope_kg_per_day' => round($estimate->slopeKgPerDay, 5),
            'n_days'                  => $estimate->completeLogDays,
            'n_weighins'              => $estimate->weighIns,
        ];

        $existing = TdeeEstimate::query()->where($key)->first();

        if ($existing !== null && $this->unchanged($existing, $values)) {
            return $existing;
        }

        return TdeeEstimate::query()->updateOrCreate(
            $key,
            $values + ['computed_at' => CarbonImmutable::now()],
        );
    }

    /** The stored row for a window, if there is one. */
    public function stored(TdeeWindow $window, ?string $methodVersion = null): ?TdeeEstimate
    {
        return TdeeEstimate::query()
            ->where('window_start', $window->startDate())
            ->where('window_end', $window->endDate())
            ->where('method_version', $methodVersion ?? (string) config('health.tdee.method_version', 'v1'))
            ->first();
    }

    /**
     * @param  array<string, float|int>  $values
     */
    private function unchanged(TdeeEstimate $existing, array $values): bool
    {
        foreach ($values as $column => $value) {
            // Decimal columns come back as numeric strings ("2110.00"); compare
            // numerically or every row looks changed.
            if (abs((float) $existing->getAttribute($column) - (float) $value) > 1e-9) {
                return false;
            }
        }

        return true;
    }
}
