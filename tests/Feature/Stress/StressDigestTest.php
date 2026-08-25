<?php

declare(strict_types=1);

namespace Tests\Feature\Stress;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Tests\Support\StressFixture;
use App\Services\Reporting\StressView;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The analysis section, end to end, against fixtures whose answers are known
 * before the code runs.
 *
 * This is the part of the app that most resembles the commercial one it
 * replaces, so it is where the old habits could most easily creep back in.
 * Four of them, one test each:
 *
 *   A percentage that really states which days the Watch was on -> a built
 *   fortnight gives back the change it was built with; a half-empty week
 *   refuses the comparison.
 *   Headline days chosen from a week nobody measured -> skipped days are
 *   counted and broken down, and future ones are not gaps.
 *   "Moments" always found because the section always has two rows -> a planted
 *   outlier is found at the z it was planted at, a flat week gives an empty list.
 *   A quiet failure when history is too short to know what unusual is -> the
 *   section is null, which the page renders as nothing at all.
 */
final class StressDigestTest extends TestCase
{
    use RefreshDatabase;

    /** A Sunday, so the current week is complete. */
    private const TODAY = '2026-06-21';

    private const WEEK_START = '2026-06-15';

    /** Levels cycling through these have median 0 and MAD exactly 0.1. */
    private const CYCLE = [-0.2, -0.1, 0.0, 0.1, 0.2];

    /**
     * Seven day-offsets summing to zero, so a week built from them has a
     * geometric mean of exactly BASE_MS and the millisecond assertions are as
     * exact as the percentage one.
     */
    private const WEEK_PATTERN = [-0.2, -0.1, 0.0, 0.1, 0.2, -0.05, 0.05];

    /** 1.4826 x 0.1 — one personal sigma, in log units. */
    private const SIGMA = 0.14826;

    private const BASE_MS = 33.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 20:00:00', StressFixture::TZ));
    }

    // --- The HRV percentage ---

    public function test_a_constructed_fortnight_gives_back_the_change_it_was_built_with(): void
    {
        /*
         * Both weeks carry the SAME variation pattern and the later one is
         * lifted by exactly ln(1.10), so the difference of the two means is that
         * lift and nothing else: +10.0% is arithmetic, not a snapshot, and it
         * appears nowhere in the fixture as a number.
         */
        $lift = log(1.10);

        $this->seedLevels(100, function (int $day) use ($lift): float {
            if ($day >= 93) {
                return self::WEEK_PATTERN[$day - 93] + $lift;
            }

            if ($day >= 86) {
                return self::WEEK_PATTERN[$day - 86];
            }

            return self::CYCLE[$day % 5];
        });

        $digest = $this->props()['digest'];

        self::assertSame(10.0, $digest['hrv']['percent']);
        self::assertSame(7, $digest['hrv']['currentDays']);
        self::assertSame(7, $digest['hrv']['previousDays']);

        // 33 ms lifted by 10%, in units the reader can check against the Watch.
        self::assertSame(36.3, $digest['hrv']['currentMs']);
        self::assertSame(33.0, $digest['hrv']['previousMs']);

        /*
         * The tile beside the score card runs the same arithmetic over the same
         * seven days, so it must agree to the decimal: two different +10%s on
         * one screen are indistinguishable from a rounding difference.
         */
        self::assertSame(10.0, $this->props()['hrv7']['percent']);
    }

    public function test_a_half_measured_week_refuses_the_comparison_it_could_have_made(): void
    {
        // Only three days have readings: still an average, but no percentage.
        $this->seedLevels(100, fn (int $day): float => self::CYCLE[$day % 5], function (int $day, int $hour): bool {
            return $day < 93 || $day >= 97;
        });

        $digest = $this->props()['digest'];

        self::assertSame(3, $digest['hrv']['currentDays']);
        self::assertNull($digest['hrv']['percent']);
        self::assertNotNull($digest['hrv']['currentMs']);
        self::assertSame(4, $digest['hrv']['minDays']);

        // Same gate on the score side, from the same three days.
        self::assertSame(3, $digest['scoredDays']);
        self::assertNotNull($digest['meanScore']);
        self::assertNull($digest['scoreDelta']);
    }

    // --- The two ends of the week ---

    public function test_it_names_the_hardest_and_easiest_days_and_counts_the_ones_it_could_not(): void
    {
        $this->seedLevels(
            100,
            function (int $day): float {
                if ($day < 93) {
                    return self::CYCLE[$day % 5];
                }

                return match ($day) {
                    94      => -0.30,   // Tuesday: the week's low point
                    96      => 0.30,    // Thursday: its high point
                    default => 0.0,
                };
            },
            // Friday the Watch was off; Saturday managed two readings, which is
            // two readings and not a day.
            function (int $day, int $hour): bool {
                if ($day === 97) {
                    return false;
                }

                if ($day === 98) {
                    return in_array($hour, [3, 4], strict: true);
                }

                return true;
            },
        );

        $digest = $this->props()['digest'];

        self::assertSame('2026-06-16', $digest['mostStressful']['date']);
        self::assertSame('2026-06-18', $digest['leastStressful']['date']);

        // Higher score means less stress: the one place a sign error is invisible.
        self::assertLessThan($digest['leastStressful']['score'], $digest['mostStressful']['score']);
        self::assertSame('attention', $digest['mostStressful']['band']);
        self::assertSame('great', $digest['leastStressful']['band']);

        self::assertSame(5, $digest['scoredDays']);
        self::assertSame(7, $digest['elapsedDays']);
        self::assertSame(2, $digest['skippedDays']);
        self::assertSame(
            ['no_readings' => 1, 'too_few_readings' => 1],
            (array) $digest['skipped'],
        );
        self::assertFalse($digest['singleDay']);

        // Both weeks are well covered, so the week-on-week move is allowed.
        self::assertNotNull($digest['scoreDelta']);
        self::assertSame(
            $digest['meanScore'] - $digest['previousMeanScore'],
            $digest['scoreDelta'],
        );
    }

    public function test_the_days_of_this_week_that_have_not_happened_are_not_reported_as_gaps(): void
    {
        // Wednesday lunchtime: Thursday to Sunday are not missing, just not yet.
        $this->travelTo(CarbonImmutable::parse('2026-06-17 13:00:00', StressFixture::TZ));

        // Ninety-six days ENDING ON THE WEDNESDAY: seeding through Sunday would
        // be seeding the future, which is what the assertion below is about.
        $this->seedLevels(96, fn (int $day): float => self::CYCLE[$day % 5], endDate: '2026-06-17');

        $digest = $this->props()['digest'];

        self::assertSame(3, $digest['elapsedDays']);
        self::assertSame(0, $digest['skippedDays']);
        self::assertSame(3, $digest['scoredDays']);
    }

    // --- Notable moments ---

    public function test_it_finds_a_planted_outlier_with_the_z_it_was_planted_at(): void
    {
        $this->seedWithOutlier(-3.0);

        $moments = $this->props()['digest']['moments'];

        self::assertCount(1, $moments);
        self::assertSame('2026-06-17', $moments[0]['date']);
        self::assertSame(3, $moments[0]['hour']);
        self::assertSame('low', $moments[0]['direction']);
        self::assertEqualsWithDelta(-3.0, $moments[0]['z'], 0.01);

        // The reading in real units, with this person's own middle half at that
        // hour beside it: the whole claim, checkable.
        self::assertEqualsWithDelta(
            self::BASE_MS * exp(-3.0 * self::SIGMA),
            $moments[0]['valueMs'],
            0.05,
        );
        self::assertSame(33.0, $moments[0]['typicalMs']);
        self::assertSame(30.0, $moments[0]['typicalLowMs']);
        self::assertSame(36.0, $moments[0]['typicalHighMs']);
    }

    public function test_a_flat_week_reports_nothing_rather_than_its_two_least_ordinary_hours(): void
    {
        $this->seedWithOutlier(null);

        // An EMPTY list, not null: the question was asked and answered no, and
        // the page says so in a sentence rather than showing nothing.
        self::assertSame([], $this->props()['digest']['moments']);
    }

    public function test_without_enough_history_there_is_no_section_at_all(): void
    {
        // Twenty-five days, eighteen of them before the viewed week — under the
        // twenty-one a baseline needs, so there is no honest spread to measure
        // an hour against.
        $this->seedLevels(25, fn (int $day): float => self::CYCLE[$day % 5]);

        self::assertNull($this->props()['digest']['moments']);
    }

    public function test_a_single_reading_is_never_given_a_band(): void
    {
        $this->seedWithOutlier(-4.0);

        $moment = $this->props()['digest']['moments'][0];

        // The bug designed out: the old app calls one 1 a.m. reading "overload
        // zone". A band belongs to a day, never to a moment.
        self::assertArrayNotHasKey('band', $moment);
        self::assertArrayNotHasKey('score', $moment);
        self::assertSame(
            ['date', 'hour', 'weekday', 'valueMs', 'z', 'direction', 'typicalMs', 'typicalLowMs', 'typicalHighMs'],
            array_keys($moment),
        );
    }

    // --- The tiles ---

    public function test_the_resting_tile_compares_with_the_previous_reading_that_exists(): void
    {
        $this->seedLevels(100, fn (int $day): float => self::CYCLE[$day % 5]);

        StressFixture::resting([
            '2026-05-30' => 68.0,
            '2026-06-10' => 66.0,
            '2026-06-14' => 64.0,
            '2026-06-18' => 59.0,
            '2026-06-21' => 62.0,
        ]);

        $resting = $this->props()['restingHr'];

        self::assertSame(62.0, $resting['latestBpm']);
        self::assertSame(self::TODAY, $resting['latestDate']);
        // Named, not "yesterday": that would be wrong on most days of the year.
        self::assertSame('2026-06-18', $resting['previousDate']);
        self::assertSame(3.0, $resting['delta']);
        // Median, not mean: the 68 in May was a week they had, not a new normal.
        self::assertSame(64.0, $resting['medianBpm']);
        self::assertSame(5, $resting['days']);
        self::assertSame(90, $resting['windowDays']);
    }

    public function test_the_tiles_stay_on_today_when_an_older_week_is_browsed(): void
    {
        $this->seedLevels(100, fn (int $day): float => self::CYCLE[$day % 5]);

        StressFixture::resting(['2026-06-20' => 58.0, '2026-06-21' => 62.0]);

        $current = $this->props();
        $browsed = $this->props('2026-06-03');

        // The digest follows the URL...
        self::assertSame(self::WEEK_START, $current['week']['start']);
        self::assertSame('2026-06-01', $browsed['week']['start']);

        // ...and the tiles do not: one is by definition the LATEST resting heart
        // rate, and a week in the past has no latest reading.
        self::assertSame($current['restingHr'], $browsed['restingHr']);
        self::assertSame($current['hrv7'], $browsed['hrv7']);
        self::assertSame(62.0, $browsed['restingHr']['latestBpm']);
    }

    public function test_a_tile_with_no_readings_behind_it_is_blank_rather_than_zero(): void
    {
        $this->seedLevels(100, fn (int $day): float => self::CYCLE[$day % 5]);

        $resting = $this->props()['restingHr'];

        self::assertNull($resting['latestBpm']);
        self::assertNull($resting['medianBpm']);
        self::assertNull($resting['delta']);
        self::assertSame(0, $resting['days']);
    }

    // --- The page ---

    public function test_the_page_ships_the_digest_and_both_tiles(): void
    {
        $this->actingAs(User::factory()->create());

        $this->seedLevels(100, fn (int $day): float => self::CYCLE[$day % 5]);
        StressFixture::resting(['2026-06-21' => 62.0]);

        $this->get('/stress')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Stress')
                ->has('digest.mostStressful')
                ->has('digest.leastStressful')
                ->has('digest.hrv')
                ->where('digest.momentMinZ', 2.5)
                ->where('digest.momentPoolDays', 60)
                ->where('hrv7.currentDays', 7)
                // An int on the wire: json_encode drops a whole float's zero
                // fraction unless asked not to.
                ->where('restingHr.latestBpm', 62)
                ->etc()
            );
    }

    // --- Fixtures ---

    /**
     * @return array<string, mixed>
     */
    private function props(?string $week = null): array
    {
        return (new StressView)->props($week);
    }

    /**
     * `$count` days of readings ending today, every hour at a level chosen per
     * day. Equal hours make the within-day residuals zero, so the circadian
     * profile comes out flat AND estimated for every hour — letting these tests
     * reason about levels directly while still exercising the per-hour gate in
     * NotableMoments.
     *
     * @param  callable(int $day): float  $level  log offset from BASE_MS; day 0 is oldest
     * @param  (callable(int $day, int $hour): bool)|null  $wears  when the Watch was on
     */
    private function seedLevels(
        int $count,
        callable $level,
        ?callable $wears = null,
        string $endDate = self::TODAY,
    ): void {
        StressFixture::days(
            $endDate,
            $count,
            function (int $day, int $hour) use ($level, $wears): ?float {
                if ($wears !== null && ! $wears($day, $hour)) {
                    return null;
                }

                return self::BASE_MS * exp($level($day));
            },
        );
    }

    /**
     * A hundred ordinary days, optionally with one reading on the viewed week's
     * Wednesday a chosen number of personal sigma below the middle. The pool is
     * the sixty days before the week, all cycling through the five offsets, so
     * their median is exactly ln(33) and their MAD exactly 0.1 — the planted
     * reading's z is exactly what it was planted at, and the code is told
     * neither number.
     */
    private function seedWithOutlier(?float $z): void
    {
        $planted = $z === null ? null : self::BASE_MS * exp($z * self::SIGMA);

        StressFixture::days(
            self::TODAY,
            100,
            function (int $day, int $hour) use ($planted): float {
                // The viewed week sits on the middle, so only the planting can
                // be notable in it.
                $level = $day >= 93 ? 0.0 : self::CYCLE[$day % 5];

                if ($planted !== null && $day === 95 && $hour === 3) {
                    return $planted;
                }

                return self::BASE_MS * exp($level);
            },
        );
    }
}
