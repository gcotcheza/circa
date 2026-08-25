<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\Meal;
use Carbon\CarbonImmutable;
use App\Services\Vision\PhotoStore;
use App\Services\Rollup\SummaryRebuilder;

/**
 * Any change to a meal dirties the day it was eaten on.
 *
 * Deleting matters as much as creating: a meal logged against the wrong
 * day and then removed must take its calories with it. `local_date` is a
 * Postgres generated column not yet populated on a fresh model, so the
 * date is recomputed here from `eaten_at` in the SAME timezone the
 * column's DDL uses — a duplicated literal, flagged in config/health.php
 * as a migration if the zone ever changes.
 */
final class MealObserver
{
    public function __construct(
        private readonly SummaryRebuilder $rebuilder,
        private readonly PhotoStore $photos,
    ) {}

    public function created(Meal $meal): void
    {
        $this->rebuilder->queue($this->localDate($meal));
    }

    public function updated(Meal $meal): void
    {
        $dates = [$this->localDate($meal)];

        // Moving a meal from Monday to Tuesday dirties both days.
        if ($meal->wasChanged('eaten_at') && $meal->getOriginal('eaten_at') !== null) {
            $dates[] = $this->dateOf($meal->getOriginal('eaten_at'));
        }

        $this->rebuilder->queue($dates);
    }

    /**
     * Deleting a meal also deletes its photographs — originals and thumbnails.
     *
     * `deleting`, not `deleted`, and that's load-bearing now: photos used
     * to be found via `meals.photo_path`, still readable after delete, but
     * now live in `meal_photos`, `cascadeOnDelete` — so by the time
     * `deleted` fires there are no rows left to read paths from, and the
     * files would be orphaned forever. Done in the observer rather than
     * the controller so every route to deletion takes it (a discarded
     * proposal, an edited meal's delete button, a tinker session); a meal
     * that no longer exists has no record left to support keeping
     * photographs of somebody's dinner.
     */
    public function deleting(Meal $meal): void
    {
        $this->photos->forget($meal);
    }

    public function deleted(Meal $meal): void
    {
        $this->rebuilder->queue($this->localDate($meal));
    }

    private function localDate(Meal $meal): string
    {
        return $this->dateOf($meal->eaten_at);
    }

    private function dateOf(mixed $eatenAt): string
    {
        return CarbonImmutable::parse($eatenAt)
            ->setTimezone((string) config('health.timezone'))
            ->toDateString();
    }
}
