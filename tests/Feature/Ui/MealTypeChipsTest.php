<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\User;
use App\Enums\MealType;
use Illuminate\Support\Str;
use Tests\Concerns\ReadsSource;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * MEAL TYPE IS A CHIP ROW, NOT A DROPDOWN — and the wire is untouched.
 *
 * On iOS a `<select>` is a system popover covering half the sheet, two taps to
 * say "lunch". Four fixed options do not earn that, and both meal sheets
 * already answer questions of this shape with chips.
 *
 * The half a screenshot cannot show is that THE SERVER SEES NOTHING DIFFERENT —
 * same field, same four values, same `null` — so the assertions come in pairs:
 * the chips offer exactly the enum, the endpoint still takes exactly that.
 *
 * The structural half is read off the source, as ModelNoteVisibilityTest and
 * SheetGivensWiringTest do: an Inertia SPA with no component renderer leaves a
 * template claim no other way to be held.
 */
final class MealTypeChipsTest extends TestCase
{
    use ReadsSource;
    use RefreshDatabase;

    private const CHIPS = 'resources/js/Components/MealTypeChips.vue';

    private const EDIT_SHEET = 'resources/js/Components/MealSheet.vue';

    private const REVIEW_SHEET = 'resources/js/Components/ProposalReview.vue';

    // -----------------------------------------------------------------------
    // The control
    // -----------------------------------------------------------------------

    public function test_neither_sheet_opens_a_native_dropdown_any_more(): void
    {
        foreach ([self::EDIT_SHEET, self::REVIEW_SHEET] as $sheet) {
            $code = $this->sourceWithoutComments($sheet);

            self::assertStringNotContainsString(
                '<select',
                $code,
                $sheet.' is back to a native select. On a phone that is a half-screen popover.'
            );

            self::assertStringContainsString(
                '<MealTypeChips v-model="form.meal_type"',
                $code,
                $sheet.' no longer binds the chip row to the field it edits.'
            );
        }
    }

    /** The chips ARE the enum: a fifth case with no chip is a value nobody can set. */
    public function test_the_chips_offer_exactly_the_enum(): void
    {
        preg_match_all("/value: '([a-z_]+)'/", $this->sourceWithoutComments(self::CHIPS), $matches);

        self::assertSame(MealType::values(), $matches[1]);
    }

    /**
     * `—` was the select's "no meal type" and most meals are logged without
     * one, so tapping the lit chip is the way back to it.
     */
    public function test_the_lit_chip_clears_back_to_no_type(): void
    {
        $code = $this->sourceWithoutComments(self::CHIPS);

        self::assertStringContainsString(
            "emit('update:modelValue', props.modelValue === value ? null : value)",
            $code,
            self::CHIPS.': tapping the selected chip no longer clears the meal type, so a mis-tap is permanent.'
        );

        // And it is SAID, because the gesture is not guessable — while it is true.
        self::assertStringContainsString('Tap again to clear', $code);
        self::assertStringContainsString('v-if="modelValue !== null"', $code);
    }

    /**
     * The Time/Meal grid is load-bearing — see the comment above it and the
     * `input[type='time']` rules in app.css. The chips take a second ROW rather
     * than the second column: four words do not fit in half a phone.
     */
    public function test_the_time_field_keeps_the_half_width_track_the_webkit_fix_gave_it(): void
    {
        foreach ([self::EDIT_SHEET, self::REVIEW_SHEET] as $sheet) {
            $code = $this->sourceWithoutComments($sheet);

            // The box is written over several lines since it grew a blur check;
            // what this pins is the GRID it sits in, not how the tag is wrapped.
            self::assertMatchesRegularExpression(
                '/<div class="grid grid-cols-2 gap-2">\s*<label class="min-w-0">\s*'
                    .'<span[^>]*>Time<\/span>\s*<input\s[^>]*v-model="form\.time"[^>]*type="time"/s',
                $code,
                $sheet.': the Time field left the two-column grid, which is what keeps it in its half.'
            );

            self::assertStringContainsString('class="col-span-2 min-w-0"', $code);
        }
    }

    // -----------------------------------------------------------------------
    // The wire, unchanged
    // -----------------------------------------------------------------------

    public function test_the_form_still_starts_and_resets_at_no_meal_type(): void
    {
        $code = $this->sourceWithoutComments(self::EDIT_SHEET);

        // Twice: the initial `useForm` shape and a NEW meal's reset defaults. A
        // chip row starting at `''` would post a string the enum rule rejects.
        self::assertSame(2, substr_count($code, 'meal_type: null'));
        self::assertStringContainsString('meal_type: props.meal.mealType', $code);
    }

    public function test_the_server_still_takes_each_of_the_four(): void
    {
        $this->actingAs(User::factory()->create());

        foreach (MealType::values() as $value) {
            $this->post('/meals', $this->payload(['meal_type' => $value]))->assertRedirect();

            $meal = Meal::query()->latest('id')->firstOrFail();

            self::assertNotNull($meal->meal_type);
            self::assertSame($value, $meal->meal_type->value);
        }
    }

    /** And the state the `—` option used to be the only way to reach. */
    public function test_the_server_still_takes_no_meal_type_at_all(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post('/meals', $this->payload(['meal_type' => null]))->assertRedirect();

        self::assertNull(Meal::query()->latest('id')->firstOrFail()->meal_type);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'uuid'      => (string) Str::uuid(),
            'date'      => '2026-08-10',
            'time'      => '12:30',
            'meal_type' => null,
            'notes'     => null,
            'items'     => [[
                'name'  => 'Chicken breast',
                'basis' => 'absolute',
                'kcal'  => 220,
            ]],
        ], $overrides);
    }
}
