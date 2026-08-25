<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use App\Enums\HealthReportKind;
use App\Services\Report\ReportRange;

/**
 * Four rules in one class rather than in each of the four callers. An
 * off-by-one here lands in every figure of every report and is invisible: a
 * seven-day report covering six days looks exactly like a seven-day report.
 */
final class ReportRangeTest extends TestCase
{
    private const TZ = 'Europe/Amsterdam';

    protected function setUp(): void
    {
        parent::setUp();

        // A Wednesday: "the previous week" is unambiguous and the clamp bites.
        $this->travelTo(CarbonImmutable::parse('2026-08-12 09:30:00', self::TZ));
    }

    public function test_both_ends_are_inclusive(): void
    {
        $range = ReportRange::between('2026-08-03', '2026-08-09');

        self::assertSame(7, $range->days);
        self::assertCount(7, $range->dates());
        self::assertSame('2026-08-03', $range->dates()[0]);
        self::assertSame('2026-08-09', $range->dates()[6]);
    }

    public function test_a_single_day_is_one_day_not_zero(): void
    {
        $range = ReportRange::between('2026-08-09', '2026-08-09');

        self::assertSame(1, $range->days);
        self::assertSame(['2026-08-09'], $range->dates());
    }

    /**
     * The preset chips: "the last 7 days" is today and the six before it — the
     * ceiling being today is what makes it seven rather than eight.
     */
    public function test_last_days_counts_the_end_day(): void
    {
        $range = ReportRange::lastDays(7);

        self::assertSame('2026-08-06', $range->start);
        self::assertSame('2026-08-12', $range->end);
        self::assertSame(7, $range->days);
    }

    public function test_a_backwards_range_is_a_typo_not_a_request(): void
    {
        $range = ReportRange::between('2026-08-09', '2026-08-03');

        self::assertSame('2026-08-03', $range->start);
        self::assertSame('2026-08-09', $range->end);
    }

    /** Tomorrow is a real day with nothing in it, and would read as a day nothing was logged. */
    public function test_the_end_is_clamped_to_today(): void
    {
        $range = ReportRange::between('2026-08-10', '2026-12-25');

        self::assertSame('2026-08-12', $range->end);
        self::assertSame(3, $range->days);
    }

    /**
     * The start is deliberately NOT clamped: a wholly future window is a
     * mistake worth reporting, not one to rewrite silently into "today".
     */
    public function test_a_range_entirely_in_the_future_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('has not happened yet');

        ReportRange::between('2026-09-01', '2026-09-07');
    }

    public function test_a_range_past_the_ceiling_is_refused_with_the_ceiling_in_the_message(): void
    {
        config()->set('health.report.max_range_days', 30);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 30 days');

        ReportRange::between('2026-01-01', '2026-08-12');
    }

    public function test_a_date_that_is_not_a_date_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportRange::between('last tuesday', '2026-08-12');
    }

    /** The cron's range: Wednesday the 12th means the week of Monday the 3rd. */
    public function test_the_previous_week_is_the_last_complete_monday_to_sunday(): void
    {
        $range = ReportRange::previousWeek();

        self::assertSame('2026-08-03', $range->start);
        self::assertSame('2026-08-09', $range->end);
        self::assertSame(7, $range->days);
    }

    /** Run ON a Monday: the week that ended yesterday, not the one six hours old. */
    public function test_the_previous_week_run_on_a_monday_is_the_week_that_just_ended(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-10 04:10:00', self::TZ));

        $range = ReportRange::previousWeek();

        self::assertSame('2026-08-03', $range->start);
        self::assertSame('2026-08-09', $range->end);
    }

    /** The drift block's year-ago window: both ends move alike, so the medians compare. */
    public function test_shifting_moves_both_ends_and_keeps_the_length(): void
    {
        $range = ReportRange::between('2026-08-03', '2026-08-09')->shifted(365);

        self::assertSame('2025-08-03', $range->start);
        self::assertSame('2025-08-09', $range->end);
        self::assertSame(7, $range->days);
    }

    /** A shifted window is historical; clamping it collapses the comparison to nothing. */
    public function test_shifting_does_not_clamp_against_today(): void
    {
        $range = ReportRange::lastDays(7)->shifted(365);

        self::assertSame('2025-08-06', $range->start);
        self::assertSame('2025-08-12', $range->end);
    }

    /** The asymmetry the whole idempotency design rests on. */
    public function test_a_weekly_key_is_derived_from_the_week_so_a_double_fire_collides(): void
    {
        $a = ReportRange::previousWeek()->idempotencyKey(HealthReportKind::Weekly);
        $b = ReportRange::previousWeek()->idempotencyKey(HealthReportKind::Weekly);

        self::assertSame('weekly:2026-08-03:2026-08-09', $a);
        self::assertSame($a, $b);
    }

    public function test_a_manual_key_is_unique_so_asking_again_is_a_new_report(): void
    {
        $range = ReportRange::lastDays(7);

        $a = $range->idempotencyKey(HealthReportKind::Manual);
        $b = $range->idempotencyKey(HealthReportKind::Manual);

        self::assertNotSame($a, $b);
        self::assertStringStartsWith('manual:2026-08-06:2026-08-12:', $a);
    }

    /**
     * Europe/Amsterdam has a 23-hour and a 25-hour day every year: a `dates()`
     * built by adding 86 400 seconds would duplicate a date or skip one.
     */
    public function test_a_range_across_the_spring_clock_change_still_has_one_date_per_day(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-04-05 12:00:00', self::TZ));

        $range = ReportRange::between('2026-03-27', '2026-03-31');

        self::assertSame(5, $range->days);
        self::assertSame(
            ['2026-03-27', '2026-03-28', '2026-03-29', '2026-03-30', '2026-03-31'],
            $range->dates(),
        );
    }
}
