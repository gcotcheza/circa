<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\User;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use Tests\Concerns\ReadsSource;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * WHERE THE MODEL'S NOTE IS ALLOWED TO SPEAK.
 *
 * `meals.model_notes` is Claude's commentary on its own answer — "assumed level
 * tablespoons", "portion judged against a 27 cm plate". Printed under every card
 * on the day it was a wall of italics pushing the food list, which is what a
 * card is for, off the first screen. So it is off the CARD and on the two
 * SHEETS, and this pins that split. Both halves matter:
 *
 *   1. The prop stays on the day payload. MealSheet and ProposalReview are
 *      handed the SAME meal object the card is (see Day.vue), so trimming
 *      `modelNotes` out of DailyView::mealProps() would silently blank the note
 *      in both sheets as well.
 *
 *   2. The card does not render it and the sheets do — a fact about three .vue
 *      files that nothing else can assert: Inertia SPA, so the server never
 *      emits the card's markup, and no JS test runner. Reading the source has
 *      precedent here; ConfirmRequestShapeTest reads the review sheet's queued
 *      action out of the same directory.
 *
 * Comments are stripped before matching, deliberately: all three files EXPLAIN
 * this arrangement in prose naming the prop, and a test that broke when someone
 * documented the rule would be worse than no test.
 */
final class ModelNoteVisibilityTest extends TestCase
{
    use ReadsSource;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    private const CARD = 'resources/js/Components/MealCard.vue';

    private const EDIT_SHEET = 'resources/js/Components/MealSheet.vue';

    private const REVIEW_SHEET = 'resources/js/Components/ProposalReview.vue';

    public function test_the_day_still_hands_the_sheets_the_model_note(): void
    {
        $this->actingAs(User::factory()->create());

        $meal = Meal::factory()->eatenAt(
            CarbonImmutable::parse(self::DATE.' 11:00:00', 'UTC')
        )->create([
            'notes'       => 'Ate it at my desk.',
            'model_notes' => 'Portion judged against a 27 cm plate.',
        ]);

        MealItem::factory()->named('Chicken breast')->create(['meal_id' => $meal->id]);

        // Still two props: the card reads one, the sheets it opens the other.
        $this->get('/?date='.self::DATE)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('meals.0.notes', 'Ate it at my desk.')
                ->where('meals.0.modelNotes', 'Portion judged against a 27 cm plate.')
                ->etc()
            );
    }

    public function test_the_day_view_card_does_not_render_the_model_note(): void
    {
        self::assertStringNotContainsString(
            'modelNotes',
            $this->sourceWithoutComments(self::CARD),
            self::CARD.' renders the model note again. It belongs on the sheets, not on every card in the day.'
        );
    }

    public function test_the_edit_sheet_still_shows_the_model_note(): void
    {
        self::assertStringContainsString(
            'meal.modelNotes',
            $this->sourceWithoutComments(self::EDIT_SHEET),
            self::EDIT_SHEET.' no longer shows the model note, so nothing does when a meal is edited.'
        );
    }

    public function test_the_review_sheet_still_shows_the_model_note(): void
    {
        $code = $this->sourceWithoutComments(self::REVIEW_SHEET);

        self::assertStringContainsString('modelNotes', $code);

        // The per-PLATE note, falling back to the meal's: three courses analysed
        // in sequence must not all quote the third's note — the other reason
        // `mealProps()` keeps the prop.
        self::assertStringContainsString(
            'entry.value?.modelNotes ?? props.meal.modelNotes',
            $code,
            self::REVIEW_SHEET.' no longer prefers the plate\'s own note over the meal\'s.'
        );
    }
}
