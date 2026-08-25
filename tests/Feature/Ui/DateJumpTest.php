<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\User;
use App\Models\Source;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Jumping to a date, on the two screens that browse a series.
 *
 * The control is a calendar (Air Datepicker via Components/AirDate.vue) behind
 * an invisible input over the header label — see Components/DateJump.vue. None
 * of that is testable from here and none of it is this app's code; the
 * JavaScript half that IS ours has its own tests in tests/js/dates.test.js. What
 * this app decides is the two ENDS of the picker, where the history starts and
 * where today is, and getting either wrong is silent: a floor that is too late
 * hides months of real days behind a control that looks like it works, and a
 * missing one offers 1970.
 *
 * The other half is the landing: `?date=` and `?week=` already took a date, and
 * these tests pin that the picker's output is a thing those parameters accept —
 * including a Wednesday, which the stress page has to resolve to the week around
 * it rather than 404 or clamp to today.
 */
final class DateJumpTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Europe/Amsterdam';

    /** A Monday, so "this week" starts on it. */
    private const TODAY = '2026-06-15';

    private ?Source $watch = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:00:00', self::TZ));
    }

    public function test_the_day_picker_is_bounded_by_the_history_and_by_today(): void
    {
        $this->measurementOn('2023-04-05');
        $this->measurementOn('2026-06-14');

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Day')
                ->where('earliest', '2023-04-05')
                // The other end, already sent for the "back to today" shortcut.
                ->where('today', self::TODAY)
                ->etc()
            );
    }

    /** A logged meal is a day the view can show, so both origins move the floor. */
    public function test_a_meal_older_than_any_measurement_moves_the_day_floor_back(): void
    {
        $this->measurementOn('2026-01-10');

        Meal::factory()->eatenAt(CarbonImmutable::parse('2025-11-02 12:00', self::TZ))->create();

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('earliest', '2025-11-02')
                ->etc()
            );
    }

    /**
     * A fresh deploy is an app that does not know its past yet, not one with no
     * past: null leaves that end unbounded rather than pinning it to today.
     */
    public function test_an_empty_database_leaves_the_day_picker_unbounded(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('earliest', null)
                ->etc()
            );
    }

    public function test_a_picked_date_lands_on_that_day(): void
    {
        $this->measurementOn('2025-02-03');

        $this->get('/?date=2025-02-03')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('date', '2025-02-03')
                ->where('isToday', false)
                ->where('label', 'Mon 3 Feb')
                ->etc()
            );
    }

    /**
     * The stress floor is NARROWER than the day view's, deliberately: that page
     * can only draw a week it has HRV for, and this history's step counts start
     * long before its HRV. Offering a year of weeks that are seven blanks by
     * construction would make the control feel broken on its first use.
     */
    public function test_the_week_picker_is_bounded_by_the_first_hrv_reading(): void
    {
        $this->measurementOn('2023-04-05');
        $this->measurementOn('2024-09-01', 'heart_rate_variability');
        $this->measurementOn('2026-06-14', 'heart_rate_variability');

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Stress')
                ->where('week.earliest', '2024-09-01')
                ->where('today_date', self::TODAY)
                ->etc()
            );
    }

    public function test_the_week_picker_is_unbounded_before_the_first_reading(): void
    {
        // Steps, and nothing this page can score.
        $this->measurementOn('2026-06-01');

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('week.earliest', null)
                ->etc()
            );
    }

    /**
     * The picker hands back whatever day was spun to, a Wednesday six times out
     * of seven, and `?week=` has always meant "any date inside the week to show".
     */
    public function test_a_picked_date_lands_on_the_week_that_contains_it(): void
    {
        $this->measurementOn('2025-04-16', 'heart_rate_variability');

        $this->get('/stress?week=2025-04-16')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('week.start', '2025-04-14')
                ->where('week.end', '2025-04-20')
                ->where('week.isCurrent', false)
                ->etc()
            );
    }

    /**
     * One reading, on one day, from the one Watch. The source is made once and
     * reused: `sources.raw_name` is unique and the factory mints a fresh Watch
     * per row, which is a second "Demo's Apple Watch" the moment a test needs
     * two readings.
     */
    private function measurementOn(string $date, string $metric = 'step_count'): void
    {
        $this->watch ??= Source::factory()->watch()->create();

        HealthMetric::factory()
            ->metric($metric)
            ->fromSource($this->watch)
            ->inHourBucket(CarbonImmutable::parse($date.' 12:00', self::TZ))
            ->create();
    }
}
