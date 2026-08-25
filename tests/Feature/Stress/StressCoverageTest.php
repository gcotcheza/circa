<?php

declare(strict_types=1);

namespace Tests\Feature\Stress;

use Tests\TestCase;
use App\Models\StressDaily;
use Carbon\CarbonImmutable;
use Tests\Support\StressFixture;
use App\Services\Stress\DailyStress;
use App\Services\Stress\StressAnalysis;
use App\Services\Stress\StressRecorder;
use App\Services\Stress\StressCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * "Not enough data" beats an invented number.
 *
 * The Watch is not worn 24/7 and this user's history proves it: 250 of their 850
 * HRV days carry seven readings or fewer, and 21 carry three or fewer. Every one
 * would produce a presentable score if the code were willing to compute one, and
 * none would be worth looking at. So there are three separate refusals, and this
 * file pins all three plus the badge qualifying the days that do get through.
 */
final class StressCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-15';

    private const CYCLE = [-0.2, -0.1, 0.0, 0.1, 0.2];

    private const BASE_MS = 33.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:00:00', StressFixture::TZ));
    }

    public function test_a_day_with_no_readings_says_so_rather_than_going_missing(): void
    {
        $this->seedHistory(hoursToday: []);

        $day = $this->analyse()->day(self::TODAY);

        self::assertFalse($day->hasScore());
        self::assertSame(DailyStress::NO_READINGS, $day->reason);
        self::assertSame(0, $day->samples);
        self::assertSame(0.0, $day->coveredHours);
        self::assertSame('none', $day->confidence());
    }

    public function test_two_readings_are_two_readings_and_not_a_day(): void
    {
        config()->set('health.stress.min_day_samples', 3);

        $this->seedHistory(hoursToday: [3, 4]);

        $day = $this->analyse()->day(self::TODAY);

        self::assertFalse($day->hasScore());
        self::assertSame(DailyStress::TOO_FEW_READINGS, $day->reason);
        // Still REPORTED: the day is thin, not invisible.
        self::assertSame(2, $day->samples);
        self::assertEqualsWithDelta(2.0, $day->coveredHours, 1e-9);
    }

    public function test_a_day_with_no_history_behind_it_has_no_yardstick_and_says_that_instead(): void
    {
        config()->set('health.stress.min_baseline_days', 21);

        // Ten days of perfect coverage, all fully measured and none scorable,
        // because there is nothing to compare them WITH — a completely
        // different statement from "thin day".
        StressFixture::days(self::TODAY, 10, fn (int $i): float => self::BASE_MS * exp(self::CYCLE[$i % 5]));

        $day = $this->analyse()->day(self::TODAY);

        self::assertFalse($day->hasScore());
        self::assertSame(DailyStress::NO_BASELINE, $day->reason);
        self::assertSame(24, $day->samples);
        // The day's own level is still known, and still shown.
        self::assertNotNull($day->hrvMs);
    }

    public function test_the_confidence_badge_follows_the_reading_count(): void
    {
        config()->set('health.stress.confidence.high_samples', 16);
        config()->set('health.stress.confidence.medium_samples', 8);

        foreach ([[20, 'high'], [16, 'high'], [10, 'medium'], [8, 'medium'], [5, 'low'], [3, 'low']] as [$count, $expected]) {
            $day = new DailyStress(
                date: self::TODAY,
                samples: $count,
                coveredHours: (float) $count,
                score: 65,
                z: 0.0,
            );

            self::assertSame($expected, $day->confidence(), "{$count} readings");
        }
    }

    public function test_a_thin_day_is_scored_but_carries_its_thinness_into_the_stored_row(): void
    {
        // Four readings — the Watch went on late. It scores, because four
        // deseasonalised readings ARE evidence, and it gets a badge saying how
        // much, because four is not twenty.
        $this->seedHistory(hoursToday: [22, 23, 0, 1]);

        $day = $this->analyse()->day(self::TODAY);

        self::assertTrue($day->hasScore());
        self::assertSame(4, $day->samples);
        self::assertSame('low', $day->confidence());

        app(StressRecorder::class)->store($day);

        $row = StressDaily::query()->find(self::TODAY);

        self::assertNotNull($row);
        self::assertSame('low', $row->confidence);
        self::assertSame(4, $row->samples);
        self::assertTrue($row->isThin());
    }

    public function test_unscored_days_are_absent_from_the_table_rather_than_stored_as_placeholders(): void
    {
        $this->seedHistory(hoursToday: [3, 4]);

        $analysis = $this->analyse();

        $result = app(StressRecorder::class)->record([
            $analysis->day(CarbonImmutable::parse(self::TODAY)->subDay()->toDateString()),
            $analysis->day(self::TODAY),
        ]);

        self::assertSame(1, $result['written']);
        self::assertNull(StressDaily::query()->find(self::TODAY));
        self::assertNotNull(StressDaily::query()->find(
            CarbonImmutable::parse(self::TODAY)->subDay()->toDateString()
        ));
    }

    public function test_a_day_that_stops_qualifying_has_its_row_removed_rather_than_left_stale(): void
    {
        StressDaily::factory()->onDate(self::TODAY)->create();

        self::assertNotNull(StressDaily::query()->find(self::TODAY));

        $result = app(StressRecorder::class)->record([
            new DailyStress(
                date: self::TODAY,
                samples: 1,
                coveredHours: 1.0,
                reason: DailyStress::TOO_FEW_READINGS,
            ),
        ]);

        self::assertSame(1, $result['removed']);
        self::assertNull(StressDaily::query()->find(self::TODAY));
    }

    /**
     * Sixty days of full coverage behind today; today covered only at the hours
     * given.
     *
     * @param  list<int>  $hoursToday
     */
    private function seedHistory(array $hoursToday): void
    {
        StressFixture::days(
            CarbonImmutable::parse(self::TODAY)->subDay()->toDateString(),
            60,
            fn (int $i): float => self::BASE_MS * exp(self::CYCLE[$i % 5]),
        );

        if ($hoursToday !== []) {
            StressFixture::day(
                self::TODAY,
                array_combine($hoursToday, array_fill(0, count($hoursToday), self::BASE_MS)),
            );
        }
    }

    private function analyse(): StressAnalysis
    {
        $from = CarbonImmutable::parse(self::TODAY)->subDays(10)->toDateString();

        return app(StressCalculator::class)->analyse(
            $from,
            self::TODAY,
            CarbonImmutable::parse(self::TODAY, StressFixture::TZ),
        );
    }
}
