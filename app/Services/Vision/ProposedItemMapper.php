<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Models\MealItem;
use App\Services\Rollup\IntakeCalculator;

/**
 * Absolutes (what the model says is on the plate) -> densities (what
 * `meal_items` stores).
 *
 * `meal_items` deliberately stores per-100 g densities and a portion, and
 * derives absolutes, so dragging 150 g to 220 g recomputes the item. The
 * model, equally deliberately, is asked for absolutes: "how much protein
 * is on this plate" is answerable from a photograph, "what is the protein
 * density of this stew" is not.
 *
 * Somebody has to divide. IntakeCalculator's rule for reading the numbers
 * back out is:
 *
 *     absolute_min = portion_min * density_min / 100
 *     absolute_max = portion_max * density_max / 100
 *
 * so the densities that REPRODUCE the model's band exactly are
 *
 *     density_min = 100 * kcal_min / portion_min
 *     density_max = 100 * kcal_max / portion_max
 *
 * which is what this class computes. Round-tripping is the property being
 * bought: what the review screen shows is what the model said, to the
 * gram, and confirming doesn't quietly change the number agreed to.
 *
 * The prompt asks for consistent ends (kcal_min is the value AT
 * portion_g_min) so this division lands the right way round. When it
 * doesn't — "100-200 g, 150-160 kcal" claims calories for a portion size
 * it doesn't know — the two results come out inverted, and the pair is
 * sorted rather than trusted, widening the band as the honest reading of
 * a self-contradictory answer.
 *
 * Everything is clamped on the way through, to the SAME ceilings the
 * barcode parser and the manual form use (config/health.php -> food):
 * nothing edible is denser than 900 kcal/100 g, and 100 g of a macro per
 * 100 g of food is not a food. A model returning an impossible density has
 * made an arithmetic slip, and storing it would put that number in front
 * of a user about to tap Confirm.
 *
 * The shared plate passes through here untouched: every number this class
 * handles is about the WHOLE plate, what the photograph shows and what
 * the model was asked for. "I ate half of it" is a separate claim, made
 * by a person after the fact, carried onto the row as `share_fraction`
 * for ConsumptionShare to apply at insert time — not here, for two
 * reasons. Densities must be derived from the plate's own portion or the
 * clamps distort them (a 2 g mint garnish shared three ways is 0.67 g,
 * MIN_PORTION_G would round it up to 1, and the density comes out a third
 * too low). And `portion_full_g_*` has to record what the model actually
 * said, so changing ½ to ⅓ to All rescales from the estimate rather than
 * from the last scaled copy of it.
 *
 * @phpstan-import-type MealItemRow from MealItem
 */
final class ProposedItemMapper
{
    /**
     * Portions below this are treated as this, because the divisor cannot be
     * zero and because "0 g of olive oil" is not a thing anybody eats. One
     * gram is small enough to be honest and large enough to divide by.
     */
    private const MIN_PORTION_G = 1.0;

    /**
     * low / medium / high -> the 0.000-1.000 column.
     *
     * The model is asked for a word because that's what it can calibrate;
     * the column is numeric because it predates this prompt's schema.
     * Coarse on purpose — the difference between 0.6 and 0.65 isn't a
     * thing the model knows.
     */
    private const CONFIDENCE = ['low' => 0.3, 'medium' => 0.6, 'high' => 0.9];

    /**
     * One proposed item as a `meal_items` row.
     *
     * `confirmed_at` is NOT set here. That is the entire point of the vision
     * path: the model proposes, and only a tap confirms.
     *
     * @return array{name: string, slug: string, portion_g_min: float, portion_g_max: float, share_fraction: float, confidence: float|null, kcal_per_100g_min: float, kcal_per_100g_max: float, protein_per_100g_min: float, protein_per_100g_max: float, carbs_per_100g_min: float, carbs_per_100g_max: float, fat_per_100g_min: float, fat_per_100g_max: float}
     */
    public function toRow(ProposedItem $item): array
    {
        $maxPortion = $this->maxPortion();

        $portionMin = $this->clamp($item->portionGMin, self::MIN_PORTION_G, $maxPortion);
        $portionMax = $this->clamp($item->portionGMax, $portionMin, $maxPortion);

        $row = [
            'name' => $item->name,
            'slug' => str($item->name)->slug()->value(),

            /*
             * The plate's portion, not the eaten one — everything here is
             * about the whole plate, what the model was asked about.
             * Dividing a HALVED portion into a halved kcal figure gives the
             * same density, but distorts the clamps (a 2 g mint garnish
             * shared three ways is 0.67 g; MIN_PORTION_G would round it to
             * 1 g, understating density by a third). So the share is
             * carried on the row and applied last, by ConsumptionShare at
             * insert; `portion_full_g_*` derives from these in that same
             * step.
             */
            'portion_g_min' => $portionMin,
            'portion_g_max' => $portionMax,

            // What the user said they ate of it. 1.0 — all of it — on
            // everything the model produces; a fraction only where somebody
            // tapped a chip on the review sheet.
            'share_fraction' => $item->shareFraction,

            'confidence' => $this->confidence($item->confidence),
        ];

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            [$absoluteMin, $absoluteMax] = $item->nutrient($nutrient);

            [$min, $max] = $this->density($absoluteMin, $absoluteMax, $portionMin, $portionMax, $this->ceiling($nutrient));

            $row[$nutrient.'_per_100g_min'] = $min;
            $row[$nutrient.'_per_100g_max'] = $max;
        }

        return $row;
    }

    /**
     * @param  list<ProposedItem>  $items
     * @return list<MealItemRow>
     */
    public function toRows(array $items): array
    {
        return array_map(fn (ProposedItem $item): array => $this->toRow($item), $items);
    }

    /**
     * @return array{float, float}
     */
    private function density(float $absoluteMin, float $absoluteMax, float $portionMin, float $portionMax, float $ceiling): array
    {
        $atMin = 100 * max(0.0, $absoluteMin) / $portionMin;
        $atMax = 100 * max(0.0, $absoluteMax) / $portionMax;

        // Sorted rather than trusted: see the class docblock. A consistent
        // answer sorts to itself and round-trips exactly; an inconsistent one
        // widens instead of inverting.
        $min = min($atMin, $atMax);
        $max = max($atMin, $atMax);

        return [
            round($this->clamp($min, 0.0, $ceiling), 3),
            round($this->clamp($max, 0.0, $ceiling), 3),
        ];
    }

    private function ceiling(string $nutrient): float
    {
        return $nutrient === 'kcal'
            ? (float) config('health.food.max_kcal_per_100g')
            : (float) config('health.food.max_macro_per_100g');
    }

    /**
     * The ceiling `items.*.grams` puts on a typed portion — now literally the
     * same number rather than a copy that matched: 10 kg on one line is a typo
     * whichever path it arrives by. Read per call, as the densities above are.
     */
    private function maxPortion(): float
    {
        return (float) config('health.food.max_portion_g');
    }

    private function confidence(?string $word): ?float
    {
        return $word === null ? null : self::CONFIDENCE[$word] ?? null;
    }

    private function clamp(float $value, float $min, float $max): float
    {
        if (! is_finite($value)) {
            return $min;
        }

        return max($min, min($max, $value));
    }
}
