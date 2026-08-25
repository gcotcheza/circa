<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\ValidatesPhotoUpload;
use App\Http\Requests\Concerns\ValidatesIdempotencyKey;

/**
 * The label photograph that starts a reading.
 *
 * TWO CLIENT-GENERATED IDS, doing the same two jobs as on the meal path:
 * `client_id` identifies the PHOTOGRAPH — it's the file name on disk and
 * the handle the review screen polls, so a replayed upload writes the same
 * bytes to the same place and lands back on the reading already started.
 * `idempotency_key` identifies the READING — `vision_requests` is unique
 * on it, so a double-tap costs one Anthropic call, and "read it again"
 * deliberately sends a NEW key, the difference between "again" and "the
 * same request arriving twice" that neither id can express alone.
 *
 * No `date`/`time` pair, unlike MealPhotoRequest: a meal belongs to a day,
 * a bottle does not.
 */
final class SupplementLabelRequest extends FormRequest
{
    use ValidatesIdempotencyKey;
    use ValidatesPhotoUpload;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'uuid'],

            'idempotency_key' => $this->idempotencyKeyRules(),

            'photo' => $this->photoRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->photoMessages();
    }
}
