<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Models\Meal;
use App\Models\MealItem;
use App\Services\Rollup\IntakeCalculator;

/**
 * A confirmed meal, read as "what should be remembered about it".
 *
 * The one interesting decision is duplicate foods: the fingerprint is a
 * SET, so two slices of the same bread contribute one slug, but the
 * snapshot can't be a set the same way — 2 x 30 g of bread is 60 g, and
 * remembering 30 g would halve the meal on every re-log. So same-slug
 * items are merged: portions ADD (arithmetic), densities take the widest
 * band (min of mins, max of maxes) — the honest reading of two rows that
 * disagree about a food's content, since averaging would invent a
 * precision neither row claimed.
 *
 * Only items with a `confirmed_at` are read. Today's writers can't produce
 * a `confirmed` meal with unconfirmed items, but the column is per-item
 * precisely so a later step can let the user accept three proposals and
 * keep arguing about the fourth — a half-agreed plate must not be
 * remembered as a habit.
 */
final readonly class ConfirmedMeal
{
    /**
     * @param  list<RememberedItem>  $items  in the order they appear on the meal
     */
    private function __construct(public array $items) {}

    /**
     * @return self|null null when there is nothing worth remembering
     */
    public static function from(Meal $meal): ?self
    {
        /** @var array<string, RememberedItem> $merged keyed by slug */
        $merged = [];

        $fingerprints = new MealFingerprint;

        foreach ($meal->items as $item) {
            if ($item->confirmed_at === null) {
                continue;
            }

            $slug = $fingerprints->slug((string) $item->name);

            if ($slug === '') {
                continue;
            }

            $portion = self::portionOf($item);
            $densities = self::densitiesOf($item);

            if (! isset($merged[$slug])) {
                $merged[$slug] = new RememberedItem(
                    name: trim((string) $item->name),
                    slug: $slug,
                    portionG: $portion,
                    densities: $densities,
                );

                continue;
            }

            $existing = $merged[$slug];

            $widened = [];

            foreach ($densities as $nutrient => $band) {
                $widened[$nutrient] = [
                    'min' => min($existing->density($nutrient, 'min'), $band['min']),
                    'max' => max($existing->density($nutrient, 'max'), $band['max']),
                ];
            }

            $merged[$slug] = new RememberedItem(
                name: $existing->name,
                slug: $slug,
                portionG: $existing->portionG + $portion,
                densities: $widened,
            );
        }

        return $merged === [] ? null : new self(array_values($merged));
    }

    /**
     * @return list<string>
     */
    public function slugs(): array
    {
        return array_map(static fn (RememberedItem $item): string => $item->slug, $this->items);
    }

    /**
     * What to call this meal in a list. Meals have no name column —
     * deliberately, nobody titles their lunch — so the human-readable name
     * is the item names joined in entry order, rewritten by the most
     * recent log of the same fingerprint, so renaming "Rice" to "Basmati"
     * follows in the picker.
     */
    public function canonicalName(): string
    {
        return implode(', ', array_map(
            static fn (RememberedItem $item): string => $item->name,
            $this->items
        ));
    }

    /**
     * The midpoint of the confirmed portion range. A zero-width (typed or
     * scanned) item gives back exactly what was typed; a vision item gives
     * the middle of the agreed band, the single number best representing
     * "how much of this I eat".
     *
     * Uses `portion_full_g_*`, the PLATE's portion, not the shared one, so
     * a shared plate doesn't teach memory a smaller habit — memory's claim
     * is "a portion of this food is usually 180 g," a fact about the food
     * and serving that makes it useful as a pre-fill against the NEXT
     * photograph. Recording the eaten half instead would compound:
     * MemoryPrefill pulls a fresh proposal toward the remembered portion
     * and the share applies on top, so a plate shared once would come back
     * at 90 g, be halved to 45 g, be remembered as 45 g, and shrink again
     * on every re-photograph of the same dinner. The sharing is a fact
     * about one evening; the portion is not.
     */
    private static function portionOf(MealItem $item): float
    {
        return ((float) $item->portion_full_g_min + (float) $item->portion_full_g_max) / 2;
    }

    /**
     * @return array<string, array{min: float, max: float}>
     */
    private static function densitiesOf(MealItem $item): array
    {
        $densities = [];

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $densities[$nutrient] = [
                'min' => (float) $item->{$nutrient.'_per_100g_min'},
                'max' => (float) $item->{$nutrient.'_per_100g_max'},
            ];
        }

        return $densities;
    }
}
