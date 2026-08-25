<?php

declare(strict_types=1);

namespace Tests\Unit\Stress;

use Tests\TestCase;
use App\Enums\StressBand;
use App\Services\Stress\DailyStress;
use App\Services\Stress\LevelChange;
use App\Services\Stress\WeeklyDigest;

/**
 * The week read back, at the four places it would be easy to lie: extremes of a
 * barely measured week, future days of a week in progress, a comparison against
 * a week that was not there, three on-screen numbers that do not subtract.
 */
final class WeeklyDigestTest extends TestCase
{
    private const MONDAY = '2026-06-15';

    /** The end of the week these fixtures describe, so the week is complete. */
    private const SUNDAY = '2026-06-21';

    public function test_it_finds_the_worst_and_best_scored_days(): void
    {
        $digest = $this->digest([
            self::scored(0, 71),
            self::scored(1, 44),
            self::scored(2, 88),
            self::scored(3, 60),
            self::scored(4, 52),
            self::scored(5, 79),
            self::scored(6, 66),
        ]);

        // Higher score = LESS stress, so the most stressful day is the lowest.
        // Order matters: PHPUnit's assertSame narrows the nullsafe receiver for
        // the plain ->score reads below (no phpstan-phpunit installed).
        self::assertSame('2026-06-16', $digest->mostStressful?->date);
        self::assertSame(44, $digest->mostStressful->score);

        self::assertSame('2026-06-17', $digest->leastStressful?->date);
        self::assertSame(88, $digest->leastStressful->score);

        self::assertSame(7, $digest->scoredDays);
        self::assertSame(7, $digest->elapsedDays);
        self::assertSame([], $digest->skipped);
        self::assertFalse($digest->isSingleDay());
    }

    public function test_a_tie_goes_to_the_earlier_day(): void
    {
        // Untied, the headline follows array order: identical weeks, different days.
        $digest = $this->digest([
            self::scored(0, 88),
            self::scored(1, 44),
            self::scored(2, 66),
            self::scored(3, 44),
            self::scored(4, 88),
        ]);

        self::assertSame('2026-06-16', $digest->mostStressful?->date);
        self::assertSame('2026-06-15', $digest->leastStressful?->date);
    }

    public function test_one_scored_day_is_not_a_range(): void
    {
        $digest = $this->digest([
            self::unscored(0, DailyStress::NO_READINGS),
            self::scored(1, 71),
            self::unscored(2, DailyStress::TOO_FEW_READINGS),
        ]);

        self::assertTrue($digest->isSingleDay());
        self::assertSame($digest->mostStressful?->date, $digest->leastStressful?->date);
        self::assertTrue($digest->toArray()['singleDay']);
    }

    public function test_days_that_have_not_happened_yet_are_not_counted_as_gaps(): void
    {
        // Viewed on the Wednesday: calling the four future days "no readings"
        // would print a permanent "4 of 7 days missing" until Sunday, training
        // the reader to ignore the one line on this card meant to be believed.
        $digest = WeeklyDigest::from(
            week: [
                self::scored(0, 71),
                self::unscored(1, DailyStress::NO_READINGS),
                self::scored(2, 66),
                self::unscored(3, DailyStress::NO_READINGS),
                self::unscored(4, DailyStress::NO_READINGS),
                self::unscored(5, DailyStress::NO_READINGS),
                self::unscored(6, DailyStress::NO_READINGS),
            ],
            previousWeek: [],
            hrv: self::noChange(),
            today: '2026-06-17',
            minDays: 4,
        );

        self::assertSame(3, $digest->elapsedDays);
        self::assertSame(['no_readings' => 1], $digest->skipped);
        self::assertSame(2, $digest->scoredDays);
    }

    public function test_skipped_days_are_counted_by_reason(): void
    {
        $digest = $this->digest([
            self::scored(0, 71),
            self::unscored(1, DailyStress::NO_READINGS),
            self::unscored(2, DailyStress::TOO_FEW_READINGS),
            self::unscored(3, DailyStress::NO_READINGS),
            self::scored(4, 66),
            self::scored(5, 60),
            self::unscored(6, DailyStress::NO_BASELINE),
        ]);

        self::assertSame(
            ['no_readings' => 2, 'too_few_readings' => 1, 'no_baseline' => 1],
            $digest->skipped,
        );
        self::assertSame(4, $digest->toArray()['skippedDays']);
        self::assertSame(3, $digest->scoredDays);
    }

    public function test_the_week_on_week_delta_subtracts_the_numbers_that_are_on_screen(): void
    {
        // A reader subtracting the numbers on screen must come out right: 61.75
        // and 66.5 show as 62 and 67, and the last assertion pins -5 to those.
        $digest = WeeklyDigest::from(
            week: [self::scored(0, 60), self::scored(1, 62), self::scored(2, 63), self::scored(3, 62)],
            previousWeek: [self::scored(0, 66), self::scored(1, 67), self::scored(2, 67), self::scored(3, 66)],
            hrv: self::noChange(),
            today: self::SUNDAY,
            minDays: 4,
        );

        self::assertSame(62, $digest->meanScore);
        self::assertSame(67, $digest->previousMeanScore);
        self::assertSame(-5, $digest->scoreDelta);
        self::assertSame($digest->meanScore - $digest->previousMeanScore, $digest->scoreDelta);
    }

    public function test_a_week_that_was_barely_measured_gets_no_comparison(): void
    {
        // Three scored days: enough for an average, not enough to call a change.
        $thin = WeeklyDigest::from(
            week: [self::scored(0, 60), self::scored(1, 62), self::scored(2, 63)],
            previousWeek: array_map(static fn (int $i): DailyStress => self::scored($i, 66), range(0, 6)),
            hrv: self::noChange(),
            today: self::SUNDAY,
            minDays: 4,
        );

        self::assertSame(62, $thin->meanScore);
        self::assertNull($thin->scoreDelta);

        // Symmetric: a full week against a thin one is equally uncomparable.
        $thinBefore = WeeklyDigest::from(
            week: array_map(static fn (int $i): DailyStress => self::scored($i, 62), range(0, 6)),
            previousWeek: [self::scored(0, 66), self::scored(1, 67), self::scored(2, 67)],
            hrv: self::noChange(),
            today: self::SUNDAY,
            minDays: 4,
        );

        self::assertSame(67, $thinBefore->previousMeanScore);
        self::assertSame(3, $thinBefore->previousScoredDays);
        self::assertNull($thinBefore->scoreDelta);
    }

    public function test_a_week_with_nothing_in_it_says_so_instead_of_averaging_nothing(): void
    {
        $digest = $this->digest([
            self::unscored(0, DailyStress::NO_READINGS),
            self::unscored(1, DailyStress::NO_READINGS),
        ]);

        self::assertNull($digest->mostStressful);
        self::assertNull($digest->leastStressful);
        self::assertNull($digest->meanScore);
        self::assertNull($digest->scoreDelta);
        self::assertSame(0, $digest->scoredDays);
    }

    /**
     * @param  list<DailyStress>  $week
     */
    private function digest(array $week): WeeklyDigest
    {
        return WeeklyDigest::from($week, [], self::noChange(), self::SUNDAY, minDays: 4);
    }

    private static function noChange(): LevelChange
    {
        return LevelChange::from([], [], 4);
    }

    private static function scored(int $dayOffset, int $score): DailyStress
    {
        return new DailyStress(
            date: self::date($dayOffset),
            samples: 20,
            coveredHours: 20.0,
            score: $score,
            band: StressBand::fromScore($score),
            z: 0.0,
            hrvMs: 33.0,
            baselineHrvMs: 33.0,
            baselineSpreadLog: 0.15,
            baselineDays: 60,
        );
    }

    private static function unscored(int $dayOffset, string $reason): DailyStress
    {
        return new DailyStress(
            date: self::date($dayOffset),
            samples: $reason === DailyStress::NO_READINGS ? 0 : 2,
            coveredHours: 0.0,
            reason: $reason,
        );
    }

    private static function date(int $dayOffset): string
    {
        $timestamp = strtotime(self::MONDAY.' +'.$dayOffset.' days');
        self::assertNotFalse($timestamp, 'a fixed literal date plus a day offset always parses');

        return date('Y-m-d', $timestamp);
    }
}
