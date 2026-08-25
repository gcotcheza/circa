<?php

declare(strict_types=1);

namespace Tests\Feature\Stress;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use Tests\Support\StressFixture;
use Illuminate\Support\Facades\DB;
use App\Services\Stress\StressHeatmap;
use App\Services\Stress\StressAnalysis;
use App\Services\Stress\StressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The hour x weekday grid: 168 cells, and what each may claim.
 *
 * The first test carries the weight. RAW HRV by hour is a picture of the
 * circadian rhythm — green all night, amber all evening — identical for every
 * human alive. Deseasonalising first makes it a picture of the WEEK, the only
 * version that can tell this user something they did not know.
 */
final class StressHeatmapTest extends TestCase
{
    use RefreshDatabase;

    /** A Monday, so the weekday arithmetic below is easy to follow. */
    private const TODAY = '2026-06-15';

    private const CYCLE = [-0.2, -0.1, 0.0, 0.1, 0.2];

    private const BASE_MS = 33.0;

    /** The circadian swing built into every fixture here, in log units. */
    private const NIGHT_LIFT = 0.2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', StressFixture::TZ));
        config()->set('health.stress.heatmap_days', 90);
    }

    public function test_a_week_with_no_pattern_in_it_produces_a_flat_grid_despite_a_strong_daily_rhythm(): void
    {
        // Ninety identical days: 45%-swing circadian shape, no weekday effect.
        // Every filled cell lands on the baseline anchor — nothing found because
        // there is nothing.
        StressFixture::days(
            self::TODAY,
            90,
            fn (int $i, int $hour): float => self::BASE_MS * exp($this->shape($hour)),
        );

        $heatmap = $this->heatmap();

        self::assertNotNull($heatmap);
        self::assertSame(168, $heatmap->filledCells);

        foreach ($heatmap->cells as $cell) {
            self::assertSame(65, $cell['score'], "cell {$cell['weekday']}:{$cell['hour']}");
        }
    }

    public function test_a_real_weekday_pattern_survives_the_deseasonalising(): void
    {
        // Same shape, but Mondays run a fifth of a log unit lower: a real working
        // week showing up in HRV.
        StressFixture::days(
            self::TODAY,
            90,
            fn (int $i, int $hour, string $date): float => self::BASE_MS * exp(
                self::CYCLE[$i % 5]
                + $this->shape($hour)
                + (CarbonImmutable::parse($date)->isoWeekday() === 1 ? -0.2 : 0.0)
            ),
        );

        $heatmap = $this->heatmap();

        self::assertNotNull($heatmap);

        $monday = $this->meanScore($heatmap->cells, weekday: 1);
        $wednesday = $this->meanScore($heatmap->cells, weekday: 3);

        // A whole band lower, on a chart emptied of the clock.
        self::assertLessThan($wednesday - 15, $monday, "Monday {$monday} vs Wednesday {$wednesday}");
    }

    public function test_an_hour_of_the_week_the_watch_was_never_on_for_stays_empty(): void
    {
        config()->set('health.stress.min_cell_samples', 3);

        // Never worn at 04:00 on a Sunday. Shading that hole from its neighbours
        // would be a lie that looks exactly like a measurement.
        StressFixture::days(
            self::TODAY,
            90,
            fn (int $i, int $hour, string $date): ?float => $hour === 4 && CarbonImmutable::parse($date)->isoWeekday() === 7
                ? null
                : self::BASE_MS * exp(self::CYCLE[$i % 5] + $this->shape($hour)),
        );

        $heatmap = $this->heatmap();

        self::assertNotNull($heatmap);

        $empty = $this->cell($heatmap->cells, weekday: 7, hour: 4);

        self::assertSame(0, $empty['samples']);
        self::assertNull($empty['score']);
        self::assertNull($empty['band']);

        self::assertSame(167, $heatmap->filledCells);
        self::assertSame(168, $heatmap->toArray()['totalCells']);
    }

    public function test_a_cell_just_below_the_threshold_is_still_empty(): void
    {
        config()->set('health.stress.min_cell_samples', 3);

        // Two Sundays at 04:00 out of thirteen: not a typical Sunday morning,
        // just two Sunday mornings.
        $kept = 0;

        StressFixture::days(
            self::TODAY,
            90,
            function (int $i, int $hour, string $date) use (&$kept): ?float {
                $isTarget = $hour === 4 && CarbonImmutable::parse($date)->isoWeekday() === 7;

                if ($isTarget && ++$kept > 2) {
                    return null;
                }

                return self::BASE_MS * exp(self::CYCLE[$i % 5] + $this->shape($hour));
            },
        );

        $cell = $this->cell($this->notNull($this->heatmap())->cells, weekday: 7, hour: 4);

        self::assertSame(2, $cell['samples']);
        self::assertNull($cell['score']);
    }

    public function test_the_grid_states_the_window_it_was_built_from(): void
    {
        StressFixture::days(self::TODAY, 90, fn (int $i, int $hour): float => self::BASE_MS * exp($this->shape($hour)));

        $props = $this->notNull($this->heatmap())->toArray();

        // A heatmap without its window is a claim about all of history.
        self::assertSame(CarbonImmutable::parse(self::TODAY)->subDays(89)->toDateString(), $props['from']);
        self::assertSame(self::TODAY, $props['to']);
        self::assertSame(90, $props['days']);

        // Every reading lands in exactly one cell. NOT 90 x 24: the window spans
        // 2026-03-29, the Sunday Amsterdam has no 02:00 on — a genuine 23-hour day.
        self::assertSame(
            DB::table('health_metrics')
                ->where('metric', StressFixture::METRIC)
                ->whereBetween('local_date', [$props['from'], $props['to']])
                ->count(),
            $props['observations'],
        );

        self::assertSame(90 * 24 - 1, $props['observations']);
    }

    public function test_there_is_no_grid_at_all_below_the_history_gate(): void
    {
        config()->set('health.stress.min_baseline_days', 21);

        StressFixture::days(self::TODAY, 10, fn (int $i, int $hour): float => self::BASE_MS * exp($this->shape($hour)));

        self::assertNull($this->heatmap());
    }

    public function test_the_grid_is_weekday_major_and_starts_on_monday(): void
    {
        StressFixture::days(self::TODAY, 90, fn (int $i, int $hour): float => self::BASE_MS * exp($this->shape($hour)));

        $cells = $this->notNull($this->heatmap())->cells;

        self::assertCount(168, $cells);
        self::assertSame(['weekday' => 1, 'hour' => 0], array_intersect_key($cells[0], ['weekday' => 0, 'hour' => 0]));
        self::assertSame(['weekday' => 1, 'hour' => 23], array_intersect_key($cells[23], ['weekday' => 0, 'hour' => 0]));
        self::assertSame(['weekday' => 7, 'hour' => 23], array_intersect_key($cells[167], ['weekday' => 0, 'hour' => 0]));
    }

    /** The circadian shape every fixture in this file carries. */
    private function shape(int $hour): float
    {
        return match (true) {
            $hour < 6   => self::NIGHT_LIFT,
            $hour >= 18 => -self::NIGHT_LIFT,
            default     => 0.0,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     */
    private function meanScore(array $cells, int $weekday): float
    {
        $scores = array_column(
            array_filter($cells, static fn (array $c): bool => $c['weekday'] === $weekday && $c['score'] !== null),
            'score'
        );

        return array_sum($scores) / max(1, count($scores));
    }

    /**
     * @param  list<array<string, mixed>>  $cells
     * @return array<string, mixed>
     */
    private function cell(array $cells, int $weekday, int $hour): array
    {
        foreach ($cells as $cell) {
            if ($cell['weekday'] === $weekday && $cell['hour'] === $hour) {
                return $cell;
            }
        }

        self::fail("No cell for weekday {$weekday} hour {$hour}");
    }

    private function heatmap(): ?StressHeatmap
    {
        $today = CarbonImmutable::parse(self::TODAY, StressFixture::TZ);

        [$from, $to] = StressCalculator::heatmapWindow($today);

        return $this->analyse()->heatmap($from, $to);
    }

    private function analyse(): StressAnalysis
    {
        return app(StressCalculator::class)->analyse(
            CarbonImmutable::parse(self::TODAY)->subDays(6)->toDateString(),
            self::TODAY,
            CarbonImmutable::parse(self::TODAY, StressFixture::TZ),
        );
    }
}
