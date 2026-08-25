<?php

declare(strict_types=1);

namespace Tests\Feature\Memory;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealMemory;
use Illuminate\Support\Str;
use Tests\Support\TestImage;
use Illuminate\Http\Response;
use App\Models\MealMemoryItem;
use Illuminate\Http\UploadedFile;
use Tests\Concerns\ActsAsFreshUser;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeVisionAnalyzer;
use App\Services\Vision\VisionAnalyzer;
use Illuminate\Support\Facades\Storage;
use App\Services\Memory\MealFingerprint;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What confirming a meal teaches the app. These go through the HTTP endpoints
 * rather than the recorder: the claim is "EVERY confirmation is remembered",
 * and the only way that fails is a write path that misses the hook.
 */
final class MemoryRecorderTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    public function test_confirming_a_typed_meal_records_it(): void
    {
        $this->logMeal('2026-06-15', [
            $this->item('Chicken breast', 150, 165),
            $this->item('White rice', 200, 130),
        ])->assertRedirect();

        $memory = MealMemory::query()->with('items')->sole();

        self::assertSame(
            (new MealFingerprint)->forNames(['White rice', 'Chicken breast']),
            $memory->fingerprint
        );
        self::assertSame(1, $memory->times_logged);
        self::assertSame('Chicken breast, White rice', $memory->canonical_name);
        self::assertSame('2026-06-15', $memory->last_logged_at?->setTimezone('Europe/Amsterdam')->toDateString());

        // Densities and the logged portion, so a re-log pre-fills with no conversion.
        $rice = $memory->items->firstWhere('slug', 'white-rice');

        self::assertNotNull($rice);
        self::assertSame(200.0, (float) $rice->typical_portion_g);
        self::assertSame(130.0, (float) $rice->kcal_per_100g_min);
        self::assertSame(130.0, (float) $rice->kcal_per_100g_max);
    }

    public function test_the_same_shape_on_another_day_increments_rather_than_duplicates(): void
    {
        $items = [$this->item('Chicken breast', 150, 165), $this->item('White rice', 200, 130)];

        $this->logMeal('2026-06-15', $items);
        // Same two foods, listed the other way round: a meal is a set.
        $this->logMeal('2026-06-16', array_reverse($items));

        self::assertSame(1, MealMemory::query()->count());

        $memory = MealMemory::query()->sole();

        self::assertSame(2, $memory->times_logged);
        self::assertSame('2026-06-16', $memory->last_logged_at?->setTimezone('Europe/Amsterdam')->toDateString());
    }

    /**
     * `last_logged_at` drives the picker's recency ranking and means "when did
     * I last EAT this" — back-filling Sunday on Tuesday must not outrank Monday.
     */
    public function test_logging_an_older_day_does_not_rewind_recency(): void
    {
        $items = [$this->item('Porridge', 300, 71)];

        $this->logMeal('2026-06-16', $items);
        $this->logMeal('2026-06-10', $items);

        $memory = MealMemory::query()->sole();

        self::assertSame(2, $memory->times_logged);
        self::assertSame('2026-06-16', $memory->last_logged_at?->setTimezone('Europe/Amsterdam')->toDateString());
    }

    /**
     * The portion is a HABIT: a running mean, so one hungry Tuesday moves it
     * rather than redefining it. Densities are not averaged — the latest
     * confirmation is the user's best current statement.
     */
    public function test_the_remembered_portion_is_a_running_mean(): void
    {
        $this->logMeal('2026-06-15', [$this->item('Porridge', 200, 71)]);

        self::assertSame(200.0, (float) MealMemoryItem::query()->sole()->typical_portion_g);

        $this->logMeal('2026-06-16', [$this->item('Porridge', 100, 71)]);

        // 200 + (100 - 200) / 2
        self::assertSame(150.0, (float) MealMemoryItem::query()->sole()->typical_portion_g);

        $this->logMeal('2026-06-17', [$this->item('Porridge', 300, 71)]);

        // 150 + (300 - 150) / 3
        self::assertSame(200.0, (float) MealMemoryItem::query()->sole()->typical_portion_g);
    }

    /**
     * The fingerprint is a set, so two slices are one token — but 2 x 30 g is
     * 60 g, and remembering 30 g would halve the meal on every re-log.
     */
    public function test_two_helpings_of_the_same_food_are_one_item_with_both_portions(): void
    {
        $this->logMeal('2026-06-15', [
            $this->item('Rye bread', 30, 250),
            $this->item('Rye bread', 30, 250),
            $this->item('Cheese', 20, 400),
        ]);

        $memory = MealMemory::query()->with('items')->sole();

        self::assertSame(
            (new MealFingerprint)->forNames(['Rye bread', 'Cheese']),
            $memory->fingerprint
        );
        self::assertCount(2, $memory->items);
        self::assertSame(60.0, (float) $this->notNull($memory->items->firstWhere('slug', 'rye-bread'))->typical_portion_g);
    }

    /**
     * `times_logged` counts meals eaten, not forms submitted: without the
     * `meals.memory_fingerprint` guard it would count note edits.
     */
    public function test_editing_a_meal_without_changing_its_items_does_not_count_again(): void
    {
        $items = [$this->item('Chicken breast', 150, 165), $this->item('White rice', 200, 130)];

        $uuid = (string) Str::uuid();

        $this->logMeal('2026-06-15', $items, $uuid);
        $this->editMeal($uuid, '2026-06-15', $items, notes: 'a bit dry');

        $memory = MealMemory::query()->sole();

        self::assertSame(1, $memory->times_logged);
    }

    /**
     * Edit semantics: the NEW shape is counted and the old aggregate keeps its
     * count — correcting today does not un-eat the dinners behind the number.
     */
    public function test_changing_the_items_records_the_new_shape_and_leaves_the_old_one_alone(): void
    {
        $uuid = (string) Str::uuid();

        $this->logMeal('2026-06-15', [
            $this->item('Chicken breast', 150, 165),
            $this->item('White rice', 200, 130),
        ], $uuid);

        $this->editMeal($uuid, '2026-06-15', [
            $this->item('Chicken breast', 150, 165),
            $this->item('Sweet potato', 220, 86),
        ]);

        $fingerprints = new MealFingerprint;

        $old = MealMemory::query()
            ->where('fingerprint', $fingerprints->forNames(['Chicken breast', 'White rice']))
            ->sole();

        $new = MealMemory::query()
            ->with('items')
            ->where('fingerprint', $fingerprints->forNames(['Chicken breast', 'Sweet potato']))
            ->sole();

        self::assertSame(1, $old->times_logged, 'history is not rewritten');
        self::assertSame(1, $new->times_logged);
        self::assertSame('Chicken breast, Sweet potato', $new->canonical_name);

        // And the meal now points at the shape it actually is.
        self::assertSame(
            $new->fingerprint,
            Meal::query()->where('uuid', $uuid)->sole()->memory_fingerprint
        );
    }

    public function test_deleting_a_meal_leaves_the_memory_as_history(): void
    {
        $uuid = (string) Str::uuid();

        $this->logMeal('2026-06-15', [$this->item('Porridge', 300, 71)], $uuid);

        $this->delete('/meals/'.$uuid)->assertRedirect();

        // A claim about the past: deleting today's row does not un-eat it, and
        // decrementing to zero would throw away weeks of tuned portions.
        self::assertSame(1, MealMemory::query()->sole()->times_logged);
    }

    /**
     * "The model proposes, the user confirms." Remembering a proposal would
     * teach the app from a guess it was built to make the user check.
     */
    public function test_a_proposal_is_not_remembered_until_it_is_confirmed(): void
    {
        Storage::fake('meal-photos');

        $analyzer = (new FakeVisionAnalyzer)->willPropose([
            FakeVisionAnalyzer::rice(),
            FakeVisionAnalyzer::chicken(),
        ]);

        $this->app->instance(VisionAnalyzer::class, $analyzer);

        $uuid = (string) Str::uuid();

        $this->post('/api/meals/photo', [
            'uuid'            => $uuid,
            'idempotency_key' => (string) Str::uuid(),
            'date'            => '2026-06-15',
            'time'            => '19:30',
            'photo'           => UploadedFile::fake()->createWithContent('plate.jpg', TestImage::jpeg()),
        ])->assertStatus(202);

        self::assertSame(0, MealMemory::query()->count());

        $meal = Meal::query()->where('uuid', $uuid)->with('items')->firstOrFail();

        $this->put("/meals/{$meal->uuid}/proposal", [
            'date'  => '2026-06-15',
            'time'  => '19:30',
            'items' => $meal->items->map(static fn ($item): array => [
                'name'          => $item->name,
                'portion_g_min' => (float) $item->portion_g_min,
                'portion_g_max' => (float) $item->portion_g_max,
                'kcal_min'      => 156,
                // Wider than the fixture, so the stored densities are a real band.
                'kcal_max'      => 300,
                'protein_g_min' => 3,
                'protein_g_max' => 6,
                'carbs_g_min'   => 33,
                'carbs_g_max'   => 56,
                'fat_g_min'     => 0.3,
                'fat_g_max'     => 0.7,
            ])->all(),
        ])->assertRedirect();

        $memory = MealMemory::query()->with('items')->sole();

        self::assertSame(1, $memory->times_logged);
        self::assertSame(
            (new MealFingerprint)->forNames(['White rice', 'Grilled chicken breast']),
            $memory->fingerprint
        );

        // The BAND survives into memory: re-logging uncertainty keeps it uncertain.
        $rice = $memory->items->firstWhere('slug', 'white-rice');

        self::assertNotNull($rice);
        self::assertGreaterThan((float) $rice->kcal_per_100g_min, (float) $rice->kcal_per_100g_max);

        // The portion collapses to the midpoint of 120-200 g: a habit is not a band.
        self::assertSame(160.0, (float) $rice->typical_portion_g);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return TestResponse<Response>
     */
    private function logMeal(string $date, array $items, ?string $uuid = null): TestResponse
    {
        return $this->post('/meals', [
            'uuid'  => $uuid ?? (string) Str::uuid(),
            'date'  => $date,
            'time'  => '12:30',
            'items' => $items,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return TestResponse<Response>
     */
    private function editMeal(string $uuid, string $date, array $items, ?string $notes = null): TestResponse
    {
        return $this->put('/meals/'.$uuid, [
            'uuid'  => $uuid,
            'date'  => $date,
            'time'  => '12:30',
            'notes' => $notes,
            'items' => $items,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $name, float $grams, float $kcalPer100g): array
    {
        return [
            'name'          => $name,
            'basis'         => 'per_100g',
            'grams'         => $grams,
            'kcal_per_100g' => $kcalPer100g,
        ];
    }
}
