<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Profile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A profile, with nothing in it.
 *
 * WHY `definition()` IS EMPTY, UNLIKE EVERY OTHER FACTORY: a meal or
 * weigh-in with random values is still a meal or weigh-in, but a
 * profile's whole subject is what one specific person has and hasn't
 * told the app — defaulting a sex and DOB would mean every test ran
 * against a fully-populated profile, including the tests whose point is
 * the empty one.
 *
 * Default stays blank; a test states exactly the fields it's about.
 * `->filled()` covers cases wanting a complete profile regardless of
 * values.
 *
 * @extends Factory<Profile>
 */
final class ProfileFactory extends Factory
{
    protected $model = Profile::class;

    /**
     * A precise shape rather than `array<string, mixed>`. Upstream
     * `Factory::definition()` returns `array<model property of Model,
     * mixed>`; every other factory here narrows to `array<string, mixed>`
     * and sits in the phpstan baseline for it. Nothing to narrow here —
     * the definition is one key — so stating it exactly costs nothing and
     * keeps the baseline from growing by one more of the same thing.
     *
     * @return array{id: int}
     */
    public function definition(): array
    {
        // The singleton id, so a factory-made profile is the one
        // `current()` finds — everything else stays null on purpose.
        return ['id' => Profile::SINGLETON_ID];
    }

    /**
     * One of everything, for a test that wants a profile rather than a
     * particular profile.
     */
    public function filled(): self
    {
        return $this->state(fn (): array => [
            'date_of_birth'          => '1990-04-23',
            'sex'                    => 'female',
            'height_cm'              => '160.0',
            'ethnicity'              => 'Mixed heritage',
            'country'                => 'Netherlands',
            'goal'                   => 'lose weight',
            'target_weight_kg'       => '54.0',
            'goal_notes'             => 'Slowly, no crash diets.',
            'dietary_preferences'    => 'Not much red meat.',
            'allergies_intolerances' => 'Peanut allergy.',
            'smoking'                => 'never',
            'alcohol'                => 'A glass of wine at weekends.',
            'sun_exposure'           => 'low',
            'activity_context'       => 'Desk job, cycles to the shops.',
            'life_stage'             => 'none',
            'health_notes'           => 'On a long-term PPI.',
        ]);
    }
}
