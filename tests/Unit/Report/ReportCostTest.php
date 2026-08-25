<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use Tests\TestCase;
use App\Services\Report\ReportCost;

/**
 * What a report cost, frozen at the rates in force when it was written. The
 * case that matters is not the multiplication: an unknown token count gives an
 * unknown cost, never a zero — a zero is indistinguishable from a call that
 * really was free, and those are the rows worth telling apart.
 */
final class ReportCostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('health.report.price_per_mtok', ['input' => 5.00, 'output' => 25.00]);
    }

    public function test_it_prices_a_report_at_the_configured_rates(): void
    {
        // 14 200 / 1M x $5 = $0.0710; 3 100 / 1M x $25 = $0.0775.
        self::assertEqualsWithDelta(0.1485, (float) ReportCost::usd(14_200, 3_100), 0.000001);
    }

    public function test_a_price_change_moves_only_what_is_priced_after_it(): void
    {
        config()->set('health.report.price_per_mtok', ['input' => 10.00, 'output' => 50.00]);

        self::assertEqualsWithDelta(0.297, (float) ReportCost::usd(14_200, 3_100), 0.000001);
    }

    public function test_an_unknown_usage_is_an_unknown_cost_rather_than_a_zero(): void
    {
        self::assertNull(ReportCost::usd(null, null));
    }

    /** A refusal before any output IS a real, priced call on the input side. */
    public function test_a_call_with_no_output_still_costs_its_input(): void
    {
        self::assertEqualsWithDelta(0.07, (float) ReportCost::usd(14_000, 0), 0.000001);
    }

    /** Six decimals, matching the column: rounding to two prints every report as $0.15. */
    public function test_it_keeps_enough_precision_to_tell_two_reports_apart(): void
    {
        $small = (float) ReportCost::usd(1_000, 100);
        $smaller = (float) ReportCost::usd(1_000, 99);

        self::assertNotSame($small, $smaller);
    }
}
