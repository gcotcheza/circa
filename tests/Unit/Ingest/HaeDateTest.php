<?php

declare(strict_types=1);

namespace Tests\Unit\Ingest;

use PHPUnit\Framework\TestCase;
use App\Services\Ingest\HaeDate;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * The offset in "2026-08-07 10:00:00 +0200" is load-bearing.
 */
final class HaeDateTest extends TestCase
{
    public function test_summer_offset_parses_to_the_right_utc_instant(): void
    {
        $at = HaeDate::parse('2026-08-07 10:00:00 +0200');

        self::assertSame('2026-08-07 08:00:00', $at->utc()->format('Y-m-d H:i:s'));
        self::assertSame(120, HaeDate::offsetMinutes($at));
    }

    public function test_winter_offset_parses_to_the_right_utc_instant(): void
    {
        $at = HaeDate::parse('2026-01-15 10:00:00 +0100');

        self::assertSame('2026-01-15 09:00:00', $at->utc()->format('Y-m-d H:i:s'));
        self::assertSame(60, HaeDate::offsetMinutes($at));
    }

    /**
     * The fall-back night is why the offset is parsed rather than assumed: on
     * 2026-10-25 Amsterdam runs 02:00 twice, only the suffix tells them apart,
     * and collapsing them loses an hour of a 25-hour day.
     */
    public function test_the_two_local_two_am_hours_of_a_fall_back_night_are_different_instants(): void
    {
        $first = HaeDate::parse('2026-10-25 02:00:00 +0200');
        $second = HaeDate::parse('2026-10-25 02:00:00 +0100');

        self::assertSame('2026-10-25 00:00:00', $first->utc()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-25 01:00:00', $second->utc()->format('Y-m-d H:i:s'));
        self::assertNotSame($first->getTimestamp(), $second->getTimestamp());
    }

    public function test_an_offsetless_date_is_rejected_rather_than_assumed(): void
    {
        $this->expectException(MalformedDatapoint::class);

        HaeDate::parse('2026-08-07 10:00:00', 'step_count');
    }

    public function test_garbage_is_rejected(): void
    {
        $this->expectException(MalformedDatapoint::class);

        HaeDate::parse('yesterday afternoon', 'step_count');
    }

    /** Colon-separated offsets are not what HAE sends, but cost nothing to accept. */
    public function test_iso_style_offsets_are_accepted(): void
    {
        self::assertSame(
            '2026-08-07 08:00:00',
            HaeDate::parse('2026-08-07 10:00:00 +02:00')->utc()->format('Y-m-d H:i:s')
        );
    }

    public function test_utc_offsets_round_trip_to_zero_minutes(): void
    {
        self::assertSame(0, HaeDate::offsetMinutes(HaeDate::parse('2026-08-07 10:00:00 +0000')));
    }
}
