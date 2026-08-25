<?php

declare(strict_types=1);

namespace Tests\Unit\Stress;

use PHPUnit\Framework\TestCase;
use App\Services\Stress\LevelChange;

/**
 * "+8.6% vs the seven days before" — the headline the old app prints without
 * ever saying how many days went into either side of it.
 *
 * Every case starts from a chosen percentage and demands it back, never a snapshot.
 */
final class LevelChangeTest extends TestCase
{
    private const BASE = 33.0;

    public function test_it_recovers_a_percentage_it_was_never_told(): void
    {
        $change = LevelChange::from(
            self::levels(7, self::BASE * 1.10),
            self::levels(7, self::BASE),
            minDays: 4,
        );

        self::assertEqualsWithDelta(10.0, (float) $change->percent, 1e-9);
        self::assertEqualsWithDelta(36.3, (float) $change->currentMs, 1e-9);
        self::assertEqualsWithDelta(33.0, (float) $change->previousMs, 1e-9);
        self::assertSame(7, $change->currentDays);
        self::assertSame(7, $change->previousDays);
    }

    public function test_the_average_is_geometric_so_the_percentage_is_reversible(): void
    {
        // Reversibility is what an arithmetic mean of milliseconds does NOT
        // have: +25.0% forwards must read -20.0% backwards, since 1/1.25 = 0.8.
        $high = [log(30.0), log(40.0), log(50.0), log(60.0)];
        $low = array_map(static fn (float $l): float => $l - log(1.25), $high);

        $up = LevelChange::from($high, $low, minDays: 4);
        $down = LevelChange::from($low, $high, minDays: 4);

        self::assertEqualsWithDelta(25.0, (float) $up->percent, 1e-9);
        self::assertEqualsWithDelta(-20.0, (float) $down->percent, 1e-9);
    }

    public function test_a_thin_window_reports_its_average_but_refuses_the_comparison(): void
    {
        // Three days against seven: their average is a real fact, the
        // percentage would be a fact about the charger.
        $change = LevelChange::from(
            self::levels(3, self::BASE),
            self::levels(7, self::BASE),
            minDays: 4,
        );

        self::assertNull($change->percent);
        self::assertEqualsWithDelta(33.0, (float) $change->currentMs, 1e-9);
        self::assertSame(3, $change->currentDays);

        $mirrored = LevelChange::from(
            self::levels(7, self::BASE),
            self::levels(3, self::BASE),
            minDays: 4,
        );

        self::assertNull($mirrored->percent);
        self::assertEqualsWithDelta(33.0, (float) $mirrored->previousMs, 1e-9);
    }

    public function test_an_empty_window_is_null_rather_than_zero(): void
    {
        $change = LevelChange::from([], [], minDays: 4);

        self::assertNull($change->currentMs);
        self::assertNull($change->previousMs);
        self::assertNull($change->percent);
        self::assertSame(0, $change->currentDays);

        // A tile drawing 0.0 ms from this would be reporting a dead person.
        self::assertNull($change->toArray()['currentMs']);
    }

    public function test_the_output_is_rounded_to_one_decimal(): void
    {
        $change = LevelChange::from(
            [log(36.28), log(36.30)],
            [log(33.0), log(33.0)],
            minDays: 2,
        );

        $array = $change->toArray();

        self::assertSame(36.3, $array['currentMs']);
        self::assertSame(33.0, $array['previousMs']);
        self::assertSame(10.0, $array['percent']);
    }

    /**
     * @return list<float>
     */
    private static function levels(int $days, float $ms): array
    {
        return array_fill(0, $days, log($ms));
    }
}
