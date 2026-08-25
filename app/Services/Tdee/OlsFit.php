<?php

declare(strict_types=1);

namespace App\Services\Tdee;

/**
 * A fitted slope, and how well the points pin it down.
 *
 * `standardError` is why this is a value object, not a float: without an
 * error bar, "losing 0.4 kg a week" becomes a sentence believed after eight
 * noisy weigh-ins.
 */
final readonly class OlsFit
{
    public function __construct(
        /** kg per day (negative = losing). */
        public float $slope,
        /** The fitted value at x = 0. */
        public float $intercept,
        /** Standard error OF THE SLOPE, same units. 0.0 when it is not defined. */
        public float $standardError,
        /** How many points were fitted. */
        public int $n,
    ) {}
}
