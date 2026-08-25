<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Profile;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The profile form, as the user left it.
 *
 * Every rule starts with `nullable` — this form fills in over months, in
 * whatever order somebody likes, and no field's absence should block a
 * save. A profile with only a date of birth beats no profile at all, and
 * refusing it would teach the user to type something plausible into a box
 * they didn't want to answer, which is worse than an empty one.
 *
 * The comma, again: this app's keypad is Dutch, whose decimal key is a
 * COMMA. `resources/js/lib/format.js` normalises it client-side, and
 * `prepareForValidation` does it again here — not distrust of the front
 * end, but because `numeric` on "160,5" fails visibly, and this endpoint
 * is reachable by anything holding a session, not only that form.
 *
 * Ceilings are plausibility, not validation of a human: `height_cm`
 * 50-260 and `target_weight_kg` 20-400 catch a typo like 1600 producing a
 * BMI of 0.2, deliberately far outside anything real so the bound never
 * argues with a genuine measurement. Nothing else has a shape rule —
 * `sex`, `goal`, `smoking`, `sun_exposure` read like enumerations but stay
 * text, since the consumer is a language model reading prose, not an integer.
 */
final class ProfileRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            // about you
            /*
             * The date, not an age. `before_or_equal:today` because a future birth
             * date is the only genuinely impossible answer here; the 1900 floor
             * catches the year typed as 1085.
             */
            'date_of_birth' => ['nullable', 'date', 'after_or_equal:1900-01-01', 'before_or_equal:today'],
            'sex'           => ['nullable', 'string', 'max:40'],
            'height_cm'     => ['nullable', 'numeric', 'min:50', 'max:260'],
            'ethnicity'     => ['nullable', 'string', 'max:120'],
            'country'       => ['nullable', 'string', 'max:120'],

            // goals
            'goal'             => ['nullable', 'string', 'max:60'],
            'target_weight_kg' => ['nullable', 'numeric', 'min:20', 'max:400'],
            'goal_notes'       => ['nullable', 'string', 'max:500'],

            // diet
            'dietary_preferences'    => ['nullable', 'string', 'max:1000'],
            'allergies_intolerances' => ['nullable', 'string', 'max:1000'],

            // lifestyle
            'smoking'          => ['nullable', 'string', 'max:60'],
            'alcohol'          => ['nullable', 'string', 'max:200'],
            'sun_exposure'     => ['nullable', 'string', 'max:40'],
            'activity_context' => ['nullable', 'string', 'max:500'],

            // health context
            'life_stage' => ['nullable', 'string', 'max:120'],
            /*
             * Roomier than the rest — the one box somebody might genuinely need a
             * paragraph for, and a truncated sentence about a medication is worse
             * than a long one.
             */
            'health_notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * Sentences, because that is what appears under the box.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'date_of_birth.before_or_equal' => 'A date of birth cannot be in the future.',
            'date_of_birth.date'            => 'That did not read as a date. Use the picker, or type it as 1990-04-23.',
            'height_cm.numeric'             => 'Height should be a number of centimetres, like 160 or 160,5.',
            'height_cm.min'                 => 'That looks like metres. Height goes in centimetres — 160, not 1,6.',
            'height_cm.max'                 => 'That is taller than anyone has ever been. Height goes in centimetres.',
            'target_weight_kg.numeric'      => 'A target weight should be a number of kilos, like 54 or 54,5.',
        ];
    }

    /**
     * The row to write, one key per editable column.
     *
     * Built off `Profile::EDITABLE`, not whatever the request happened to
     * contain, so an unposted field is written as null rather than silently
     * keeping an old value — CLEARING a box is a real edit, and the commonest
     * way that breaks is a save path that only writes what it was sent.
     *
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        $validated = $this->validated();

        $attributes = [];

        foreach (Profile::EDITABLE as $field) {
            /** @var mixed $value */
            $value = $validated[$field] ?? null;

            $attributes[$field] = $value;
        }

        return $attributes;
    }

    /**
     * Blanks become nulls, and the comma becomes a point.
     *
     * An empty box means "I have not answered this" — a null. Left as `''` it
     * would be a stored empty string that `isBlank()` and the report would
     * both have to treat as present-but-empty, a third state nothing wants.
     */
    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach (Profile::EDITABLE as $field) {
            if (! $this->has($field)) {
                continue;
            }

            /** @var mixed $raw */
            $raw = $this->input($field);

            if (is_string($raw)) {
                $raw = trim($raw);
            }

            if ($raw === '' || $raw === null) {
                $clean[$field] = null;

                continue;
            }

            $clean[$field] = in_array($field, ['height_cm', 'target_weight_kg'], true) && is_string($raw)
                ? str_replace(',', '.', $raw)
                : $raw;
        }

        $this->merge($clean);
    }
}
