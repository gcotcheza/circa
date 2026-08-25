<?php

declare(strict_types=1);

namespace Tests\Feature\Memory;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealMemory;
use Illuminate\Support\Str;
use Tests\Support\TestImage;
use App\Models\VisionRequest;
use App\Models\MealMemoryItem;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsFreshUser;
use App\Services\Vision\ProposedItem;
use Tests\Support\FakeVisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InteractsWithVision;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Memory applied to a fresh photograph — the point of the whole step. The model
 * looks at the plate and says "120-200 g of rice"; the app has watched this
 * person eat 180 g of it eleven times, and the second fact is better. It is used
 * before the proposal is stored, so the user reviews the adjusted numbers, and
 * without touching `vision_requests.raw_response`, which stays the model's own
 * opinion.
 */
final class VisionPrefillTest extends TestCase
{
    use ActsAsFreshUser;
    use InteractsWithVision;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('meal-photos');
    }

    public function test_an_exact_match_prefills_the_remembered_portion_and_densities(): void
    {
        $memory = $this->remember(
            ['White rice', 'Grilled chicken breast'],
            [
                // Inside the model's 120-200 g range, so the habit wins.
                ['name' => 'White rice', 'portion' => 180.0, 'kcal' => [128.0, 132.0]],
                ['name' => 'Grilled chicken breast', 'portion' => 130.0, 'kcal' => [165.0, 165.0]],
            ],
            timesLogged: 11,
        );

        $meal = $this->photograph([FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()]);

        $rice = $meal->items->firstWhere('slug', 'white-rice');

        self::assertNotNull($rice);
        self::assertSame(180.0, (float) $rice->portion_g_min);
        self::assertSame(180.0, (float) $rice->portion_g_max);
        self::assertTrue($rice->memory_adjusted);

        // Densities are replaced only when the remembered band is TIGHTER: the
        // rice round-trips to a flat 130/130, so 128-132 is wider.
        self::assertSame(130.0, (float) $rice->kcal_per_100g_min);
        self::assertSame(130.0, (float) $rice->kcal_per_100g_max);

        // The meal records what it was matched against.
        self::assertSame($memory->id, $meal->memory_match_id);
        self::assertSame(1.0, (float) $meal->memory_match_score);
    }

    public function test_a_tighter_remembered_density_replaces_the_proposed_one(): void
    {
        $this->remember(
            ['White rice'],
            [['name' => 'White rice', 'portion' => 180.0, 'kcal' => [131.0, 131.0]]],
        );

        // A vaguer answer: 120-200 g at 150-300 kcal is a 125-150 kcal/100 g band,
        // which the remembered flat 131 is genuinely narrower than.
        $meal = $this->photograph([FakeVisionAnalyzer::vagueRice()]);

        $rice = $meal->items->sole();

        self::assertSame(131.0, (float) $rice->kcal_per_100g_min);
        self::assertSame(131.0, (float) $rice->kcal_per_100g_max);
        self::assertTrue($rice->memory_adjusted);
    }

    /**
     * The habit only wins where the photograph agrees: 40-90 g on the plate
     * against a habitual 180 g is a different meal, and the picture wins.
     */
    public function test_a_remembered_portion_outside_the_proposed_range_is_not_forced(): void
    {
        $this->remember(
            ['White rice'],
            [['name' => 'White rice', 'portion' => 400.0, 'kcal' => [130.0, 130.0]]],
        );

        $meal = $this->photograph([FakeVisionAnalyzer::rice()]);

        $rice = $meal->items->sole();

        self::assertSame(120.0, (float) $rice->portion_g_min);
        self::assertSame(200.0, (float) $rice->portion_g_max);
        self::assertFalse($rice->memory_adjusted);

        // The MEAL still matched, even with nothing memory could usefully tighten.
        self::assertNotNull($meal->memory_match_id);
    }

    /**
     * Containment: shared / union. Three of four shared is 0.75, over the 0.60
     * threshold, so the extra olive oil does not make it a different dinner.
     */
    public function test_a_partial_match_above_the_threshold_still_prefills(): void
    {
        $memory = $this->remember(
            ['White rice', 'Grilled chicken breast', 'Broccoli'],
            [
                ['name' => 'White rice', 'portion' => 180.0, 'kcal' => [130.0, 130.0]],
                ['name' => 'Grilled chicken breast', 'portion' => 130.0, 'kcal' => [165.0, 165.0]],
                ['name' => 'Broccoli', 'portion' => 120.0, 'kcal' => [34.0, 34.0]],
            ],
        );

        $meal = $this->photograph([
            FakeVisionAnalyzer::rice(),
            FakeVisionAnalyzer::chicken(),
            FakeVisionAnalyzer::broccoli(),
            FakeVisionAnalyzer::oliveOil(),
        ]);

        self::assertSame($memory->id, $meal->memory_match_id);
        self::assertSame(0.75, (float) $meal->memory_match_score);

        // The three remembered foods are pre-filled; the new one is untouched.
        self::assertSame(180.0, (float) $this->notNull($meal->items->firstWhere('slug', 'white-rice'))->portion_g_min);
        self::assertFalse($this->notNull($meal->items->firstWhere('slug', 'olive-oil'))->memory_adjusted);
    }

    /**
     * Two shared of a four-item union is 0.50, under the threshold: half a dinner
     * is a different dinner. Dividing by the UNION rather than by the proposal is
     * what stops one shared staple dragging in every rice-bearing meal logged.
     */
    public function test_a_partial_match_below_the_threshold_is_not_a_match(): void
    {
        $this->remember(
            ['White rice', 'Grilled chicken breast', 'Broccoli', 'Olive oil'],
            [
                ['name' => 'White rice', 'portion' => 180.0, 'kcal' => [130.0, 130.0]],
                ['name' => 'Grilled chicken breast', 'portion' => 130.0, 'kcal' => [165.0, 165.0]],
                ['name' => 'Broccoli', 'portion' => 120.0, 'kcal' => [34.0, 34.0]],
                ['name' => 'Olive oil', 'portion' => 10.0, 'kcal' => [884.0, 884.0]],
            ],
        );

        $meal = $this->photograph([FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()]);

        self::assertNull($meal->memory_match_id);
        self::assertNull($meal->memory_match_score);
        self::assertSame(120.0, (float) $this->notNull($meal->items->firstWhere('slug', 'white-rice'))->portion_g_min);
    }

    public function test_an_exact_match_beats_a_partial_one(): void
    {
        // A superset that would score 0.67 on containment...
        $this->remember(
            ['White rice', 'Grilled chicken breast', 'Broccoli'],
            [
                ['name' => 'White rice', 'portion' => 300.0, 'kcal' => [130.0, 130.0]],
                ['name' => 'Grilled chicken breast', 'portion' => 130.0, 'kcal' => [165.0, 165.0]],
                ['name' => 'Broccoli', 'portion' => 120.0, 'kcal' => [34.0, 34.0]],
            ],
            timesLogged: 50,
        );

        // ...and the exact one, logged far less often.
        $exact = $this->remember(
            ['White rice', 'Grilled chicken breast'],
            [
                ['name' => 'White rice', 'portion' => 150.0, 'kcal' => [130.0, 130.0]],
                ['name' => 'Grilled chicken breast', 'portion' => 130.0, 'kcal' => [165.0, 165.0]],
            ],
            timesLogged: 2,
        );

        $meal = $this->photograph([FakeVisionAnalyzer::rice(), FakeVisionAnalyzer::chicken()]);

        self::assertSame($exact->id, $meal->memory_match_id);
        self::assertSame(150.0, (float) $this->notNull($meal->items->firstWhere('slug', 'white-rice'))->portion_g_min);
    }

    public function test_with_no_history_the_proposal_is_exactly_what_the_model_said(): void
    {
        $meal = $this->photograph([FakeVisionAnalyzer::rice()]);

        $rice = $meal->items->sole();

        self::assertSame(120.0, (float) $rice->portion_g_min);
        self::assertSame(200.0, (float) $rice->portion_g_max);
        self::assertFalse($rice->memory_adjusted);
        self::assertNull($meal->memory_match_id);
    }

    /**
     * The audit row is the model's opinion and what a prompt change is evaluated
     * against: memory must not edit history to agree with itself.
     */
    public function test_the_vision_audit_row_is_never_rewritten_by_memory(): void
    {
        $this->remember(
            ['White rice'],
            [['name' => 'White rice', 'portion' => 180.0, 'kcal' => [131.0, 131.0]]],
        );

        $meal = $this->photograph([FakeVisionAnalyzer::rice()]);

        $request = VisionRequest::query()->where('meal_id', $meal->id)->sole();

        self::assertSame(['stub' => true], $request->raw_response);

        // The item disagrees with the audit row, and the flag says which moved.
        self::assertTrue($meal->items->sole()->memory_adjusted);
        self::assertSame(
            1,
            MealItem::query()->where('meal_id', $meal->id)->where('memory_adjusted', true)->count()
        );
    }

    /**
     * @param  list<string>  $names
     * @param  list<array{name: string, portion: float, kcal: array{0: float, 1: float}}>  $items
     */
    private function remember(array $names, array $items, int $timesLogged = 3): MealMemory
    {
        $memory = MealMemory::factory()->forItems($names)->create([
            'times_logged'   => $timesLogged,
            'last_logged_at' => now()->subDays(2),
        ]);

        foreach ($items as $item) {
            MealMemoryItem::factory()->named($item['name'])->create([
                'meal_memory_id'    => $memory->id,
                'typical_portion_g' => $item['portion'],
                'kcal_per_100g_min' => $item['kcal'][0],
                'kcal_per_100g_max' => $item['kcal'][1],
                // Macros wide enough never to be the tighter band, so each test
                // asserts on the one thing it is about.
                'protein_per_100g_min' => 0,
                'protein_per_100g_max' => 100,
                'carbs_per_100g_min'   => 0,
                'carbs_per_100g_max'   => 100,
                'fat_per_100g_min'     => 0,
                'fat_per_100g_max'     => 100,
            ]);
        }

        return $memory->load('items');
    }

    /**
     * @param  list<ProposedItem>  $items
     */
    private function photograph(array $items): Meal
    {
        $this->analyzer->willPropose($items);

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => '2026-06-15',
            'time'            => '19:30',
            'photo'           => UploadedFile::fake()->createWithContent('plate.jpg', TestImage::jpeg()),
        ])->assertStatus(202);

        return Meal::query()->where('uuid', $uuid)->with('items')->firstOrFail();
    }
}
