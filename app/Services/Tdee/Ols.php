<?php

declare(strict_types=1);

namespace App\Services\Tdee;

use InvalidArgumentException;

/**
 * Ordinary least squares through (x, y) points. No dependencies, no
 * database, no clock — the numerical core of the TDEE estimate, in a form
 * checkable against a textbook.
 *
 * Not (last − first) / days: the endpoint difference is almost pure noise —
 * daily weight varies ±1 kg on water and gut content alone, while a
 * genuine 0.5 kg/week deficit moves the scale 1 kg over a fortnight, so two
 * endpoints carry a signal the same size as their own noise, and the error
 * is unbounded (one salty dinner before the last weigh-in flips the sign of
 * the whole estimate, a 600 kcal error in the number the user eats to). A
 * least-squares line uses every point instead, and — the part that matters
 * for honesty — reports how much they disagree via the slope's standard
 * error (`SE(b) = sqrt(RSS / (n − 2) / Sxx)`), which becomes the width of
 * the published TDEE range: a noisy fortnight produces a wide range and
 * says so, instead of a confident wrong number.
 */
final class Ols
{
    /**
     * @param  list<array{0: float, 1: float}>  $points  [x, y] pairs.
     *
     * @throws InvalidArgumentException when fewer than two points, or when every
     *                                  x is identical (a vertical line has no slope).
     */
    public static function fit(array $points): OlsFit
    {
        $n = count($points);

        if ($n < 2) {
            throw new InvalidArgumentException('OLS needs at least two points.');
        }

        $meanX = array_sum(array_column($points, 0)) / $n;
        $meanY = array_sum(array_column($points, 1)) / $n;

        $sxx = 0.0;
        $sxy = 0.0;

        foreach ($points as [$x, $y]) {
            $dx = $x - $meanX;
            $sxx += $dx * $dx;
            $sxy += $dx * ($y - $meanY);
        }

        if ($sxx <= 0.0) {
            throw new InvalidArgumentException('OLS needs at least two distinct x values.');
        }

        $slope = $sxy / $sxx;
        $intercept = $meanY - $slope * $meanX;

        /*
         * Residual sum of squares -> standard error of the slope. With
         * exactly two points the line passes through both, RSS and degrees
         * of freedom (n − 2) are both 0: a perfect fit that says nothing
         * about its own reliability. Returning SE = 0.0 here would claim
         * certainty from two measurements, but it's safe only because the
         * gate (>= 8 weigh-ins) means this case never occurs in
         * practice — the caller refuses to publish below the gate.
         */
        if ($n === 2) {
            return new OlsFit($slope, $intercept, 0.0, $n);
        }

        $rss = 0.0;

        foreach ($points as [$x, $y]) {
            $residual = $y - ($intercept + $slope * $x);
            $rss += $residual * $residual;
        }

        $standardError = sqrt(($rss / ($n - 2)) / $sxx);

        return new OlsFit($slope, $intercept, $standardError, $n);
    }
}
