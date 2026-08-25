<?php

declare(strict_types=1);

namespace App\Services\Rollup;

use App\Models\MealItem;
use Illuminate\Support\Collection;

/**
 * meal_items -> intake bands.
 *
 * Used twice with the same code: once by DailySummaryBuilder for the day, once
 * by the daily view for each meal's own band. Two implementations of "what does
 * this plate add up to?" would eventually disagree on screen, in the same view.
 *
 * Absolute values are derived from the stored per-100g densities here rather
 * than read from a column, because there is no such column — that is the point
 * of the schema: editing 150 g to 220 g recomputes everything downstream.
 */
final class IntakeCalculator
{
    /** Column prefixes on `meal_items`, by nutrient. */
    public const NUTRIENTS = ['kcal', 'protein', 'carbs', 'fat'];

    /**
     * @param  Collection<int, MealItem>  $items
     * @return array<string, IntakeBand> nutrient => band
     */
    public function bands(Collection $items): array
    {
        $bands = [];

        foreach (self::NUTRIENTS as $nutrient) {
            $bands[$nutrient] = IntakeBand::fromRanges(
                array_values($items->map(fn (MealItem $item): array => $this->range($item, $nutrient))->all())
            );
        }

        return $bands;
    }

    /**
     * @param  Collection<int, MealItem>  $items
     */
    public function kcalBand(Collection $items): IntakeBand
    {
        return IntakeBand::fromRanges(
            array_values($items->map(fn (MealItem $item): array => $this->range($item, 'kcal'))->all())
        );
    }

    /**
     * Absolute min/max for one item and one nutrient.
     *
     * The extremes multiply: the lightest portion at the leanest density is the
     * floor, the heaviest at the richest is the ceiling. Both factors are
     * non-negative (a validation rule, not an assumption), so no sign analysis
     * is needed.
     *
     * @return array{min: float, max: float}
     */
    public function range(MealItem $item, string $nutrient): array
    {
        $minDensity = "{$nutrient}_per_100g_min";
        $maxDensity = "{$nutrient}_per_100g_max";

        return [
            'min' => (float) $item->portion_g_min * (float) $item->{$minDensity} / 100,
            'max' => (float) $item->portion_g_max * (float) $item->{$maxDensity} / 100,
        ];
    }
}
