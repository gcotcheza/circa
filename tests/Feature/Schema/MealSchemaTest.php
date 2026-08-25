<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Tests\TestCase;
use App\Models\Meal;
use App\Enums\MealType;
use App\Models\MealItem;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealMemory;
use App\Models\FoodProduct;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Models\TdeeEstimate;
use App\Models\VisionRequest;
use App\Models\MealMemoryItem;
use App\Enums\VisionRequestStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Schema-level guarantees for the meal side.
 */
final class MealSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_meal_enums_cast_and_relations_resolve(): void
    {
        $meal = Meal::factory()->proposed()->create(['meal_type' => MealType::Dinner]);
        $item = MealItem::factory()->create(['meal_id' => $meal->id]);
        $request = VisionRequest::factory()->create(['meal_id' => $meal->id]);

        $fresh = $this->notNull($meal->fresh());

        self::assertSame(MealStatus::Proposed, $fresh->status);
        self::assertSame(MealSource::Photo, $fresh->source);
        self::assertSame(MealType::Dinner, $fresh->meal_type);
        self::assertFalse($fresh->status->countsTowardIntake());

        self::assertTrue($this->notNull($fresh->items->first())->is($item));
        self::assertTrue($this->notNull($fresh->visionRequests->first())->is($request));
        self::assertTrue($this->notNull($item->meal)->is($meal));
    }

    public function test_an_unknown_status_is_rejected_by_the_model(): void
    {
        // The cast is the first line: a bad value never reaches the driver.
        $this->expectException(\ValueError::class);

        Meal::factory()->create(['status' => 'eaten-probably']);
    }

    public function test_an_unknown_status_is_rejected_by_the_database(): void
    {
        // The CHECK constraint backstops anything writing round the model — a
        // raw insert, a future job, a psql session at 2am.
        $this->expectException(QueryException::class);

        DB::table('meals')->insert([
            'uuid'     => '5b8f0e5e-9c1a-4f6f-9a2c-2d1f7d0d0f02',
            'status'   => 'eaten-probably',
            'source'   => MealSource::Manual->value,
            'eaten_at' => '2026-08-07 12:00:00+02',
        ]);
    }

    public function test_client_generated_uuid_is_unique(): void
    {
        $uuid = '5b8f0e5e-9c1a-4f6f-9a2c-2d1f7d0d0f01';

        Meal::factory()->create(['uuid' => $uuid]);

        // The offline queue retries with the same uuid; the index is what stops
        // a flush creating a second meal.
        $this->expectException(QueryException::class);

        Meal::factory()->create(['uuid' => $uuid]);
    }

    public function test_meal_local_date_is_generated_from_eaten_at(): void
    {
        // 21:30 UTC = 23:30 local on the same day.
        $meal = Meal::factory()->eatenAt(CarbonImmutable::parse('2026-08-07 21:30:00', 'UTC'))->create();
        self::assertSame('2026-08-07', $this->notNull($meal->fresh())->local_date->toDateString());

        // 22:30 UTC has already rolled over to the next local day.
        $late = Meal::factory()->eatenAt(CarbonImmutable::parse('2026-08-07 22:30:00', 'UTC'))->create();
        self::assertSame('2026-08-08', $this->notNull($late->fresh())->local_date->toDateString());
    }

    public function test_absolute_values_are_derived_from_per_100g_densities(): void
    {
        $item = MealItem::factory()->named('Chicken breast')->create([
            'portion_g_min'        => 100,
            'portion_g_max'        => 200,
            'kcal_per_100g_min'    => 150,
            'kcal_per_100g_max'    => 180,
            'protein_per_100g_min' => 28,
            'protein_per_100g_max' => 32,
        ]);

        // Editing the portion changes the answer; no stored total goes stale.
        self::assertSame(['min' => 150.0, 'max' => 360.0], $item->kcalRange());
        self::assertSame(['min' => 28.0, 'max' => 64.0], $item->macroRange('protein'));
    }

    public function test_purging_a_cached_product_does_not_delete_the_food_log(): void
    {
        $product = FoodProduct::factory()->create(['barcode' => '5000112637922']);
        $item = MealItem::factory()->exact()->create(['food_product_id' => $product->barcode]);

        self::assertTrue($this->notNull($item->foodProduct)->is($product));
        self::assertIsArray($this->notNull($product->fresh())->raw);

        $product->delete();

        // nullOnDelete: the cache is disposable, the record of what was eaten is not.
        self::assertTrue($this->notNull($item->fresh())->exists);
        self::assertNull($this->notNull($item->fresh())->food_product_id);
    }

    public function test_vision_request_idempotency_key_is_unique_and_jsonb_round_trips(): void
    {
        $key = 'idem-4f6f9a2c2d1f';

        $request = VisionRequest::factory()->create(['idempotency_key' => $key]);
        $fresh = $this->notNull($request->fresh());

        self::assertSame(VisionRequestStatus::Succeeded, $fresh->status);
        $rawResponse = $this->notNull($fresh->raw_response);
        self::assertSame('Chicken breast', $rawResponse['items'][0]['name']);

        // Double-tap must not double-pay.
        $this->expectException(QueryException::class);

        VisionRequest::factory()->create(['idempotency_key' => $key]);
    }

    public function test_meal_memory_fingerprint_is_order_independent(): void
    {
        $a = MealMemory::fingerprintFor(['Chicken breast', 'White rice, cooked', 'Broccoli']);
        $b = MealMemory::fingerprintFor(['Broccoli', 'Chicken breast', 'White rice, cooked']);

        // A meal is a set, not a sequence, and vision's order is not stable.
        self::assertSame($a, $b);
        self::assertSame(64, mb_strlen($a));

        // A different set is a different meal.
        self::assertNotSame($a, MealMemory::fingerprintFor(['Chicken breast', 'Broccoli']));
    }

    public function test_meal_memory_relations_and_uniqueness(): void
    {
        $memory = MealMemory::factory()->forItems(['Chicken breast', 'Broccoli'])->create();
        $item = MealMemoryItem::factory()->named('Chicken breast')->create([
            'meal_memory_id' => $memory->id,
        ]);

        self::assertTrue($this->notNull($memory->items->first())->is($item));
        self::assertTrue($this->notNull($item->memory)->is($memory));

        // One row per food per remembered meal.
        $this->expectException(QueryException::class);

        MealMemoryItem::factory()->named('Chicken breast')->create([
            'meal_memory_id' => $memory->id,
        ]);
    }

    public function test_daily_summary_is_keyed_on_local_date_and_joins_meals(): void
    {
        $summary = DailySummary::factory()->onDate('2026-08-07')->withWeighIn(56.2)->create();

        // withoutEvents: this is about the generated-column join, not step 3's
        // rebuild trigger. Creating a meal normally fires MealObserver, whose
        // rebuild recomputes the very row the factory set up, leaving the
        // assertions below testing the rollup. The rebuild has its own tests.
        $meal = Meal::withoutEvents(fn (): Meal => Meal::factory()
            ->eatenAt(CarbonImmutable::parse('2026-08-07 12:00:00', 'UTC'))
            ->create());

        // Both sides come from the same generated expression, so there is no PHP
        // date arithmetic anywhere in the join.
        self::assertTrue($this->notNull($summary->meals->first())->is($meal));

        $fresh = $this->notNull($summary->fresh());
        self::assertTrue($fresh->is_complete_log);
        self::assertFalse($fresh->active_kcal_is_partial);
        self::assertTrue($fresh->expenditureIsTrustworthy());
        self::assertNotNull($fresh->totalKcalOut());
    }

    public function test_a_day_the_watch_was_not_worn_flags_itself(): void
    {
        $summary = DailySummary::factory()->onDate('2026-08-06')->watchNotWorn()->create();

        // Under-reported burn must not be presentable as a fact.
        self::assertFalse($this->notNull($summary->fresh())->expenditureIsTrustworthy());
    }

    public function test_daily_summary_local_date_is_the_primary_key(): void
    {
        DailySummary::factory()->onDate('2026-08-05')->create();

        $this->expectException(QueryException::class);

        DailySummary::factory()->onDate('2026-08-05')->create();
    }

    public function test_tdee_estimates_are_unique_per_window_and_method(): void
    {
        $estimate = TdeeEstimate::factory()->forWindow('2026-07-11', '2026-08-07')->create();

        self::assertTrue($estimate->meetsGate());
        self::assertGreaterThan((float) $estimate->tdee_min, $estimate->midpoint());

        // A new method version may coexist with the old one for the same window.
        TdeeEstimate::factory()
            ->forWindow('2026-07-11', '2026-08-07')
            ->create(['method_version' => 'v2']);

        self::assertSame(2, TdeeEstimate::count());

        $this->expectException(QueryException::class);

        TdeeEstimate::factory()->forWindow('2026-07-11', '2026-08-07')->create();
    }

    public function test_an_estimate_below_the_gate_says_so(): void
    {
        $estimate = TdeeEstimate::factory()->belowGate()->create();

        self::assertFalse($estimate->meetsGate());
    }
}
