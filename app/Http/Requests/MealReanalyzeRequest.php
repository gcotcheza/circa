<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Meals\ConsumptionShare;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\NamesMealItems;
use App\Http\Requests\Concerns\ValidatesSheetGivens;
use App\Http\Requests\Concerns\ValidatesIdempotencyKey;

/**
 * "Read this plate again."
 *
 * A NEW key and `vision_requests` row, for the reason MealReestimateRequest
 * gives. The plate's note and share ride along because the two taps are
 * independent: without them a fresh answer would come back unhinted and at
 * full size, making the user say both things twice.
 *
 * Sheet figures sent here SURVIVE the fresh answer (AnalyzeMealPhoto's
 * `preserve()`), which is possible because rows store densities — pinning
 * a portion rescales that item's totals coherently rather than pasting a
 * number over a different estimate.
 */
final class MealReanalyzeRequest extends FormRequest
{
    use NamesMealItems;
    use ValidatesIdempotencyKey;
    use ValidatesSheetGivens;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'idempotency_key' => $this->idempotencyKeyRules(),

            // Absent leaves the plate's existing note alone; an explicit
            // empty string clears it. `share_fraction` below reads the same
            // way, and the controller depends on the distinction.
            'hint' => ['nullable', 'string', 'max:500'],

            'share_fraction' => ['nullable', 'numeric', 'min:'.ConsumptionShare::MIN, 'max:1'],

            ...$this->sheetGivensRules(),
        ];
    }
}
