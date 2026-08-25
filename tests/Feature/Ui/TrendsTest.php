<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use Inertia\Testing\AssertableInertia;
use App\Services\Reporting\TrendSeries;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The trends page and its EMA. Server-side on purpose: step 7's TDEE estimator
 * takes the OLS slope of this same series; a JS copy would draw a different line.
 */
final class TrendsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00', 'Europe/Amsterdam'));
    }

    public function test_the_default_window_is_seven_days_ending_today(): void
    {
        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trends')
                ->where('range', 7)
                ->where('start', '2026-06-09')
                ->where('end', '2026-06-15')
                ->has('days', 7)
                ->etc()
            );
    }

    public function test_a_longer_window_is_selectable_and_anything_else_is_not(): void
    {
        $this->get('/trends?range=28')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('days', 28)->etc());

        // A free-integer range is a slow query and an unreadable chart, and no button makes one.
        $this->get('/trends?range=3650')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('range', 7)->etc());
    }

    public function test_days_with_no_summary_are_present_and_null_rather_than_missing(): void
    {
        DailySummary::factory()->onDate('2026-06-14')->create([
            'kcal_in_mid'  => 2000,
            'kcal_in_min'  => 1900,
            'kcal_in_max'  => 2100,
            'active_kcal'  => 500,
            'resting_kcal' => 1400,
            'steps'        => 9000,
        ]);

        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                // A gap in the chart has to be a gap, not a collapsed axis.
                ->has('days', 7)
                ->where('days.5.date', '2026-06-14')
                ->where('days.5.kcalOut', 1900)
                ->where('days.5.steps', 9000)
                ->where('days.0.kcalIn.mid', null)
                ->where('days.0.kcalOut', null)
                ->etc()
            );
    }

    public function test_the_weight_ema_smooths_and_skips_gaps(): void
    {
        config()->set('health.trends.ema_alpha', 0.5);

        // Noisy and gappy like real daily weight — ±1 kg swings on water alone.
        DailySummary::factory()->onDate('2026-06-09')->create(['weight_kg' => 56.0]);
        DailySummary::factory()->onDate('2026-06-10')->create(['weight_kg' => 58.0]);
        DailySummary::factory()->onDate('2026-06-14')->create(['weight_kg' => 57.0]);

        $props = app(TrendSeries::class)->props(7);
        $ema = collect($this->asArray($props['days']))->keyBy('date');

        // Seeded from the first observation, not from zero.
        self::assertSame(56.0, $ema['2026-06-09']['weightEma']);
        // 0.5 * 58 + 0.5 * 56.
        self::assertSame(57.0, $ema['2026-06-10']['weightEma']);
        // 0.5 * 57 + 0.5 * 57: four days without a weigh-in are four days of no information.
        self::assertSame(57.0, $ema['2026-06-14']['weightEma']);

        self::assertNull($ema['2026-06-11']['weightEma']);
        self::assertNull($ema['2026-06-11']['weightKg']);
    }

    public function test_the_ema_is_seeded_from_history_outside_the_window(): void
    {
        // Otherwise a 7-day chart opens on a cold start that reads as a sudden jump.
        DailySummary::factory()->onDate('2026-05-20')->create(['weight_kg' => 60.0]);
        DailySummary::factory()->onDate('2026-06-10')->create(['weight_kg' => 56.0]);

        config()->set('health.trends.ema_alpha', 0.5);

        $props = app(TrendSeries::class)->props(7);
        $day = collect($this->asArray($props['days']))->firstWhere('date', '2026-06-10');

        // 0.5 * 56 + 0.5 * 60 = 58, not 56.
        self::assertSame(58.0, $day['weightEma']);
    }
}
