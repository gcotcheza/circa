<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Ramsey\Uuid\Uuid;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\ComposesEatenAt;
use App\Http\Requests\Concerns\ValidatesPhotoUpload;
use App\Http\Requests\Concerns\ValidatesIdempotencyKey;

/**
 * The photo upload that starts a vision analysis.
 *
 * Three client-generated ids, doing different jobs:
 *
 *   `uuid`            identifies the MEAL — a retry lands on the meal it
 *                     already created, and a second plate posted with the
 *                     same uuid joins the dinner rather than starting a new one.
 *   `client_id`       identifies the PHOTO. `meal_photos` is unique on it, so
 *                     three plates flushing from the offline queue are three
 *                     rows, and the same plate flushing twice is one — the
 *                     meal uuid is now deliberately the same across all three.
 *   `idempotency_key` identifies the ANALYSIS. `vision_requests` is unique on
 *                     it, so a double-tap or an offline replay costs one
 *                     Anthropic call, not two. Re-analysing sends a NEW key —
 *                     that's the difference between "again" and "the same
 *                     request arriving twice", which neither other id expresses.
 *
 * `date` + `time` rather than a timestamp, composed server-side: see
 * ComposesEatenAt.
 */
final class MealPhotoRequest extends FormRequest
{
    use ComposesEatenAt;
    use ValidatesIdempotencyKey;
    use ValidatesPhotoUpload;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'uuid' => ['required', 'uuid'],

            'idempotency_key' => $this->idempotencyKeyRules(),

            /*
             * Nullable, and `clientId()` falls back to the analysis key. A photo
             * queued in IndexedDB by the previous app version carries no `client_id`
             * (predates the column); rejecting it would lose a meal somebody watched
             * go in — the one failure the offline queue exists to prevent.
             */
            'client_id' => ['nullable', 'uuid'],

            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],

            /*
             * What the user already knows about this plate — "3 eggs, 120 g drained
             * tuna". Nullable, and that's the whole design: a required hint would turn
             * a two-tap camera into a form, and the photo path's advantage over the
             * text path is that it asks for nothing. Most uploads carry none.
             *
             * Bounded at 500 characters because it's a HINT, not the meal — someone
             * wanting to describe the whole dinner already has a better path (POST
             * /api/meals/{uuid}/estimate), and the ceiling stops the prompt's own
             * instruction being outweighed by a paragraph of somebody else's.
             */
            'hint' => ['nullable', 'string', 'max:500'],

            'photo' => $this->photoRules(),
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return $this->photoMessages();
    }

    /**
     * The photo's own id, or the analysis key standing in for a queued upload
     * written before this column existed.
     *
     * Deterministic either way: the same replay produces the same id, which is
     * the whole point of both.
     */
    public function clientId(): string
    {
        $clientId = $this->string('client_id')->value();

        if ($clientId !== '') {
            return $clientId;
        }

        // Derived, not random, so the SAME legacy replay lands on the same row every
        // time. v5 uuid because `client_id` is a uuid column but the analysis key isn't.
        return Uuid::uuid5(Uuid::NAMESPACE_URL, $this->string('idempotency_key')->value())->toString();
    }

    /**
     * The note, or null when the box was left empty.
     *
     * An empty string and an absent field are the same thing — a `<input>` the
     * user tabbed through posts `""` — and both must reach the column as NULL:
     * `''` is a hint that exists and says nothing, and would make `hint IS NOT
     * NULL` stop meaning "the user told us something".
     */
    public function hint(): ?string
    {
        $hint = trim($this->string('hint')->value());

        return $hint === '' ? null : $hint;
    }
}
