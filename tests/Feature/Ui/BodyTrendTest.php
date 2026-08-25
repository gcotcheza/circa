<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Models\HealthMetric;
use App\Services\Reporting\BodyTrend;
use App\Services\Reporting\WeightEma;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The body card's payload: weight, body fat, muscle mass, and the range it opens
 * on. The weight series is `daily_summaries.weight_kg`, and its EMA is
 * byte-for-byte the series the TDEE estimator fits — drawn from a second query
 * the chart would drift from the published number, both still looking like
 * plausible weights. The body-composition series go through the ROLLUP'S OWN
 * instant-pick rule, because the scale reports every reading twice (the Fitage
 * app and its export are both `scale`), so "one value per day" is a decision.
 * The opening window is derived from the data: the right default for two
 * weigh-ins a month is the wrong one for two a week. And muscle mass is the one
 * series nothing syncs, so the card states the day the last hand-run import
 * reached — the tests at the bottom guard the two ways that note could be WRONG
 * rather than absent: nagging a user who has no such workflow, and blaming the
 * import for a fortnight the user spent off the scale entirely.
 */
final class BodyTrendTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 10:00:00', 'Europe/Amsterdam'));
    }

    public function test_there_is_no_card_at_all_until_something_has_been_weighed(): void
    {
        self::assertNull(app(BodyTrend::class)->props()['body']);
    }

    public function test_the_weight_series_is_the_weigh_in_days_with_their_trend(): void
    {
        config()->set('health.trends.ema_alpha', 0.5);

        DailySummary::factory()->onDate('2026-06-09')->create(['weight_kg' => 56.0]);
        DailySummary::factory()->onDate('2026-06-10')->create(['weight_kg' => 58.0]);
        // Four days of no information in between: not points.
        DailySummary::factory()->onDate('2026-06-14')->create(['weight_kg' => 57.0]);

        $body = app(BodyTrend::class)->props()['body'];
        $weight = $body['series'][0];

        self::assertSame('weight', $weight['key']);
        self::assertSame('kg', $weight['unit']);
        self::assertSame('2026-06-15', $body['today']);

        self::assertSame(
            [
                ['date' => '2026-06-09', 'value' => 56.0, 'trend' => 56.0],
                ['date' => '2026-06-10', 'value' => 58.0, 'trend' => 57.0],
                ['date' => '2026-06-14', 'value' => 57.0, 'trend' => 57.0],
            ],
            $weight['points']
        );
    }

    public function test_the_trend_is_the_same_series_the_tdee_estimator_fits(): void
    {
        config()->set('health.trends.ema_alpha', 0.25);

        foreach (['2026-05-02' => 56.4, '2026-05-19' => 55.1, '2026-06-01' => 55.9] as $date => $kg) {
            DailySummary::factory()->onDate($date)->create(['weight_kg' => $kg]);
        }

        $published = app(WeightEma::class)->series();

        $points = app(BodyTrend::class)->props()['body']['series'][0]['points'];

        foreach ($points as $point) {
            self::assertSame(
                round($published[$point['date']], 2),
                $point['trend'],
                "the chart and the estimator disagree about {$point['date']}"
            );
        }
    }

    public function test_body_composition_is_one_reading_per_day_picked_the_way_the_rollup_picks_it(): void
    {
        DailySummary::factory()->onDate('2026-06-10')->create(['weight_kg' => 56.0]);

        $scale = Source::factory()->scale('FITAGE')->create();
        $export = Source::factory()->scale('FITAGE export')->create();
        $phone = Source::factory()->phone()->create();

        // One morning: twice from the scale's two apps, then typed by hand.
        $this->instant('body_fat_percentage', '2026-06-10 07:12', 24.5, $scale);
        $this->instant('body_fat_percentage', '2026-06-10 07:12', 24.5, $export);
        // A re-weigh an hour later is a CORRECTION, so it wins for its device...
        $this->instant('body_fat_percentage', '2026-06-10 08:40', 23.9, $scale);
        // ...but the phone loses to the scale whatever time it arrived.
        $this->instant('body_fat_percentage', '2026-06-10 22:00', 30.0, $phone);

        // `props()` is `array<string, mixed>`, so collect() needs the series
        // named on the way out to have a TKey/TValue to infer.
        /** @var list<array<string, mixed>> $series */
        $series = app(BodyTrend::class)->props()['body']['series'];

        $fat = collect($series)->firstWhere('key', 'fat');

        self::assertNotNull($fat);
        self::assertSame('%', $fat['unit']);
        self::assertSame([['date' => '2026-06-10', 'value' => 23.9, 'trend' => 23.9]], $fat['points']);
    }

    public function test_a_series_with_nothing_in_it_gets_no_chip(): void
    {
        DailySummary::factory()->onDate('2026-06-10')->create(['weight_kg' => 56.0]);

        $scale = Source::factory()->scale()->create();
        $this->instant('body_fat_percentage', '2026-06-10 07:12', 24.5, $scale);

        $keys = array_column(app(BodyTrend::class)->props()['body']['series'], 'key');

        // Nothing reports muscle mass: a chip opening an empty chart is worse.
        self::assertSame(['weight', 'fat'], $keys);
    }

    public function test_the_card_opens_on_the_shortest_range_that_holds_enough_weigh_ins(): void
    {
        config()->set('health.trends.body.default_min_points', 5);

        // Five weigh-ins, all inside the last four weeks.
        foreach (['2026-05-25', '2026-05-28', '2026-06-02', '2026-06-08', '2026-06-14'] as $date) {
            DailySummary::factory()->onDate($date)->create(['weight_kg' => 55.0]);
        }

        self::assertSame('4w', app(BodyTrend::class)->props()['body']['range']);
    }

    public function test_the_shortest_chip_is_a_week_of_daily_weigh_ins(): void
    {
        DailySummary::factory()->onDate('2026-06-14')->create(['weight_kg' => 56.0]);

        /** @var list<array{key: string, days: int|null, title: string}> $ranges */
        $ranges = app(BodyTrend::class)->props()['body']['ranges'];

        self::assertSame(
            ['1w', '4w', '3m', '6m', '1y', 'all'],
            array_column($ranges, 'key')
        );

        self::assertSame(7, $ranges[0]['days']);
        self::assertSame('a week', $ranges[0]['title']);
    }

    public function test_a_week_of_daily_weigh_ins_opens_the_card_on_the_week(): void
    {
        // NO config override, deliberately: this and the three below pin the
        // SHIPPED threshold. "The card opens on the week once I weigh in every
        // morning" is the behaviour the user asked for, so change
        // `default_min_points` in config/health.php and one of the four goes red.
        foreach (range(0, 6) as $back) {
            DailySummary::factory()
                ->onDate(CarbonImmutable::parse('2026-06-15')->subDays($back)->toDateString())
                ->create(['weight_kg' => 56.0]);
        }

        self::assertSame('1w', app(BodyTrend::class)->props()['body']['range']);
    }

    public function test_the_week_a_daily_habit_starts_opens_on_the_week(): void
    {
        // The real history on the day this threshold came down to four: five
        // consecutive mornings, the first two days of the trailing week from
        // before the habit existed. Six wanted one more morning and kept the
        // card on 4w for a user who was already weighing in daily.
        foreach (['06-15', '06-14', '06-13', '06-12', '06-11'] as $day) {
            DailySummary::factory()->onDate("2026-{$day}")->create(['weight_kg' => 56.0]);
        }

        self::assertSame('1w', app(BodyTrend::class)->props()['body']['range']);
    }

    public function test_a_week_with_three_mornings_missed_still_opens_on_the_week(): void
    {
        // Four of the last seven — the floor, exactly. Three missed mornings is
        // a weekend away, and four points across seven days still have a
        // direction in them; tolerating that is why the number is four.
        foreach (['06-15', '06-14', '06-11', '06-09'] as $day) {
            DailySummary::factory()->onDate("2026-{$day}")->create(['weight_kg' => 56.0]);
        }

        self::assertSame('1w', app(BodyTrend::class)->props()['body']['range']);
    }

    public function test_a_week_with_four_mornings_missed_falls_through_to_the_next_chip(): void
    {
        // Three of the last seven, plus enough older readings that four weeks
        // clears the same bar. Three dots in a week is a scatter, not a trend,
        // so it degrades: the shortest window that HOLDS enough wins, not the
        // shortest one offered.
        foreach (['06-15', '06-14', '06-10', '06-02', '05-27'] as $day) {
            DailySummary::factory()->onDate("2026-{$day}")->create(['weight_kg' => 56.0]);
        }

        self::assertSame('4w', app(BodyTrend::class)->props()['body']['range']);
    }

    public function test_a_sparse_history_opens_wider_rather_than_on_an_empty_card(): void
    {
        config()->set('health.trends.body.default_min_points', 5);

        // Two a month, so four weeks holds two and three months holds six.
        foreach (
            ['2026-03-20', '2026-04-02', '2026-04-19', '2026-05-04', '2026-05-21', '2026-06-04', '2026-06-12'] as $date
        ) {
            DailySummary::factory()->onDate($date)->create(['weight_kg' => 55.0]);
        }

        self::assertSame('3m', app(BodyTrend::class)->props()['body']['range']);
    }

    public function test_a_history_too_thin_for_any_window_opens_on_all(): void
    {
        // The shape this app started with: two isolated manual entries years
        // apart, too thin for any window at any plausible threshold.
        DailySummary::factory()->onDate('2024-04-03')->create(['weight_kg' => 52.0]);
        DailySummary::factory()->onDate('2024-09-14')->create(['weight_kg' => 55.0]);

        self::assertSame('all', app(BodyTrend::class)->props()['body']['range']);
    }

    public function test_the_page_ships_the_card_and_its_gap_threshold(): void
    {
        $this->actingAs(User::factory()->create());

        DailySummary::factory()->onDate('2026-06-10')->create(['weight_kg' => 56.0]);

        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Trends')
                // The card does NOT follow the page's 7/28-day buttons.
                ->where('range', 7)
                ->where('body.gapDays', 21)
                ->where('body.today', '2026-06-15')
                ->has('body.ranges', 6)
                ->has('body.series.0.points', 1)
                ->etc()
            );
    }

    // --- the scale export, and how far behind it is --------------------------

    public function test_the_muscle_series_says_which_export_it_stops_at(): void
    {
        $this->weighIns(['2026-06-13', '2026-06-14', '2026-06-15']);
        $this->muscleOn(['2026-06-09' => 42.1, '2026-06-13' => 42.4]);

        $note = $this->muscle()['export'];

        // The newest reading: the morning the last exported .xlsx reached.
        self::assertSame('2026-06-13', $note['through']);
        self::assertSame('Scale export', $note['label']);
        self::assertSame(2, $note['lagDays']);
        self::assertSame(14, $note['thresholdDays']);

        // Two days behind is a routine fortnightly export at its best.
        self::assertFalse($note['stale']);
    }

    public function test_an_export_left_a_season_behind_turns_the_note_amber(): void
    {
        $this->weighIns(['2026-05-20', '2026-06-01', '2026-06-15']);
        $this->muscleOn(['2026-03-28' => 41.6, '2026-04-01' => 41.9]);

        $note = $this->muscle()['export'];

        self::assertSame('2026-04-01', $note['through']);
        self::assertTrue($note['stale']);

        // 1 April to 15 June, stated so the card need not make anyone subtract.
        self::assertSame(75, $note['lagDays']);
    }

    public function test_a_user_who_has_never_imported_is_not_nagged_about_the_import(): void
    {
        $this->weighIns(['2026-06-14', '2026-06-15']);

        $keys = array_column(app(BodyTrend::class)->props()['body']['series'], 'key');

        // No muscle chip, so no note: telling users they are behind on a
        // workflow they do not have invents an obligation out of an absence.
        self::assertSame(['weight'], $keys);
    }

    public function test_only_the_hand_fed_series_carries_a_note(): void
    {
        $this->weighIns(['2026-06-15']);

        $scale = Source::factory()->scale()->create();
        $this->instant('body_fat_percentage', '2026-06-15 07:10', 24.1, $scale);

        /** @var list<array<string, mixed>> $series */
        $series = app(BodyTrend::class)->props()['body']['series'];

        // HealthKit carries weight and body fat and the phone exports every
        // night, so there is nothing to be behind on.
        foreach ($series as $one) {
            self::assertNull($one['export'], "{$one['key']} should not claim to be exported by hand");
        }
    }

    public function test_a_user_who_stopped_weighing_in_is_not_told_the_scale_export_is_late(): void
    {
        // Five weeks since the last weigh-in, with the export run AFTER it.
        // Measuring against today instead of against the card would put an
        // amber warning in front of somebody who only stopped weighing in.
        $this->weighIns(['2026-05-08']);
        $this->muscleOn(['2026-05-08' => 42.0, '2026-05-11' => 42.2]);

        $note = $this->muscle()['export'];

        self::assertSame('2026-05-11', $note['through']);
        self::assertSame(0, $note['lagDays']);
        self::assertFalse($note['stale']);
    }

    public function test_a_fortnight_behind_is_a_routine_export_cycle(): void
    {
        // NO config override here or below, deliberately: the two sit either
        // side of the shipped threshold. "A fortnight is how often this export
        // actually happens" is the judgement the number encodes, so changing
        // `export.stale_days` in config/health.php should turn one of them red.
        $this->weighIns(['2026-06-15']);
        $this->muscleOn(['2026-06-01' => 42.0]);

        self::assertSame(14, $this->muscle()['export']['lagDays']);
        self::assertFalse($this->muscle()['export']['stale']);
    }

    public function test_a_day_past_the_fortnight_is_not(): void
    {
        $this->weighIns(['2026-06-15']);
        $this->muscleOn(['2026-05-31' => 42.0]);

        self::assertSame(15, $this->muscle()['export']['lagDays']);
        self::assertTrue($this->muscle()['export']['stale']);
    }

    public function test_the_page_ships_the_note_the_card_draws(): void
    {
        $this->actingAs(User::factory()->create());

        $this->weighIns(['2026-06-15']);
        $this->muscleOn(['2026-04-20' => 41.4]);

        $this->get('/trends')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('body.series.1.key', 'muscle')
                ->where('body.series.1.export.through', '2026-04-20')
                ->where('body.series.1.export.label', 'Scale export')
                ->where('body.series.1.export.stale', true)
                ->etc()
            );
    }

    /**
     * The muscle series as the card hands it to the component.
     *
     * @return array<string, mixed>
     */
    private function muscle(): array
    {
        /** @var list<array<string, mixed>> $series */
        $series = app(BodyTrend::class)->props()['body']['series'];

        $muscle = collect($series)->firstWhere('key', 'muscle');

        self::assertNotNull($muscle, 'there is no muscle series to read a note off');

        return $muscle;
    }

    /**
     * @param  list<string>  $dates
     */
    private function weighIns(array $dates): void
    {
        foreach ($dates as $date) {
            DailySummary::factory()->onDate($date)->create(['weight_kg' => 56.0]);
        }
    }

    /**
     * The scale's own readings, which only ever arrive through `fitage:import`.
     *
     * @param  array<string, float>  $byDate
     */
    private function muscleOn(array $byDate): void
    {
        $scale = Source::factory()->scale('FITAGE export')->create();

        foreach ($byDate as $date => $kg) {
            $this->instant('muscle_mass', "{$date} 07:15", $kg, $scale);
        }
    }

    /**
     * One reading, at a LOCAL wall-clock time. ->utc() is the whole reason this
     * helper exists: Eloquent formats a Carbon in whatever zone the instance
     * carries, so a +0200 instance is written as its local string, and the
     * generated column `health_metrics.local_date` reads that back AT TIME ZONE
     * Europe/Amsterdam and adds the two hours a second time. A 22:00 weigh-in
     * lands on the following day — the bug this test caught on its first run.
     */
    private function instant(string $metric, string $localTime, float $value, Source $source): void
    {
        $at = CarbonImmutable::parse($localTime, 'Europe/Amsterdam')->utc();

        HealthMetric::factory()->metric($metric)->create([
            'value'      => $value,
            'started_at' => $at,
            'ended_at'   => $at,
            'source_id'  => $source->id,
        ]);
    }
}
