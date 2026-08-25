<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealStatus;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use App\Jobs\EstimateMealNutrition;
use Tests\Concerns\ActsAsFreshUser;
use App\Services\Vision\ProposedItem;
use Tests\Support\FakeVisionAnalyzer;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A described line, and what the proposal is allowed to do to it.
 *
 * One text box, on a phone, on 7 August:
 *
 *     "Full kwark 250g with 1 tablespoons of honey, 2 tablespoon of chia seed,
 *      1 tablespoon of protein powder and 1 handful if frozen wild blueberries"
 *
 * — every number box left empty. Claude answered with the five components, and
 * the review sheet said **Confirm 6 items**: the five, plus the user's own
 * sentence beside them at 100 g and 0–0 kcal, in the same columns as everything
 * they had actually typed. Neither number came from a person — the 100 g is the
 * BASIS an absolute-mode item is stored at (`TypedItem::toRow()`), the 0 is what
 * a NOT NULL density column does with an empty box — and the model's own working
 * note landed in the editable Notes field, over whatever the user wrote there.
 *
 * Three rules, one test file: BLANK IS NOT ZERO (only figures in
 * `vision_requests.input_payload` are the user's; a stored 0 is an artefact);
 * DECOMPOSITION REPLACES (a line with no numbers is a DESCRIPTION and cannot
 * survive beside the components that answer it, while a line WITH numbers is
 * data and always survives); NOTES HAVE AN AUTHOR (`meals.notes` is the user's,
 * the model's note is read-only `model_notes`).
 */
final class DescribedLineTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    private const DATE = '2026-08-07';

    private const LINE = 'Full kwark 250g with 1 tablespoons of honey, 2 tablespoon of chia seed, '
        .'1 tablespoon of protein powder and 1 handful if frozen wild blueberries';

    // --- 2b: decomposition replaces ----------------------------------------

    public function test_the_described_line_does_not_survive_its_own_decomposition(): void
    {
        $this->analyzer->willPropose($this->kwarkComponents(), notes: 'Split the single line into its five components.');

        $uuid = $this->estimate([['name' => self::LINE, 'basis' => 'absolute']]);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame(MealStatus::Proposed, $meal->status);

        $names = $meal->items()->orderBy('id')->pluck('name')->all();

        // Five, not six. The sentence is not one of them.
        self::assertCount(5, $names);
        self::assertNotContains(self::LINE, $names);
        self::assertSame('full-fat kwark', $names[0]);
    }

    public function test_no_item_comes_back_carrying_a_number_nobody_typed(): void
    {
        $this->analyzer->willPropose($this->kwarkComponents());

        $uuid = $this->estimate([['name' => self::LINE, 'basis' => 'absolute']]);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        foreach ($meal->items as $item) {
            // The fabricated row: the 100 g basis as a portion, a NOT NULL 0 as energy.
            self::assertFalse(
                (float) $item->portion_g_min === 100.0
                    && (float) $item->portion_g_max === 100.0
                    && (float) $item->kcal_per_100g_max === 0.0,
                "{$item->name} came back at 100 g and 0 kcal — nobody typed either."
            );
        }
    }

    public function test_a_line_the_user_put_numbers_on_survives_everything(): void
    {
        // Rice with real figures; the model answers only about the description.
        $this->analyzer->willPropose([
            $this->proposedItem('Two slices of wholemeal bread', 60, 80, 150, 200),
            $this->proposedItem('Butter', 8, 12, 59, 88),
        ]);

        $uuid = $this->estimate([
            ['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 150, 'kcal_per_100g' => 130],
            ['name' => 'Sandwich', 'basis' => 'absolute'],
        ]);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        $names = $meal->items()->orderBy('id')->pluck('name')->all();

        self::assertCount(3, $names);
        self::assertContains('White rice', $names);
        self::assertNotContains('Sandwich', $names);

        // Its numbers untouched, the older guarantee this must not break.
        $rice = $meal->items()->where('name', 'White rice')->sole();

        self::assertSame(150.0, (float) $rice->portion_g_min);
        self::assertSame(150.0, (float) $rice->portion_g_max);
        self::assertSame(130.0, (float) $rice->kcal_per_100g_min);
        self::assertSame(130.0, (float) $rice->kcal_per_100g_max);
    }

    public function test_a_described_line_survives_when_the_answer_did_not_replace_it(): void
    {
        // Nothing decomposed the sandwich, so dropping it would delete a food the
        // user listed — the failure `complete()` exists to prevent.
        $this->analyzer->willPropose([$this->proposedItem('Chicken curry', 300, 400, 400, 600)]);

        $uuid = $this->estimate([
            ['name' => 'Chicken curry', 'basis' => 'absolute'],
            ['name' => 'Sandwich', 'basis' => 'absolute'],
        ]);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        $names = $meal->items()->orderBy('id')->pluck('name')->all();

        self::assertCount(2, $names);
        self::assertContains('Sandwich', $names);
    }

    // --- 2a: blank is not zero ---------------------------------------------

    public function test_a_blank_is_recorded_as_blank_and_described_as_not_given(): void
    {
        $this->analyzer->willPropose($this->kwarkComponents());

        $this->estimate([['name' => self::LINE, 'basis' => 'absolute']]);

        $payload = $this->notNull(VisionRequest::query()->sole()->input_payload);

        self::assertNull($payload['items'][0]['grams']);
        self::assertNull($payload['items'][0]['values']['kcal']);

        // "NOT GIVEN" and "0" are the difference between a question and an answer.
        $described = $this->analyzer->lastDescription();

        self::assertStringContainsString('Portion: NOT GIVEN', $described);
        self::assertStringContainsString('Energy: NOT GIVEN', $described);
        self::assertStringNotContainsString('100 g (GIVEN', $described);
    }

    public function test_the_100_g_storage_basis_is_never_read_back_as_a_typed_portion(): void
    {
        /*
         * The second ask, on a meal SAVED BLANK first. `TypedItem::toRow()`
         * writes 100 g for an absolute-mode item because at 100 g the density is
         * the absolute value — a basis, not a weight. Reading it back as a
         * portion the user stated is how a blank line reached the model as
         * "Portion: 100 g (GIVEN — use exactly this)" and came back pinned there.
         */
        $meal = $this->savedBlank('Chicken curry');

        $this->analyzer->willPropose([$this->proposedItem('Chicken curry', 300, 400, 400, 600)]);

        $this->postJson('/api/meals/'.$meal->uuid.'/estimate', ['idempotency_key' => (string) Str::uuid()])
            ->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->sole()->id);

        $described = $this->analyzer->lastDescription();

        self::assertStringContainsString('Portion: NOT GIVEN', $described);
        self::assertStringNotContainsString('100 g (GIVEN', $described);

        // The proposal keeps the model's portion, not the stored basis.
        $item = $meal->refresh()->items()->sole();

        self::assertSame(300.0, (float) $item->portion_g_min);
        self::assertSame(400.0, (float) $item->portion_g_max);
    }

    public function test_a_weight_the_user_actually_typed_still_wins(): void
    {
        // The mirror of the test above: 250 g is not 100 g, so somebody stated it.
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice()]);

        $uuid = $this->estimate([
            ['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 250],
        ]);

        $item = Meal::query()->where('uuid', $uuid)->sole()->items()->sole();

        self::assertSame(250.0, (float) $item->portion_g_min);
        self::assertSame(250.0, (float) $item->portion_g_max);
    }

    // --- 3: notes have an author -------------------------------------------

    public function test_the_model_note_does_not_become_the_users_notes(): void
    {
        $this->analyzer->willPropose(
            $this->kwarkComponents(),
            notes: 'Split the single line into its five components; the 250 g kwark is as given.'
        );

        $uuid = $this->estimate(
            [['name' => self::LINE, 'basis' => 'absolute']],
            notes: 'Ate it at my desk.',
        );

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame('Ate it at my desk.', $meal->notes);
        self::assertStringStartsWith('Split the single line', (string) $meal->model_notes);
    }

    public function test_a_user_who_wrote_no_notes_still_has_none(): void
    {
        $this->analyzer->willPropose($this->kwarkComponents(), notes: 'Assumed level tablespoons.');

        $uuid = $this->estimate([['name' => self::LINE, 'basis' => 'absolute']]);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        // Null, not the model's sentence: the review sheet's Notes box is empty,
        // so Confirm cannot store Claude's reasoning as the user's own words.
        self::assertNull($meal->notes);
        self::assertSame('Assumed level tablespoons.', $meal->model_notes);
    }

    public function test_the_two_notes_reach_the_day_as_two_props(): void
    {
        $this->analyzer->willPropose($this->kwarkComponents(), notes: 'Assumed level tablespoons.');

        $this->estimate([['name' => self::LINE, 'basis' => 'absolute']], notes: 'Ate it at my desk.');

        $this->get('/?date='.self::DATE)
            ->assertInertia(fn ($page) => $page
                ->where('meals.0.notes', 'Ate it at my desk.')
                ->where('meals.0.modelNotes', 'Assumed level tablespoons.')
            );
    }

    public function test_the_users_notes_are_what_the_model_is_shown_as_notes(): void
    {
        // The other half of the leak: `typedFrom()` fed the model its own
        // previous note back under "Notes from the user".
        $this->analyzer->willPropose($this->kwarkComponents(), notes: 'Assumed level tablespoons.');

        $this->estimate([['name' => self::LINE, 'basis' => 'absolute']], notes: 'Ate it at my desk.');

        $described = $this->analyzer->lastDescription();

        self::assertStringContainsString('Notes from the user: Ate it at my desk.', $described);
        self::assertStringNotContainsString('Assumed level tablespoons', $described);
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Post the estimate and run the job it queued, returning the meal uuid.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function estimate(array $items, ?string $notes = null): string
    {
        $uuid = (string) Str::uuid();

        $this->postJson('/api/meals/estimate', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => self::DATE,
            'time'            => '09:21',
            'meal_type'       => 'breakfast',
            'notes'           => $notes,
            'items'           => $items,
        ])->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->sole()->id);

        return $uuid;
    }

    /** A meal logged with a name and nothing else — "Save as-is". */
    private function savedBlank(string $name): Meal
    {
        $uuid = (string) Str::uuid();

        $this->post('/meals', [
            'uuid'      => $uuid,
            'date'      => self::DATE,
            'time'      => '09:21',
            'meal_type' => 'breakfast',
            'items'     => [['name' => $name, 'basis' => 'absolute']],
        ])->assertRedirect();

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        // The row it wrote is the thing under test: 100 g, zero everything.
        $item = $meal->items()->sole();

        self::assertSame(100.0, (float) $item->portion_g_min);
        self::assertSame(0.0, (float) $item->kcal_per_100g_max);

        return $meal;
    }

    /** @return list<ProposedItem> */
    private function kwarkComponents(): array
    {
        return [
            $this->proposedItem('full-fat kwark', 250, 250, 240, 300),
            $this->proposedItem('1 tablespoon of honey', 17, 23, 52, 70),
            $this->proposedItem('2 tablespoons of chia seed', 20, 28, 97, 136),
            $this->proposedItem('1 tablespoon of protein powder', 7, 13, 28, 52),
            $this->proposedItem('1 handful of frozen wild blueberries', 45, 90, 22, 50),
        ];
    }

    private function proposedItem(string $name, float $gMin, float $gMax, float $kcalMin, float $kcalMax): ProposedItem
    {
        return new ProposedItem(
            name: $name,
            portionGMin: $gMin,
            portionGMax: $gMax,
            kcalMin: $kcalMin,
            kcalMax: $kcalMax,
            proteinGMin: 1,
            proteinGMax: 2,
            carbsGMin: 1,
            carbsGMax: 2,
            fatGMin: 1,
            fatGMax: 2,
            confidence: 'high',
        );
    }
}
