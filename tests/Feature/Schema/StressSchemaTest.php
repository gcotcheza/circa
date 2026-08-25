<?php

declare(strict_types=1);

namespace Tests\Feature\Schema;

use Tests\TestCase;
use App\Enums\StressBand;
use App\Models\StressDaily;
use App\Models\DailySummary;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Schema-level guarantees for `stress_daily`, above all the one the coming health
 * report depends on: ONE ROW PER DAY, joinable on `local_date`.
 */
final class StressSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_day_can_hold_exactly_one_score(): void
    {
        StressDaily::factory()->onDate('2026-06-15')->create();

        // Not (date, method_version) like `tdee_estimates`: two rows for one day
        // make `join stress_daily using (local_date)` silently duplicate it,
        // with no error anywhere. See the migration.
        $this->expectException(QueryException::class);

        DB::table('stress_daily')->insert([
            'local_date'          => '2026-06-15',
            'score'               => 40,
            'band'                => 'attention',
            'z'                   => -1.0,
            'hrv_ms'              => 30,
            'baseline_hrv_ms'     => 33,
            'baseline_spread_log' => 0.16,
            'samples'             => 20,
            'covered_hours'       => 20,
            'baseline_days'       => 55,
            'confidence'          => 'high',
            'method_version'      => 'v2',
            'computed_at'         => now(),
        ]);
    }

    public function test_the_band_round_trips_as_an_enum(): void
    {
        $row = StressDaily::factory()->onDate('2026-06-15')->scoring(12)->create();

        self::assertSame(StressBand::Overload, $this->notNull($row->fresh())->band);
        self::assertSame(12, $this->notNull($row->fresh())->score);
    }

    public function test_it_joins_a_daily_summary_on_the_shared_date(): void
    {
        // The forward hook for the health report: stress beside intake, burn and
        // weight for one date, with nobody writing the join by hand.
        DailySummary::factory()->onDate('2026-06-15')->create(['kcal_in_mid' => 2000]);
        StressDaily::factory()->onDate('2026-06-15')->scoring(41)->create();

        $row = StressDaily::query()->with('summary')->findOrFail('2026-06-15');

        self::assertNotNull($row->summary);
        self::assertSame('2000.00', $row->summary->kcal_in_mid);

        // ...and a stress score with no summary is legal in both directions.
        StressDaily::factory()->onDate('2026-06-16')->create();

        self::assertNull(StressDaily::query()->with('summary')->findOrFail('2026-06-16')->summary);
    }

    public function test_bad_days_are_a_one_line_query(): void
    {
        StressDaily::factory()->onDate('2026-06-13')->scoring(88)->create();
        StressDaily::factory()->onDate('2026-06-14')->scoring(64)->create();
        StressDaily::factory()->onDate('2026-06-15')->scoring(31)->create();
        StressDaily::factory()->onDate('2026-06-16')->scoring(9)->create();

        $bad = StressDaily::query()->atOrBelow(StressBand::Attention)->pluck('score')->all();

        self::assertSame([31, 9], $bad);

        $window = StressDaily::query()->between('2026-06-14', '2026-06-15')->pluck('score')->all();

        self::assertSame([64, 31], $window);
    }

    public function test_the_coverage_columns_survive_a_round_trip(): void
    {
        $row = $this->notNull(StressDaily::factory()->onDate('2026-06-15')->thin()->create()->fresh());

        self::assertSame(4, $row->samples);
        self::assertSame('4.0', $row->covered_hours);
        self::assertTrue($row->isThin());
    }
}
