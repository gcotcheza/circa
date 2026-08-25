<?php

declare(strict_types=1);

namespace App\Services\Tdee;

use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use Illuminate\Support\Collection;
use App\Services\Reporting\WeightEma;

/**
 * Back-calculated expenditure: the feature this app is built around. Other
 * trackers predict TDEE from a formula (height/weight/age/sex regression
 * plus a guessed activity multiplier); this one solves for the user's own
 * unknown from what they actually ate and what the scale actually did:
 *
 *     TDEE = mean(intake over complete-log days) − kcal_per_kg × slope(weight)
 *
 * Full equation/uncertainty/biases on EstimatedTdee, window rule on
 * TdeeWindow, why slope is a least-squares fit not a subtraction on Ols.
 * This class is just the plumbing between them — pure of side effects, so
 * safe on a page render and trivial to test against synthetic data.
 *
 * Returns a TdeeOutcome, never null or a bare float: below the gate the
 * answer is CollectingData with how far off it is — "not yet, here's
 * what's missing" is a real answer, an empty card is not.
 */
final class TdeeEstimator
{
    public function __construct(private readonly WeightEma $ema = new WeightEma) {}

    public function estimate(?TdeeWindow $window = null): TdeeOutcome
    {
        $window ??= TdeeWindow::current();

        $minDays = (int) config('health.tdee.min_complete_days', 14);
        $minWeighIns = (int) config('health.tdee.min_weighins', 8);

        $rows = DailySummary::query()
            ->whereBetween('local_date', [$window->startDate(), $window->endDate()])
            ->orderBy('local_date')
            ->get(['local_date', 'kcal_in_min', 'kcal_in_mid', 'kcal_in_max', 'weight_kg', 'is_complete_log']);

        /*
         * The intake side: `is_complete_log` AND a usable midpoint. The
         * heuristic already implies one (>= 1000 kcal), but a flagged day
         * with no number would still count toward the gate and contribute
         * nothing to the mean — the one way this could quietly under-report.
         */
        $completeDays = $rows->filter(
            fn (DailySummary $row): bool => $row->is_complete_log && $row->kcal_in_mid !== null
        );

        // The weight side: days with a weigh-in, in order — gaps are absent, never filled in.
        $weighInDates = array_values($rows->filter(fn (DailySummary $row): bool => $row->weight_kg !== null)
            ->map(fn (DailySummary $row): string => $row->local_date->toDateString())
            ->all());

        $nDays = $completeDays->count();
        $nWeighIns = count($weighInDates);

        if ($nDays < $minDays || $nWeighIns < $minWeighIns) {
            return new CollectingData($window, $nDays, $nWeighIns, $minDays, $minWeighIns);
        }

        $fit = Ols::fit($this->emaPoints($window, $weighInDates));

        $intakeMean = $completeDays->avg(fn (DailySummary $row): float => (float) $row->kcal_in_mid);

        return new EstimatedTdee(
            window: $window,
            intakeMean: (float) $intakeMean,
            intakeBandLow: $this->meanHalfBand($completeDays, low: true),
            intakeBandHigh: $this->meanHalfBand($completeDays, low: false),
            slopeKgPerDay: $fit->slope,
            slopeStandardError: $fit->standardError,
            kcalPerKg: (float) config('health.tdee.kcal_per_kg', 7700),
            completeLogDays: $nDays,
            weighIns: $nWeighIns,
            methodVersion: (string) config('health.tdee.method_version', 'v1'),
        );
    }

    /**
     * The points the line is fitted through.
     *
     * x is the REAL calendar offset of each weigh-in, not its position in
     * the list — four days without one must cost four days of x, or a gap
     * in the middle gets fitted as consecutive and the slope comes out too
     * steep.
     *
     * y is the EMA value for that day (WeightEma, same series the trends
     * chart draws), seeded from the full history so the fit doesn't sit on
     * a start-up transient.
     *
     * @param  list<string>  $dates
     * @return list<array{0: float, 1: float}>
     */
    private function emaPoints(TdeeWindow $window, array $dates): array
    {
        $ema = $this->ema->series();
        $origin = $window->start;

        $points = [];

        foreach ($dates as $date) {
            if (! isset($ema[$date])) {
                continue;
            }

            $points[] = [
                (float) $origin->diffInDays(CarbonImmutable::parse($date, $origin->timezone)),
                $ema[$date],
            ];
        }

        return $points;
    }

    /**
     * Mean half-width of the daily intake band, on one side.
     *
     * Not divided by sqrt(n): a portion-size error repeats daily rather
     * than cancelling (see EstimatedTdee).
     *
     * @param  Collection<int, DailySummary>  $days
     */
    private function meanHalfBand(Collection $days, bool $low): float
    {
        return (float) $days->avg(function (DailySummary $row) use ($low): float {
            $mid = (float) $row->kcal_in_mid;

            $edge = $low
                ? ($row->kcal_in_min === null ? $mid : (float) $row->kcal_in_min)
                : ($row->kcal_in_max === null ? $mid : (float) $row->kcal_in_max);

            return abs($mid - $edge);
        });
    }
}
