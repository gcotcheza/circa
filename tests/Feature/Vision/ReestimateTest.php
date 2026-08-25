<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealStatus;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use App\Jobs\EstimateMealNutrition;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeVisionAnalyzer;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "Estimate the missing values" on a meal that is already saved.
 *
 * Same semantics as re-analysing a photograph: a NEW idempotency key and a NEW
 * `vision_requests` row, because "ask again" and "the same request arrived twice"
 * are different intentions the audit table must tell apart. Reading a saved meal
 * back, `meal_items` cannot say which zeros were typed and which were left blank
 * — the density columns are NOT NULL — so "blank" here means the only thing a
 * row can honestly mean by it: no energy claimed.
 */
final class ReestimateTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    public function test_a_saved_meal_with_a_blank_item_can_be_estimated(): void
    {
        Queue::fake();

        $meal = $this->savedMeal([
            ['name' => 'Chicken curry', 'basis' => 'absolute'],
        ]);

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
        ])
            ->assertStatus(202)
            ->assertJsonPath('meal.status', 'analyzing')
            ->assertJsonPath('analysis.kind', 'text');

        $request = VisionRequest::query()->sole();

        self::assertSame(VisionRequestKind::Text, $request->request_kind);
        self::assertSame('v1-text', $request->prompt_version);
        self::assertSame('Chicken curry', $this->notNull($request->input_payload)['items'][0]['name']);

        self::assertSame(MealStatus::Analyzing, $meal->refresh()->status);

        Queue::assertPushed(EstimateMealNutrition::class);
    }

    public function test_it_fills_the_blank_and_keeps_the_figures_already_there(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice(), FakeVisionAnalyzer::chicken()]);

        $meal = $this->savedMeal([
            ['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 150, 'kcal_per_100g' => 130],
            ['name' => 'Grilled chicken breast', 'basis' => 'absolute'],
        ]);

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->sole()->id);

        $items = $meal->refresh()->items()->orderBy('id')->get();

        self::assertSame(MealStatus::Proposed, $meal->status);

        // The rice was fully specified when saved, so re-estimating must not move
        // it: a stored figure is as much the user's as a freshly typed one.
        $rice = $this->notNull($items->get(0));
        self::assertSame(150.0, (float) $rice->portion_g_min);
        self::assertSame(130.0, (float) $rice->kcal_per_100g_min);
        self::assertSame(130.0, (float) $rice->kcal_per_100g_max);

        // The chicken had nothing, and now has the model's range.
        self::assertSame(165.0, (float) $this->notNull($items->get(1))->kcal_per_100g_min);
    }

    public function test_a_meal_whose_items_all_have_calories_is_refused(): void
    {
        $meal = $this->savedMeal([
            ['name' => 'Cappuccino', 'basis' => 'absolute', 'kcal' => 120],
        ]);

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
        ])
            ->assertStatus(409)
            ->assertJsonPath('status', 'conflict');

        // Nothing claimed, nothing spent: every number would have been overwritten.
        self::assertSame(0, VisionRequest::query()->count());
        self::assertSame(MealStatus::Confirmed, $meal->refresh()->status);
    }

    public function test_a_meal_already_being_estimated_is_refused(): void
    {
        $meal = Meal::factory()->create(['status' => MealStatus::Analyzing]);

        MealItem::factory()->for($meal)->create(['kcal_per_100g_min' => 0, 'kcal_per_100g_max' => 0]);

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409);

        self::assertSame(0, VisionRequest::query()->count());
    }

    public function test_an_empty_meal_has_nothing_to_estimate(): void
    {
        $meal = Meal::factory()->create(['status' => MealStatus::Confirmed]);

        $this->postJson("/api/meals/{$meal->uuid}/estimate", [
            'idempotency_key' => (string) Str::uuid(),
        ])->assertStatus(409);
    }

    public function test_asking_again_creates_a_second_audit_row(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::rice()]);

        $meal = $this->savedMeal([['name' => 'White rice', 'basis' => 'absolute']]);

        $this->postJson("/api/meals/{$meal->uuid}/estimate", ['idempotency_key' => (string) Str::uuid()])
            ->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->firstOrFail()->id);

        // A new key, so a new question rather than the same one twice. Allowed
        // even though every item now has a calorie figure, because those are the
        // MODEL's: the proposal is unconfirmed, and disliking an estimate is the
        // reason to ask again.
        $this->postJson("/api/meals/{$meal->uuid}/estimate", ['idempotency_key' => (string) Str::uuid()])
            ->assertStatus(202);

        self::assertSame(2, VisionRequest::query()->count());
        self::assertSame(2, VisionRequest::query()->where('request_kind', VisionRequestKind::Text)->count());
    }

    public function test_asking_again_re_uses_the_original_typed_input(): void
    {
        $this->analyzer->willPropose([FakeVisionAnalyzer::wrongRice(), FakeVisionAnalyzer::chicken()]);

        // Saved through the estimate endpoint, so there IS an original input: rice specified, chicken not.
        $uuid = (string) Str::uuid();

        $this->postJson('/api/meals/estimate', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => self::DATE,
            'time'            => '12:30',
            'items'           => [
                ['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 150, 'kcal_per_100g' => 130],
                ['name' => 'Grilled chicken breast', 'basis' => 'absolute'],
            ],
        ])->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->sole()->id);

        $this->postJson("/api/meals/{$uuid}/estimate", ['idempotency_key' => (string) Str::uuid()])
            ->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->firstOrFail()->id);

        $items = Meal::query()->where('uuid', $uuid)->sole()->items()->orderBy('id')->get();

        /*
         * The 150 g and 130 kcal/100 g are still the user's two estimates later.
         * Reading the input off the stored rows would hand the model its own first
         * answer as though a human typed it, and the rice would drift.
         */
        $reusedRice = $this->notNull($items->get(0));
        self::assertSame(150.0, (float) $reusedRice->portion_g_min);
        self::assertSame(130.0, (float) $reusedRice->kcal_per_100g_min);
        self::assertSame(130.0, (float) $reusedRice->kcal_per_100g_max);

        self::assertStringContainsString('Portion: 150 g (GIVEN', $this->analyzer->lastDescription());
    }

    public function test_the_same_key_twice_is_still_one_estimate(): void
    {
        $meal = $this->savedMeal([['name' => 'White rice', 'basis' => 'absolute']]);

        $key = (string) Str::uuid();

        $this->postJson("/api/meals/{$meal->uuid}/estimate", ['idempotency_key' => $key])->assertStatus(202);
        $this->postJson("/api/meals/{$meal->uuid}/estimate", ['idempotency_key' => $key])->assertStatus(200);

        self::assertSame(1, VisionRequest::query()->count());
        self::assertSame(1, $this->analyzer->estimateCount());
    }

    /**
     * A meal saved the ordinary way, through the manual form. Not a factory: what
     * is under test is a meal the USER saved, with the zeros a real save writes.
     *
     * @param  list<array<string, mixed>>  $items
     */
    private function savedMeal(array $items): Meal
    {
        $uuid = (string) Str::uuid();

        $this->post('/meals', [
            'uuid'  => $uuid,
            'date'  => self::DATE,
            'time'  => '12:30',
            'items' => $items,
        ])->assertSessionHasNoErrors();

        return Meal::query()->where('uuid', $uuid)->sole();
    }
}
