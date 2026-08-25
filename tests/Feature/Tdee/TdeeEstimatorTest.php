<?php

declare(strict_types=1);

namespace Tests\Feature\Tdee;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use Tests\Support\TdeeFixture;
use App\Services\Tdee\EstimatedTdee;
use App\Services\Tdee\TdeeEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Does the estimator recover an expenditure it was never told?
 *
 * Synthetic fixtures because production has 101 summarised days, TWO weigh-ins
 * and ZERO complete food logs. So they are built backwards: fix the intake, fix
 * the rate the weight falls, and the expenditure is arithmetic rather than
 * opinion — 2 000 kcal eaten while losing 0.1 kg/week is 2 000 + 7700 × 0.1/7 ≈
 * 2 110. The estimator gets the two observable halves and must produce the third.
 */
final class TdeeEstimatorTest extends TestCase
{
    use RefreshDatabase;

    /** The window: 28 days ending on "today". */
    private const TODAY = '2026-06-15';

    private const FIRST = '2026-05-19';

    /** 0.1 kg/week down, in kg/day. */
    private const KG_PER_DAY = -0.1 / 7;

    /** 2 000 kcal eaten, 0.1 kg/week lost -> 2 110 kcal burned. */
    private const TRUTH = 2000 + 7700 * 0.1 / 7;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', 'Europe/Amsterdam'));

        config()->set('health.trends.ema_alpha', 0.25);
        config()->set('health.tdee.kcal_per_kg', 7700);
    }

    public function test_a_known_linear_scenario_is_recovered_from_intake_and_weight_alone(): void
    {
        // 28 days at 2 000 kcal (±100), weighed daily, falling exactly 0.1 kg/week.
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 + self::KG_PER_DAY * $i,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        self::assertSame(28, $outcome->completeLogDays);
        self::assertSame(28, $outcome->weighIns);

        /*
         * 2 102.4, not 2 110.0, and the 7.6 kcal is understood: this window IS
         * the whole weigh-in history, so the EMA is still in its start-up
         * transient over the first third, biasing the slope ~7% toward zero.
         * The next test runs the same fixture with history behind it: exact.
         */
        self::assertEqualsWithDelta(self::TRUTH, $outcome->mid(), 25);

        // The range is the claim; it has to contain the answer.
        self::assertLessThan(self::TRUTH, $outcome->min());
        self::assertGreaterThan(self::TRUTH, $outcome->max());

        // And it is a range, not a decorated point.
        self::assertGreaterThan(50, $outcome->max() - $outcome->min());
    }

    public function test_with_weighins_behind_the_window_the_answer_is_exact(): void
    {
        // 60 days weighing before the window — the ordinary case for anyone who
        // owns a scale, and what the full-history EMA seed exists for.
        TdeeFixture::priorWeighIns(
            CarbonImmutable::parse(self::FIRST)->subDay()->toDateString(),
            60,
            fn (int $i): float => 70 + self::KG_PER_DAY * ($i - 60),
        );

        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 + self::KG_PER_DAY * $i,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        // No transient left to bias it: the arithmetic comes back whole.
        self::assertEqualsWithDelta(self::TRUTH, $outcome->mid(), 1.0);
        self::assertEqualsWithDelta(self::KG_PER_DAY, $outcome->slopeKgPerDay, 1e-4);
    }

    public function test_a_noisy_scale_barely_moves_the_estimate_and_would_wreck_the_naive_one(): void
    {
        /*
         * THE POINT OF THE WHOLE DESIGN: same true trend, plus ±1 kg
         * alternating day to day — what a real scale does on water and gut
         * content alone, and TEN TIMES the weekly signal being measured.
         */
        $weight = fn (int $i): float => 70 + self::KG_PER_DAY * $i + ($i % 2 === 0 ? 1.0 : -1.0);

        TdeeFixture::priorWeighIns(
            CarbonImmutable::parse(self::FIRST)->subDay()->toDateString(),
            60,
            fn (int $i): float => 70 + self::KG_PER_DAY * ($i - 60) + ($i % 2 === 0 ? 1.0 : -1.0),
        );

        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => $weight($i),
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);

        // EMA + OLS: within ~10 kcal of the truth, and the range still holds it.
        self::assertEqualsWithDelta(self::TRUTH, $outcome->mid(), 40);
        self::assertLessThan(self::TRUTH, $outcome->min());
        self::assertGreaterThan(self::TRUTH, $outcome->max());

        // Endpoint-minus-endpoint on the same rows: 2 680, out by 570 kcal — a
        // +1 kg first weigh-in and a −1 kg last one. That IS the naive method,
        // not a bad day for it.
        $endpoint = TdeeFixture::endpointTdee(2000.0, self::FIRST, self::TODAY);

        self::assertGreaterThan(400, abs($endpoint - self::TRUTH));
        self::assertLessThan(abs($endpoint - self::TRUTH) / 10, abs($outcome->mid() - self::TRUTH));

        // The noise is not hidden: it widens the published range instead.
        self::assertGreaterThan(150, $outcome->max() - $outcome->min());
    }

    public function test_a_noisy_scale_with_no_history_behind_it_is_biased_and_still_beats_the_naive_method(): void
    {
        /*
         * The user's VERY FIRST estimate, honestly measured. With no weigh-ins
         * before the window the EMA is seeded on one noisy reading (+1 kg) and
         * decays toward the trend over the first third — a drift the fit cannot
         * tell from real loss, costing ~150 kcal. Asserted rather than hidden:
         * it is the one case this estimator is meaningfully wrong, it happens
         * once per user, and the endpoint method is out by four times as much.
         */
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 + self::KG_PER_DAY * $i + ($i % 2 === 0 ? 1.0 : -1.0),
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();
        $endpoint = TdeeFixture::endpointTdee(2000.0, self::FIRST, self::TODAY);

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        self::assertLessThan(250, abs($outcome->mid() - self::TRUTH));
        self::assertLessThan(abs($endpoint - self::TRUTH) / 2, abs($outcome->mid() - self::TRUTH));
    }

    public function test_weighins_are_placed_by_calendar_date_not_by_position(): void
    {
        // 14 weigh-ins over 28 days. Fitted as if consecutive, the trend comes
        // out twice as steep and the estimate ~110 kcal high.
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => $i % 2 === 0 ? 70 + self::KG_PER_DAY * $i : null,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        self::assertSame(14, $outcome->weighIns);
        self::assertEqualsWithDelta(self::TRUTH, $outcome->mid(), 40);
    }

    public function test_an_incomplete_day_cannot_drag_the_intake_mean_down(): void
    {
        /*
         * Fourteen logged 2 000 kcal days, fourteen breakfast-only ones. A
         * breakfast-only day is not a 400 kcal day: averaging it in says 1 200
         * and reports a TDEE 800 kcal low — which the user would then eat to.
         */
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => $i % 2 === 0 ? 2000.0 : 400.0,
            'band'            => 100.0,
            'is_complete_log' => $i % 2 === 0,
            'weight_kg'       => 70 + self::KG_PER_DAY * $i,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);

        // 14 complete days, and the mean is over those 14 only.
        self::assertSame(14, $outcome->completeLogDays);
        self::assertEqualsWithDelta(2000.0, $outcome->intakeMean, 0.01);
        self::assertEqualsWithDelta(self::TRUTH, $outcome->mid(), 25);
    }

    public function test_the_intake_band_carries_through_to_the_range_asymmetrically(): void
    {
        // "Probably 2 000, could be 1 950, could be 2 300" — a photographed plate.
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'kcal_in_min'     => 1950.0,
            'kcal_in_mid'     => 2000.0,
            'kcal_in_max'     => 2300.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 + self::KG_PER_DAY * $i,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        self::assertEqualsWithDelta(50.0, $outcome->intakeBandLow, 0.01);
        self::assertEqualsWithDelta(300.0, $outcome->intakeBandHigh, 0.01);

        // Quadrature with the tiny slope error leaves the widths ≈ the intake
        // band; the midpoint is NOT the mean of the ends, hence stored tdee_mid.
        self::assertEqualsWithDelta(50.0, $outcome->mid() - $outcome->min(), 5);
        self::assertEqualsWithDelta(300.0, $outcome->max() - $outcome->mid(), 5);
        self::assertGreaterThan(50, ($outcome->min() + $outcome->max()) / 2 - $outcome->mid());
    }

    public function test_a_flat_weight_means_tdee_is_just_what_was_eaten(): void
    {
        // Maintenance, the sanity check: no weight change, no correction term.
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2200.0,
            'band'            => 80.0,
            'is_complete_log' => true,
            'weight_kg'       => 70.0,
        ]);

        $outcome = app(TdeeEstimator::class)->estimate();

        self::assertInstanceOf(EstimatedTdee::class, $outcome);
        self::assertEqualsWithDelta(2200.0, $outcome->mid(), 0.5);
        self::assertEqualsWithDelta(0.0, $outcome->slopeKgPerDay, 1e-9);
    }
}
