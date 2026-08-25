<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\Source;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use App\Enums\MetricAggregation;
use Tests\Concerns\ActsAsFreshUser;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The daily view's props.
 *
 * Props rather than markup: the data shape is the contract between the rollup
 * and the screen, and the thing that breaks silently. A missing
 * `flags.activeKcalIsPartial` does not throw — it just stops warning that the
 * burn figure is an undercount.
 */
final class DailyViewTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    private const DAY_START = '2026-06-14 22:00:00';

    public function test_the_daily_view_renders_a_real_day(): void
    {
        $watch = Source::factory()->watch()->create();

        for ($hour = 0; $hour < 24; $hour++) {
            $this->bucket('active_energy', $watch, $hour, 25.0);
            $this->bucket('basal_energy_burned', $watch, $hour, 60.0);
            $this->bucket('step_count', $watch, $hour, 360.0, 'count');
        }

        SleepSession::factory()->fromSource($watch)->forNight(self::DATE)->create([
            'total_sleep_minutes' => 469.024,
            'rem_minutes'         => 134.299,
            'core_minutes'        => 292.137,
            'deep_minutes'        => 42.588,
            'awake_minutes'       => 52.623,
        ]);

        $meal = Meal::factory()->eatenAt(
            CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours(13)
        )->create();

        MealItem::factory()->create([
            'meal_id'           => $meal->id,
            'name'              => 'Chicken breast',
            'portion_g_min'     => 150,
            'portion_g_max'     => 150,
            'kcal_per_100g_min' => 165,
            'kcal_per_100g_max' => 165,
        ]);

        $this->get('/?date='.self::DATE)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Day')
                ->where('date', self::DATE)
                ->where('isToday', false)
                ->where('previous', '2026-06-14')
                ->where('next', '2026-06-16')
                ->where('summary.activeKcal', 600)
                ->where('summary.restingKcal', 1440)
                ->where('summary.kcalOut', 2040)
                ->where('summary.steps', 8640)
                // JSON reads integral values back as ints; 247.5 keeps its fraction.
                ->where('summary.kcalIn.mid', 247.5)
                ->where('summary.flags.hasFullMetricCoverage', true)
                ->where('summary.flags.activeKcalIsPartial', false)
                ->has('meals', 1)
                // 22:00 UTC on the 14th + 13 h = 11:00 UTC = 13:00 CEST.
                ->where('meals.0.time', '13:00')
                ->where('meals.0.kcal.mid', 248)
                ->has('meals.0.items', 1)
                ->where('meals.0.items.0.name', 'Chicken breast')
                ->where('sleep.totalMinutes', 469.024)
                ->etc()
            );
    }

    public function test_an_empty_day_renders_rather_than_erroring(): void
    {
        // No metrics, meals or summary row: the normal case for a future day.
        $this->get('/?date=2026-06-20')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Day')
                ->where('summary.kcalIn.mid', null)
                ->where('summary.kcalOut', null)
                ->where('summary.flags.isCompleteLog', false)
                ->has('meals', 0)
                ->where('sleep', null)
                ->etc()
            );
    }

    public function test_today_has_no_next_day_to_offer(): void
    {
        $today = CarbonImmutable::now('Europe/Amsterdam');

        // The arrow is disabled on today, but a SWIPE has no disabled state, so
        // tomorrow must be absent: loading it reset every strip on the page.
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('isToday', true)
                ->where('next', null)
                ->where('previous', $today->subDay()->toDateString())
                ->etc()
            );

        // Yesterday still steps forward, and forward is today.
        $this->get('/?date='.$today->subDay()->toDateString())
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('isToday', false)
                ->where('next', $today->toDateString())
                ->etc()
            );
    }

    public function test_an_unparseable_date_falls_back_to_today(): void
    {
        $today = CarbonImmutable::now('Europe/Amsterdam')->toDateString();

        // A restored tab's stale query string shows the food log, not an error.
        $this->get('/?date=not-a-date')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('date', $today)
                ->where('isToday', true)
                ->etc()
            );
    }

    public function test_recent_meals_are_offered_for_one_tap_relogging(): void
    {
        $older = Meal::factory()->eatenAt(CarbonImmutable::parse('2026-06-10 12:00', 'UTC'))->create();
        MealItem::factory()->named('Porridge')->create(['meal_id' => $older->id]);

        // Same item set on another day: one entry in the list, not two.
        $duplicate = Meal::factory()->eatenAt(CarbonImmutable::parse('2026-06-11 12:00', 'UTC'))->create();
        MealItem::factory()->named('Porridge')->create(['meal_id' => $duplicate->id]);

        $other = Meal::factory()->eatenAt(CarbonImmutable::parse('2026-06-12 19:00', 'UTC'))->create();
        MealItem::factory()->named('Salmon fillet')->create(['meal_id' => $other->id]);

        $this->get('/?date='.self::DATE)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('recentMeals', 2)
                // Most recent first.
                ->where('recentMeals.0.label', 'Salmon fillet')
                ->where('recentMeals.1.label', 'Porridge')
                ->etc()
            );
    }

    private function bucket(string $metric, Source $source, int $hour, float $value, string $unit = 'kcal'): void
    {
        $start = CarbonImmutable::parse(self::DAY_START, 'UTC')->addHours($hour);

        HealthMetric::query()->create([
            'metric'                    => $metric,
            'aggregation'               => MetricAggregation::Sum,
            'period'                    => 'hour',
            'value'                     => $value,
            'unit'                      => $unit,
            'started_at'                => $start,
            'ended_at'                  => $start->addHour(),
            'device_utc_offset_minutes' => 120,
            'source_id'                 => $source->id,
            'ingested_at'               => CarbonImmutable::now(),
        ]);
    }
}
