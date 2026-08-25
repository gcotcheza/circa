<?php

declare(strict_types=1);

namespace Tests\Unit\Stress;

use App\Services\Stress\Normal;
use PHPUnit\Framework\TestCase;

/**
 * The normal CDF and its inverse, against published tables, never a prior run.
 *
 * Every score squashes through them, so a drift moves all of them unnoticed.
 */
final class NormalTest extends TestCase
{
    public function test_the_cdf_matches_published_values(): void
    {
        // Enough digits to measure the APPROXIMATION, not input rounding: at
        // Phi'(0.674) = 0.32, six places already costs 8e-8 of the budget.
        $expected = [
            [-3.0, 0.0013498980],
            [-2.0, 0.0227501319],
            [-1.9599639845, 0.0250000000],
            [-1.0, 0.1586552539],
            [-0.5, 0.3085375387],
            [0.0, 0.5000000000],
            [0.5, 0.6914624613],
            [0.6744897502, 0.7500000000],
            [1.0, 0.8413447461],
            [1.9599639845, 0.9750000000],
            [2.0, 0.9772498680],
            [3.0, 0.9986501020],
        ];

        foreach ($expected as [$z, $p]) {
            // A&S 26.2.17 bounds the error at 7.5e-8; a [-6, 6] sweep hits 7.45e-8.
            self::assertEqualsWithDelta($p, Normal::cdf($z), 1e-7, "Phi({$z})");
        }
    }

    public function test_the_cdf_is_symmetric_and_monotone(): void
    {
        $previous = -1.0;

        for ($z = -5.0; $z <= 5.0; $z += 0.25) {
            $p = Normal::cdf($z);

            self::assertGreaterThan($previous, $p, "Phi is not increasing at {$z}");
            self::assertEqualsWithDelta(1.0, $p + Normal::cdf(-$z), 1e-7);

            $previous = $p;
        }
    }

    public function test_the_tails_saturate_rather_than_underflow(): void
    {
        self::assertSame(0.0, Normal::cdf(-100.0));
        self::assertSame(1.0, Normal::cdf(100.0));
        // A NAN z would otherwise poison a whole rebuild silently.
        self::assertSame(0.5, Normal::cdf(NAN));
    }

    public function test_the_inverse_matches_published_probits(): void
    {
        $expected = [
            [0.025, -1.959964],
            [0.05, -1.644854],
            [0.1586552539, -1.0],
            [0.25, -0.674490],
            [0.5, 0.0],
            [0.75, 0.674490],
            [0.9, 1.281552],
            [0.975, 1.959964],
            [0.999, 3.090232],
        ];

        foreach ($expected as [$p, $z]) {
            self::assertEqualsWithDelta($z, Normal::inverseCdf($p), 1e-5, "probit({$p})");
        }
    }

    public function test_the_inverse_round_trips_through_the_cdf(): void
    {
        foreach ([0.001, 0.02, 0.2, 0.35, 0.5, 0.65, 0.8, 0.98, 0.999] as $p) {
            self::assertEqualsWithDelta($p, Normal::cdf(Normal::inverseCdf($p)), 1e-6);
        }
    }

    public function test_degenerate_probabilities_saturate_instead_of_returning_infinity(): void
    {
        // An anchor outside 1-99 is a config error: INF would NAN every later score.
        self::assertSame(-40.0, Normal::inverseCdf(0.0));
        self::assertSame(40.0, Normal::inverseCdf(1.0));
        self::assertFalse(is_nan(Normal::cdf(Normal::inverseCdf(0.0))));
    }
}
