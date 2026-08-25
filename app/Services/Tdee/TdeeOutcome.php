<?php

declare(strict_types=1);

namespace App\Services\Tdee;

/**
 * Exactly two shapes, and the caller must handle both: "collecting data" is
 * a first-class answer, not a null left for the UI to interpret.
 *
 * @see EstimatedTdee   gate cleared, a range, never a point
 * @see CollectingData  gate not cleared, progress the UI can report
 */
interface TdeeOutcome
{
    public function meetsGate(): bool;

    public function window(): TdeeWindow;

    /** Complete-log days found in the window. */
    public function completeLogDays(): int;

    /** Weigh-ins found in the window. */
    public function weighIns(): int;
}
