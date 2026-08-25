<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workout;
use App\Models\StressDaily;
use Carbon\CarbonImmutable;
use Tests\Support\StressFixture;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The /stress page: today's card, one browsable week, the hour x weekday grid.
 * Today's score is computed, never read back: HRV arrives hour by hour, and a
 * number written at 03:50 that has not noticed the morning is worse than none.
 */
final class StressPageTest extends TestCase
{
    use RefreshDatabase;

    /** A Monday, so "this week" starts on it. */
    private const TODAY = '2026-06-15';

    private const CYCLE = [-0.2, -0.1, 0.0, 0.1, 0.2];

    private const BASE_MS = 33.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:00:00', StressFixture::TZ));
    }

    public function test_a_guest_cannot_see_it(): void
    {
        auth()->logout();

        $this->get('/stress')->assertRedirect('/login');
    }

    public function test_the_default_view_is_this_week_and_it_cannot_go_forward(): void
    {
        $this->seedHistory(70);

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Stress')
                ->where('week.start', self::TODAY)
                ->where('week.end', '2026-06-21')
                ->where('week.isCurrent', true)
                ->where('week.next', null)
                ->where('week.previous', '2026-06-08')
                ->has('week.days', 7)
                ->etc()
            );
    }

    /**
     * Training days are CONTEXT, not a second series: sharing no axis with a
     * 1-99 score, a mark under the weekday dates a dip without claiming cause.
     */
    public function test_the_week_marks_the_days_with_a_training_session(): void
    {
        $this->seedHistory(70);

        Workout::factory()->ofType('Martial Arts')->startingAt('2026-06-17', '11:03', 70.3)
            ->withoutDistance()->create();
        Workout::factory()->ofType('Outdoor Run')->startingAt('2026-06-17', '07:27', 47.4)->create();

        // The forgotten timer must not mark four days off one artifact.
        Workout::factory()->ofType('Hiking')->startingAt('2026-06-19', '20:56')->implausible()->create();

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('week.days.0.trained', false)
                ->where('week.days.2.trained', true)
                ->where('week.days.2.trainedLabel', 'Outdoor Run, Martial Arts')
                ->where('week.days.4.trained', false)
                ->where('week.days.4.trainedLabel', null)
                ->etc()
            );
    }

    public function test_an_earlier_week_is_browsable_and_links_forward_again(): void
    {
        $this->seedHistory(70);

        $this->get('/stress?week=2026-06-03')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('week.start', '2026-06-01')
                ->where('week.end', '2026-06-07')
                ->where('week.isCurrent', false)
                ->where('week.next', '2026-06-08')
                ->etc()
            );
    }

    public function test_a_future_or_malformed_week_falls_back_to_this_one(): void
    {
        $this->seedHistory(70);

        foreach (['2027-01-01', 'nonsense', '2026-13-45'] as $week) {
            $this->get('/stress?week='.urlencode($week))
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('week.start', self::TODAY)
                    ->etc()
                );
        }
    }

    public function test_today_is_scored_live_on_render_and_persisted(): void
    {
        $this->seedHistory(70);

        self::assertSame(0, StressDaily::query()->count());

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('today.date', self::TODAY)
                ->where('today.isToday', true)
                ->where('today.score', 65)
                ->where('today.band', 'normal')
                ->where('today.confidence', 'high')
                ->where('today.samples', 24)
                ->has('yesterday.score')
                ->etc()
            );

        // The page computed it; the table remembers it, for the health report.
        $row = StressDaily::query()->find(self::TODAY);

        self::assertNotNull($row);
        self::assertSame(24, $row->samples);
    }

    public function test_a_day_with_no_readings_is_present_and_null_rather_than_missing(): void
    {
        // Sixty days behind, then a two-day hole: a gappy week must LOOK gappy.
        StressFixture::days(
            CarbonImmutable::parse(self::TODAY)->subDays(2)->toDateString(),
            60,
            fn (int $i): float => self::BASE_MS * exp(self::CYCLE[$i % 5]),
        );

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('week.days', 7)
                ->where('week.days.0.date', self::TODAY)
                ->where('week.days.0.score', null)
                ->where('week.days.0.reason', 'no_readings')
                ->where('week.days.0.samples', 0)
                ->where('week.days.6.isFuture', true)
                ->etc()
            );
    }

    public function test_the_bands_scale_and_coverage_rules_travel_with_the_page(): void
    {
        $this->seedHistory(70);

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // Legend and score read one config: a threshold edit cannot split them.
                ->has('bands', 4)
                ->where('bands.0.band', 'great')
                ->where('bands.0.from', 80)
                ->where('bands.3.band', 'overload')
                ->where('scale.baselineScore', 65)
                ->where('baseline.days', 60)
                ->where('baseline.minDays', 21)
                ->where('baseline.minDaySamples', 3)
                ->where('methodVersion', 'v1')
                ->etc()
            );
    }

    public function test_the_heatmap_arrives_with_its_window_stated(): void
    {
        $this->seedHistory(95);

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->has('heatmap.cells', 168)
                ->where('heatmap.to', self::TODAY)
                ->where('heatmap.from', '2026-03-18')
                ->where('heatmap.days', 90)
                ->where('heatmap.minCellSamples', 3)
                ->etc()
            );
    }

    public function test_there_is_no_heatmap_before_there_is_enough_history(): void
    {
        $this->seedHistory(10);

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('heatmap', null)
                ->where('today.score', null)
                ->where('today.reason', 'no_baseline')
                ->etc()
            );
    }

    /**
     * The header jumps eighteen months back, so the label must name WHICH April.
     * The year shows only when it informs: never this year, both across New Year.
     */
    public function test_the_week_label_names_the_year_only_when_it_is_not_this_one(): void
    {
        $this->seedHistory(70);

        $cases = [
            self::TODAY  => '15–21 Jun',
            '2026-04-01' => '30 Mar – 5 Apr',
            '2025-04-16' => '14–20 Apr 2025',
            '2025-03-27' => '24–30 Mar 2025',
            '2025-12-31' => '29 Dec 2025 – 4 Jan 2026',
        ];

        foreach ($cases as $week => $label) {
            $this->get('/stress?week='.$week)
                ->assertOk()
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->where('week.label', $label)
                    ->etc()
                );
        }
    }

    public function test_the_stress_page_is_in_the_bottom_navigation(): void
    {
        // The nav is the only route in: a page nobody can reach is a page nobody has.
        $layout = file_get_contents(resource_path('js/Layouts/AppLayout.vue'));
        self::assertIsString($layout);

        self::assertStringContainsString('href="/stress"', $layout);
        self::assertStringContainsString('Stress', $layout);
    }

    /**
     * Ordinary history, TODAY exactly on the baseline: the headline number is a
     * known 65/Normal, not an accident of where the fixture's cycle landed.
     */
    private function seedHistory(int $days): void
    {
        StressFixture::days(
            self::TODAY,
            $days,
            fn (int $i): float => self::BASE_MS * exp($i === $days - 1 ? 0.0 : self::CYCLE[$i % 5]),
        );
    }
}
