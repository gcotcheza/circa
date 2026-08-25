<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Services\Meals\ConsumptionShare;

/**
 * One food the model proposed, in the shape the model returns it.
 *
 * ABSOLUTES, not densities: `kcal_min` is calories in `portion_g_min`
 * of this food, not per 100 g — deliberately different from
 * `meal_items`, which stores densities so editing the portion
 * recomputes everything. The conversion lives in exactly one place
 * (ProposedItemMapper), the step where a mistake would be invisible.
 *
 * The same shape comes back from the review form: the user edits
 * "180-260 kcal of rice," what they see on the plate — nobody reviews
 * a photo thinking in kcal per 100 g.
 *
 * THE NUMBERS ARE ALWAYS THE WHOLE PLATE, `shareFraction` SEPARATE: a
 * shared plate doesn't change what the model was asked — it estimated
 * the bowl of pho, and the bowl is what these fields describe.
 * `shareFraction` is the user's own claim about how much they ate,
 * applied to the portion when ProposedItemMapper builds the row.
 *
 * Keeping them apart makes changing your mind free: posting halved
 * numbers instead would force "actually I ate all of it" to
 * reconstruct the plate by dividing, losing precision each edit.
 */
final readonly class ProposedItem
{
    public function __construct(
        public string $name,
        public float $portionGMin,
        public float $portionGMax,
        public float $kcalMin,
        public float $kcalMax,
        public float $proteinGMin,
        public float $proteinGMax,
        public float $carbsGMin,
        public float $carbsGMax,
        public float $fatGMin,
        public float $fatGMax,
        public ?string $confidence = null,
        public float $shareFraction = 1.0,
    ) {}

    /**
     * The same item, eaten in a different proportion.
     *
     * Used where the share is neither the model's business nor the
     * item's: a re-analysis re-applying the plate's stored share, and
     * `vision:reproject` carrying across what the user already chose.
     */
    public function withShare(float $fraction): self
    {
        return new self(
            name: $this->name,
            portionGMin: $this->portionGMin,
            portionGMax: $this->portionGMax,
            kcalMin: $this->kcalMin,
            kcalMax: $this->kcalMax,
            proteinGMin: $this->proteinGMin,
            proteinGMax: $this->proteinGMax,
            carbsGMin: $this->carbsGMin,
            carbsGMax: $this->carbsGMax,
            fatGMin: $this->fatGMin,
            fatGMax: $this->fatGMax,
            confidence: $this->confidence,
            shareFraction: $fraction,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function fromArray(array $raw): self
    {
        $number = static fn (string $key): float => (float) ($raw[$key] ?? 0);

        $confidence = $raw['confidence'] ?? null;

        return new self(
            name: trim((string) ($raw['name'] ?? '')),
            portionGMin: $number('portion_g_min'),
            portionGMax: $number('portion_g_max'),
            kcalMin: $number('kcal_min'),
            kcalMax: $number('kcal_max'),
            proteinGMin: $number('protein_g_min'),
            proteinGMax: $number('protein_g_max'),
            carbsGMin: $number('carbs_g_min'),
            carbsGMax: $number('carbs_g_max'),
            fatGMin: $number('fat_g_min'),
            fatGMax: $number('fat_g_max'),
            confidence: is_string($confidence) ? $confidence : null,
            // Absent on everything the MODEL returns — it's never
            // asked how much was eaten. Arrives only from the review
            // form, where a person tapped a chip.
            shareFraction: ConsumptionShare::of($raw['share_fraction'] ?? null)->fraction,
        );
    }

    /**
     * Absolute min/max for one nutrient, keyed as ProposedItemMapper wants it.
     *
     * @return array{float, float}
     */
    public function nutrient(string $nutrient): array
    {
        return match ($nutrient) {
            'kcal'    => [$this->kcalMin, $this->kcalMax],
            'protein' => [$this->proteinGMin, $this->proteinGMax],
            'carbs'   => [$this->carbsGMin, $this->carbsGMax],
            'fat'     => [$this->fatGMin, $this->fatGMax],
            default   => [0.0, 0.0],
        };
    }
}
