<?php

declare(strict_types=1);

namespace Tests\Feature\Tdee;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use Tests\Support\TdeeFixture;
use App\Services\Tdee\TdeeWindow;
use App\Services\Tdee\EstimatedTdee;
use App\Services\Tdee\TdeeEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Which days the estimate is allowed to see.
 *
 * The rule (in full on TdeeWindow): ends TODAY, back 28 days, front-trimmed to
 * the first day with data, never shorter than 14. "Longest window ≤ 28 that
 * clears the gate" reduces to this — both gate counts only grow with it.
 */
final class TdeeWindowTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', 'Europe/Amsterdam'));
    }

    public function test_the_default_window_is_twenty_eight_days_ending_today(): void
    {
        $window = TdeeWindow::current();

        self::assertSame('2026-05-19', $window->startDate());
        self::assertSame(self::TODAY, $window->endDate());
        self::assertSame(28, $window->days());
    }

    public function test_the_window_is_front_trimmed_to_the_first_day_with_data(): void
    {
        // Nothing before 1 June: a 19 May start claims a fortnight it knows nothing of.
        TdeeFixture::days('2026-06-15', 15, fn (int $i): array => [
            'intake'          => 2000.0,
            'is_complete_log' => true,
            'weight_kg'       => 70.0,
        ]);

        $window = TdeeWindow::current();

        self::assertSame('2026-06-01', $window->startDate());
        self::assertSame(15, $window->days());
    }

    public function test_a_weighin_alone_is_data_the_window_starts_at(): void
    {
        TdeeFixture::day('2026-05-25', ['weight_kg' => 70.0]);

        self::assertSame('2026-05-25', TdeeWindow::current()->startDate());
    }

    public function test_the_trim_never_produces_a_window_shorter_than_fourteen_days(): void
    {
        // All the data is in the last three days. Fourteen is the SPEC floor;
        // three days is not a window this estimator has an opinion about.
        TdeeFixture::days('2026-06-15', 3, fn (int $i): array => [
            'intake'          => 2000.0,
            'is_complete_log' => true,
            'weight_kg'       => 70.0,
        ]);

        $window = TdeeWindow::current();

        self::assertSame('2026-06-02', $window->startDate());
        self::assertSame(14, $window->days());
    }

    public function test_days_before_the_window_are_excluded_from_both_terms(): void
    {
        // A month of 3 000 kcal days before the window: a leaked mean would run
        // ~500 kcal high. The weights continue the trend backwards — see below.
        TdeeFixture::days('2026-05-18', 30, fn (int $i): array => [
            'intake'          => 3000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.1 / 7 * ($i - 30),
        ]);

        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.1 / 7 * $i,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        self::assertSame(28, $outcome->completeLogDays);
        self::assertSame(28, $outcome->weighIns);
        self::assertEqualsWithDelta(2000.0, $outcome->intakeMean, 0.01);

        /*
         * Out-of-window weigh-ins are NOT excluded from the EMA — they seed it,
         * which is why the slope here is exact rather than 7% shallow. No POINT
         * to the fit; history to the smoothing.
         */
        self::assertEqualsWithDelta(-0.1 / 7, $outcome->slopeKgPerDay, 5e-4);
    }

    public function test_contains_is_inclusive_at_both_ends(): void
    {
        $window = TdeeWindow::current();

        self::assertTrue($window->contains('2026-05-19'));
        self::assertTrue($window->contains(self::TODAY));
        self::assertFalse($window->contains('2026-05-18'));
        self::assertFalse($window->contains('2026-06-16'));
    }

    public function test_the_ceiling_is_configurable_and_stays_the_longest_fit(): void
    {
        config()->set('health.tdee.window_days', 21);

        $window = TdeeWindow::current();

        self::assertSame('2026-05-26', $window->startDate());
        self::assertSame(21, $window->days());
    }
}
