<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Report;

use Tests\TestCase;
use App\Services\Report\Series;

/**
 * The one summary shape the report quotes numbers in, pinned where both the
 * window block and the weekly buckets can see it.
 */
final class SeriesTest extends TestCase
{
    public function test_an_empty_run_is_nulls_rather_than_zeroes(): void
    {
        // n is the honest zero; a mean of 0 would read to the model as a
        // measurement of nothing rather than as nothing measured.
        self::assertSame(
            ['n' => 0, 'mean' => null, 'median' => null, 'min' => null, 'max' => null],
            Series::summarise([], 1),
        );
    }

    public function test_the_five_figures_come_back_in_one_shape(): void
    {
        self::assertSame(
            ['n' => 4, 'mean' => 5.5, 'median' => 5.5, 'min' => 2.0, 'max' => 9.0],
            Series::summarise([2.0, 4.0, 7.0, 9.0], 1),
        );
    }

    public function test_the_median_of_an_odd_run_is_the_middle_value(): void
    {
        $summary = Series::summarise([9.0, 1.0, 5.0], 1);

        // Sorted by the median itself: the caller hands over a run in the
        // order the days came, not in order of size.
        self::assertSame(5.0, $summary['median']);
        self::assertSame(1.0, $summary['min']);
        self::assertSame(9.0, $summary['max']);
    }

    public function test_precision_is_the_callers_and_applies_to_every_figure(): void
    {
        self::assertSame(
            ['n' => 3, 'mean' => 2.0, 'median' => 2.0, 'min' => 1.0, 'max' => 3.0],
            Series::summarise([1.234, 2.345, 3.456], 0),
        );

        self::assertSame(2.35, Series::summarise([1.234, 2.345, 3.456], 2)['median']);
    }

    public function test_one_value_is_its_own_mean_median_and_both_ends(): void
    {
        self::assertSame(
            ['n' => 1, 'mean' => 7.5, 'median' => 7.5, 'min' => 7.5, 'max' => 7.5],
            Series::summarise([7.5], 1),
        );
    }
}
