<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Services\Reporting\DayPace;
use Inertia\Testing\AssertableInertia;
use App\Services\Reporting\EnergyBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Where the day in progress is heading, and the ways that could be a lie.
 *
 * The projection adds the rest of today's RESTING burn — the median of recent
 * complete days — to a burn only two thirds accrued, so the headline describes a
 * day rather than an hour. The tests guard the boundary of that licence: only
 * measured complete days vote and never today's own partial one; below the
 * minimum there is no projection and the card is untouched; active energy is
 * never projected and a day past its usual resting has nothing left to come
 * rather than a negative amount; a finished day carries the props it always did.
 */
final class DayPaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-17 19:16:00', 'Europe/Amsterdam'));
    }

    public function test_the_rest_of_the_days_resting_burn_comes_from_the_median_of_complete_days(): void
    {
        // Deliberately not symmetric: a mean of these five is 1,145.6 and the
        // median is 1,140, so a switch to a mean turns this red.
        $this->complete(['08-12' => 1100.0, '08-13' => 1200.0, '08-14' => 1140.0, '08-15' => 1129.30, '08-16' => 1158.70]);

        $today = $this->inProgress(resting: 865.21, active: 307.36, kcalIn: [1993.23, 2173.50, 2353.78]);

        $projection = $this->projection($today);

        self::assertSame(1140.0, $projection['expectedResting']);

        // 1,140 expected − 865.21 already rested.
        self::assertSame(275.0, $projection['restingRemaining']);
        self::assertSame(5, $projection['basisDays']);
        self::assertTrue($projection['projected']);
    }

    public function test_the_projected_band_is_the_intake_band_against_the_whole_days_burn(): void
    {
        $this->complete(['08-14' => 1140.0, '08-15' => 1140.0, '08-16' => 1140.0]);

        $today = $this->inProgress(resting: 865.21, active: 307.36, kcalIn: [1993.23, 2173.50, 2353.78]);

        $projection = $this->projection($today);

        // Whole-day burn: 307.36 active (measured, never projected) + 865.21
        // rested + 274.79 to come = 1,447.36. Eating the most leaves the smallest
        // balance, so the band's low end comes from the intake's high end:
        // 1447.36 − 2353.78 = −906, 1447.36 − 1993.23 = −546. Both below zero, so
        // a surplus, magnitudes small end first.
        self::assertSame('surplus', $projection['direction']);
        self::assertSame(-906.0, $projection['min']);
        self::assertSame(-546.0, $projection['max']);
        self::assertSame(546.0, $projection['lo']);
        self::assertSame(906.0, $projection['hi']);

        // The measured card underneath is untouched: the same subtraction against
        // the burn SO FAR (1,172.57), the day with none of this.
        $measured = EnergyBalance::from(
            kcalOut: $today->totalKcalOut(),
            kcalInMin: 1993.23,
            kcalInMid: 2173.50,
            kcalInMax: 2353.78,
            provisional: true,
        );

        self::assertNotNull($measured);
        self::assertSame(-1181.0, $measured->min);
        self::assertSame(-821.0, $measured->max);
    }

    public function test_a_partial_day_never_votes_on_what_a_full_one_looks_like(): void
    {
        // Three complete days at 1,140 and two half-covered far below. A watch-off
        // day's resting is a FLOOR, and a median of floors wears a median's authority.
        $this->complete(['08-14' => 1140.0, '08-15' => 1140.0, '08-16' => 1140.0]);

        foreach (['08-11' => 400.0, '08-12' => 380.0] as $day => $kcal) {
            DailySummary::factory()->onDate("2026-{$day}")->watchNotWorn()->create(['resting_kcal' => $kcal]);
        }

        $projection = $this->projection($this->inProgress(resting: 500.0, active: 200.0, kcalIn: [2000.0, 2000.0, 2000.0]));

        self::assertSame(1140.0, $projection['expectedResting']);
        self::assertSame(3, $projection['basisDays']);
    }

    public function test_todays_own_partial_resting_is_not_part_of_its_own_forecast(): void
    {
        $this->complete(['08-14' => 1140.0, '08-15' => 1140.0, '08-16' => 1140.0]);

        // Today is marked fully covered before midnight and must still be excluded,
        // or a morning's 300 kcal drags down the level it is measured against.
        $today = $this->inProgress(resting: 300.0, active: 100.0, kcalIn: [2000.0, 2000.0, 2000.0]);

        $projection = $this->projection($today);

        self::assertSame(1140.0, $projection['expectedResting']);
        self::assertSame(3, $projection['basisDays']);
        self::assertSame(840.0, $projection['restingRemaining']);
    }

    public function test_two_measured_days_are_not_enough_to_forecast_a_third(): void
    {
        $this->complete(['08-15' => 1129.30, '08-16' => 1142.27]);

        self::assertNull(
            app(DayPace::class)->project($this->inProgress(resting: 865.21, active: 307.36, kcalIn: [2000.0, 2100.0, 2200.0])),
            'two days is one person\'s Tuesday, and the card must stay exactly as it is'
        );
    }

    public function test_a_day_already_past_its_usual_resting_has_nothing_left_to_come(): void
    {
        $this->complete(['08-14' => 1100.0, '08-15' => 1120.0, '08-16' => 1140.0]);

        // A late night, or a generous watch. The remainder clamps to zero rather
        // than projecting DOWNWARDS and moving the card the wrong way all evening.
        $today = $this->inProgress(resting: 1400.0, active: 300.0, kcalIn: [2000.0, 2000.0, 2000.0]);

        $projection = $this->projection($today);

        self::assertSame(1120.0, $projection['expectedResting']);
        self::assertSame(0.0, $projection['restingRemaining']);

        // Nothing added, so the projection is the measured balance: 1,700 burned, 2,000 eaten.
        self::assertSame(-300.0, $projection['min']);
        self::assertSame(-300.0, $projection['max']);
    }

    public function test_half_a_burn_is_not_a_day_to_project_from(): void
    {
        $this->complete(['08-14' => 1140.0, '08-15' => 1140.0, '08-16' => 1140.0]);

        $today = DailySummary::factory()->onDate('2026-08-17')->create([
            'resting_kcal' => 865.21,
            'active_kcal'  => null,
            'kcal_in_min'  => 2000.0,
            'kcal_in_mid'  => 2000.0,
            'kcal_in_max'  => 2000.0,
        ]);

        // The measured balance's gate: half a subtraction is not a balance.
        self::assertNull($today->totalKcalOut());
        self::assertNull(app(DayPace::class)->project($today));
    }

    public function test_the_day_in_progress_ships_its_projection_and_a_finished_day_does_not(): void
    {
        $this->actingAs(User::factory()->create());

        $this->complete(['08-14' => 1140.0, '08-15' => 1140.0, '08-16' => 1140.0]);
        $this->inProgress(resting: 865.21, active: 307.36, kcalIn: [1993.23, 2173.50, 2353.78]);

        $this->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('isToday', true)
                // The measured balance is untouched and still says "so far".
                ->where('summary.balance.provisional', true)
                ->where('summary.projection.projected', true)
                ->where('summary.projection.direction', 'surplus')
                ->where('summary.projection.expectedResting', 1140)
                ->where('summary.projection.restingRemaining', 275)
                ->where('summary.projection.basisDays', 3)
                ->etc()
            );

        // Yesterday is finished: the key is present and null, not two card shapes.
        $this->get('/?date=2026-08-16')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('isToday', false)
                ->where('summary.projection', null)
                ->etc()
            );
    }

    /**
     * The projection, insisted upon: every caller is about what it SAYS, so a
     * null one is a broken premise rather than a case to branch on.
     *
     * @return array{direction: string, lo: float, hi: float, min: float, max: float, provisional: bool, projected: bool, expectedResting: float, restingRemaining: float, basisDays: int}
     */
    private function projection(DailySummary $summary): array
    {
        $projection = app(DayPace::class)->project($summary);

        self::assertNotNull($projection, 'there is no projection to read');

        return $projection;
    }

    /**
     * Complete, fully measured days: date => resting kcal.
     *
     * @param  array<string, float>  $byDay
     */
    private function complete(array $byDay): void
    {
        foreach ($byDay as $day => $kcal) {
            DailySummary::factory()->onDate("2026-{$day}")->create([
                'resting_kcal'             => $kcal,
                'has_full_metric_coverage' => true,
            ]);
        }
    }

    /**
     * Today, part way through: a food log that is done and a burn that is not.
     *
     * @param  array{0: float, 1: float, 2: float}  $kcalIn
     */
    private function inProgress(float $resting, float $active, array $kcalIn): DailySummary
    {
        return DailySummary::factory()->onDate('2026-08-17')->create([
            'resting_kcal' => $resting,
            'active_kcal'  => $active,
            'kcal_in_min'  => $kcalIn[0],
            'kcal_in_mid'  => $kcalIn[1],
            'kcal_in_max'  => $kcalIn[2],
        ]);
    }
}
