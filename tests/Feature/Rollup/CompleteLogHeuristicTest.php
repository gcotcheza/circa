<?php

declare(strict_types=1);

namespace Tests\Feature\Rollup;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Services\Rollup\DailySummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `is_complete_log` — which days the TDEE estimator may average.
 *
 * SPEC.md: "≥N items, last item after ~18:00 local, kcal above a floor.
 * Breakfast-only days must not read as low-intake days."
 *
 * All three must hold; each test below passes exactly two. A day that looks
 * disciplined because half of it was never typed in drags estimated maintenance
 * intake down, and that estimate is the whole product. N and the floor are
 * config (`health.rollup.complete_log`), movable without a migration.
 */
final class CompleteLogHeuristicTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    private const DAY_START = '2026-06-14 22:00:00';

    public function test_a_full_day_of_logging_counts(): void
    {
        $this->meal(8, [500, 300]);
        $this->meal(13, [700]);
        $this->meal(19, [600]);

        $summary = $this->build();

        self::assertSame(2100.0, (float) $summary->kcal_in_mid);
        self::assertTrue($summary->is_complete_log);
    }

    public function test_a_breakfast_only_day_does_not_count(): void
    {
        // Four items and 1 400 kcal clears count and floor, but not the clock:
        // a day that stopped being logged, not a day of eating little.
        $this->meal(8, [400, 350, 350, 300]);

        $summary = $this->build();

        self::assertSame(1400.0, (float) $summary->kcal_in_mid);
        self::assertFalse($summary->is_complete_log);
    }

    public function test_a_late_snack_alone_does_not_count(): void
    {
        // After 18:00 and over the item count, but 300 kcal is not a day.
        $this->meal(21, [100, 100, 100]);

        self::assertFalse($this->build()->is_complete_log);
    }

    public function test_one_big_late_item_does_not_count(): void
    {
        // Late and far over the floor, but one item is a placeholder, not a log:
        // N exists to reject "2500 kcal, restaurant".
        $this->meal(20, [2500]);

        $summary = $this->build();

        self::assertSame(2500.0, (float) $summary->kcal_in_mid);
        self::assertFalse($summary->is_complete_log);
    }

    public function test_the_thresholds_are_configuration_not_code(): void
    {
        config()->set('health.rollup.complete_log', [
            'min_items'       => 1,
            'last_meal_after' => '12:00',
            'kcal_floor'      => 200,
        ]);

        $this->meal(13, [400]);

        // Same rows, different rule, no migration: daily_summaries is derived.
        self::assertTrue($this->build()->is_complete_log);
    }

    public function test_a_day_with_no_meals_is_not_a_complete_log(): void
    {
        $summary = $this->build();

        self::assertNull($summary->kcal_in_mid);
        self::assertFalse($summary->is_complete_log);
    }

    /**
     * @param  list<float>  $kcals  one absolute kcal figure per item
     */
    private function meal(int $localHour, array $kcals): void
    {
        $meal = Meal::withoutEvents(fn (): Meal => Meal::factory()->eatenAt(
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours($localHour)
        )->create());

        foreach ($kcals as $kcal) {
            // The manual-entry shape: 100 g basis, zero-width.
            MealItem::withoutEvents(fn () => MealItem::factory()->create([
                'meal_id'           => $meal->id,
                'portion_g_min'     => 100,
                'portion_g_max'     => 100,
                'kcal_per_100g_min' => $kcal,
                'kcal_per_100g_max' => $kcal,
            ]));
        }
    }

    private function build(): DailySummary
    {
        return app(DailySummaryBuilder::class)->build(self::DATE);
    }
}
