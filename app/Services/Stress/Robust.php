<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * Median and MAD — the two statistics this whole feature is built on.
 *
 * Not mean and standard deviation: production HRV holds single hourly
 * readings up to 213 ms against a median of 34. One of those inside a
 * 60-day baseline moves a mean by ~3 ms and inflates a standard deviation
 * by more, quietly compressing every score in the following two months
 * toward the middle — indistinguishable, on screen, from a calm summer.
 * The median and MAD both ignore it, so a spurious reading costs one
 * sample's worth of influence and nothing else. The 1.4826 factor rescales
 * the MAD so that for normally-distributed data it estimates the same
 * quantity the standard deviation does — what makes "0.85 sigma above
 * baseline" mean the usual thing.
 */
final class Robust
{
    /**
     * The constant that makes MAD a consistent estimator of sigma under
     * normality: 1 / Phi^-1(0.75).
     */
    public const MAD_TO_SIGMA = 1.4826;

    /**
     * The midpoint of the values, interpolated for an even count.
     *
     * @param  list<float>  $values
     */
    public static function median(array $values): ?float
    {
        $n = count($values);

        if ($n === 0) {
            return null;
        }

        sort($values);

        $mid = intdiv($n, 2);

        return $n % 2 === 1
            ? $values[$mid]
            : ($values[$mid - 1] + $values[$mid]) / 2.0;
    }

    /**
     * Median absolute deviation, rescaled to a standard-deviation equivalent.
     *
     * Null when there's nothing to measure. ZERO is a legitimate answer
     * (every value identical), returned as such rather than smoothed away —
     * the caller floors it with `min_spread_log`, where that judgment belongs.
     *
     * @param  list<float>  $values
     */
    public static function scaledMad(array $values, ?float $center = null): ?float
    {
        if ($values === []) {
            return null;
        }

        $center ??= self::median($values);

        $deviations = array_map(
            static fn (float $v): float => abs($v - (float) $center),
            $values
        );

        $mad = self::median($deviations);

        return $mad === null ? null : self::MAD_TO_SIGMA * $mad;
    }
}
