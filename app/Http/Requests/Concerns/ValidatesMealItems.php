<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Models\MealItem;
use Illuminate\Validation\Rule;
use App\Services\Vision\TypedMeal;
use App\Services\Meals\ConsumptionShare;

/**
 * The manual item list, validated identically wherever it is posted.
 *
 * The NAME is required; every number is optional. Step 3 required kcal on
 * every item, reasoning that a calorie-less item wasn't worth a row — but
 * that meant a meal couldn't be logged without knowing its calories, and
 * the choice became inventing a number or logging nothing. A made-up
 * 600 kcal is indistinguishable in `daily_summaries` from a measured one,
 * which is the failure this app is built to avoid. So an item with only a
 * name is a legal, storable meal item contributing 0 to the day, honestly.
 * Filling the blanks is the user's business: type them later, or ask for
 * an estimate (POST /api/meals/estimate), the same list sent to the same
 * model behind the same review sheet as a photograph.
 *
 * The ceilings are unchanged and still read from config: a scanned product
 * that survives the Open Food Facts parser must not be rejected when the
 * meal it is in is saved.
 *
 * @phpstan-import-type MealItemRow from MealItem
 */
trait ValidatesMealItems
{
    /**
     * @return array<string, mixed>
     */
    protected function itemRules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:40'],

            // The one thing still required, trimmed to non-empty — a row
            // of spaces is a validation error, not an item called "  ".
            'items.*.name'  => ['required', 'string', 'max:191'],
            'items.*.basis' => ['required', Rule::in(['absolute', 'per_100g'])],

            // Quick absolute mode: what the whole item contains.
            'items.*.kcal'    => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_kcal_absolute')],
            'items.*.protein' => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_absolute')],
            'items.*.carbs'   => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_absolute')],
            'items.*.fat'     => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_absolute')],

            // Per-100g mode: a density and a weight. Both optional too — "some
            // rice, I did not weigh it" is a thing a person eats.
            'items.*.grams' => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_portion_g')],
            // 900 kcal/100g is pure fat — above it is a typo that silently
            // doubles a day. Read from config since the barcode parser
            // applies the SAME ceiling.
            'items.*.kcal_per_100g'    => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_kcal_per_100g')],
            'items.*.protein_per_100g' => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_per_100g')],
            'items.*.carbs_per_100g'   => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_per_100g')],
            'items.*.fat_per_100g'     => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_per_100g')],

            /*
             * Barcode provenance (step 4). `exists`, not a bare string: this
             * is a foreign key written by our own lookup endpoint moments
             * earlier, so a client that invents one gets a validation
             * error, not a 500 from Postgres — an item WITHOUT it is still
             * valid (every typed item). Nutrition values are NOT read back
             * from the product server-side: the user may correct a wrong
             * OFF entry before saving, and re-deriving would silently
             * discard that edit — the link records provenance, not values.
             */
            'items.*.food_product_id' => ['nullable', 'string', 'max:32', Rule::exists('food_products', 'barcode')],

            /*
             * "I ate half the chocolate bar." The shared plate reaches
             * typed and scanned items too: a barcode gives you the whole
             * bar, which is often not what was eaten. Absent means all of
             * it, matching every meal saved before this feature. It scales
             * the PORTION — grams in per-100g mode, the 100g basis in quick
             * mode — leaving densities alone, since half a bar of chocolate
             * is still chocolate.
             */
            'items.*.share_fraction' => ['nullable', 'numeric', 'min:'.ConsumptionShare::MIN, 'max:1'],
        ];
    }

    /**
     * The typed meal, blanks still blank — the one place the form is read,
     * so "save as-is" and "save and estimate" can't drift into
     * interpreting the same payload differently.
     */
    public function typedMeal(): TypedMeal
    {
        /** @var array<int, array<string, mixed>> $raw */
        $raw = $this->validated()['items'];

        return TypedMeal::fromValidated(
            items: $raw,
            mealType: $this->input('meal_type'),
            time: $this->input('time'),
            notes: $this->input('notes'),
        );
    }

    /**
     * Items in `meal_items` shape: per-100g densities plus a portion, all
     * zero-width — the honest claim that the user typed a number and
     * stands behind it. A blank is stored as 0 (columns are NOT NULL);
     * which zeros were typed vs. left empty is recorded on the estimate's
     * audit row, not here (see TypedItem).
     *
     * @return list<MealItemRow>
     */
    public function items(): array
    {
        return $this->typedMeal()->toRows();
    }
}
