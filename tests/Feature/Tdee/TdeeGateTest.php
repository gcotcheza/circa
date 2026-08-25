<?php

declare(strict_types=1);

namespace Tests\Feature\Tdee;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Models\TdeeEstimate;
use Tests\Support\TdeeFixture;
use App\Services\Tdee\TdeeRecorder;
use App\Services\Tdee\EstimatedTdee;
use App\Services\Tdee\TdeeEstimator;
use App\Services\Tdee\CollectingData;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The gate: >= 14 complete-log days AND >= 8 weigh-ins, both, in the window.
 *
 * The difference between "we do not know yet" and 1 850 kcal off four logged
 * dinners — and a person eats to that number. Below the gate the estimator
 * returns CollectingData carrying the progress and writes NOTHING: an empty
 * `tdee_estimates` is itself the honest statement.
 */
final class TdeeGateTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', 'Europe/Amsterdam'));
    }

    public function test_thirteen_complete_days_is_not_fourteen(): void
    {
        // Every day weighed (28, comfortably past that gate), one day short on logs.
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => $i >= 15,
            'weight_kg'       => 70 - 0.014 * $i,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(CollectingData::class, $outcome);
        self::assertSame(13, $outcome->completeLogDays);
        self::assertSame(28, $outcome->weighIns);
        self::assertSame(14, $outcome->daysNeeded);
        self::assertSame(8, $outcome->weighInsNeeded);

        // The UI needs to know WHICH half is short, so it can say so.
        self::assertTrue($outcome->needsMoreDays());
        self::assertFalse($outcome->needsMoreWeighIns());
    }

    public function test_fourteen_days_with_seven_weighins_is_not_an_estimate_either(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => $i >= 14,
            // Seven weigh-ins: intake half satisfied, slope with nothing to stand on.
            'weight_kg' => $i % 4 === 0 && $i < 28 ? 70 - 0.014 * $i : null,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(CollectingData::class, $outcome);
        self::assertSame(14, $outcome->completeLogDays);
        self::assertSame(7, $outcome->weighIns);
        self::assertFalse($outcome->needsMoreDays());
        self::assertTrue($outcome->needsMoreWeighIns());
    }

    public function test_both_gates_met_is_an_estimate(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => $i >= 14,
            'weight_kg'       => $i % 2 === 0 ? 70 - 0.014 * $i : null,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        self::assertSame(14, $outcome->completeLogDays);
        self::assertSame(14, $outcome->weighIns);
        self::assertTrue($outcome->meetsGate());
    }

    public function test_nothing_is_written_below_the_gate(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => $i >= 20,
            'weight_kg'       => 70 - 0.014 * $i,
        ]);

        $outcome = app(TdeeRecorder::class)->record();

        self::assertInstanceOf(CollectingData::class, $outcome);
        // A row here is a number the app does not believe, among ones it does.
        self::assertSame(0, TdeeEstimate::query()->count());
    }

    public function test_a_day_flagged_complete_with_no_intake_number_does_not_count(): void
    {
        // The heuristic already requires >= 1000 kcal, but a flagged day with a
        // null midpoint would count toward the gate and add nothing to the mean
        // — the one bug shape that quietly under-reports expenditure.
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => $i >= 15 ? 2000.0 : null,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.014 * $i,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(CollectingData::class, $outcome);
        self::assertSame(13, $outcome->completeLogDays);
    }

    public function test_the_thresholds_come_from_config(): void
    {
        // Moving the gate is a config edit and a re-run, never a migration.
        config()->set('health.tdee.min_complete_days', 5);
        config()->set('health.tdee.min_weighins', 3);

        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => $i >= 23,
            'weight_kg'       => $i >= 23 ? 70 - 0.014 * $i : null,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        self::assertSame(5, $outcome->completeLogDays);
    }

    public function test_an_empty_history_reports_zero_progress_rather_than_failing(): void
    {
        // Production, today: no complete logs at all, and the card still renders.
        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(CollectingData::class, $outcome);
        self::assertSame(0, $outcome->completeLogDays);
        self::assertSame(0, $outcome->weighIns);
        self::assertSame(28, $outcome->window()->days());
    }
}
