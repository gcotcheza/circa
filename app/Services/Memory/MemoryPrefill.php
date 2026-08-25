<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Models\MealMemoryItem;
use App\Services\Rollup\IntakeCalculator;

/**
 * "You have had this before" applied to a fresh vision proposal.
 *
 * Runs inside AnalyzeMealPhoto, AFTER ProposedItemMapper turns the
 * model's absolutes into densities and BEFORE ProposalWriter stores
 * them — so the user reviews adjusted numbers, and confirming an
 * untouched proposal still stores exactly what was on screen.
 *
 * `vision_requests.raw_response` is never touched: that's the model's
 * verbatim opinion, kept for evaluating prompt changes against
 * history; this is ours. `meal_items.memory_adjusted` keeps the
 * difference legible — after this runs, `where memory_adjusted` is
 * precisely the numbers the model didn't choose.
 *
 * WHAT'S OVERWRITTEN — "tighter wins," one guard each.
 *
 * PORTION: the remembered portion is a single number, always tighter
 * than any range, so it replaces the estimate only when it falls
 * INSIDE the model's proposed range — if the model says 40-90 g and
 * the user always has 180 g, the picture shows something different
 * from the habit, and wins.
 *
 * DENSITY: replaced when the remembered band is strictly narrower —
 * density is a property of the food, not the plate (chicken breast is
 * 165 kcal/100 g in every photo of it), so a confirmed or scanned
 * number beats an image estimate.
 *
 * Nothing is ever WIDENED: a tighter model band leaves the memory with
 * nothing to add.
 */
final class MemoryPrefill
{
    public function __construct(private readonly MemoryMatcher $matcher = new MemoryMatcher) {}

    /**
     * @param  list<array<string, mixed>>  $rows  meal_items shape, from ProposedItemMapper
     */
    public function apply(array $rows): PrefilledProposal
    {
        if ($rows === []) {
            return new PrefilledProposal([]);
        }

        $match = $this->matcher->forNames(array_map(
            static fn (array $row): string => (string) ($row['name'] ?? ''),
            $rows
        ));

        if ($match === null) {
            return new PrefilledProposal($this->withFlag($rows), null);
        }

        /** @var array<string, MealMemoryItem> $remembered */
        $remembered = $match->memory->items->keyBy('slug')->all();

        $adjusted = [];

        foreach ($rows as $row) {
            $slug = (string) ($row['slug'] ?? '');

            $adjusted[] = isset($remembered[$slug])
                ? $this->prefill($row, $remembered[$slug])
                : [...$row, 'memory_adjusted' => false];
        }

        return new PrefilledProposal($adjusted, $match);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function prefill(array $row, MealMemoryItem $item): array
    {
        $changed = false;

        $portion = (float) $item->typical_portion_g;

        if ($portion > 0
            && $portion >= (float) $row['portion_g_min']
            && $portion <= (float) $row['portion_g_max']
            // A range that is already a point cannot be tightened.
            && (float) $row['portion_g_min'] !== (float) $row['portion_g_max']
        ) {
            $row['portion_g_min'] = $portion;
            $row['portion_g_max'] = $portion;
            $changed = true;
        }

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $min = (float) $item->{$nutrient.'_per_100g_min'};
            $max = (float) $item->{$nutrient.'_per_100g_max'};

            $proposedWidth = (float) $row[$nutrient.'_per_100g_max'] - (float) $row[$nutrient.'_per_100g_min'];

            if ($max - $min >= $proposedWidth) {
                continue;
            }

            $row[$nutrient.'_per_100g_min'] = $min;
            $row[$nutrient.'_per_100g_max'] = $max;
            $changed = true;
        }

        $row['memory_adjusted'] = $changed;

        return $row;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withFlag(array $rows): array
    {
        return array_map(
            static fn (array $row): array => [...$row, 'memory_adjusted' => false],
            $rows
        );
    }
}
