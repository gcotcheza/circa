<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Services\Meals\ConsumptionShare;
use App\Services\Rollup\IntakeCalculator;

/**
 * One item exactly as the user typed it, with the blanks still blank.
 *
 * WHY NULL, NOT ZERO: `meal_items`'s density columns are NOT NULL, so a
 * no-calories item stores 0.000 — and 0 is a number a user is entitled to
 * type (a glass of water). Once written, "the user said zero" and "the
 * user said nothing" are the same bytes, and the difference is the whole
 * basis of the estimate: one is a value to echo back untouched, the other
 * is the question being asked. So the typed form is captured BEFORE it
 * becomes a row, kept in `vision_requests.input_payload`, read back by
 * the job.
 *
 * The two entry modes stay as the user chose them, not normalised to one,
 * because the choice is itself information: `per_100g` with a density and
 * no weight means "I know what this food is, not how much I had" (a claim
 * that survives whatever portion the model proposes); `absolute` with a
 * kcal figure and no weight is the opposite — a claim about the plate,
 * no opinion about grams.
 */
final readonly class TypedItem
{
    /**
     * @param  array<string, float|null>  $values  kcal/protein/carbs/fat, in the basis's own unit
     */
    public function __construct(
        public string $name,
        public string $basis,
        public ?float $grams,
        public array $values,
        public ?string $foodProductId = null,
        /**
         * How much of it was eaten (the shared plate).
         *
         * Kept out of `values` on purpose: those describe the FOOD in the
         * basis's own unit, this is a claim about the sitting. Typing "300
         * kcal" and tapping ½ states two separate true things — the
         * estimate path echoes the first back untouched while still
         * halving what lands in the day.
         */
        public float $shareFraction = 1.0,
    ) {}

    /**
     * @param  array<string, mixed>  $item  one validated `items.*` entry
     */
    public static function fromValidated(array $item): self
    {
        $basis = ($item['basis'] ?? 'absolute') === 'per_100g' ? 'per_100g' : 'absolute';

        $suffix = $basis === 'per_100g' ? '_per_100g' : '';

        $values = [];

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $values[$nutrient] = self::number($item[$nutrient.$suffix] ?? null);
        }

        return new self(
            name: trim((string) ($item['name'] ?? '')),
            basis: $basis,
            grams: $basis === 'per_100g' ? self::number($item['grams'] ?? null) : null,
            values: $values,
            foodProductId: isset($item['food_product_id']) && $item['food_product_id'] !== ''
                ? (string) $item['food_product_id']
                : null,
            shareFraction: ConsumptionShare::of($item['share_fraction'] ?? null)->fraction,
        );
    }

    /**
     * One line of the REVIEW SHEET, as the user currently has it.
     *
     * "Estimate this again" used to read the STORED items — the previous
     * answer — so correcting a portion from a garbage 2 g to a weighed
     * 250 g and then asking for a better estimate threw the correction
     * away and asked about the garbage again: the one number that wasn't
     * a guess was the one that didn't survive the request. The sheet now
     * sends what it's holding, and it arrives here.
     *
     * Keys are the review sheet's own (`portion_g`, `kcal`, `protein_g`,
     * `carbs_g`, `fat_g`), ONE value each rather than the pair the sheet
     * shows, because a given is a claim and a claim is a number. The
     * client decides which boxes became claims (see `statedItems` in
     * ProposalReview.vue); anything unsent stays estimable — the whole
     * point of asking again.
     *
     * VALUES DESCRIBE THE WHOLE PLATE, as the sheet's own model does:
     * boxes show `plate × share`, the form holds the plate, and share
     * rides along beside them, applied once on the way into the row like
     * everywhere else.
     *
     * A stated portion makes the item `per_100g` (the only basis that can
     * carry a weight); totals convert to densities over it, so "250 g and
     * 400 kcal" becomes 160 kcal/100 g of 250 g and reads back as exactly
     * 400 kcal. With no portion, totals stay absolute and survive
     * whatever portion the model proposes (see `preserve`).
     *
     * @param  array<string, mixed>  $raw  one validated `items.*` entry from the sheet
     */
    public static function fromSheet(array $raw): self
    {
        $portion = self::number($raw['portion_g'] ?? null);

        // A portion of zero is not a weight anybody stated, and it would make
        // the density below a division by zero.
        $grams = $portion !== null && $portion > 0.0 ? $portion : null;

        $values = [];

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $total = self::number($raw[self::SHEET_KEYS[$nutrient]] ?? null);

            // grams x density / 100, inverted: the sheet speaks in absolutes
            // for the plate, and per_100g mode stores a density.
            $values[$nutrient] = $total === null || $grams === null
                ? $total
                : round(100 * $total / $grams, 3);
        }

        return new self(
            name: trim((string) ($raw['name'] ?? '')),
            basis: $grams === null ? 'absolute' : 'per_100g',
            grams: $grams,
            values: $values,
            foodProductId: null,
            shareFraction: ConsumptionShare::of($raw['share_fraction'] ?? null)->fraction,
        );
    }

    /** The review sheet's name for each nutrient's absolute figure. */
    private const SHEET_KEYS = [
        'kcal'    => 'kcal',
        'protein' => 'protein_g',
        'carbs'   => 'carbs_g',
        'fat'     => 'fat_g',
    ];

    /**
     * @param  array<string, mixed>  $raw  as stored in `vision_requests.input_payload`
     */
    public static function fromArray(array $raw): self
    {
        $values = [];

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $values[$nutrient] = self::number(($raw['values'] ?? [])[$nutrient] ?? null);
        }

        return new self(
            name: (string) ($raw['name'] ?? ''),
            basis: ($raw['basis'] ?? 'absolute') === 'per_100g' ? 'per_100g' : 'absolute',
            grams: self::number($raw['grams'] ?? null),
            values: $values,
            foodProductId: isset($raw['food_product_id']) ? (string) $raw['food_product_id'] : null,
            shareFraction: ConsumptionShare::of($raw['share_fraction'] ?? null)->fraction,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name'            => $this->name,
            'basis'           => $this->basis,
            'grams'           => $this->grams,
            'values'          => $this->values,
            'food_product_id' => $this->foodProductId,
            // Round-tripped through `vision_requests.input_payload`, so that
            // an estimate asked for on a half-portion comes back a half
            // portion. Without it `vision:reproject` would quietly restore the
            // whole plate months later.
            'share_fraction' => $this->shareFraction,
        ];
    }

    public function slug(): string
    {
        return str($this->name)->slug()->value();
    }

    /** True when nothing but a name was typed — the item this whole feature exists for. */
    public function needsEstimate(): bool
    {
        return $this->values['kcal'] === null;
    }

    /**
     * True when the user entered ANY figure against this item.
     *
     * ---------------------------------------------------------------------------
     * THIS IS THE LINE BETWEEN DATA AND A DESCRIPTION
     *
     * An item with a number on it is a claim: the user read 210 kcal off a
     * packet, or weighed 250 g of it. Nothing may change that number and nothing
     * may drop the row.
     *
     * An item with NO numbers is not a claim at all — it is a description of
     * something to be worked out, and the user's own words are the whole of it:
     *
     *     "Full kwark 250g with 1 tablespoons of honey, 2 tablespoon of chia
     *      seed, 1 tablespoon of protein powder and 1 handful of blueberries"
     *
     * That is one text box, not one food. The proposal that comes back — five
     * components with real portions and real energy — IS this line, answered.
     * Keeping the line as a sixth item beside them would put a 100 g, 0 kcal row
     * called "Full kwark 250g with …" in the meal, which is what happened in
     * production: a placeholder the storage layer had to invent, presented in
     * the review sheet as though the user had typed a zero.
     *
     * `false` here does NOT mean "worthless". It means "the model's answer is
     * allowed to be this line's replacement" — see TypedMeal::complete(), which
     * still restores a blank line when nothing came back that could be its
     * answer, and TypedMeal::preserve(), which has nothing to preserve on it.
     * ---------------------------------------------------------------------------
     */
    public function hasTypedValues(): bool
    {
        if ($this->grams !== null) {
            return true;
        }

        foreach ($this->values as $value) {
            if ($value !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * The portion the user actually CLAIMED, as opposed to the one storage
     * assumes.
     *
     * Null in `absolute` mode even though the row will be written at 100 g:
     * that 100 is a basis chosen so the density and the total are the same
     * number (see MealRequest), not a statement that the cappuccino weighed
     * 100 g. Pinning the model's portion to it would be inventing a weight the
     * user never gave.
     */
    public function claimedPortionG(): ?float
    {
        return $this->basis === 'per_100g' ? $this->grams : null;
    }

    /**
     * The per-100 g density the user stated for this nutrient, if they stated
     * one at all. Only `per_100g` mode states a density directly.
     */
    public function claimedDensity(string $nutrient): ?float
    {
        return $this->basis === 'per_100g' ? ($this->values[$nutrient] ?? null) : null;
    }

    /**
     * The absolute total the user stated for this nutrient, if they stated one.
     *
     * In `per_100g` mode a total exists only when BOTH the density and the
     * weight were given; a density on its own is a claim about the food, not
     * about the plate, and is preserved as a density instead.
     */
    public function claimedAbsolute(string $nutrient): ?float
    {
        $value = $this->values[$nutrient] ?? null;

        if ($value === null) {
            return null;
        }

        if ($this->basis === 'absolute') {
            return $value;
        }

        return $this->grams === null ? null : $this->grams * $value / 100;
    }

    /**
     * The `meal_items` row for this item, blanks read as zero.
     *
     * This is the ONE definition of the manual-entry arithmetic, used by the
     * ordinary save and by the estimate path alike — so "save as-is" and
     * "save and estimate" cannot drift into reading the same form differently.
     *
     * @return array{name: string, slug: string, portion_g_min: float, portion_g_max: float, share_fraction: float, food_product_id: string|null, kcal_per_100g_min: float, kcal_per_100g_max: float, protein_per_100g_min: float, protein_per_100g_max: float, carbs_per_100g_min: float, carbs_per_100g_max: float, fat_per_100g_min: float, fat_per_100g_max: float}
     */
    public function toRow(): array
    {
        $absolute = $this->basis === 'absolute';

        // 100 g is the basis, not a claim about the weight: at 100 g the
        // density IS the absolute value, so the two modes converge.
        $portion = $absolute ? 100.0 : ($this->grams ?? 100.0);

        // A weight of zero would make the row undividable later and says
        // nothing that a blank does not.
        if ($portion <= 0.0) {
            $portion = 100.0;
        }

        $row = [
            'name' => $this->name,
            'slug' => $this->slug(),

            // The portion as TYPED — the whole cappuccino, the whole bar. The
            // share is carried alongside and applied by ConsumptionShare on the
            // way into the row, so that changing "½" to "all of it" later
            // rescales from this number rather than from half of it.
            'portion_g_min'   => $portion,
            'portion_g_max'   => $portion,
            'share_fraction'  => $this->shareFraction,
            'food_product_id' => $this->foodProductId,
        ];

        foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
            $density = $this->values[$nutrient] ?? 0.0;

            $row[$nutrient.'_per_100g_min'] = $density;
            $row[$nutrient.'_per_100g_max'] = $density;
        }

        return $row;
    }

    /**
     * This item as a proposal, for when the model drops it entirely.
     *
     * Built from `toRow()` and converted back to absolutes, so that pushing it
     * through ProposedItemMapper reproduces the same row the ordinary save
     * would have written. Zero-width on every axis: it is what the user typed
     * and nothing more.
     */
    public function toProposedItem(): ProposedItem
    {
        $row = $this->toRow();
        $portion = (float) $row['portion_g_min'];

        $absolute = static fn (string $nutrient): float => $portion * (float) $row[$nutrient.'_per_100g_min'] / 100;

        return new ProposedItem(
            name: $this->name,
            portionGMin: $portion,
            portionGMax: $portion,
            kcalMin: $absolute('kcal'),
            kcalMax: $absolute('kcal'),
            proteinGMin: $absolute('protein'),
            proteinGMax: $absolute('protein'),
            carbsGMin: $absolute('carbs'),
            carbsGMax: $absolute('carbs'),
            fatGMin: $absolute('fat'),
            fatGMax: $absolute('fat'),
            // Deliberately null. `confidence` is the MODEL's confidence in
            // having identified a food, and there is nothing to be confident
            // about in a word somebody typed.
            confidence: null,
            // Carried, because this is the same claim wearing a different
            // shape: the user said they had half of it before asking for an
            // estimate, and the estimate arriving does not change that.
            shareFraction: $this->shareFraction,
        );
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }
}
