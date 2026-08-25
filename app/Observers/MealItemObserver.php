<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\MealItem;
use Carbon\CarbonImmutable;
use App\Services\Rollup\SummaryRebuilder;

/**
 * Items carry the calories, so an item change is an intake change.
 *
 * Editing 150 g to 220 g touches no meal row at all — without this observer
 * the day's band would silently keep the old portion until an unrelated
 * export happened to dirty the date.
 *
 * Deleting a meal cascades to its items in Postgres, not Eloquent, so no
 * per-item `deleted` fires here — correct, not a gap: MealObserver::deleted
 * already dirties the same date, and both would only queue the rebuild twice.
 */
final class MealItemObserver
{
    public function __construct(private readonly SummaryRebuilder $rebuilder) {}

    public function created(MealItem $item): void
    {
        $this->queue($item);
    }

    public function updated(MealItem $item): void
    {
        $this->queue($item);
    }

    public function deleted(MealItem $item): void
    {
        $this->queue($item);
    }

    private function queue(MealItem $item): void
    {
        $meal = $item->meal;

        if ($meal === null) {
            return;
        }

        $this->rebuilder->queue(
            CarbonImmutable::parse($meal->eaten_at)
                ->setTimezone((string) config('health.timezone'))
                ->toDateString()
        );
    }
}
