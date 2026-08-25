<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\ComposesEatenAt;

/**
 * One tap putting a meal that already exists onto the selected day — a
 * previous entry repeated, or a remembered one logged again.
 *
 * Nothing about the FOOD is posted: the source row supplies that, which is
 * what makes this one request for both paths. All it carries is where the
 * copy lands, plus the client-generated `uuid` that makes a retried tap
 * land on the same meal rather than a second copy of dinner.
 */
final class RelogRequest extends FormRequest
{
    use ComposesEatenAt;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
        ];
    }
}
