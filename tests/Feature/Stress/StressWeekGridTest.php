<?php

declare(strict_types=1);

namespace Tests\Feature\Stress;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\StressFixture;
use App\Services\Reporting\StressView;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The week grid, end to end: does the page ship the week you are looking at,
 * hour by hour, with its holes intact?
 *
 * THE TWO GRIDS ARE DIFFERENT ANSWERS AND MUST NOT BECOME ONE. `weekGrid`
 * follows `?week=` — seven real days; `heatmap` does not — ninety days folded
 * into a typical week. The most valuable assertion here is that browsing back a
 * week moves one and leaves the other alone: if both moved, or neither did, the
 * card would show the same picture under two labels for months unnoticed.
 */
final class StressWeekGridTest extends TestCase
{
    use RefreshDatabase;

    /** A Sunday, so the current week is complete. */
    private const TODAY = '2026-06-21';

    private const CYCLE = [-0.2, -0.1, 0.0, 0.1, 0.2];

    private const SIGMA = 0.14826;

    private const BASE_MS = 33.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 20:00:00', StressFixture::TZ));
    }

    public function test_the_grid_is_the_week_that_is_being_viewed(): void
    {
        $this->seedLevels(100);

        $grid = $this->props()['weekGrid'];

        self::assertSame('2026-06-15', $grid['start']);
        self::assertSame('2026-06-21', $grid['end']);
        self::assertCount(168, $grid['cells']);

        // Every hour worn, and today is the Sunday: complete and fully covered.
        self::assertSame(168, $grid['filledCells']);
        self::assertSame(168, $grid['elapsedCells']);
        self::assertSame(0, $grid['unrankedCells']);

        // The week sits on the middle of the pool, so every cell scores the
        // baseline anchor rather than a spread of colours.
        foreach ($grid['cells'] as $cell) {
            self::assertNotNull($cell['band'], $cell['date'].' '.$cell['hour']);
            self::assertSame(65, $cell['score']);
        }
    }

    public function test_a_planted_hour_is_coloured_from_its_own_hour_of_the_day(): void
    {
        // One reading three personal sigma below the middle, on the Wednesday.
        $this->seedLevels(100, planted: -3.0);

        $cell = $this->cell($this->props()['weekGrid'], '2026-06-17', 3);

        self::assertEqualsWithDelta(-3.0, (float) $cell['z'], 0.02);
        self::assertSame('overload', $cell['band']);
        self::assertEqualsWithDelta(self::BASE_MS * exp(-3.0 * self::SIGMA), (float) $cell['hrvMs'], 0.05);

        // Its neighbours are untouched — nothing bleeds across a cell boundary.
        self::assertSame(65, $this->cell($this->props()['weekGrid'], '2026-06-17', 4)['score']);

        /*
         * The same reading is the week's notable moment: grid and list are built
         * from ONE HourlyBaseline, because a cell drawn at the bottom of the ramp
         * that the list does not mention is two answers to one question.
         */
        $moments = $this->props()['digest']['moments'];

        self::assertCount(1, $moments);
        self::assertSame('2026-06-17', $moments[0]['date']);
        self::assertSame(3, $moments[0]['hour']);
        self::assertEqualsWithDelta((float) $cell['z'], (float) $moments[0]['z'], 0.02);
    }

    public function test_the_hours_the_watch_was_off_stay_empty(): void
    {
        // The Watch on overnight only, for the whole history and the week.
        StressFixture::days(
            self::TODAY,
            100,
            fn (int $day): float => self::BASE_MS * exp(self::CYCLE[$day % 5]),
            StressFixture::NIGHT_HOURS,
        );

        $grid = $this->props()['weekGrid'];

        // Six hours a day, seven days: 42 cells, the rest holes rather than a
        // smooth wash of colour across the day.
        self::assertSame(42, $grid['filledCells']);

        // 165 rather than 168: at 20:00 on the Sunday, 21:00 to 23:00 have not
        // happened and are not hours the Watch missed.
        self::assertSame(165, $grid['elapsedCells']);

        $daytime = $this->cell($grid, '2026-06-17', 14);

        self::assertSame(0, $daytime['samples']);
        self::assertNull($daytime['hrvMs']);
        self::assertNull($daytime['band']);
    }

    public function test_the_hours_that_have_not_happened_are_not_counted_against_coverage(): void
    {
        // Wednesday lunchtime. Thursday to Sunday are not gaps, they are not yet.
        $this->travelTo(CarbonImmutable::parse('2026-06-17 13:40:00', StressFixture::TZ));

        /*
         * Ninety-six days ENDING ON THE WEDNESDAY, which itself stops at 13:00:
         * the Watch cannot have reported an hour that has not happened, and
         * seeding one would make this test prove nothing.
         */
        StressFixture::days(
            '2026-06-17',
            96,
            function (int $day, int $hour): ?float {
                if ($day === 95 && $hour > 13) {
                    return null;
                }

                return self::BASE_MS * exp(self::CYCLE[$day % 5]);
            },
        );

        $grid = $this->props()['weekGrid'];

        // Monday and Tuesday whole, plus Wednesday 00:00 through 13:00.
        self::assertSame(62, $grid['elapsedCells']);
        self::assertSame(62, $grid['filledCells']);

        self::assertFalse($this->cell($grid, '2026-06-17', 13)['future']);
        self::assertTrue($this->cell($grid, '2026-06-17', 14)['future']);
        self::assertTrue($this->cell($grid, '2026-06-20', 9)['future']);
    }

    public function test_browsing_moves_the_week_grid_and_leaves_the_typical_week_alone(): void
    {
        $this->seedLevels(100);

        $current = $this->props();
        $earlier = $this->props('2026-06-03');

        self::assertSame('2026-06-15', $current['weekGrid']['start']);
        self::assertSame('2026-06-01', $earlier['weekGrid']['start']);
        self::assertNotSame($current['weekGrid']['cells'], $earlier['weekGrid']['cells']);

        // The pooled grid is ninety days as of TODAY either way: a habit is not a
        // property of the week you happen to be looking at.
        self::assertSame($current['heatmap']['from'], $earlier['heatmap']['from']);
        self::assertSame($current['heatmap']['to'], $earlier['heatmap']['to']);
        self::assertSame($current['heatmap']['cells'], $earlier['heatmap']['cells']);
    }

    public function test_without_enough_history_there_is_no_week_grid(): void
    {
        // Eighteen days before the viewed week — below the twenty-one a baseline
        // needs. Nothing to colour against, so no grid rather than colours
        // nobody can defend.
        $this->seedLevels(25);

        self::assertNull($this->props()['weekGrid']);
    }

    public function test_the_page_ships_both_grids(): void
    {
        $this->actingAs(User::factory()->create());

        $this->seedLevels(100);

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Stress')
                ->has('weekGrid.cells', 168)
                ->where('weekGrid.start', '2026-06-15')
                ->where('weekGrid.filledCells', 168)
                ->has('heatmap.cells', 168)
                ->etc()
            );
    }

    // ---

    /**
     * @return array<string, mixed>
     */
    private function props(?string $week = null): array
    {
        return (new StressView)->props($week);
    }

    /**
     * @param  array<string, mixed>  $grid
     * @return array<string, mixed>
     */
    private function cell(array $grid, string $date, int $hour): array
    {
        foreach ($grid['cells'] as $cell) {
            if ($cell['date'] === $date && $cell['hour'] === $hour) {
                return $cell;
            }
        }

        self::fail("no cell for {$date} {$hour}:00");
    }

    /**
     * `$count` days ending today, every hour worn, levels cycling the five
     * offsets so the pool has a median of exactly ln(33) and a MAD of exactly
     * 0.1 — the viewed week flat on the middle, so anything planted in it is the
     * only thing that can be notable.
     */
    private function seedLevels(int $count, ?float $planted = null): void
    {
        $plantedMs = $planted === null ? null : self::BASE_MS * exp($planted * self::SIGMA);

        StressFixture::days(
            self::TODAY,
            $count,
            function (int $day, int $hour) use ($count, $plantedMs): float {
                $week = $count - 7;

                if ($plantedMs !== null && $day === $week + 2 && $hour === 3) {
                    return $plantedMs;
                }

                return self::BASE_MS * exp($day >= $week ? 0.0 : self::CYCLE[$day % 5]);
            },
        );
    }
}
