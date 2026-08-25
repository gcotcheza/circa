<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\Workout;
use App\Models\IngestRun;
use App\Models\HealthMetric;
use App\Enums\IngestRunStatus;
use App\Models\RawIngestPayload;
use Tests\Support\PayloadBuilder;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `data.workouts[]` through the whole pipeline, and the backfill that replays
 * it. The second automation was pointed here before the parser existed, so the
 * feature had to be a REPLAY first: the bytes were banked and their runs said
 * `empty`. Everything below is about that recovery path as much as the live
 * one, which is why it goes through RawPayloadProcessor and `ingest:replay`
 * rather than calling the writer.
 */
final class WorkoutIngestTest extends TestCase
{
    use RefreshDatabase;

    private function process(RawIngestPayload $payload): void
    {
        app(RawPayloadProcessor::class)->process($payload);
    }

    /**
     * THE BRANCH IS ON THE BODY, NEVER ON THE HEADER. `automation-name:
     * Workout` is a string the user typed into a phone and can rename in four
     * taps; a parser keyed on it would route two years of training into the
     * metrics path, produce nothing and mark the run `empty` — a data loss
     * shaped exactly like a quiet week.
     */
    public function test_a_workout_payload_is_recognised_by_its_shape_not_its_automation_name(): void
    {
        $payload = PayloadBuilder::make()
            ->header('automation-name', 'something else entirely')
            ->workout()
            ->store();

        $this->process($payload);

        self::assertSame(1, Workout::query()->count());
        self::assertSame('Outdoor Run', Workout::query()->value('type'));
    }

    /**
     * A real run with a real size, not the `empty` gap signal. Before the
     * parser all 86 of these payloads produced zero rows and read as silence to
     * the gap detector. `row_count` is rows REPRESENTED, the same rule the
     * metric writer uses, so a replayed workout still counts.
     */
    public function test_the_run_reports_the_workouts_it_represented(): void
    {
        $payload = PayloadBuilder::make()
            ->workout(uuid: 'AAAAAAAA-0000-4000-8000-000000000001')
            ->workout(uuid: 'AAAAAAAA-0000-4000-8000-000000000002', name: 'Climbing')
            ->store();

        $this->process($payload);

        $run = IngestRun::query()->sole();

        self::assertSame(IngestRunStatus::Completed, $run->status);
        self::assertSame(2, $run->row_count);
        self::assertNull($run->error);
    }

    /** Both envelopes land: nothing guarantees a future export keeps them apart. */
    public function test_a_payload_with_both_envelopes_lands_both(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-02 08:00:00 +0200', 1200)
            ->workout()
            ->store();

        $this->process($payload);

        self::assertSame(1, Workout::query()->count());
        self::assertSame(1, HealthMetric::query()->count());
    }

    /**
     * THE IDEMPOTENCY PROOF, ON THE SHAPE THE BACKFILL HAD: two years exported
     * by hand across 86 overlapping payloads. The same session arriving three
     * times must be one row, and the third must not even write a tuple — the
     * upsert carries a WHERE that skips no-op updates.
     */
    public function test_the_same_session_across_overlapping_payloads_is_one_row(): void
    {
        $uuid = 'BBBBBBBB-0000-4000-8000-000000000001';

        foreach (['SESSION-A', 'SESSION-B', 'SESSION-C'] as $session) {
            $payload = PayloadBuilder::make()->sessionId($session)->workout(uuid: $uuid)->store();

            $this->process($payload);
        }

        self::assertSame(1, Workout::query()->count());

        // A replayed payload that changed nothing is not an empty payload.
        self::assertSame(3, IngestRun::query()->where('row_count', 1)->count());
    }

    /**
     * Two records of one session INSIDE a payload collapse before the insert —
     * a multi-row `ON CONFLICT` whose VALUES share the conflict key fails with
     * "cannot affect row a second time".
     */
    public function test_a_duplicate_session_inside_one_payload_collapses(): void
    {
        $uuid = 'CCCCCCCC-0000-4000-8000-000000000001';

        $payload = PayloadBuilder::make()
            ->workout(uuid: $uuid, name: 'Outdoor Run')
            ->workout(uuid: $uuid, name: 'Outdoor Walk')
            ->store();

        $this->process($payload);

        self::assertSame(1, Workout::query()->count());
        // Last one wins, the same rule the writer applies across payloads.
        self::assertSame('Outdoor Walk', Workout::query()->value('type'));
    }

    /** A restated session is a correction and lands. */
    public function test_a_restated_session_updates_in_place(): void
    {
        $uuid = 'DDDDDDDD-0000-4000-8000-000000000001';

        $this->process(PayloadBuilder::make()->sessionId('A')->workout(uuid: $uuid)->store());

        $this->process(PayloadBuilder::make()->sessionId('B')->workout(
            uuid: $uuid,
            overrides: ['distance' => ['qty' => 6.4, 'units' => 'km']],
        )->store());

        self::assertSame(1, Workout::query()->count());
        self::assertSame('6.4000', Workout::query()->value('distance_km'));
    }

    /**
     * An unreadable record costs itself and nothing else: not the other workout
     * in the same POST, and not tomorrow's export.
     */
    public function test_an_unreadable_record_is_skipped_and_summarised(): void
    {
        $payload = PayloadBuilder::make()
            ->workout(uuid: 'EEEEEEEE-0000-4000-8000-000000000001')
            ->workout(overrides: ['id' => null])
            ->store();

        $this->process($payload);

        self::assertSame(1, Workout::query()->count());

        $run = IngestRun::query()->sole();

        self::assertSame(IngestRunStatus::Completed, $run->status);
        self::assertStringContainsString('Skipped 1 datapoint', (string) $run->error);
    }

    /**
     * The day boundary is the database's, from the instant in the reporting
     * timezone — the rule `health_metrics.local_date` follows. A session belongs
     * to the day it STARTED, which is how a person talks about a run.
     */
    public function test_the_local_date_is_the_day_the_session_started(): void
    {
        $payload = PayloadBuilder::make()->workout(
            start: '2026-08-02 23:40:00 +0200',
            end: '2026-08-03 00:25:00 +0200',
            durationSeconds: 2700.0,
        )->store();

        $this->process($payload);

        self::assertSame('2026-08-02', Workout::query()->sole()->local_date->toDateString());
    }

    /**
     * THE BACKFILL, END TO END. `ingest:replay` filters on nothing but the id
     * range, which is why two years of workouts landed the moment the parser
     * existed: payloads banked before the feature, replayed after it, become
     * rows.
     */
    public function test_replay_backfills_workout_payloads_that_were_banked_before_the_parser(): void
    {
        foreach (range(1, 3) as $i) {
            PayloadBuilder::make()
                ->sessionId('BACKFILL-'.$i)
                ->workout(uuid: sprintf('FFFFFFFF-0000-4000-8000-00000000000%d', $i))
                ->store();
        }

        // The state the real box was in: bytes banked, nothing derived.
        self::assertSame(0, Workout::query()->count());
        self::assertSame(3, RawIngestPayload::query()->count());

        $this->runArtisan('ingest:replay')->assertSuccessful();

        self::assertSame(3, Workout::query()->count());
    }

    /** A PROVABLE no-op: no tuple written, which is what IS DISTINCT FROM buys. */
    public function test_a_second_replay_of_workouts_changes_nothing(): void
    {
        PayloadBuilder::make()->workout()->store();

        $this->runArtisan('ingest:replay')->assertSuccessful();

        $ingestedAt = Workout::query()->value('ingested_at');

        $this->runArtisan('ingest:replay')
            ->expectsOutputToContain('Nothing changed')
            ->assertSuccessful();

        self::assertSame(1, Workout::query()->count());
        // Untouched down to the ingest timestamp: Postgres skipped the update.
        self::assertEquals($ingestedAt, Workout::query()->value('ingested_at'));
    }

    /**
     * A workout invalidates no daily summary: `daily_summaries` derives nothing
     * from this table, since a session's active energy is already in the day's.
     * Queueing rebuilds would have fired a hundred pointless jobs on the
     * backfill and implied a dependency that must never exist.
     */
    public function test_a_workout_payload_dirties_no_summary_date(): void
    {
        $payload = PayloadBuilder::make()->workout()->store();

        $result = app(RawPayloadProcessor::class)->process($payload);

        self::assertSame([], $result->dirtyDates);
        self::assertSame(1, $result->workouts->inserted);
    }
}
