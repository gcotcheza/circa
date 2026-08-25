<?php

declare(strict_types=1);

namespace App\Services\Rollup;

/**
 * An intake figure as what it actually is: a band, not a number.
 *
 * Why quadrature, not a linear sum (SPEC.md, "Honesty flags"): summing
 * every item's min and max gives a technically-correct bound that claims
 * every estimate is wrong in the same direction at once — ten items each
 * ±60 kcal would come out ±600 kcal, wider than the meal. Treating the
 * half-widths as independent errors and combining them in quadrature
 * (root-sum-square) gives ±190 kcal instead: narrower and honest, since
 * independent errors partially cancel — that's not an assumption, it's
 * what independence means.
 *
 * `mid` is the sum of per-item midpoints, the number the UI draws the band
 * around. Stored rather than derived, since (min+max)/2 of the RSS band
 * equals it only while nothing is clamped, and `min` IS clamped at zero.
 *
 * A manual entry with a known kcal figure has min == max, a half-width of
 * 0, collapsing the band exactly as far as it should.
 */
final readonly class IntakeBand
{
    public function __construct(
        public ?float $min = null,
        public ?float $mid = null,
        public ?float $max = null,
    ) {}

    /**
     * @param  list<array{min: float, max: float}>  $ranges  one per item
     */
    public static function fromRanges(array $ranges): self
    {
        if ($ranges === []) {
            return new self;
        }

        $mid = 0.0;
        $variance = 0.0;

        foreach ($ranges as $range) {
            $itemMid = ($range['min'] + $range['max']) / 2;
            $half = ($range['max'] - $range['min']) / 2;

            $mid += $itemMid;
            $variance += $half ** 2;
        }

        $half = sqrt($variance);

        return new self(
            min: max(0.0, $mid - $half),
            mid: $mid,
            max: $mid + $half,
        );
    }

    public function isEmpty(): bool
    {
        return $this->mid === null;
    }

    /** @return array{min: float|null, mid: float|null, max: float|null} */
    public function toArray(int $precision = 2): array
    {
        return [
            'min' => $this->min === null ? null : round($this->min, $precision),
            'mid' => $this->mid === null ? null : round($this->mid, $precision),
            'max' => $this->max === null ? null : round($this->max, $precision),
        ];
    }
}
