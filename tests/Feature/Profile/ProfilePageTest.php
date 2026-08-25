<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use Tests\TestCase;
use App\Models\User;
use App\Models\Profile;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use Tests\Support\StressFixture;
use Illuminate\Support\Facades\Hash;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The profile screen: two routes, one row, and a long list of things it is not
 * allowed to do.
 *
 * IT IS NOT AN ACCOUNT SCREEN. `/profile` writes `profiles` and nothing else —
 * the whole reason this is a separate table from `users`.
 *
 * AN EMPTY SAVE IS A VALID SAVE. Every rule is `nullable`: a form filled in over
 * months whose validator refused partial answers would teach the user to type
 * something plausible, which the report cannot tell from the truth.
 *
 * NOTHING IS EVER FILLED IN FOR THEM. The app can derive a height from the BMI
 * the scale sends beside every weigh-in; it says so and leaves the box empty, so
 * a later convenience cannot turn that derivation into a stated fact.
 */
final class ProfilePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/profile')->assertRedirect(route('login'));
        $this->put('/profile', [])->assertRedirect(route('login'));
    }

    /**
     * JSON callers get 401 rather than a followed redirect reading as success,
     * the same rule the app's other write routes hold to.
     */
    public function test_a_json_caller_gets_401_rather_than_the_login_page(): void
    {
        $this->getJson('/profile')->assertUnauthorized();
        $this->putJson('/profile', [])->assertUnauthorized();
    }

    public function test_the_page_renders_with_every_field_empty_before_anything_is_saved(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/profile')->assertOk()->assertInertia(
            fn ($page) => $page
                ->component('Profile')
                ->where('profile.dateOfBirth', null)
                ->where('profile.sex', null)
                ->where('profile.heightCm', null)
                ->where('profile.goal', null)
                ->where('profile.healthNotes', null)
                ->where('impliedHeightCm', null)
                ->etc()
        );
    }

    public function test_a_save_writes_the_row_and_comes_back_to_the_page(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/profile', [
            'date_of_birth'          => '1990-08-07',
            'sex'                    => 'female',
            'height_cm'              => '160',
            'ethnicity'              => 'Mixed heritage',
            'country'                => 'Netherlands',
            'goal'                   => 'Lose weight',
            'target_weight_kg'       => '54',
            'goal_notes'             => 'Slowly.',
            'dietary_preferences'    => 'Not much red meat.',
            'allergies_intolerances' => 'Peanuts.',
            'smoking'                => 'Never',
            'alcohol'                => 'A glass of wine at weekends.',
            'sun_exposure'           => 'Low',
            'activity_context'       => 'Desk job.',
            'life_stage'             => 'none',
            'health_notes'           => 'On a long-term PPI.',
        ])->assertRedirect(route('profile.edit'))->assertSessionHas('success');

        $profile = Profile::current();

        self::assertSame('1990-08-07', $profile->date_of_birth?->toDateString());
        self::assertSame('female', $profile->sex);
        self::assertSame(160.0, $profile->heightCm());
        self::assertSame('Mixed heritage', $profile->ethnicity);
        self::assertSame('Netherlands', $profile->country);
        self::assertSame('Lose weight', $profile->goal);
        self::assertSame(54.0, $profile->targetWeightKg());
        self::assertSame('Peanuts.', $profile->allergies_intolerances);
        self::assertSame('On a long-term PPI.', $profile->health_notes);
    }

    /** A blank form is a legitimate save — what a first visit posts on a curious tap. */
    public function test_saving_nothing_at_all_is_allowed(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/profile', [])->assertSessionHasNoErrors();

        self::assertTrue(Profile::current()->isBlank());
    }

    /**
     * THE DUTCH KEYPAD'S DECIMAL KEY IS A COMMA. `resources/js/lib/format.js`
     * normalises it out of the form and the request normalises it again — not
     * distrust, but `numeric` on "160,5" fails with "the height must be a
     * number" about a number on screen, and this endpoint has other callers.
     */
    public function test_a_comma_decimal_is_read_as_a_decimal(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/profile', ['height_cm' => '160,5', 'target_weight_kg' => '54,5'])
            ->assertSessionHasNoErrors();

        self::assertSame(160.5, Profile::current()->heightCm());
        self::assertSame(54.5, Profile::current()->targetWeightKg());
    }

    /**
     * An emptied box is an unanswered question, not an empty string — a third
     * state `isBlank()` and the report would both have to know about.
     */
    public function test_clearing_a_box_stores_a_null_rather_than_an_empty_string(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/profile', ['ethnicity' => 'Mixed heritage', 'sex' => 'female']);
        $this->put('/profile', ['ethnicity' => '', 'sex' => '   ']);

        $profile = Profile::current();

        self::assertNull($profile->ethnicity);
        self::assertNull($profile->sex);
        self::assertTrue($profile->isBlank());
    }

    /**
     * A field the form did not post is written null: the form posts every field
     * it owns, so an absent one means cleared. Merging instead is how "I deleted
     * that and it came back" happens.
     */
    public function test_a_field_the_form_did_not_send_is_cleared_rather_than_kept(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/profile', ['ethnicity' => 'Mixed heritage', 'country' => 'Netherlands']);
        $this->put('/profile', ['country' => 'Netherlands']);

        self::assertNull(Profile::current()->ethnicity);
        self::assertSame('Netherlands', Profile::current()->country);
    }

    public function test_two_saves_leave_one_profile(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put('/profile', ['sex' => 'female']);
        $this->put('/profile', ['sex' => 'female', 'country' => 'Netherlands']);

        self::assertSame(1, Profile::query()->count());
    }

    /**
     * The plausibility bounds are deliberately far outside anything real: they
     * catch a height typed in metres or with an extra zero, not a measurement.
     */
    public function test_the_implausible_is_refused_with_a_sentence(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/profile')->put('/profile', ['height_cm' => '1,6'])
            ->assertSessionHasErrors('height_cm');

        $this->from('/profile')->put('/profile', ['height_cm' => '1600'])
            ->assertSessionHasErrors('height_cm');

        $this->from('/profile')->put('/profile', ['height_cm' => 'about five foot'])
            ->assertSessionHasErrors('height_cm');

        // Nothing was written by any of them.
        self::assertNull(Profile::current()->height_cm);
    }

    public function test_a_date_of_birth_in_the_future_is_refused(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/profile')
            ->put('/profile', ['date_of_birth' => CarbonImmutable::now()->addDay()->toDateString()])
            ->assertSessionHasErrors('date_of_birth');

        $this->from('/profile')
            ->put('/profile', ['date_of_birth' => '1085-04-23'])
            ->assertSessionHasErrors('date_of_birth');
    }

    public function test_an_over_long_note_is_refused_rather_than_truncated(): void
    {
        $this->actingAs(User::factory()->create());

        $this->from('/profile')
            ->put('/profile', ['health_notes' => str_repeat('a', 2001)])
            ->assertSessionHasErrors('health_notes');
    }

    /**
     * THE CLAIM THIS TABLE EXISTS FOR: `/profile` cannot touch the account.
     * There is no registration, password reset or email change anywhere in this
     * app, and a form about a body must not become the exception by accident.
     */
    public function test_the_profile_form_cannot_reach_the_account(): void
    {
        $user = User::factory()->create([
            'email'    => 'owner@example.test',
            'password' => Hash::make('the-original-password'),
        ]);

        $this->actingAs($user);

        $this->put('/profile', [
            'sex'      => 'female',
            'email'    => 'attacker@example.test',
            'password' => 'a-new-password',
            'name'     => 'Somebody Else',
        ]);

        $user->refresh();

        self::assertSame('owner@example.test', $user->email);
        self::assertTrue(Hash::check('the-original-password', $user->password));
        self::assertNotSame('Somebody Else', $user->name);

        // And nothing stray was written to the profile either.
        self::assertSame('female', Profile::current()->sex);
    }

    /**
     * THE HINT THAT IS NOT AN ANSWER. BMI plus weight is height; the app works
     * it out, shows it, and leaves the box empty, because filling it in would
     * state a fact about somebody's body they never told it — the confident
     * invention the report's design exists to prevent.
     */
    public function test_the_scale_implies_a_height_and_the_app_still_does_not_fill_it_in(): void
    {
        $this->actingAs(User::factory()->create());

        $at = CarbonImmutable::parse('2026-08-06 07:15:00', StressFixture::TZ)->utc();

        // 56.3 kg at a BMI of 22.0 is 1.5997 m.
        HealthMetric::factory()->metric('body_mass_index')->fromSource(StressFixture::watch())->create([
            'value' => '22.000000', 'started_at' => $at, 'ended_at' => $at,
        ]);

        HealthMetric::factory()->metric('weight_body_mass')->fromSource(StressFixture::watch())->create([
            'value' => '56.300000', 'started_at' => $at, 'ended_at' => $at,
        ]);

        $this->get('/profile')->assertOk()->assertInertia(
            fn ($page) => $page
                ->where('impliedHeightCm', 160)
                // The assertion that matters: a hint on screen, nothing stored.
                ->where('profile.heightCm', null)
                ->etc()
        );

        self::assertTrue(Profile::current()->isBlank());
    }

    /**
     * A BMI with no same-day weight implies nothing: this morning's weight with
     * last month's BMI would be two different bodies.
     */
    public function test_there_is_no_implied_height_without_both_readings_on_one_day(): void
    {
        $this->actingAs(User::factory()->create());

        $at = CarbonImmutable::parse('2026-08-06 07:15:00', StressFixture::TZ)->utc();

        HealthMetric::factory()->metric('body_mass_index')->fromSource(StressFixture::watch())->create([
            'value' => '22.000000', 'started_at' => $at, 'ended_at' => $at,
        ]);

        $this->get('/profile')->assertOk()->assertInertia(
            fn ($page) => $page->where('impliedHeightCm', null)->etc()
        );
    }

    /** What is on the page after a save is what is in the database. */
    public function test_the_saved_profile_comes_back_to_the_form(): void
    {
        $this->actingAs(User::factory()->create());

        Profile::put(['date_of_birth' => '1990-08-07', 'height_cm' => '160.5', 'goal' => 'Maintain']);

        $this->get('/profile')->assertOk()->assertInertia(
            fn ($page) => $page
                ->where('profile.dateOfBirth', '1990-08-07')
                ->where('profile.heightCm', 160.5)
                ->where('profile.goal', 'Maintain')
                ->etc()
        );
    }
}
