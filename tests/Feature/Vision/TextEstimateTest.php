<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use App\Enums\VisionRequestStatus;
use App\Jobs\EstimateMealNutrition;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeVisionAnalyzer;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "Estimate it for me" — the text path, end to end.
 *
 * A NUMBER THE USER TYPED IS NEVER CHANGED: not by the model, not by meal
 * memory, not by the two of them together. The prompt asks for that, and asking
 * is not a guarantee — so the fake here deliberately answers with figures that
 * CONTRADICT what the user entered, and the assertions are that the user's
 * survive anyway.
 *
 * The mirror property: an item the user listed cannot disappear. ProposalWriter
 * replaces a meal's items wholesale, so a food the model failed to mention would
 * be silently deleted — and unlike a wrong number, nothing would be left on
 * screen for the confirmation step to catch.
 */
final class TextEstimateTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    // --- the happy path ----------------------------------------------------

    public function test_saving_with_blanks_claims_an_estimate_and_leaves_the_meal_analyzing(): void
    {
        Queue::fake();

        $uuid = (string) Str::uuid();
        $key = (string) Str::uuid();

        $this->postJson('/api/meals/estimate', $this->payload([
            ['name' => 'Chicken curry', 'basis' => 'absolute'],
        ], $uuid, $key))
            ->assertStatus(202)
            ->assertJsonPath('meal.status', 'analyzing')
            ->assertJsonPath('meal.date', self::DATE)
            ->assertJsonPath('analysis.kind', 'text')
            ->assertJsonPath('analysis.promptVersion', 'v1-text');

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame(MealStatus::Analyzing, $meal->status);
        self::assertSame(MealSource::Manual, $meal->source);

        // The typed item is stored unconfirmed: nothing is lost if the estimate
        // never comes back, and nothing counts toward the day until Confirm.
        $item = $meal->items()->sole();

        self::assertSame('Chicken curry', $item->name);
        self::assertNull($item->confirmed_at);

        $request = VisionRequest::query()->sole();

        self::assertSame(VisionRequestKind::Text, $request->request_kind);
        self::assertSame(VisionRequestStatus::Pending, $request->status);
        self::assertSame('v1-text', $request->prompt_version);
        self::assertSame('claude-opus-5', $request->model);
        self::assertNull($request->image_sha256);

        // The stored input makes the row replayable against a future prompt —
        // the text path's `image_sha256`.
        $inputPayload = $this->notNull($request->input_payload);
        self::assertSame('Chicken curry', $inputPayload['items'][0]['name']);
        self::assertNull($inputPayload['items'][0]['values']['kcal']);

        Queue::assertPushed(EstimateMealNutrition::class);
    }

    public function test_the_estimate_fills_the_blanks_and_proposes(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()], notes: 'Assumed a standard bowl.');

        $uuid = $this->estimate([['name' => 'White rice', 'basis' => 'absolute']]);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame(MealStatus::Proposed, $meal->status);
        self::assertSame('Assumed a standard bowl.', $meal->model_notes);

        $item = $meal->items()->sole();

        self::assertSame('White rice', $item->name);
        self::assertNull($item->confirmed_at);

        // 120-200 g at 130 kcal/100 g, exactly as the photo path's mapper derives it.
        self::assertSame(120.0, (float) $item->portion_g_min);
        self::assertSame(200.0, (float) $item->portion_g_max);
        self::assertSame(130.0, (float) $item->kcal_per_100g_min);
        self::assertSame(130.0, (float) $item->kcal_per_100g_max);

        $request = VisionRequest::query()->sole();

        self::assertSame(VisionRequestStatus::Succeeded, $request->status);
        self::assertSame(1_800, $request->input_tokens);
        self::assertIsArray($request->raw_response);
    }

    public function test_the_description_sent_names_the_item_and_marks_what_was_given(): void
    {
        $this->estimate([
            ['name' => 'Basmati rice', 'basis' => 'per_100g', 'grams' => 150],
            ['name' => 'Chicken curry', 'basis' => 'absolute'],
        ], mealType: 'dinner', notes: 'Leftovers from Sunday.');

        $sent = $this->analyzer->lastDescription();

        self::assertStringContainsString('Basmati rice', $sent);
        self::assertStringContainsString('Chicken curry', $sent);
        self::assertStringContainsString('dinner', $sent);
        self::assertStringContainsString('Leftovers from Sunday.', $sent);

        // The distinction the prompt turns on must be legible in the text, not
        // merely implied by a missing key.
        self::assertStringContainsString('Portion: 150 g (GIVEN', $sent);
        self::assertStringContainsString('NOT GIVEN', $sent);

        self::assertSame(1, $this->analyzer->estimateCount());
        self::assertSame(0, $this->analyzer->callCount());
    }

    // --- the preserved-values rule -----------------------------------------

    public function test_a_kcal_figure_the_user_typed_survives_a_contradicting_model(): void
    {
        // Model: 900-1200 kcal over 300-400 g. User: 210 kcal. The user wins.
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice()]);

        $uuid = $this->estimate([
            ['name' => 'White rice', 'basis' => 'absolute', 'kcal' => 210],
        ]);

        $item = Meal::query()->where('uuid', $uuid)->sole()->items()->sole();

        $atMin = (float) $item->portion_g_min * (float) $item->kcal_per_100g_min / 100;
        $atMax = (float) $item->portion_g_max * (float) $item->kcal_per_100g_max / 100;

        self::assertEqualsWithDelta(210.0, $atMin, 0.01);
        self::assertEqualsWithDelta(210.0, $atMax, 0.01);

        // The model still contributes what it was asked for: how much rice.
        self::assertSame(300.0, (float) $item->portion_g_min);
        self::assertSame(400.0, (float) $item->portion_g_max);
    }

    public function test_a_weight_the_user_typed_is_not_re_estimated(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice()]);

        $uuid = $this->estimate([
            ['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 150],
        ]);

        $item = Meal::query()->where('uuid', $uuid)->sole()->items()->sole();

        self::assertSame(150.0, (float) $item->portion_g_min);
        self::assertSame(150.0, (float) $item->portion_g_max);
    }

    public function test_a_density_the_user_typed_is_preserved_as_a_density(): void
    {
        // Density but no weight: a claim about the FOOD, at any amount on the plate.
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice()]);

        $uuid = $this->estimate([
            ['name' => 'White rice', 'basis' => 'per_100g', 'kcal_per_100g' => 130],
        ]);

        $item = Meal::query()->where('uuid', $uuid)->sole()->items()->sole();

        self::assertSame(130.0, (float) $item->kcal_per_100g_min);
        self::assertSame(130.0, (float) $item->kcal_per_100g_max);
        self::assertSame(300.0, (float) $item->portion_g_min);
    }

    public function test_blanks_are_filled_while_typed_values_beside_them_are_not(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice(), FakeVisionAnalyzer::chicken()]);

        $uuid = $this->estimate([
            ['name' => 'White rice', 'basis' => 'absolute', 'kcal' => 210],
            ['name' => 'Grilled chicken breast', 'basis' => 'absolute'],
        ]);

        $items = Meal::query()->where('uuid', $uuid)->sole()->items()->orderBy('id')->get();

        self::assertCount(2, $items);

        // Typed: untouched.
        $typedRice = $this->notNull($items->get(0));
        self::assertEqualsWithDelta(
            210.0,
            (float) $typedRice->portion_g_min * (float) $typedRice->kcal_per_100g_min / 100,
            0.01
        );

        // Blank: the model's answer, mapped to a density like any proposal.
        $blankItem = $this->notNull($items->get(1));
        self::assertSame(100.0, (float) $blankItem->portion_g_min);
        self::assertSame(165.0, (float) $blankItem->kcal_per_100g_min);
    }

    public function test_macros_the_user_typed_are_preserved_independently_of_the_calories(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice()]);

        $uuid = $this->estimate([
            // Protein known, calories not: a piece of fish in a mystery oil.
            ['name' => 'White rice', 'basis' => 'absolute', 'protein' => 6],
        ]);

        $item = Meal::query()->where('uuid', $uuid)->sole()->items()->sole();

        self::assertEqualsWithDelta(
            6.0,
            (float) $item->portion_g_min * (float) $item->protein_per_100g_min / 100,
            0.01
        );

        // The calories are the model's, because that is what was asked for.
        self::assertGreaterThan(0.0, (float) $item->kcal_per_100g_min);
    }

    // --- nothing the user listed can vanish --------------------------------

    public function test_an_item_the_model_dropped_is_put_back(): void
    {
        // The model answers about the rice only, and forgets the coffee.
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = $this->estimate([
            ['name' => 'White rice', 'basis' => 'absolute'],
            ['name' => 'Cappuccino', 'basis' => 'absolute', 'kcal' => 120],
        ]);

        $items = Meal::query()->where('uuid', $uuid)->sole()->items()->orderBy('id')->get();

        self::assertSame(['White rice', 'Cappuccino'], $items->pluck('name')->all());

        // Restored as it would have saved without an estimate: 100 g basis, 120 kcal.
        $coffee = $this->notNull($items->get(1));

        self::assertSame(100.0, (float) $coffee->portion_g_min);
        self::assertSame(120.0, (float) $coffee->kcal_per_100g_min);
        self::assertSame(120.0, (float) $coffee->kcal_per_100g_max);
    }

    public function test_an_empty_answer_still_keeps_everything_the_user_typed(): void
    {
        $this->analyzer->willFind('a description it could make nothing of');

        $uuid = $this->estimate([
            ['name' => 'Chicken curry', 'basis' => 'absolute'],
        ]);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        // A SUCCESS, as on the photo path — and not even empty: the item is put back.
        self::assertSame(MealStatus::Proposed, $meal->status);
        self::assertSame('Chicken curry', $meal->items()->sole()->name);
    }

    // --- the state machine -------------------------------------------------

    public function test_a_failed_estimate_leaves_the_typed_meal_intact(): void
    {
        $this->analyzer->willFail('The analysis service returned an error.');

        $uuid = $this->estimate([
            ['name' => 'Chicken curry', 'basis' => 'absolute', 'kcal' => 620],
        ]);

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame(MealStatus::Failed, $meal->status);

        // Still what was typed, so Confirm is one tap of "save it as I wrote it".
        $item = $meal->items()->sole();

        self::assertSame('Chicken curry', $item->name);
        self::assertSame(620.0, (float) $item->kcal_per_100g_min);

        $request = VisionRequest::query()->sole();

        self::assertSame(VisionRequestStatus::Failed, $request->status);
        self::assertSame('The analysis service returned an error.', $request->error);
    }

    public function test_confirming_a_text_proposal_counts_it_toward_the_day(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = $this->estimate([['name' => 'White rice', 'basis' => 'absolute']]);

        $this->put("/meals/{$uuid}/proposal", [
            'date'      => self::DATE,
            'time'      => '12:30',
            'meal_type' => 'lunch',
            'items'     => [[
                'name'          => 'White rice',
                'portion_g_min' => 120, 'portion_g_max' => 200,
                'kcal_min'      => 156, 'kcal_max' => 260,
                'protein_g_min' => 3.24, 'protein_g_max' => 5.4,
                'carbs_g_min'   => 33.6, 'carbs_g_max' => 56,
                'fat_g_min'     => 0.36, 'fat_g_max' => 0.6,
            ]],
        ])->assertRedirect(route('day', ['date' => self::DATE]));

        $meal = Meal::query()->where('uuid', $uuid)->sole();

        self::assertSame(MealStatus::Confirmed, $meal->status);
        self::assertNotNull($meal->items()->sole()->confirmed_at);
    }

    // --- idempotency, conflicts, auth, throttle ----------------------------

    public function test_the_same_idempotency_key_buys_one_estimate(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $uuid = (string) Str::uuid();
        $key = (string) Str::uuid();
        $payload = $this->payload([['name' => 'White rice', 'basis' => 'absolute']], $uuid, $key);

        $this->postJson('/api/meals/estimate', $payload)->assertStatus(202);
        $this->postJson('/api/meals/estimate', $payload)->assertStatus(200);

        self::assertSame(1, VisionRequest::query()->count());
        self::assertSame(1, Meal::query()->count());
        self::assertSame(1, $this->analyzer->estimateCount());
    }

    public function test_a_confirmed_meal_is_not_quietly_reopened(): void
    {
        $uuid = (string) Str::uuid();

        Meal::factory()->create(['uuid' => $uuid, 'status' => MealStatus::Confirmed]);

        $this->postJson('/api/meals/estimate', $this->payload([
            ['name' => 'White rice', 'basis' => 'absolute'],
        ], $uuid))
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        self::assertSame(0, VisionRequest::query()->count());
    }

    public function test_the_estimate_endpoints_require_a_session(): void
    {
        auth()->logout();

        $meal = Meal::factory()->create();

        $this->postJson('/api/meals/estimate', [])->assertUnauthorized();
        $this->postJson("/api/meals/{$meal->uuid}/estimate", [])->assertUnauthorized();
    }

    public function test_the_estimate_endpoint_is_throttled_like_the_photo_one(): void
    {
        // Same limiter, same ceiling: both spend money, and idempotency already
        // makes a double-tap free, so this caps a stuck retry loop.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/meals/estimate', $this->payload([
                ['name' => 'White rice', 'basis' => 'absolute'],
            ]))->assertStatus(202);
        }

        $this->postJson('/api/meals/estimate', $this->payload([
            ['name' => 'White rice', 'basis' => 'absolute'],
        ]))->assertStatus(429);
    }

    public function test_the_items_are_validated_exactly_as_an_ordinary_save_is(): void
    {
        $this->postJson('/api/meals/estimate', $this->payload([
            ['name' => '', 'basis' => 'absolute'],
        ]))->assertStatus(422)->assertJsonValidationErrors('items.0.name');

        $this->postJson('/api/meals/estimate', $this->payload([
            ['name' => 'Impossible', 'basis' => 'per_100g', 'grams' => 100, 'kcal_per_100g' => 5000],
        ]))->assertStatus(422)->assertJsonValidationErrors('items.0.kcal_per_100g');

        // The idempotency key is not optional: a replay would buy a second analysis.
        $payload = $this->payload([['name' => 'Rice', 'basis' => 'absolute']]);
        unset($payload['idempotency_key']);

        $this->postJson('/api/meals/estimate', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('idempotency_key');
    }

    // --- helpers -----------------------------------------------------------

    /**
     * Post the estimate and run the job it queued, returning the meal uuid.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function estimate(array $items, ?string $mealType = null, ?string $notes = null): string
    {
        $uuid = (string) Str::uuid();

        $this->postJson('/api/meals/estimate', [
            ...$this->payload($items, $uuid),
            'meal_type' => $mealType,
            'notes'     => $notes,
        ])->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->sole()->id);

        return $uuid;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    private function payload(array $items, ?string $uuid = null, ?string $key = null): array
    {
        return [
            'uuid'            => $uuid ?? (string) Str::uuid(),
            'idempotency_key' => $key ?? (string) Str::uuid(),
            'date'            => self::DATE,
            'time'            => '12:30',
            'items'           => $items,
        ];
    }
}
