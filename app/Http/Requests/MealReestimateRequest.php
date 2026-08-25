<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\NamesMealItems;
use App\Http\Requests\Concerns\ValidatesSheetGivens;
use App\Http\Requests\Concerns\ValidatesIdempotencyKey;

/**
 * "Estimate the blanks on this meal again."
 *
 * A NEW key each time, and so a new `vision_requests` row: "try again" and
 * "it arrived twice" are intentions the audit table must be able to tell
 * apart, which is the whole reason the key is client-generated.
 *
 * `items` absent is not an empty meal — it means "work it out from what is
 * stored", which MealEstimateController::typedFrom does rather more
 * carefully than a rule could.
 */
final class MealReestimateRequest extends FormRequest
{
    use NamesMealItems;
    use ValidatesIdempotencyKey;
    use ValidatesSheetGivens;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKeyRules(),

            ...$this->sheetGivensRules(),
        ];
    }
}
