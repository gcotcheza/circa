<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Services\Stress\Robust;

/**
 * One run of numbers, summarised the one way this report summarises numbers.
 *
 * Shared so a bucket reads exactly the way the window block does: the same
 * keys in the same order with the same rounding, whether the run is a month
 * of steps or a fortnight of HRV. A second copy of this reducer would let
 * the two drift into a report that appears to compare like with like.
 */
final class Series
{
    /**
     * Nulls rather than zeroes for an empty run: these shapes go to the model
     * as facts, and a zero mean would be read there as a measurement.
     *
     * @param  list<float>  $values
     * @return array<string, mixed>
     */
    public static function summarise(array $values, int $precision): array
    {
        if ($values === []) {
            return ['n' => 0, 'mean' => null, 'median' => null, 'min' => null, 'max' => null];
        }

        return [
            'n'      => count($values),
            'mean'   => round(array_sum($values) / count($values), $precision),
            'median' => round((float) Robust::median($values), $precision),
            'min'    => round(min($values), $precision),
            'max'    => round(max($values), $precision),
        ];
    }
}
