<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\User;
use App\Models\Source;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use App\Enums\MetricAggregation;
use Inertia\Testing\AssertableInertia;
use App\Services\Reporting\NightCoverage;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A night that only partly arrived, and the card admitting it: night
 * 2026-08-18 first reached the server as 119 minutes, then a manual export
 * replaced it with 507. The claim is DELIVERY, never the wrist — the
 * discriminator is WHERE the gap falls. See docs/rationale-frontend.md §
 * "The 2026-08-18 Night Fragment Incident" for the incident and why each
 * fixture below exists.
 */
final class NightFragmentTest extends TestCase
{
    use RefreshDatabase;

    /** The incident night. */
    private const NIGHT = '2026-08-18';

    private const TZ = 'Europe/Amsterdam';

    private Source $watch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-18 11:00:00', self::TZ));

        $this->watch = Source::factory()->watch()->create();
    }

    public function test_a_night_that_has_only_partly_arrived_says_so(): void
    {
        $this->priorNights();
        $this->fragmentNight();

        // Window: 22:00 the evening before to 08:26 (session's own end, past the
        // 08:00 default). 06:26 on is covered, so 8h26m before it is not.
        self::assertSame(506, $this->missingMinutes());
    }

    public function test_a_night_the_export_delivered_whole_is_left_alone(): void
    {
        $this->priorNights();
        $this->wholeNight();

        self::assertNull($this->fragment(), 'a night that arrived whole must say nothing at all');
    }

    /**
     * B1: the evening hours have to be inside the window they are bound against.
     *
     * `started_at` is timestamptz and Eloquent serialises a Carbon with no
     * offset, so binding an Amsterdam bound against a UTC session shifts the
     * whole window two hours and silently drops exactly the 22:00-00:00 band.
     * Every other fixture here survives that bug by luck; this one does not.
     */
    public function test_the_evening_hours_are_inside_the_window_they_are_bound_against(): void
    {
        // Now is 06:00, so the window is 22:00 to 06:00 and the two evening
        // hours are a quarter of it — over the threshold, which is what turns a
        // silent night into a firing one if they go missing.
        $this->travelTo(CarbonImmutable::parse('2026-08-18 06:00:00', self::TZ));

        $this->priorNights();
        $this->nightSession(sleepStart: '2026-08-18 01:00', sleepEnd: '2026-08-18 05:30', minutes: 270);

        // Covers everything outside the session: evening, pre-sleep hour, post-wake half hour.
        foreach (['2026-08-17 22:00', '2026-08-17 23:00', '2026-08-18 00:00', '2026-08-18 05:00'] as $hour) {
            $this->basal($hour, 47.0);
        }

        // Nothing is unaccounted for — but bound in local time, the two evening
        // buckets are never fetched: 120 minutes go missing and this fires a
        // note instead of null.
        self::assertNull($this->fragment());
    }

    /** S1: a complete night with the Watch charging mid-night. */
    public function test_a_hole_inside_the_reported_session_is_not_a_delivery_problem(): void
    {
        $this->priorNights();
        $this->nightSession(sleepStart: '2026-08-17 23:56', sleepEnd: '2026-08-18 08:26', minutes: 507);

        // Worn until 01:00, back on at 06:00 — coverage is barely a third, but
        // every missing hour is INSIDE a session reporting 507 minutes, so that
        // sleep plainly did arrive. The hole is a wrist story; this app doesn't
        // tell it.
        foreach (['2026-08-17 22:00', '2026-08-17 23:00', '2026-08-18 00:00'] as $hour) {
            $this->basal($hour, 47.0);
        }

        foreach (['2026-08-18 06:00', '2026-08-18 07:00'] as $hour) {
            $this->basal($hour, 47.0);
        }

        self::assertNull(
            $this->fragment(),
            'a complete night must never be told that only part of it arrived'
        );
    }

    /** S2: awake at 02:00, not judged against hours still to come. */
    public function test_a_night_still_in_progress_is_not_measured_against_the_future(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-18 02:30:00', self::TZ));

        $this->priorNights();
        $this->nightSession(sleepStart: '2026-08-17 22:30', sleepEnd: '2026-08-18 02:00', minutes: 210);
        $this->basal('2026-08-17 22:00', 47.0);

        // Unclamped, the window would run to 08:00 — six hours not yet happened would read as six hours that never arrived.
        self::assertNull($this->fragment());
    }

    /** S3: the worst outage — the one that used to say nothing. */
    public function test_a_night_with_no_overnight_data_at_all_is_the_loudest_case(): void
    {
        // Export resumed in the morning: a stub session, no basal bucket anywhere
        // in the window. Judging it on the night's own emptiness kept the
        // original failure silent.
        $this->priorNights();
        $this->nightSession(sleepStart: '2026-08-18 06:26', sleepEnd: '2026-08-18 08:26', minutes: 119);

        self::assertSame(506, $this->missingMinutes());
    }

    public function test_a_setup_with_no_watch_behind_it_is_never_nagged(): void
    {
        // Same shape, but no watch-derived basal on any night — a phone-only
        // user must never be told every morning their nights are short.
        $this->nightSession(sleepStart: '2026-08-18 06:26', sleepEnd: '2026-08-18 08:26', minutes: 119);

        self::assertNull($this->fragment());
    }

    public function test_a_session_with_no_clock_on_it_is_not_judged(): void
    {
        $this->priorNights();

        SleepSession::factory()->create([
            'night_date'          => self::NIGHT,
            'source_id'           => $this->watch->id,
            'sleep_start'         => null,
            'sleep_end'           => null,
            'total_sleep_minutes' => 119,
        ]);

        // No span means no inside/outside — and that distinction is the whole discriminator.
        self::assertNull($this->fragment());
    }

    public function test_the_evidence_is_the_night_and_not_the_rest_of_the_day(): void
    {
        $this->priorNights();
        $this->fragmentNight();

        // Full morning/afternoon basal coverage, all after the window — covering the day says nothing about whether the night was delivered.
        foreach (range(9, 20) as $hour) {
            $this->basal(sprintf('2026-08-18 %02d:00', $hour), 47.0);
        }

        self::assertSame(506, $this->missingMinutes());
    }

    public function test_a_catch_up_bucket_inside_the_night_buys_no_coverage(): void
    {
        $this->priorNights();
        $this->fragmentNight();

        // The 2026-08-18 artifact moved inside the window: 1,283 kcal in one
        // hour, 15x the biggest genuine basal bucket on record. Ungated, it
        // would cover an hour nothing measured and this would read 446 — the
        // rate ceiling is load-bearing here too, not just for daily totals.
        $this->basal('2026-08-18 02:00', 1283.0);

        self::assertSame(506, $this->missingMinutes());
    }

    public function test_the_page_ships_the_note_and_the_card_wires_one_up(): void
    {
        $this->actingAs(User::factory()->create());
        $this->priorNights();
        $this->fragmentNight();

        $this->get('/?date='.self::NIGHT)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sleep.totalMinutes', fn (mixed $v): bool => (float) $v === 119.0)
                ->where('sleep.fragment.missingMinutes', 506)
                ->etc()
            );

        // Wiring only — the sentence's wording is pinned without a browser in tests/js/sleep.test.js.
        $card = (string) file_get_contents(base_path('resources/js/Components/SleepCard.vue'));

        self::assertStringContainsString('arrivalNote(props.sleep)', $card);
        self::assertStringContainsString('v-if="partial"', $card);
        self::assertStringContainsString('tone="warn"', $card);
    }

    public function test_the_note_clears_itself_when_the_rest_of_the_night_lands(): void
    {
        $this->actingAs(User::factory()->create());
        $this->priorNights();
        $this->fragmentNight();

        $this->get('/?date='.self::NIGHT)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sleep.fragment.missingMinutes', 506)
                ->etc()
            );

        // Manual export lands: the real session replaces the stub, overnight
        // buckets arrive behind it. Nothing is recomputed on a schedule or
        // stored — the next render just has more to read, exactly as happened
        // in production.
        SleepSession::query()->delete();
        $this->wholeNight();

        $this->get('/?date='.self::NIGHT)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('sleep.totalMinutes', fn (mixed $v): bool => (float) $v === 507.0)
                ->where('sleep.fragment', null)
                ->etc()
            );
    }

    /** @return array{missingMinutes: int}|null */
    private function fragment(): ?array
    {
        return app(NightCoverage::class)->fragment(SleepSession::query()->firstOrFail());
    }

    /** The shortfall, insisted upon: asserting a number presumes there IS one. */
    private function missingMinutes(): int
    {
        $fragment = $this->fragment();

        self::assertNotNull($fragment, 'there is no note to read a shortfall off');

        return $fragment['missingMinutes'];
    }

    /**
     * Two ordinary nights before this one.
     *
     * Every test expecting a note needs them: a Watch is established by nights
     * it already reported, and the night in question can't be evidence about
     * itself.
     */
    private function priorNights(): void
    {
        foreach (['2026-08-16', '2026-08-17'] as $date) {
            foreach ([0, 1, 2] as $hour) {
                $this->basal(sprintf('%s %02d:00', $date, $hour), 47.0);
            }
        }
    }

    /** The night as it first arrived: the tail of it, and nothing before. */
    private function fragmentNight(): void
    {
        $this->nightSession(sleepStart: '2026-08-18 06:26', sleepEnd: '2026-08-18 08:26', minutes: 119);

        $this->basal('2026-08-18 06:26', 30.0);
        $this->basal('2026-08-18 07:26', 47.0);
    }

    /** The night as the manual export delivered it. */
    private function wholeNight(): void
    {
        $this->nightSession(sleepStart: '2026-08-17 23:56', sleepEnd: '2026-08-18 08:26', minutes: 507);

        foreach (range(0, 9) as $index) {
            $this->basal(
                CarbonImmutable::parse('2026-08-17 22:00', self::TZ)->addHours($index)->format('Y-m-d H:i'),
                47.0
            );
        }
    }

    private function nightSession(string $sleepStart, string $sleepEnd, float $minutes): void
    {
        $start = CarbonImmutable::parse($sleepStart, self::TZ)->utc();
        $end = CarbonImmutable::parse($sleepEnd, self::TZ)->utc();

        SleepSession::factory()->create([
            'night_date'          => self::NIGHT,
            'source_id'           => $this->watch->id,
            'sleep_start'         => $start,
            'sleep_end'           => $end,
            'in_bed_start'        => $start,
            'in_bed_end'          => $end,
            'total_sleep_minutes' => $minutes,
        ]);
    }

    private function basal(string $localStart, float $kcal): void
    {
        $start = CarbonImmutable::parse($localStart, self::TZ)->utc();

        HealthMetric::query()->create([
            'metric'                    => 'basal_energy_burned',
            'aggregation'               => MetricAggregation::Sum,
            'period'                    => 'hour',
            'value'                     => $kcal,
            'unit'                      => 'kcal',
            'started_at'                => $start,
            'ended_at'                  => $start->addHour(),
            'device_utc_offset_minutes' => 120,
            'source_id'                 => $this->watch->id,
            'ingested_at'               => CarbonImmutable::now(),
        ]);
    }
}
