<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use Carbon\CarbonImmutable;
use App\Services\Tdee\TdeeOutcome;
use App\Services\Tdee\TdeeRecorder;
use App\Services\Tdee\EstimatedTdee;
use App\Services\Tdee\TdeeEstimator;
use App\Services\Tdee\CollectingData;

/**
 * The TDEE card's props — for the trends page in full, for the daily view
 * in one line.
 *
 * The page computes; the table remembers. The estimate on screen is
 * derived on render, not read out of `tdee_estimates` — two queries over
 * at most 28 rows and a least-squares fit is nothing, and it buys the one
 * property that matters: the evening the user logs their fourteenth
 * complete day, the estimate IS THERE on open, not sixty seconds later
 * when a queued job happens to run. A card still saying "collecting data"
 * after the data was collected would be the worst possible first
 * impression of the feature.
 *
 * The row is still written (see TdeeRecorder): the table is the record of
 * what was believed and when — what a TDEE-over-time chart will read, and
 * what makes a later method_version comparable to this one. It's an
 * upsert that only touches `computed_at` when the answer moved, so a page
 * refresh is genuinely a no-op. Writing during a GET has precedent one
 * file over: DailyView builds a missing summary on render for the same
 * reason — derived, idempotent, and an empty screen is worse than a write.
 *
 * Nothing is written below the gate, on any path.
 */
final class TdeeCard
{
    public function __construct(
        private readonly TdeeEstimator $estimator = new TdeeEstimator,
        private readonly TdeeRecorder $recorder = new TdeeRecorder,
    ) {}

    /**
     * The full card: an estimate, or honest progress toward one.
     *
     * @return array<string, mixed>
     */
    public function props(): array
    {
        $outcome = $this->estimator->estimate();

        return $outcome instanceof EstimatedTdee
            ? $this->estimateProps($outcome)
            : $this->collectingProps($outcome);
    }

    /**
     * The daily view's one-liner. Null below the gate ON PURPOSE: the
     * "collecting data" story is told once, properly, on the trends page —
     * repeating it under every day's balance would nag rather than inform.
     *
     * @return array<string, mixed>|null
     */
    public function compact(): ?array
    {
        $outcome = $this->estimator->estimate();

        if (! $outcome instanceof EstimatedTdee) {
            return null;
        }

        // Deliberately does not record: the write lives on the trends page and
        // in the scheduled command, so there is one place to reason about.
        return [
            'min'    => round($outcome->min()),
            'mid'    => round($outcome->mid()),
            'max'    => round($outcome->max()),
            'window' => $outcome->window()->toArray(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function estimateProps(EstimatedTdee $outcome): array
    {
        $stored = $this->recorder->store($outcome);

        return $this->shared($outcome) + [
            'status'   => 'estimate',
            'estimate' => [
                // Rounded to whole kcal at the edge, and only here. A TDEE
                // written as 2 137.4 would be claiming a tenth of a kcal from a
                // fit whose own error bar is ±90.
                'min' => round($outcome->min()),
                'mid' => round($outcome->mid()),
                'max' => round($outcome->max()),
            ],
            'intakeMean'     => round($outcome->intakeMean),
            'slopeKgPerWeek' => round($outcome->slopeKgPerWeek(), 2),
            // Signed: negative means eating under expenditure.
            'intakeVersusTdee'     => round($outcome->intakeVersusTdee()),
            'slopeUncertaintyKcal' => round($outcome->slopeUncertaintyKcal()),
            'computedAt'           => $stored->computed_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function collectingProps(TdeeOutcome $outcome): array
    {
        return $this->shared($outcome) + [
            'status'               => 'collecting',
            'estimate'             => null,
            'intakeMean'           => null,
            'slopeKgPerWeek'       => null,
            'intakeVersusTdee'     => null,
            'slopeUncertaintyKcal' => null,
            'computedAt'           => null,
            'needsMoreDays'        => $outcome instanceof CollectingData && $outcome->needsMoreDays(),
            'needsMoreWeighIns'    => $outcome instanceof CollectingData && $outcome->needsMoreWeighIns(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function shared(TdeeOutcome $outcome): array
    {
        /** @var array{min_items: int, last_meal_after: string, kcal_floor: int} $rule */
        $rule = (array) config('health.rollup.complete_log');

        return [
            'window'   => $outcome->window()->toArray(),
            'progress' => [
                'days'           => $outcome->completeLogDays(),
                'daysNeeded'     => (int) config('health.tdee.min_complete_days', 14),
                'weighIns'       => $outcome->weighIns(),
                'weighInsNeeded' => (int) config('health.tdee.min_weighins', 8),
            ],
            // The heuristic, from config rather than restated in the template:
            // the sentence on screen explaining what counts as a complete day
            // must not be able to drift from the rule that decides it.
            'completeLogRule' => [
                'minItems'      => (int) $rule['min_items'],
                'lastMealAfter' => (string) $rule['last_meal_after'],
                'kcalFloor'     => (int) $rule['kcal_floor'],
            ],
            'kcalPerKg'     => (float) config('health.tdee.kcal_per_kg', 7700),
            'methodVersion' => (string) config('health.tdee.method_version', 'v1'),
            'today'         => CarbonImmutable::now((string) config('health.timezone'))->toDateString(),
        ];
    }
}
