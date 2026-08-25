<?php

declare(strict_types=1);

namespace Tests\Unit\Stress;

use App\Services\Stress\Robust;
use PHPUnit\Framework\TestCase;

/** Median and MAD — and that they do what mean and standard deviation do not. */
final class RobustTest extends TestCase
{
    public function test_the_median_interpolates_an_even_count(): void
    {
        self::assertNull(Robust::median([]));
        self::assertSame(5.0, Robust::median([5.0]));
        self::assertSame(3.0, Robust::median([1.0, 3.0, 100.0]));
        self::assertSame(2.5, Robust::median([1.0, 2.0, 3.0, 4.0]));
        self::assertSame(2.5, Robust::median([4.0, 1.0, 3.0, 2.0]));
    }

    public function test_the_mad_is_rescaled_to_a_standard_deviation_equivalent(): void
    {
        // Deviations from the median (3) are 2,1,0,1,2 -> MAD 1 -> 1.4826.
        self::assertEqualsWithDelta(
            Robust::MAD_TO_SIGMA,
            (float) Robust::scaledMad([1.0, 2.0, 3.0, 4.0, 5.0]),
            1e-12
        );
    }

    public function test_one_absurd_reading_moves_the_mad_by_nothing_and_the_sd_by_a_lot(): void
    {
        $ordinary = [30.0, 31.0, 32.0, 33.0, 34.0, 35.0, 36.0, 37.0, 38.0];

        // Production HRV holds single hourly readings of 213 ms, median 34.
        $withArtefact = [...$ordinary, 213.0];

        $mad = (float) Robust::scaledMad($ordinary);
        $madAfter = (float) Robust::scaledMad($withArtefact);

        $sd = self::standardDeviation($ordinary);
        $sdAfter = self::standardDeviation($withArtefact);

        // The MAD barely notices...
        self::assertLessThan(0.30, abs($madAfter - $mad) / $mad);
        // ...while the SD triples, pulling two months of scores to the middle.
        self::assertGreaterThan(3.0, $sdAfter / $sd);
    }

    public function test_identical_values_give_a_spread_of_zero_rather_than_a_guess(): void
    {
        // Zero is a real answer; the floor is the caller's `min_spread_log`.
        self::assertSame(0.0, Robust::scaledMad([7.0, 7.0, 7.0, 7.0]));
        self::assertNull(Robust::scaledMad([]));
    }

    /**
     * @param  list<float>  $values
     */
    private static function standardDeviation(array $values): float
    {
        $n = count($values);
        $mean = array_sum($values) / $n;

        $sum = 0.0;

        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }

        return sqrt($sum / ($n - 1));
    }
}
