<?php

declare(strict_types=1);

namespace App\Services\Tdee;

/**
 * A fitted expenditure, as a range: `tdee_mid = intake_mean − kcal_per_kg ×
 * slope`, where slope is kg/day from an OLS fit through the EMA-smoothed
 * weight series and intake_mean is `kcal_in_mid` averaged over COMPLETE-LOG
 * days only. The min/max range combines the regression's own standard
 * error with the food-log's intake band IN QUADRATURE (not linearly, and
 * not divided by sqrt(n) — the intake uncertainty is systematic, not
 * noise), and is asymmetric because the intake band is; it is a plausible
 * range, not a 95% confidence interval.
 *
 * See docs/rationale-app.md § "TDEE Range: Equation, Uncertainty
 * Combination, and Known Biases" for the full derivation and the three
 * documented, accepted biases (water vs. tissue mass, EMA start-up
 * transient, complete-log-day sampling assumption).
 */
final readonly class EstimatedTdee implements TdeeOutcome
{
    public function __construct(
        private TdeeWindow $window,
        /** Mean of kcal_in_mid over the complete-log days in the window. */
        public float $intakeMean,
        /** Mean of (mid − min) over the same days. */
        public float $intakeBandLow,
        /** Mean of (max − mid) over the same days. */
        public float $intakeBandHigh,
        /** kg per day; negative means losing. */
        public float $slopeKgPerDay,
        /** Standard error of that slope, kg per day. */
        public float $slopeStandardError,
        public float $kcalPerKg,
        public int $completeLogDays,
        public int $weighIns,
        public string $methodVersion,
    ) {}

    public function meetsGate(): bool
    {
        return true;
    }

    public function window(): TdeeWindow
    {
        return $this->window;
    }

    public function completeLogDays(): int
    {
        return $this->completeLogDays;
    }

    public function weighIns(): int
    {
        return $this->weighIns;
    }

    /** The equation itself. */
    public function mid(): float
    {
        return $this->intakeMean - $this->kcalPerKg * $this->slopeKgPerDay;
    }

    /** The regression's contribution to the width, already in kcal/day. */
    public function slopeUncertaintyKcal(): float
    {
        return $this->kcalPerKg * $this->slopeStandardError;
    }

    public function min(): float
    {
        return $this->mid() - $this->halfWidth($this->intakeBandLow);
    }

    public function max(): float
    {
        return $this->mid() + $this->halfWidth($this->intakeBandHigh);
    }

    /** Weight trend in the unit humans actually think in. */
    public function slopeKgPerWeek(): float
    {
        return $this->slopeKgPerDay * 7;
    }

    /**
     * Signed gap between what was eaten and what was burned, at the midpoints.
     * Negative = eating under expenditure.
     */
    public function intakeVersusTdee(): float
    {
        return $this->intakeMean - $this->mid();
    }

    private function halfWidth(float $intakeHalfBand): float
    {
        $slope = $this->slopeUncertaintyKcal();

        return sqrt($slope * $slope + $intakeHalfBand * $intakeHalfBand);
    }
}
