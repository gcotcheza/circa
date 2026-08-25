<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Meals\ConsumptionShare;

/**
 * What the review sheet is holding when the user asks the model again:
 * figures they corrected by hand and have not confirmed.
 *
 * Optional on both re-ask paths — absent means "work it out from what is
 * stored" — and authoritative when present, because the sheet is the only
 * place that knows what has been typed since the last answer, and an
 * unconfirmed correction is still a correction.
 *
 * ONE VALUE PER CLAIM, not the min/max pair the sheet displays: a range is
 * a question and this list is answers. That is why this is a third shape
 * rather than a reuse — ValidatesMealItems speaks per-100g densities (what
 * gets SAVED) and MealProposalRequest speaks paired bands (what the model
 * PROPOSED), and collapsing any two of the three would make one path
 * accept a meal another could not express. The ceilings are the shared
 * absolutes, so a figure stated here is one the save would also take.
 */
trait ValidatesSheetGivens
{
    /** @return array<string, mixed> */
    protected function sheetGivensRules(): array
    {
        return [
            'items'                  => ['sometimes', 'array', 'min:1', 'max:40'],
            'items.*.name'           => ['required', 'string', 'max:191'],
            'items.*.share_fraction' => ['nullable', 'numeric', 'min:'.ConsumptionShare::MIN, 'max:1'],

            'items.*.portion_g' => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_portion_g')],
            'items.*.kcal'      => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_kcal_absolute')],
            'items.*.protein_g' => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_absolute')],
            'items.*.carbs_g'   => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_absolute')],
            'items.*.fat_g'     => ['nullable', 'numeric', 'min:0', 'max:'.config('health.food.max_macro_absolute')],
        ];
    }
}
