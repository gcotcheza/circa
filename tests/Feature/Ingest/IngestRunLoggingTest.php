<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\IngestRun;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use App\Enums\IngestRunStatus;
use App\Models\RawIngestPayload;
use Tests\Support\PayloadBuilder;
use Illuminate\Database\QueryException;
use Tests\Support\FailingMetricConnection;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `ingest_runs` — the append-only record of what was understood and when.
 *
 * It is never a rejection gate. A duplicate key means "already logged", and
 * row-level upsert remains the only dedup mechanism.
 */
final class IngestRunLoggingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The metric name the injected connection refuses to write. Any string
     * would do; naming it keeps the fixture readable.
     */
    private const DOOMED = 'doomed_metric';

    public function test_a_run_records_the_session_body_hash_and_headers(): void
    {
        $payload = PayloadBuilder::make()
            ->sessionId('4C1B4E20-1111-4000-8000-000000000009')
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        $run = IngestRun::query()->sole();

        self::assertSame('4C1B4E20-1111-4000-8000-000000000009', $run->session_id);
        self::assertSame(hash('sha256', (string) $payload->body), $run->body_sha256);
        self::assertSame((int) $payload->id, $run->raw_ingest_payload_id);
        self::assertSame('Health tracker', $run->automation_name);

        // Both headers verbatim and the right way round: aggregation is the
        // bucket width, period is the export window.
        self::assertSame('Hours', $run->automation_aggregation);
        self::assertSame('Since Last Sync', $run->automation_period);

        self::assertSame(IngestRunStatus::Completed, $run->status);
        self::assertSame(1, $run->row_count);
        self::assertNull($run->error);
        self::assertNotNull($run->processed_at);

        // The run's clock is the payload's arrival: the last-ingest indicator
        // answers "when did the phone reach us?".
        self::assertSame(
            CarbonImmutable::parse((string) $payload->received_at)->getTimestamp(),
            $run->received_at->getTimestamp()
        );
    }

    public function test_row_count_counts_metrics_and_sleep_together(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200)
            ->minAvgMax('2026-08-07 10:00:00 +0200', 52, 74, 118)
            ->sleep()
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        // 1 step row + 3 heart-rate rows + 1 night.
        self::assertSame(5, IngestRun::query()->sole()->row_count);
        self::assertSame(4, HealthMetric::query()->count());
    }

    /**
     * Replay updates the existing run rather than growing one per pass: the
     * table is keyed (session_id, sha256(body)) so a re-processed body is
     * recognised as the same body.
     */
    public function test_reprocessing_updates_the_same_run_row(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);
        $first = IngestRun::query()->sole();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(1, IngestRun::query()->count());
        self::assertSame($first->id, IngestRun::query()->sole()->id);
        // Stable across replays: still the rows this payload represents.
        self::assertSame(1, IngestRun::query()->sole()->row_count);
    }

    /**
     * The gap signal: a phone that is not syncing does not say so, and a run
     * that parsed cleanly and produced nothing is the server-side evidence.
     */
    public function test_a_payload_with_no_datapoints_is_logged_as_empty(): void
    {
        $payload = PayloadBuilder::make()->store();

        app(RawPayloadProcessor::class)->process($payload);

        $run = IngestRun::query()->sole();

        self::assertSame(IngestRunStatus::Empty, $run->status);
        self::assertSame(0, $run->row_count);
    }

    /**
     * WHAT STILL FAILS A WHOLE RUN, now that per-datapoint faults are skipped:
     * the database saying no. Injected because it has to be — see
     * Tests\Support\FailingMetricConnection for why the old fixtures (an
     * over-long name, an unstorable number) no longer reach Postgres.
     */
    private function processWithAFailingWrite(RawIngestPayload $payload): void
    {
        $processor = new RawPayloadProcessor(
            metrics: FailingMetricConnection::writerRefusing(self::DOOMED)
        );

        try {
            $processor->process($payload);
            self::fail('The processor swallowed a database error.');
        } catch (QueryException) {
            // expected — it propagates so the queue records the failure too.
        }
    }

    public function test_a_failure_marks_the_run_failed_and_keeps_the_raw_payload(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity(self::DOOMED, 'count', '2026-08-07 10:00:00 +0200', 100)
            ->store();

        $this->processWithAFailingWrite($payload);

        $run = IngestRun::query()->sole();

        self::assertSame(IngestRunStatus::Failed, $run->status);
        self::assertNotNull($run->processed_at);

        // The bytes survive: the whole point of banking them first.
        self::assertSame(1, RawIngestPayload::query()->count());
        self::assertSame(0, HealthMetric::query()->count());
    }

    /**
     * A FAILED RUN NAMES THE FAULT, NEVER THE DATA.
     *
     * `QueryException::getMessage()` is the statement with its bindings
     * interpolated, and an ingest write's bindings are the readings. This column
     * was served verbatim to any unauthenticated caller of /api/health, so it is
     * sanitised where it is WRITTEN, not only where it is published.
     *
     * The exception is raised over the real upsert's real bindings, so the
     * values asserted absent below genuinely were in the message this row was
     * built from.
     */
    public function test_a_failed_run_records_the_sqlstate_and_not_the_bindings(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity(self::DOOMED, 'count', '2026-08-07 10:00:00 +0200', 4242)
            ->store();

        $this->processWithAFailingWrite($payload);

        $error = (string) IngestRun::query()->sole()->error;

        // Class and driver state code: enough to tell an overflow from a length
        // violation from a lost connection.
        self::assertStringContainsString(QueryException::class, $error);
        self::assertStringContainsString('SQLSTATE[22003]', $error);

        // And nothing else. No binding, no SQL, no connection name.
        self::assertStringNotContainsString(self::DOOMED, $error);
        self::assertStringNotContainsString('4242', $error);
        self::assertStringNotContainsString('2026-08-07', $error);
        self::assertStringNotContainsString('insert into', mb_strtolower($error));
    }

    /**
     * The failed write must not take the record of the failure down with it.
     */
    public function test_a_failed_run_is_not_rolled_back_with_the_write(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 100)
            ->quantity(self::DOOMED, 'count', '2026-08-07 10:00:00 +0200', 900)
            ->store();

        $this->processWithAFailingWrite($payload);

        self::assertSame(IngestRunStatus::Failed, IngestRun::query()->sole()->status);
        // Nothing half-written: the payload is atomic.
        self::assertSame(0, HealthMetric::query()->count());
    }

    /**
     * A run that produced nothing is a record, not a blockage: the next
     * payload parses normally and gets its own run.
     */
    public function test_a_run_that_produced_nothing_does_not_block_later_payloads(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', 'not a date', 100)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame(IngestRunStatus::Empty, IngestRun::query()->sole()->status);

        // Same session, a body that parses.
        $fixed = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 100)
            ->store();

        app(RawPayloadProcessor::class)->process($fixed);

        self::assertSame(
            IngestRunStatus::Completed,
            IngestRun::query()->where('raw_ingest_payload_id', $fixed->id)->sole()->status
        );
    }

    public function test_skipped_datapoints_are_reported_as_warnings_on_a_completed_run(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 100)
            ->quantity('flights_climbed', 'count', 'nonsense', 3)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        $run = IngestRun::query()->sole();

        self::assertSame(IngestRunStatus::Completed, $run->status);
        self::assertStringContainsString('Skipped 1 datapoint', (string) $run->error);
    }

    public function test_a_payload_without_a_session_header_still_logs_a_run(): void
    {
        $payload = PayloadBuilder::make()
            ->header('session-id', null)
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 100)
            ->store();

        app(RawPayloadProcessor::class)->process($payload);

        self::assertSame('no-session-id', IngestRun::query()->sole()->session_id);
    }
}
