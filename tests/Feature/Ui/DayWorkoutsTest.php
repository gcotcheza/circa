<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\Source;
use App\Models\Workout;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use App\Enums\MetricAggregation;
use Tests\Concerns\ActsAsFreshUser;
use App\Services\Reporting\DailyView;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The day view's `workouts` prop. Asserting on props rather than markup, like
 * DailyViewTest: the shape is the contract between the table and the screen, and
 * the one thing that would break silently.
 */
final class DayWorkoutsTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    private const DATE = '2026-06-15';

    public function test_the_days_sessions_arrive_as_props(): void
    {
        Workout::factory()
            ->ofType('Martial Arts')
            ->startingAt(self::DATE, '11:03', 70.3)
            ->withoutDistance()
            ->create([
                'active_kcal' => 377.75,
                'avg_hr'      => 150,
                'max_hr'      => 173,
                'is_indoor'   => null,
            ]);

        Workout::factory()
            ->ofType('Outdoor Run')
            ->startingAt(self::DATE, '07:27', 47.4)
            ->create(['distance_km' => 6.03, 'active_kcal' => 340.34, 'avg_hr' => 157, 'max_hr' => 174]);

        $this->get('/?date='.self::DATE)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Day')
                ->has('workouts', 2)
                // Chronological, which is how a day is read.
                ->where('workouts.0.type', 'Outdoor Run')
                ->where('workouts.0.start', '07:27')
                ->where('workouts.0.durationMinutes', 47.4)
                ->where('workouts.0.distanceKm', 6.03)
                ->where('workouts.0.activeKcal', 340.34)
                ->where('workouts.0.avgHr', 157)
                ->where('workouts.0.maxHr', 174)
                ->where('workouts.0.isImplausible', false)
                ->where('workouts.1.type', 'Martial Arts')
                // Absent is null, never 0: climbing and martial arts have no
                // distance, and a zero would draw "0.00 km".
                ->where('workouts.1.distanceKm', null)
                ->etc()
        );
    }

    public function test_a_day_without_training_gets_an_empty_list(): void
    {
        $this->get('/?date='.self::DATE)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('Day')->where('workouts', [])
        );
    }

    /**
     * THE FLAGGED ARTIFACT IS STILL SHOWN. Somebody who left a timer running for
     * four days can only find out by seeing it, so the prop carries the flag for
     * the card to say so; the aggregates, not the log, leave it out.
     */
    public function test_an_implausible_session_is_shown_and_flagged(): void
    {
        Workout::factory()
            ->ofType('Hiking')
            ->startingAt(self::DATE, '20:56')
            ->implausible()
            ->create();

        $this->get('/?date='.self::DATE)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('workouts', 1)
                ->where('workouts.0.type', 'Hiking')
                ->where('workouts.0.isImplausible', true)
                ->etc()
        );
    }

    /**
     * A session belongs to the day it STARTED — the generated `local_date` column
     * decides, so a run ending after midnight does not appear twice.
     */
    public function test_a_session_that_crosses_midnight_belongs_to_the_day_it_started(): void
    {
        Workout::factory()->ofType('Outdoor Walk')->startingAt(self::DATE, '23:40', 45)->create();

        $this->get('/?date='.self::DATE)->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->has('workouts', 1)->etc()
        );

        $this->get('/?date=2026-06-16')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->where('workouts', [])->etc()
        );
    }

    /**
     * THE ONE THING THAT MUST NOT HAPPEN. A workout's active energy is ALREADY
     * inside the day's active kcal — the Watch recorded it whether or not a
     * session was running — so landing one must move NO figure in the summary,
     * or every run is double-counted in the balance and the TDEE fit built on it.
     */
    public function test_a_workout_changes_no_energy_figure_on_the_day(): void
    {
        $watch = Source::factory()->watch()->create();

        for ($hour = 0; $hour < 24; $hour++) {
            $this->bucket('active_energy', $watch, $hour, 25.0);
        }

        $before = $this->summary();

        Workout::factory()
            ->ofType('Outdoor Run')
            ->startingAt(self::DATE, '07:27', 47.4)
            ->create(['active_kcal' => 340.34]);

        self::assertSame($before, $this->summary());
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        $props = app(DailyView::class)->props(self::DATE);

        /** @var array<string, mixed> $summary */
        $summary = $props['summary'];

        return $summary;
    }

    private function bucket(string $metric, Source $source, int $hour, float $value, string $unit = 'kcal'): void
    {
        $start = CarbonImmutable::parse(self::DATE.' 00:00:00', 'Europe/Amsterdam')->addHours($hour);

        HealthMetric::factory()->create([
            'metric'                    => $metric,
            'aggregation'               => MetricAggregation::Sum,
            'period'                    => 'hour',
            'value'                     => $value,
            'unit'                      => $unit,
            'started_at'                => $start,
            'ended_at'                  => $start->addHour(),
            'device_utc_offset_minutes' => 120,
            'source_id'                 => $source->id,
        ]);
    }
}
