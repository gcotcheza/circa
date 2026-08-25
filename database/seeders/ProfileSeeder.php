<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Profile;
use Illuminate\Database\Seeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;

/**
 * The goal, in the owner's own words — and nothing else in the profile.
 *
 * THE ONE EXCEPTION TO "SEED NOTHING": the migration is emphatic that no
 * seeder writes to `profiles` — every column is a question about a
 * person, and answering one on their behalf invents a fact about
 * somebody's body. True for fifteen of sixteen columns, including the
 * tempting one (height, derivable from the scale's BMI, deliberately
 * isn't).
 *
 * The goal differs in kind — not derived or guessed, but STATED, out
 * loud, by the only person this app has:
 *
 *     "I want an athletic build again. I played club football for years
 *      and I have let it all slide."
 *
 * Seeding a stated sentence is transcription, like SupplementSeeder does
 * with a printed panel; inferring one would not be. `target_weight_kg`
 * stays NULL — no number was given, and an athletic goal isn't one
 * anyway; the report reads `body.composition` instead.
 *
 * FILLS BLANKS, NEVER OVERWRITES: runs every deploy, so a rerun must not
 * undo an edit (same rule as SupplementSeeder's label figures — by then
 * the person may have changed their mind). Each field writes only where
 * currently empty: change the goal and the next deploy leaves it; clear
 * it and the next deploy restores this default.
 */
final class ProfileSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Their words, not a category. `goal` is a text column with taps
     * offered over it precisely so this can be "Athletic build" rather
     * than the nearest enum member — "Gain muscle" is close and isn't
     * what they said, and reaching a language model as prose is how that
     * difference survives.
     */
    private const GOAL = 'Athletic build';

    /**
     * The history behind the goal — it changes what a good suggestion
     * looks like. "Get an athletic build" from a beginner and from a
     * former club athlete are the same words and different requests;
     * this note tells the report which one it's reading (a strong base,
     * a year mostly off it, a 10 km road race in April on almost
     * nothing — returning, not beginning and not currently in form).
     *
     * The prompt is taught to read a note like this, NOT taught this
     * story: instructions cover what a returning athlete needs, the
     * particular athlete lives here in the data, editable on the
     * profile screen without a deploy.
     */
    private const GOAL_NOTES = 'Former club-level five-a-side football player, and a '
        .'regular in the gym before that. Last year focused on work and career with almost '
        .'no training aside from occasional cycling; in April completed a 10 km road race '
        .'with almost no specific preparation. Goal is an athletic build — returning to '
        .'training, not starting from zero.';

    public function run(): void
    {
        $profile = Profile::current();

        $attributes = [];

        if ($this->isEmpty($profile->goal)) {
            $attributes['goal'] = self::GOAL;
        }

        if ($this->isEmpty($profile->goal_notes)) {
            $attributes['goal_notes'] = self::GOAL_NOTES;
        }

        // Already answered, in their own last words — nothing to do or say,
        // same as SupplementSeeder: a seeder quiet when it has no news.
        if ($attributes === []) {
            return;
        }

        /*
         * `put()` writes the whole row, so untouched fields must be carried
         * across explicitly, read off the existing profile — what makes
         * this safe beside a half-filled form: a deploy must not clear a
         * date of birth somebody typed last week.
         */
        $carried = [];

        foreach (Profile::EDITABLE as $field) {
            $carried[$field] = $profile->getAttribute($field);
        }

        Profile::put([...$carried, ...$attributes]);
    }

    private function isEmpty(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
