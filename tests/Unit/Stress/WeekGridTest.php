<?php

declare(strict_types=1);

namespace Tests\Unit\Stress;

use Tests\TestCase;
use App\Services\Stress\WeekGrid;
use App\Services\Stress\HrvSample;
use App\Services\Stress\StressScale;
use App\Services\Stress\HourlyBaseline;
use App\Services\Stress\CircadianProfile;

/**
 * 168 cells for one week, and the four states one can be in. The app being
 * replaced interpolates the hour in six it lacks; the holes are the point.
 */
final class WeekGridTest extends TestCase
{
    private const BASE = 33.0;

    private const SIGMA = 0.14826;

    /** Monday to Sunday. */
    private const DATES = [
        '2026-06-15', '2026-06-16', '2026-06-17',
        '2026-06-18', '2026-06-19', '2026-06-20', '2026-06-21',
    ];

    public function test_the_grid_is_seven_days_of_twenty_four_hours_weekday_major(): void
    {
        $grid = $this->grid([]);

        self::assertCount(168, $grid->cells);

        self::assertSame(1, $grid->cells[0]['weekday']);
        self::assertSame(0, $grid->cells[0]['hour']);
        self::assertSame('2026-06-15', $grid->cells[0]['date']);

        self::assertSame(1, $grid->cells[23]['weekday']);
        self::assertSame(23, $grid->cells[23]['hour']);

        self::assertSame(7, $grid->cells[167]['weekday']);
        self::assertSame(23, $grid->cells[167]['hour']);
        self::assertSame('2026-06-21', $grid->cells[167]['date']);

        self::assertSame('2026-06-15', $grid->start);
        self::assertSame('2026-06-21', $grid->end);
    }

    public function test_a_reading_comes_back_with_the_z_it_was_planted_at(): void
    {
        $grid = $this->grid([self::reading('2026-06-16', 3, -2.0)]);

        $cell = $this->cell($grid, '2026-06-16', 3);

        self::assertSame(1, $cell['samples']);
        self::assertEqualsWithDelta(-2.0, (float) $cell['z'], 0.005);
        self::assertEqualsWithDelta(self::BASE * exp(-2.0 * self::SIGMA), (float) $cell['hrvMs'], 0.05);

        // The band is the published scale on that z, not a grid-local opinion.
        self::assertSame((new StressScale)->score(-2.0), $cell['score']);
        self::assertSame((new StressScale)->band(-2.0)->value, $cell['band']);

        self::assertSame(33.0, $cell['typicalMs']);
        self::assertSame(30.0, $cell['typicalLowMs']);
        self::assertSame(36.0, $cell['typicalHighMs']);

        self::assertSame(1, $grid->filledCells);
        self::assertSame(1, $grid->observations);
    }

    public function test_an_hour_with_no_reading_stays_empty_and_is_not_filled_in(): void
    {
        $grid = $this->grid([
            self::reading('2026-06-16', 2, -2.0),
            self::reading('2026-06-16', 4, 2.0),
        ]);

        $hole = $this->cell($grid, '2026-06-16', 3);

        self::assertSame(0, $hole['samples']);
        self::assertNull($hole['hrvMs']);
        self::assertNull($hole['z']);
        self::assertNull($hole['score']);
        self::assertNull($hole['band']);
        self::assertFalse($hole['future']);

        self::assertSame(2, $grid->filledCells);
    }

    public function test_an_hour_that_has_not_happened_is_not_counted_as_a_gap(): void
    {
        // Wednesday 13:00: 62 of 168 hours have happened, so the other 106 are
        // calendar, not gaps — counting them is what kills trust in coverage.
        $grid = $this->grid([self::reading('2026-06-15', 3, 0.5)], nowDate: '2026-06-17', nowHour: 13);

        self::assertSame(62, $grid->elapsedCells);

        self::assertFalse($this->cell($grid, '2026-06-17', 13)['future']);
        self::assertTrue($this->cell($grid, '2026-06-17', 14)['future']);
        self::assertTrue($this->cell($grid, '2026-06-21', 0)['future']);

        // A past week has no future in it at all.
        self::assertSame(168, $this->grid([])->elapsedCells);
    }

    public function test_a_reading_always_outranks_the_clock(): void
    {
        // A clock minutes ahead, or a bucket anchored at a sync's end, gives a
        // reading for an hour this server thinks has not started. A future cell
        // draws NOTHING, so letting the clock win would hide a measurement.
        $grid = $this->grid(
            [self::reading('2026-06-17', 20, -1.0)],
            nowDate: '2026-06-17',
            nowHour: 13,
        );

        $cell = $this->cell($grid, '2026-06-17', 20);

        self::assertFalse($cell['future']);
        self::assertSame(1, $cell['samples']);
        self::assertNotNull($cell['band']);

        // Counted too: 62 elapsed hours plus the one that arrived early.
        self::assertSame(63, $grid->elapsedCells);
    }

    public function test_a_reading_at_an_hour_with_no_shape_keeps_its_value_and_gets_no_band(): void
    {
        // Measured, not comparable: the value is shown, the verdict withheld,
        // since nothing that never established what 14:00 is like can rank it.
        $grid = $this->grid(
            [self::reading('2026-06-16', 14, -3.0)],
            baseline: HourlyBaseline::from(self::pool(), self::profile([0, 1, 2, 3, 4, 5]), 0.03),
        );

        $cell = $this->cell($grid, '2026-06-16', 14);

        self::assertSame(1, $cell['samples']);
        self::assertNotNull($cell['hrvMs']);
        self::assertNull($cell['z']);
        self::assertNull($cell['band']);

        self::assertSame(1, $grid->filledCells);
        self::assertSame(1, $grid->unrankedCells);
    }

    public function test_two_readings_in_one_hour_are_combined_in_log_space(): void
    {
        // 20 ms and 45 ms in one hour: geometric middle 30, arithmetic 32.5.
        // Logs, as everywhere in this feature: only the ratio is comparable.
        $grid = $this->grid([
            HrvSample::make('2026-06-16', 7, 20.0),
            HrvSample::make('2026-06-16', 7, 45.0),
        ]);

        $cell = $this->cell($grid, '2026-06-16', 7);

        self::assertSame(2, $cell['samples']);
        self::assertSame(30.0, $cell['hrvMs']);
        self::assertSame(1, $grid->filledCells);
        self::assertSame(2, $grid->observations);
    }

    public function test_a_week_the_watch_was_off_for_is_a_grid_of_holes_and_says_so(): void
    {
        $grid = $this->grid([]);

        self::assertSame(0, $grid->filledCells);
        self::assertSame(0, $grid->observations);
        self::assertSame(168, $grid->toArray()['totalCells']);

        foreach ($grid->cells as $cell) {
            self::assertNull($cell['band']);
            self::assertNull($cell['hrvMs']);
        }
    }

    public function test_the_payload_states_the_spread_its_colours_are_measured_in(): void
    {
        // 0.14826 log units is "about 16% up": what one colour step is worth.
        self::assertSame(16, $this->grid([])->toArray()['spreadPercent']);
    }

    // -----------------------------------------------------------------------

    /**
     * @param  list<HrvSample>  $samples
     */
    private function grid(
        array $samples,
        ?HourlyBaseline $baseline = null,
        string $nowDate = '2026-06-28',
        int $nowHour = 12,
    ): WeekGrid {
        return WeekGrid::build(
            $samples,
            self::DATES,
            $baseline ?? $this->notNull(HourlyBaseline::from(self::pool(), self::profile(), 0.03)),
            new StressScale,
            $nowDate,
            $nowHour,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cell(WeekGrid $grid, string $date, int $hour): array
    {
        foreach ($grid->cells as $cell) {
            if ($cell['date'] === $date && $cell['hour'] === $hour) {
                return $cell;
            }
        }

        self::fail("no cell for {$date} {$hour}:00");
    }

    private static function reading(string $date, int $hour, float $z): HrvSample
    {
        return HrvSample::make($date, $hour, self::BASE * exp($z * self::SIGMA));
    }

    /**
     * Median ln(33), MAD exactly 0.1 — so a planted z is arithmetic.
     *
     * @return list<float>
     */
    private static function pool(): array
    {
        $pool = [];

        foreach ([-0.2, -0.1, 0.0, 0.1, 0.2] as $offset) {
            for ($i = 0; $i < 10; $i++) {
                $pool[] = log(self::BASE) + $offset;
            }
        }

        return $pool;
    }

    /**
     * @param  list<int>|null  $hours
     */
    private static function profile(?array $hours = null): CircadianProfile
    {
        $hours ??= range(0, 23);

        $byDate = [];

        for ($day = 1; $day <= 20; $day++) {
            $date = sprintf('2026-05-%02d', $day);

            $byDate[$date] = array_map(
                static fn (int $hour): HrvSample => HrvSample::make($date, $hour, self::BASE),
                $hours,
            );
        }

        return CircadianProfile::from($byDate);
    }
}
