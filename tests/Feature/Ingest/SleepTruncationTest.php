<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Models\SleepSession;
use Tests\Support\PayloadBuilder;
use App\Services\Ingest\ParseResult;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The night that walked backwards.
 *
 * "Since Last Sync" exports clip `sleep_analysis` to the export window, so one
 * night arrives several times, each copy starting later than the last. Under
 * last-writer-wins the stored night followed the clipping down and Saturday
 * 8 August read "1h 14m" instead of seven hours.
 *
 * Every record below is copied from the real payloads 114-118, offsets and
 * stage splits included — a rule that only looks at how the fields moved must
 * be tested against how they actually moved. The predicate lives on
 * SleepSessionWriter (SQL) and SleepRow (PHP); both are exercised, because one
 * payload can carry the same night twice and the halves have to agree.
 */
final class SleepTruncationTest extends TestCase
{
    use RefreshDatabase;

    private const NIGHT = '2026-08-08';

    private int $session = 0;

    /** The whole night, as payload 114 reported it at 08:36. */
    private const FULL = [
        'sleepStart' => '2026-08-08 00:33:40 +0200',
        'sleepEnd'   => '2026-08-08 08:02:43 +0200',
        'inBedStart' => '2026-08-08 00:33:40 +0200',
        'inBedEnd'   => '2026-08-08 08:02:43 +0200',
        'totalSleep' => 7.0161817350652482,
        'rem'        => 1.7122911891672346,
        'core'       => 4.5354787776867553,
        'deep'       => 0.76841176821125878,
        'awake'      => 0.46787164628505706,
    ];

    /** Payload 115 at 10:54: the same night, clipped at the front. */
    private const CLIPPED = [
        'sleepStart' => '2026-08-08 04:16:11 +0200',
        'sleepEnd'   => '2026-08-08 08:02:43 +0200',
        'inBedStart' => '2026-08-08 04:16:11 +0200',
        'inBedEnd'   => '2026-08-08 08:02:43 +0200',
        'totalSleep' => 3.6417044686939986,
        'rem'        => 0.97722661024994328,
        'core'       => 2.6644778584440552,
        'deep'       => 0,
        'awake'      => 0.13366358637809753,
    ];

    /** Payloads 116-118 from 14:38 on: clipped further, then resent verbatim. */
    private const CLIPPED_HARDER = [
        'sleepStart' => '2026-08-08 06:41:32 +0200',
        'sleepEnd'   => '2026-08-08 08:02:43 +0200',
        'inBedStart' => '2026-08-08 06:41:32 +0200',
        'inBedEnd'   => '2026-08-08 08:02:43 +0200',
        'totalSleep' => 1.2361092631684409,
        'rem'        => 0.26723800526724922,
        'core'       => 0.96887125790119177,
        'deep'       => 0,
        'awake'      => 0.11695397055811352,
    ];

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function night(array $overrides): PayloadBuilder
    {
        return PayloadBuilder::make()->sleep(nightDate: self::NIGHT, overrides: $overrides);
    }

    private function send(PayloadBuilder $builder): ParseResult
    {
        // A session id per send: ingest_runs is keyed (session_id, sha of the
        // body), and these payloads differ only in a handful of numbers.
        return app(RawPayloadProcessor::class)->process(
            $builder->sessionId('SESSION-'.++$this->session)->store()
        );
    }

    private function stored(): SleepSession
    {
        return SleepSession::query()->sole();
    }

    /** sleep_start/sleep_end/in_bed_start are nullable columns; every test here sets one. */
    private function dt(?CarbonImmutable $value): string
    {
        self::assertNotNull($value);

        return $value->toDateTimeString();
    }

    // ----------------------------------------------------- the regression ---

    /** The bug as it happened: five payloads, one night, walking down. */
    public function test_the_observed_degradation_sequence_keeps_the_full_night(): void
    {
        $this->send($this->night(self::FULL));

        $clipped = $this->send($this->night(self::CLIPPED));
        $harder = $this->send($this->night(self::CLIPPED_HARDER));
        $resent = $this->send($this->night(self::CLIPPED_HARDER));
        $again = $this->send($this->night(self::CLIPPED_HARDER));

        $night = $this->stored();

        self::assertSame('420.971', $night->total_sleep_minutes);
        self::assertSame('102.737', $night->rem_minutes);
        self::assertSame('272.129', $night->core_minutes);
        self::assertSame('46.105', $night->deep_minutes);
        self::assertSame('28.072', $night->awake_minutes);

        self::assertSame('2026-08-07 22:33:40', $this->dt($night->sleep_start));
        self::assertSame('2026-08-08 06:02:43', $this->dt($night->sleep_end));

        // The in-bed window is clipped by the same export and defended by the
        // same refusal, even though the predicate only reads the sleep one.
        self::assertSame('2026-08-07 22:33:40', $this->dt($night->in_bed_start));

        // Not "the value came out right" — nothing was written at all.
        foreach ([$clipped, $harder, $resent, $again] as $result) {
            self::assertSame(0, $result->sleep->touched());
            self::assertSame(1, $result->sleep->unchanged);
        }

        self::assertSame(1, SleepSession::query()->count());
    }

    /**
     * The repair direction: a clipped night is already stored when the full
     * record turns up, and it has to win, or the fix would need a hand-written
     * UPDATE instead of a replay.
     */
    public function test_a_fuller_record_arriving_after_a_truncated_one_upgrades_it(): void
    {
        $this->send($this->night(self::CLIPPED_HARDER));
        self::assertSame('74.167', $this->stored()->total_sleep_minutes);

        // Part of the way back, then the whole night.
        $partial = $this->send($this->night(self::CLIPPED));
        self::assertSame('218.502', $this->stored()->total_sleep_minutes);
        self::assertSame(1, $partial->sleep->updated);

        $whole = $this->send($this->night(self::FULL));

        self::assertSame('420.971', $this->stored()->total_sleep_minutes);
        self::assertSame(1, $whole->sleep->updated);
        self::assertSame('2026-08-07 22:33:40', $this->dt($this->stored()->sleep_start));
    }

    public function test_an_identical_resend_writes_nothing(): void
    {
        $payload = $this->night(self::FULL)->store();

        $first = app(RawPayloadProcessor::class)->process($payload);
        $second = app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(1, $first->sleep->inserted);
        self::assertSame(0, $second->sleep->touched());
        self::assertSame(1, $second->sleep->unchanged);

        // row_count is rows REPRESENTED, so a replay is not a zero-row gap.
        self::assertSame($first->rowCount(), $second->rowCount());
    }

    // ------------------------------------------------- honest restatements --

    /**
     * Why the volume test is `>=`, not `>`: Apple re-scores a night's stages
     * hours later — same window, same total, sleep moved between REM and deep —
     * and that correction must land.
     */
    public function test_a_revised_night_with_the_same_coverage_updates_its_stages(): void
    {
        $this->send($this->night(self::FULL));

        $revised = $this->send($this->night([
            ...self::FULL,
            // Same window, same totalSleep: 0.5 h moved from core to deep.
            'core' => 4.0354787776867553,
            'deep' => 1.26841176821125878,
        ]));

        $night = $this->stored();

        self::assertSame(1, $revised->sleep->updated);
        self::assertSame('420.971', $night->total_sleep_minutes);
        self::assertSame('242.129', $night->core_minutes);
        self::assertSame('76.105', $night->deep_minutes);
    }

    /**
     * The coverage veto earning its keep: `>=` on the total alone would let this
     * through on a tie, and the night would lose its first three and a half
     * hours anyway.
     */
    public function test_a_truncation_whose_total_ties_is_still_refused(): void
    {
        $this->send($this->night(self::FULL));

        $result = $this->send($this->night([
            ...self::CLIPPED,
            'totalSleep' => self::FULL['totalSleep'],
        ]));

        self::assertSame(0, $result->sleep->touched());
        self::assertSame('2026-08-07 22:33:40', $this->dt($this->stored()->sleep_start));
    }

    /**
     * A window that moves rather than shrinks is not a clipped export: the Watch
     * decided the night began later AND ran past the old end, so with no
     * truncation to veto the volume rule alone decides.
     */
    public function test_a_window_that_extends_past_the_stored_end_is_taken(): void
    {
        $this->send($this->night(self::FULL));

        $result = $this->send($this->night([
            ...self::FULL,
            'sleepStart' => '2026-08-08 01:00:00 +0200',
            'sleepEnd'   => '2026-08-08 09:00:00 +0200',
            'totalSleep' => 7.5,
        ]));

        self::assertSame(1, $result->sleep->updated);
        self::assertSame('450.000', $this->stored()->total_sleep_minutes);
        self::assertSame('2026-08-08 07:00:00', $this->dt($this->stored()->sleep_end));
    }

    /** Two sources, one night: separate rows, separate histories, no comparison. */
    public function test_the_rule_is_scoped_to_one_source(): void
    {
        $this->send($this->night(self::FULL));

        $other = $this->send(
            PayloadBuilder::make()->sleep(
                nightDate: self::NIGHT,
                source: 'AutoSleep',
                overrides: self::CLIPPED_HARDER,
            )
        );

        self::assertSame(1, $other->sleep->inserted);
        self::assertSame(2, SleepSession::query()->count());
        self::assertSame('420.971', SleepSession::query()
            ->orderBy('id')->firstOrFail()->total_sleep_minutes);
    }

    // ------------------------------------------------- inside one payload ---

    /**
     * The SQL predicate never sees these: two records for one night in one
     * payload collapse before the statement is built (a multi-row upsert cannot
     * touch one key twice), so SleepRow::supersedes() has to reach the same
     * verdict, in either order.
     */
    public function test_two_records_for_one_night_in_one_payload_collapse_to_the_fuller(): void
    {
        $record = fn (array $shape): array => array_merge([
            'date'   => self::NIGHT.' 00:00:00 +0200',
            'source' => PayloadBuilder::WATCH,
            'inBed'  => 0,
            'asleep' => 0,
        ], $shape);

        $this->send(PayloadBuilder::make()->metric('sleep_analysis', 'hr', [
            $record(self::FULL),
            $record(self::CLIPPED_HARDER),
        ]));

        self::assertSame('420.971', $this->stored()->total_sleep_minutes);
    }

    public function test_the_collapse_holds_when_the_clipped_record_comes_first(): void
    {
        $record = fn (array $shape): array => array_merge([
            'date'   => self::NIGHT.' 00:00:00 +0200',
            'source' => PayloadBuilder::WATCH,
            'inBed'  => 0,
            'asleep' => 0,
        ], $shape);

        $result = $this->send(PayloadBuilder::make()->metric('sleep_analysis', 'hr', [
            $record(self::CLIPPED_HARDER),
            $record(self::CLIPPED),
            $record(self::FULL),
        ]));

        self::assertSame('420.971', $this->stored()->total_sleep_minutes);
        self::assertSame(1, $result->sleep->inserted);
        self::assertSame(3, $result->datapoints);
    }

    // ------------------------------------------------------ replay -----------

    /**
     * The repair as actually performed: bank the five payloads in arrival order,
     * replay, and the night comes back whole; replay again and nothing moves.
     */
    public function test_a_replay_of_the_whole_sequence_restores_the_night_and_settles(): void
    {
        foreach ([self::FULL, self::CLIPPED, self::CLIPPED_HARDER, self::CLIPPED_HARDER, self::CLIPPED_HARDER] as $i => $shape) {
            PayloadBuilder::make()
                ->sessionId('SESSION-'.$i)
                ->sleep(nightDate: self::NIGHT, overrides: $shape)
                ->store();
        }

        $this->runArtisan('ingest:replay')->assertSuccessful();

        self::assertSame(1, SleepSession::query()->count());
        self::assertSame('420.971', $this->stored()->total_sleep_minutes);

        $ingestedAt = $this->stored()->ingested_at;
        self::assertInstanceOf(CarbonImmutable::class, $ingestedAt);

        $this->runArtisan('ingest:replay')
            ->expectsOutputToContain('Nothing changed')
            ->assertSuccessful();

        // Not even a rewritten tuple: ingested_at still records the first pass.
        $stillIngestedAt = $this->stored()->ingested_at;
        self::assertInstanceOf(CarbonImmutable::class, $stillIngestedAt);

        self::assertSame('420.971', $this->stored()->total_sleep_minutes);
        self::assertTrue($ingestedAt->equalTo($stillIngestedAt));
    }
}
