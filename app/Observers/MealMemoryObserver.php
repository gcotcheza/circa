<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Meal;
use App\Services\Memory\MemoryRecorder;

/**
 * The single hook that teaches the app what the user eats.
 *
 * `saved` rather than `created` + `updated`: four ways a meal becomes
 * confirmed (typed, scanned, photographed, re-logged) all end by saving —
 * a recorder called from each writer would work until a fifth path arrived.
 *
 * Separate from MealObserver, though both watch Meal: that one answers
 * "which day's numbers are wrong now?", this one "have I eaten this
 * before?" — same events, nothing else shared.
 *
 * Writes nothing from inside this call — see MemoryRecorder::schedule,
 * which defers to `DB::afterCommit` since a meal's items are written after
 * the meal row, in the same transaction.
 */
final class MealMemoryObserver
{
    public function __construct(private readonly MemoryRecorder $recorder) {}

    public function saved(Meal $meal): void
    {
        $this->recorder->schedule($meal);
    }
}
