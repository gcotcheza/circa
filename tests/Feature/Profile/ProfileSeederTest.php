<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use Tests\TestCase;
use App\Models\Profile;
use Database\Seeders\ProfileSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The one exception to "no seeder writes to `profiles`", held to its boundaries.
 *
 * Fifteen of the sixteen profile columns are questions about a person, and an
 * app that answers one on somebody's behalf is inventing a fact about their
 * body. The goal is different in kind: not derived from this database but
 * stated out loud by the only person this app has — seeding a sentence somebody
 * said is transcription, inferring one would not be. So this file keeps the
 * exception one field wide and stops a deploy arguing with the profile screen.
 */
final class ProfileSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_goal_in_the_owners_own_words(): void
    {
        $this->seed(ProfileSeeder::class);

        $profile = Profile::current();

        self::assertSame('Athletic build', $profile->goal);
        self::assertStringContainsString('five-a-side football', (string) $profile->goal_notes);
        self::assertStringContainsString('returning to training, not starting from zero', (string) $profile->goal_notes);
    }

    /**
     * THE BOUNDARY: everything else is a question nobody has answered —
     * including the height this app could work out from the scale's own BMI.
     */
    public function test_it_writes_nothing_else_at_all(): void
    {
        $this->seed(ProfileSeeder::class);

        $profile = Profile::current();

        foreach (Profile::EDITABLE as $field) {
            if (in_array($field, ['goal', 'goal_notes'], true)) {
                continue;
            }

            self::assertNull($profile->getAttribute($field), "ProfileSeeder wrote {$field}, which is not its business.");
        }

        // An athletic goal is not a scale number: the report reads body composition.
        self::assertNull($profile->target_weight_kg);
    }

    /**
     * It runs on every deploy, so a second run must not undo an edit — the same
     * rule SupplementSeeder states about label figures.
     */
    public function test_a_second_run_leaves_an_edited_goal_alone(): void
    {
        $this->seed(ProfileSeeder::class);

        Profile::put([
            ...$this->currentAttributes(),
            'goal'       => 'Maintain',
            'goal_notes' => 'Changed my mind.',
        ]);

        $this->seed(ProfileSeeder::class);

        self::assertSame('Maintain', Profile::current()->goal);
        self::assertSame('Changed my mind.', Profile::current()->goal_notes);
    }

    public function test_running_it_twice_is_a_no_op_and_leaves_one_row(): void
    {
        $this->seed(ProfileSeeder::class);
        $this->seed(ProfileSeeder::class);

        self::assertSame(1, Profile::query()->count());
        self::assertSame('Athletic build', Profile::current()->goal);
    }

    /**
     * A deploy must not clear a date of birth typed last week: the write path
     * is a whole-row put, so unowned fields have to be carried across.
     */
    public function test_it_does_not_clear_a_profile_somebody_has_already_filled_in(): void
    {
        Profile::put([
            'date_of_birth'          => '1990-08-07',
            'sex'                    => 'female',
            'height_cm'              => '160.0',
            'allergies_intolerances' => 'Peanuts.',
        ]);

        $this->seed(ProfileSeeder::class);

        $profile = Profile::current();

        self::assertSame('1990-08-07', $profile->date_of_birth?->toDateString());
        self::assertSame('female', $profile->sex);
        self::assertSame(160.0, $profile->heightCm());
        self::assertSame('Peanuts.', $profile->allergies_intolerances);

        // And the goal it came for is there.
        self::assertSame('Athletic build', $profile->goal);
    }

    /**
     * The goal is text with taps over it so it can be their phrase rather than
     * the nearest enum member: "Gain muscle" is close, and not what they said.
     */
    public function test_the_goal_is_their_phrase_and_not_a_category(): void
    {
        $this->seed(ProfileSeeder::class);

        self::assertNotSame('gain muscle', mb_strtolower((string) Profile::current()->goal));
    }

    /**
     * @return array<string, mixed>
     */
    private function currentAttributes(): array
    {
        $profile = Profile::current();

        $attributes = [];

        foreach (Profile::EDITABLE as $field) {
            $attributes[$field] = $profile->getAttribute($field);
        }

        return $attributes;
    }
}
