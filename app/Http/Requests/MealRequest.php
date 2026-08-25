<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MealType;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\NamesMealItems;
use App\Http\Requests\Concerns\ComposesEatenAt;
use App\Http\Requests\Concerns\ValidatesMealItems;

/**
 * Validation for manual meal entry, both create and edit.
 *
 * TWO ENTRY MODES, ONE STORED SHAPE: `meal_items` stores per-100g
 * densities and a portion, deriving absolutes. Typing "Cappuccino, 120
 * kcal" has no portion — nobody weighs a cappuccino — so quick mode is
 * stored as 100 g at 120 kcal/100 g: arithmetic comes out identical,
 * schema stays uniform, and switching to per-100g later needs no
 * migration.
 *
 * Conversion happens HERE, server-side, not in the Vue form, so both
 * modes share one tested definition, and an offline queue replaying a
 * six-hour-old payload can't run last version's arithmetic.
 *
 * Manual entries are ZERO-WIDTH (min == max on every density and
 * portion) — not a shortcut, but the honest claim that the user typed
 * a number and stands behind it. Vision (step 5) produces genuinely
 * wide items; the quadrature band handles a mix of both.
 *
 * THE NUMBERS ARE OPTIONAL, THE NAME IS NOT: item rules live in
 * ValidatesMealItems, shared with MealEstimateRequest so the saved
 * list and the list sent to the model share one definition. See that
 * trait for why requiring a kcal figure was wrong.
 *
 * `date` + `time`, not a timestamp, and composed server-side: see
 * ComposesEatenAt.
 */
final class MealRequest extends FormRequest
{
    use ComposesEatenAt;
    use NamesMealItems;
    use ValidatesMealItems;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // Client-generated, so an offline retry lands on the same
            // meal. Ignored on update (the row's already identified)
            // but still accepted, so the front end posts one shape.
            'uuid' => ['required', 'uuid'],

            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],

            'meal_type' => ['nullable', Rule::enum(MealType::class)],
            'notes'     => ['nullable', 'string', 'max:2000'],

            ...$this->itemRules(),
        ];
    }
}
