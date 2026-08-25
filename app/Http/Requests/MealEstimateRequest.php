<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\MealType;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\NamesMealItems;
use App\Http\Requests\Concerns\ComposesEatenAt;
use App\Http\Requests\Concerns\ValidatesMealItems;
use App\Http\Requests\Concerns\ValidatesIdempotencyKey;

/**
 * "Save it, and estimate what I left blank."
 *
 * The same payload MealRequest takes, plus the `idempotency_key` the photo
 * upload takes: the same meal write, and the same claim on one paid
 * analysis.
 *
 * Two client-generated ids, different jobs:
 *
 *   `uuid`            identifies the MEAL. A retry lands on the meal it
 *                     already created, as on every other entry path.
 *   `idempotency_key` identifies the ESTIMATE. `vision_requests` is unique
 *                     on it, so a double-tap or offline-queue replay costs
 *                     one Anthropic call, not two. Asking again later
 *                     deliberately sends a NEW key.
 *
 * The item rules are the shared ones, which is the point: what the model
 * is asked about is precisely what would have been saved. A separate rule
 * set here would let the two paths accept different meals.
 */
final class MealEstimateRequest extends FormRequest
{
    use ComposesEatenAt;
    use NamesMealItems;
    use ValidatesIdempotencyKey;
    use ValidatesMealItems;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],

            'idempotency_key' => $this->idempotencyKeyRules(),

            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],

            'meal_type' => ['nullable', Rule::enum(MealType::class)],
            'notes'     => ['nullable', 'string', 'max:2000'],

            ...$this->itemRules(),
        ];
    }
}
