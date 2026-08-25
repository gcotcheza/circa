<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * What a meal item's boxes are CALLED in a sentence.
 *
 * Without these, every refusal about a row read out the field's path — "The
 * items.0.kcal field must not be greater than 20000." — in the box on blur and
 * again in the 422. The path is not a thing anybody says, and its index is
 * 0-based, so it named a row nobody can count to.
 *
 * The names are the sheet's own VOCABULARY rather than its literal labels: a
 * box headed "kcal" is called calories in a sentence, the density boxes are
 * said in full, and the Quick/Per 100 g toggle carries no label at all. What
 * has to hold is that one word means one box wherever a person reads it.
 *
 * ONE MAP FOR ALL FIVE REQUESTS, because a box must not be called "calories"
 * on the sheet that saves a meal and "kcal" on the sheet that re-asks the
 * model about it: the manual list, the sheet's single figures and the
 * proposal's min/max pairs are three shapes of the same row (see
 * ValidatesMealItems, ValidatesSheetGivens, MealProposalRequest), and a name
 * defined per shape would drift between them. Keys a request does not validate
 * cost nothing — Laravel reads this per field it is actually judging.
 *
 * The pairs are named for the ends of a range rather than for `_min`/`_max`,
 * which is the wording MealProposalRequest's own `gte` messages already use:
 * a range that "ends below where it starts".
 */
trait NamesMealItems
{
    /** @return array<string, string> */
    public function attributes(): array
    {
        return [
            'items.*.name'            => 'food name',
            'items.*.basis'           => 'entry mode',
            'items.*.confidence'      => 'confidence',
            'items.*.share_fraction'  => 'share eaten',
            'items.*.food_product_id' => 'scanned product',

            // Quick mode, and the sheet's one-value-per-claim figures.
            'items.*.kcal'      => 'calories',
            'items.*.protein'   => 'protein',
            'items.*.carbs'     => 'carbs',
            'items.*.fat'       => 'fat',
            'items.*.grams'     => 'portion',
            'items.*.portion_g' => 'portion',
            'items.*.protein_g' => 'protein',
            'items.*.carbs_g'   => 'carbs',
            'items.*.fat_g'     => 'fat',

            // Per-100g mode: a density, said in full rather than as a column head.
            'items.*.kcal_per_100g'    => 'calories per 100 g',
            'items.*.protein_per_100g' => 'protein per 100 g',
            'items.*.carbs_per_100g'   => 'carbs per 100 g',
            'items.*.fat_per_100g'     => 'fat per 100 g',

            // The proposal's bands, low end and high end.
            'items.*.portion_g_min' => 'portion range start',
            'items.*.portion_g_max' => 'portion range end',
            'items.*.kcal_min'      => 'calorie range start',
            'items.*.kcal_max'      => 'calorie range end',
            'items.*.protein_g_min' => 'protein range start',
            'items.*.protein_g_max' => 'protein range end',
            'items.*.carbs_g_min'   => 'carbs range start',
            'items.*.carbs_g_max'   => 'carbs range end',
            'items.*.fat_g_min'     => 'fat range start',
            'items.*.fat_g_max'     => 'fat range end',
        ];
    }
}
