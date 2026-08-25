<?php

declare(strict_types=1);

namespace App\Services\Tdee;

/**
 * Not enough evidence yet, and precisely how much is missing — a RESULT, not
 * an error. The counts it carries turn "collecting data" into a progress bar
 * (6 of 14 days, 3 of 8 weigh-ins) rather than an apology.
 *
 * Nothing is written to `tdee_estimates` here: a row would be a number the
 * app does not believe, in a table whose whole purpose is numbers it does.
 */
final readonly class CollectingData implements TdeeOutcome
{
    public function __construct(
        private TdeeWindow $window,
        public int $completeLogDays,
        public int $weighIns,
        public int $daysNeeded,
        public int $weighInsNeeded,
    ) {}

    public function meetsGate(): bool
    {
        return false;
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

    /** Whichever gate is still short — both can be. */
    public function needsMoreDays(): bool
    {
        return $this->completeLogDays < $this->daysNeeded;
    }

    public function needsMoreWeighIns(): bool
    {
        return $this->weighIns < $this->weighInsNeeded;
    }
}
