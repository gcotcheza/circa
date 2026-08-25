<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Tests\TestCase;
use App\Models\Profile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The one row this table will ever have.
 *
 * Most of this is boring schema testing. The three doing work:
 *
 *   NOTHING IS REQUIRED — an all-null profile is its state the moment the page
 *   is first opened, and every consumer reads a null as "not answered".
 *
 *   IT IS A SINGLETON, AND THE PRIMARY KEY SAYS SO. `firstOrNew()` then `save()`
 *   leaves a window for two profiles, and nothing here can read a second one.
 *
 *   THE ARITHMETIC IS THE MODEL'S JOB, NOT THE REPORT'S: age, BMI and the gap to
 *   a target are computed in PHP because the report rests on the model never
 *   doing arithmetic, so the sums must be right where there are tests.
 */
final class ProfileSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_table_has_every_column_the_form_writes(): void
    {
        self::assertTrue(Schema::hasTable('profiles'));

        self::assertTrue(Schema::hasColumns('profiles', [
            ...Profile::EDITABLE,
            'created_at',
            'updated_at',
        ]));
    }

    /** The state this table spends its first weeks in. */
    public function test_a_profile_with_nothing_in_it_saves(): void
    {
        $profile = Profile::put([]);

        self::assertTrue($profile->exists);
        self::assertTrue($profile->refresh()->isBlank());

        foreach (Profile::EDITABLE as $field) {
            self::assertNull($profile->getAttribute($field), "{$field} should have saved as null.");
        }
    }

    /** `current()` never returns null: consumers read empty fields rather than branch on a row existing. */
    public function test_current_hands_back_an_unsaved_blank_when_nothing_has_been_filled_in(): void
    {
        $profile = Profile::current();

        self::assertInstanceOf(Profile::class, $profile);
        self::assertFalse($profile->exists);
        self::assertTrue($profile->isBlank());
        self::assertNull($profile->ageOn('2026-08-09'));
        self::assertNull($profile->bmiFor(56.2));
    }

    /** Save twice, one profile. The id is the constraint. */
    public function test_saving_twice_updates_the_one_row_rather_than_adding_another(): void
    {
        Profile::put(['sex' => 'female', 'height_cm' => '160.0']);
        Profile::put(['sex' => 'female', 'height_cm' => '161.0']);

        self::assertSame(1, Profile::query()->count());
        self::assertSame(Profile::SINGLETON_ID, Profile::current()->id);
        self::assertSame(161.0, Profile::current()->heightCm());
    }

    /** Clearing a box is a real edit; a save path that writes only the fields it was sent breaks it. */
    public function test_a_field_can_be_cleared_back_to_null(): void
    {
        Profile::put(['ethnicity' => 'Mixed heritage']);

        Profile::put(['ethnicity' => null]);

        self::assertNull(Profile::current()->ethnicity);
    }

    /** decimal, not float: a height of 160.5 shown back as 160.50000000000001 is a bug report. */
    public function test_a_half_centimetre_survives_the_round_trip(): void
    {
        Profile::put(['height_cm' => '160.5', 'target_weight_kg' => '54.5']);

        self::assertSame(160.5, Profile::current()->heightCm());
        self::assertSame(54.5, Profile::current()->targetWeightKg());
    }

    /** Completed birthdays on a date the caller names — the end of a report's range, never `now()`. */
    public function test_the_age_counts_completed_birthdays(): void
    {
        $profile = Profile::put(['date_of_birth' => '1990-08-07']);

        self::assertSame(36, $profile->ageOn('2026-08-09'));
        self::assertSame(36, $profile->ageOn('2026-08-07'));

        // The day before the birthday is still the year before.
        self::assertSame(35, $profile->ageOn('2026-08-06'));
        self::assertSame(35, $profile->ageOn('2026-01-01'));
    }

    /**
     * The case hand-rolled age arithmetic gets wrong — and it is hand-rolled on
     * purpose: "years between two dates" has three plausible answers, and this
     * app means completed birthdays.
     */
    public function test_a_leap_day_birthday_turns_a_year_older_on_the_first_of_march(): void
    {
        $profile = Profile::put(['date_of_birth' => '2000-02-29']);

        self::assertSame(25, $profile->ageOn('2026-02-28'));
        self::assertSame(26, $profile->ageOn('2026-03-01'));
    }

    public function test_the_bmi_needs_both_halves_and_rounds_to_one_decimal(): void
    {
        $profile = Profile::put(['height_cm' => '160.0']);

        // 56.2 / 1.60^2 = 21.953…
        self::assertSame(22.0, $profile->bmiFor(56.2));

        self::assertNull($profile->bmiFor(null));
        self::assertNull(Profile::put(['height_cm' => null])->bmiFor(56.2));
    }

    /**
     * Positive is above the target, signed once here, with the snapshot stating
     * the convention beside the figure: two conventions is one read backwards.
     */
    public function test_the_gap_to_the_target_is_signed_and_needs_both_halves(): void
    {
        $profile = Profile::put(['target_weight_kg' => '54.0']);

        self::assertSame(2.2, $profile->kgFromTarget(56.2));
        self::assertSame(-1.0, $profile->kgFromTarget(53.0));
        self::assertNull($profile->kgFromTarget(null));

        self::assertNull(Profile::put(['target_weight_kg' => null])->kgFromTarget(56.2));
    }

    /** One filled box stops it being blank; the report frames what it can and says so about the rest. */
    public function test_one_answered_question_is_enough_to_stop_being_blank(): void
    {
        self::assertFalse(Profile::put(['sun_exposure' => 'low'])->isBlank());
    }

    /**
     * `users` holds a hash and an email; nothing about a body, and the profile
     * form cannot reach it. That is why this is a table of its own.
     */
    public function test_the_profile_is_not_hung_off_the_users_table(): void
    {
        self::assertFalse(Schema::hasColumn('profiles', 'user_id'));

        foreach (['date_of_birth', 'height_cm', 'health_notes'] as $column) {
            self::assertFalse(
                Schema::hasColumn('users', $column),
                "users gained a {$column} column; the profile belongs on its own table."
            );
        }
    }

    /**
     * The factory invents nothing, which no other factory here can say: the
     * subject is what one person has and has not answered, so defaults would mean
     * every test ran against a populated profile, the empty-profile ones included.
     */
    public function test_the_factory_makes_an_empty_profile(): void
    {
        self::assertTrue(Profile::factory()->create()->isBlank());
    }

    /**
     * `filled()` is for a test that wants any profile. Separate, because the
     * factory builds the SINGLETON: two `create()` calls are two inserts of the
     * same primary key — the constraint working, not a bug.
     */
    public function test_the_filled_state_populates_every_field(): void
    {
        self::assertFalse(Profile::factory()->filled()->create()->isBlank());
    }

    /**
     * `Profile::EDITABLE` is what the form request, the writer and the report's
     * blank check iterate over. A column missing from it is one nothing can
     * write; a name left after a column was dropped is a silent write failure.
     */
    public function test_the_editable_list_is_exactly_the_columns_this_table_has(): void
    {
        $columns = Schema::getColumnListing('profiles');

        $expected = ['id', ...Profile::EDITABLE, 'created_at', 'updated_at'];

        sort($columns);
        sort($expected);

        self::assertSame($expected, $columns);
    }
}
