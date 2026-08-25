<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One tap on the supplements card.
 *
 * A PUT OF DESIRED STATE, NOT A POST OF AN EVENT: `{date, taken}`
 * rather than "record"/"delete an intake" makes this replay-safe by
 * construction — the same shape the offline queue relies on for a meal
 * confirm, so a flush sending it twice (two tabs, a race, a replay
 * from yesterday's IndexedDB) arrives at the same place.
 *
 * The unique index on (supplement_id, local_date) is the other half:
 * a second "taken: true" is a no-op instead of a second row, and a
 * second "taken: false" deletes something already gone instead of
 * 404ing, which the queue would treat as permanent and refuse to retry.
 *
 * `date` IS SENT BY THE CLIENT, NOT DERIVED FROM `now()`: the day view
 * has a date on it, and "I forgot to tick last night's" is the most
 * common reason to tap this at all. Filing every tap under today would
 * make the past permanently and silently unfixable.
 */
final class SupplementIntakeRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],

            // Explicit rather than inferred from the HTTP verb, so a
            // queued undo and tick are the same action with a
            // different body — no per-kind special case in the queue.
            'taken' => ['required', 'boolean'],
        ];
    }
}
