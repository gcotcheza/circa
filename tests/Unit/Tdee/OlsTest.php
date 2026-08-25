<?php

declare(strict_types=1);

namespace Tests\Unit\Tdee;

use App\Services\Tdee\Ols;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * The least-squares fit, checked against arithmetic that can be done by hand.
 * Everything the TDEE estimate says about the weight trend comes out of this
 * function, including how wide the published range is, so it is pinned against
 * known answers rather than against its own output.
 */
final class OlsTest extends TestCase
{
    public function test_a_perfect_line_is_recovered_exactly_and_has_no_error(): void
    {
        // y = 70 − 0.1x
        $points = [];

        for ($x = 0; $x < 10; $x++) {
            $points[] = [(float) $x, 70 - 0.1 * $x];
        }

        $fit = Ols::fit($points);

        self::assertEqualsWithDelta(-0.1, $fit->slope, 1e-12);
        self::assertEqualsWithDelta(70.0, $fit->intercept, 1e-12);
        // Every point on the line: the fit has nothing to be unsure about.
        self::assertEqualsWithDelta(0.0, $fit->standardError, 1e-12);
        self::assertSame(10, $fit->n);
    }

    public function test_scatter_widens_the_standard_error_without_moving_the_slope(): void
    {
        $clean = [];
        $noisy = [];

        // THIRTEEN points, not twelve: over an EVEN count an alternating +/−
        // pattern correlates with x and genuinely tilts the line, while over an
        // odd count it cancels exactly — isolating what this test is about, that
        // scatter shows up in the ERROR and not the slope.
        for ($x = 0; $x < 13; $x++) {
            $clean[] = [(float) $x, 70 - 0.1 * $x];
            $noisy[] = [(float) $x, 70 - 0.1 * $x + ($x % 2 === 0 ? 0.5 : -0.5)];
        }

        $cleanFit = Ols::fit($clean);
        $noisyFit = Ols::fit($noisy);

        self::assertEqualsWithDelta($cleanFit->slope, $noisyFit->slope, 1e-9);
        self::assertGreaterThan(0.01, $noisyFit->standardError);
    }

    public function test_gaps_in_x_are_honoured_rather_than_collapsed(): void
    {
        // Three weigh-ins a week apart; treated as consecutive, a trend 7x too steep.
        $fit = Ols::fit([[0.0, 70.0], [7.0, 69.5], [14.0, 69.0]]);

        self::assertEqualsWithDelta(-0.0714285, $fit->slope, 1e-6);
    }

    public function test_two_points_report_no_error_because_they_have_no_evidence_of_one(): void
    {
        $fit = Ols::fit([[0.0, 70.0], [10.0, 69.0]]);

        self::assertEqualsWithDelta(-0.1, $fit->slope, 1e-12);
        self::assertSame(0.0, $fit->standardError);
    }

    public function test_a_fit_that_cannot_exist_is_refused_rather_than_returned(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Ols::fit([[3.0, 70.0]]);
    }

    public function test_identical_x_values_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // Two weigh-ins on one day carry no slope; a zero Sxx divides to INF.
        Ols::fit([[4.0, 70.0], [4.0, 69.0]]);
    }
}
