<?php

declare(strict_types=1);

namespace Tests\Feature\Memory;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealMemory;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use App\Models\MealMemoryItem;
use App\Jobs\EstimateMealNutrition;
use Tests\Concerns\ActsAsFreshUser;
use App\Services\Vision\ProposedItem;
use Tests\Support\FakeVisionAnalyzer;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Meal memory applies to a text estimate exactly as it does to a photograph —
 * and loses to a number the user typed.
 *
 * Three claims, in order: the model (what came back), memory ("you have had this
 * before, and last time it was 180 g"), and the user (what they typed thirty
 * seconds ago). Memory beats the model because it is a summary of things this
 * person actually confirmed; the user beats memory because memory is a claim
 * about the past and they are telling you about tonight. AnalyzeMeal applies
 * them in that order, and this file stops the order being rearranged by accident.
 */
final class TextEstimatePrefillTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    public function test_a_remembered_portion_prefills_a_typed_meal_too(): void
    {
        $memory = $this->remember('White rice', portion: 180.0, kcal: [128.0, 132.0]);

        $meal = $this->estimate(
            [['name' => 'White rice', 'basis' => 'absolute']],
            [FakeVisionAnalyzer::rice()],
        );

        $rice = $meal->items->sole();

        // Inside the model's 120-200 g range, so the habit wins — the same class
        // doing it as on the photo path.
        self::assertSame(180.0, (float) $rice->portion_g_min);
        self::assertSame(180.0, (float) $rice->portion_g_max);
        self::assertTrue($rice->memory_adjusted);

        self::assertSame($memory->id, $meal->memory_match_id);
        self::assertSame(1.0, (float) $meal->memory_match_score);
    }

    public function test_a_typed_weight_beats_a_remembered_one(): void
    {
        $this->remember('White rice', portion: 180.0, kcal: [128.0, 132.0]);

        $meal = $this->estimate(
            // 150 g tonight against 180 g eleven times. Tonight is not eleven times.
            [['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 150]],
            [FakeVisionAnalyzer::rice()],
        );

        $rice = $meal->items->sole();

        self::assertSame(150.0, (float) $rice->portion_g_min);
        self::assertSame(150.0, (float) $rice->portion_g_max);
    }

    public function test_a_typed_density_beats_a_tighter_remembered_one(): void
    {
        // Narrow enough that MemoryPrefill would normally replace a vague proposal.
        $this->remember('White rice', portion: 180.0, kcal: [131.0, 131.0]);

        $meal = $this->estimate(
            [['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 150, 'kcal_per_100g' => 118]],
            [FakeVisionAnalyzer::vagueRice()],
        );

        $rice = $meal->items->sole();

        self::assertSame(118.0, (float) $rice->kcal_per_100g_min);
        self::assertSame(118.0, (float) $rice->kcal_per_100g_max);

        // And the badge does not claim these came from history.
        self::assertFalse($rice->memory_adjusted);
    }

    public function test_memory_still_fills_the_items_the_user_left_blank(): void
    {
        $this->remember('Grilled chicken breast', portion: 130.0, kcal: [165.0, 165.0], names: ['White rice', 'Grilled chicken breast']);
        $this->rememberItem('White rice', portion: 180.0, kcal: [128.0, 132.0]);

        $meal = $this->estimate(
            [
                ['name' => 'White rice', 'basis' => 'per_100g', 'grams' => 150],
                ['name' => 'Grilled chicken breast', 'basis' => 'absolute'],
            ],
            [FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()],
        );

        $rice = $this->notNull($meal->items->firstWhere('slug', 'white-rice'));
        $chicken = $this->notNull($meal->items->firstWhere('slug', 'grilled-chicken-breast'));

        // Typed: the user's.
        self::assertSame(150.0, (float) $rice->portion_g_min);

        // Blank: memory's remembered 130 g, inside the model's 100-150 range.
        self::assertSame(130.0, (float) $chicken->portion_g_min);
        self::assertTrue($chicken->memory_adjusted);
    }

    public function test_the_audit_row_is_never_rewritten_to_agree_with_either(): void
    {
        $this->remember('White rice', portion: 180.0, kcal: [128.0, 132.0]);

        $meal = $this->estimate(
            [['name' => 'White rice', 'basis' => 'absolute', 'kcal' => 210]],
            [FakeVisionAnalyzer::wrongRice()],
        );

        $request = VisionRequest::query()->where('meal_id', $meal->id)->sole();

        // The row still says what the model said, which is what makes a future
        // prompt comparable against this one.
        self::assertSame(['stub' => true], $request->raw_response);

        // And the input it was given is still there beside it.
        $inputPayload = $this->notNull($request->input_payload);
        self::assertSame(210.0, (float) $inputPayload['items'][0]['values']['kcal']);
    }

    private MealMemory $memory;

    /**
     * @param  list<float>  $kcal
     * @param  list<string>|null  $names
     */
    private function remember(string $name, float $portion, array $kcal, ?array $names = null): MealMemory
    {
        $this->memory = MealMemory::factory()->forItems($names ?? [$name])->create([
            'times_logged'   => 11,
            'last_logged_at' => now()->subDays(2),
        ]);

        $this->rememberItem($name, $portion, $kcal);

        return $this->memory;
    }

    /** @param list<float> $kcal */
    private function rememberItem(string $name, float $portion, array $kcal): void
    {
        MealMemoryItem::factory()->named($name)->create([
            'meal_memory_id'    => $this->memory->id,
            'typical_portion_g' => $portion,
            'kcal_per_100g_min' => $kcal[0],
            'kcal_per_100g_max' => $kcal[1],
            // Wide enough never to be the tighter band, so each test asserts on one thing.
            'protein_per_100g_min' => 0,
            'protein_per_100g_max' => 100,
            'carbs_per_100g_min'   => 0,
            'carbs_per_100g_max'   => 100,
            'fat_per_100g_min'     => 0,
            'fat_per_100g_max'     => 100,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<ProposedItem>  $proposed
     */
    private function estimate(array $items, array $proposed): Meal
    {
        $this->analyzer->willPropose($proposed);

        $uuid = (string) Str::uuid();

        $this->postJson('/api/meals/estimate', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => '2026-06-15',
            'time'            => '19:30',
            'items'           => $items,
        ])->assertStatus(202);

        EstimateMealNutrition::dispatchSync(VisionRequest::query()->latest('id')->sole()->id);

        return Meal::query()->where('uuid', $uuid)->with('items')->firstOrFail();
    }
}
