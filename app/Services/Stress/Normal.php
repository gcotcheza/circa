<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * The standard normal CDF and its inverse.
 *
 * PHP has no `erf`/`erfc` outside the unbundled stats extension, and this
 * app ships no extension it doesn't need, so both are approximated here —
 * textbook, deterministic, unit-tested against published values, which is
 * why they're a class rather than two loose functions inside the estimator.
 *
 * The score they feed is an INTEGER 1-99, so accuracy isn't close: the CDF
 * is good to 7.5e-8 and the inverse to ~1e-9, against a quantity whose
 * smallest meaningful step is 1/98.
 */
final class Normal
{
    /**
     * Phi(z) — P(Z <= z) for Z ~ N(0, 1).
     *
     * Abramowitz & Stegun 26.2.17 (the Zelen & Severo five-term form),
     * |error| < 7.5e-8 everywhere.
     */
    public static function cdf(float $z): float
    {
        if (is_nan($z)) {
            return 0.5;
        }

        // Fitted on [0, inf); the tails saturate long before they're reached,
        // and short-circuiting keeps exp(-z*z/2) from underflowing to a denormal.
        if ($z < -40.0) {
            return 0.0;
        }

        if ($z > 40.0) {
            return 1.0;
        }

        $x = abs($z);

        $t = 1.0 / (1.0 + 0.2316419 * $x);

        $poly = $t * (0.319381530
            + $t * (-0.356563782
                + $t * (1.781477937
                    + $t * (-1.821255978
                        + $t * 1.330274429))));

        $density = exp(-0.5 * $x * $x) / sqrt(2.0 * M_PI);

        $upper = $density * $poly; // 1 - Phi(x), for x >= 0

        return $z >= 0.0 ? 1.0 - $upper : $upper;
    }

    /**
     * Phi^-1(p) — the probit.
     *
     * Peter Acklam's rational approximation, relative error < 1.15e-9 over
     * (0, 1). Used only to turn the two configured anchors into the scale's
     * shape parameters, so it runs twice per estimator construction and never
     * on a hot path.
     */
    public static function inverseCdf(float $p): float
    {
        // Degenerate input is a config error, not a data error — an anchor
        // score outside (min, max). Saturating is the honest answer: it
        // flattens the scale rather than producing INF and poisoning every
        // score with NAN.
        if ($p <= 0.0) {
            return -40.0;
        }

        if ($p >= 1.0) {
            return 40.0;
        }

        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02,
            1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02,
            6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00,
            -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00,
            3.754408661907416e+00];

        $low = 0.02425;
        $high = 1.0 - $low;

        if ($p < $low) {
            $q = sqrt(-2.0 * log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }

        if ($p > $high) {
            $q = sqrt(-2.0 * log(1.0 - $p));

            return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1.0);
        }

        $q = $p - 0.5;
        $r = $q * $q;

        return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
            / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1.0);
    }
}
