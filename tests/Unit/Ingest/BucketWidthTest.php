<?php

declare(strict_types=1);

namespace Tests\Unit\Ingest;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;
use App\Services\Ingest\BucketWidth;

/** The header pair that reads backwards. */
final class BucketWidthTest extends TestCase
{
    public function test_the_bucket_width_comes_from_the_aggregation_header(): void
    {
        self::assertSame('hour', BucketWidth::fromHeaders([
            'automation-aggregation' => 'Hours',
            'automation-period'      => 'Since Last Sync',
        ]));
    }

    /**
     * `automation-period` sounds like the period column and is not: leaked in,
     * "since last sync" lands in the six-column unique key and forks history
     * the moment the automation's window mode changes.
     */
    public function test_the_export_window_header_never_becomes_a_period(): void
    {
        $period = BucketWidth::fromHeaders([
            'automation-period' => 'Since Last Sync',
        ]);

        self::assertSame('hour', $period);
        self::assertStringNotContainsStringIgnoringCase('sync', $period);
    }

    public function test_other_widths_map_to_singular_lowercase(): void
    {
        self::assertSame('day', BucketWidth::fromHeaders(['automation-aggregation' => 'Days']));
        self::assertSame('minute', BucketWidth::fromHeaders(['automation-aggregation' => 'Minutes']));
        self::assertSame('week', BucketWidth::fromHeaders(['automation-aggregation' => 'Weeks']));
        self::assertSame('month', BucketWidth::fromHeaders(['automation-aggregation' => 'Months']));
    }

    /** A missing header must match what every other payload used, or rows duplicate instead of upserting. */
    public function test_a_missing_header_falls_back_to_the_configured_width(): void
    {
        self::assertSame('hour', BucketWidth::fromHeaders([]));
        self::assertSame('hour', BucketWidth::fromHeaders(['automation-aggregation' => '   ']));
        self::assertSame(BucketWidth::DEFAULT, BucketWidth::fromHeaders([]));
    }

    /** Symfony hands multi-valued headers back as arrays. */
    public function test_array_valued_headers_are_unwrapped(): void
    {
        self::assertSame('hour', BucketWidth::fromHeaders(['automation-aggregation' => ['Hours']]));
    }

    /** An unrecognised width is kept, not coerced into hours. */
    public function test_an_unrecognised_width_is_kept_verbatim(): void
    {
        self::assertSame('fortnight', BucketWidth::fromHeaders(['automation-aggregation' => 'Fortnights']));
    }

    public function test_bucket_end_is_start_plus_one_width(): void
    {
        $start = CarbonImmutable::parse('2026-08-07 10:00:00', '+02:00');

        self::assertSame(
            '2026-08-07 11:00:00',
            BucketWidth::endOf($start, 'hour')->format('Y-m-d H:i:s')
        );

        self::assertSame(
            '2026-08-08 10:00:00',
            BucketWidth::endOf($start, 'day')->format('Y-m-d H:i:s')
        );
    }
}
