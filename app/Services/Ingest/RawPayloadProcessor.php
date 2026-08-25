<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Throwable;
use App\Models\IngestRun;
use Carbon\CarbonImmutable;
use App\Enums\IngestRunStatus;
use App\Models\RawIngestPayload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One banked payload -> rows + an `ingest_runs` entry.
 *
 * The single entry point for parsing, used identically by the queued job
 * and by `ingest:replay` — deliberate, since a replay on a different code
 * path would prove nothing about the pipeline it re-runs.
 *
 * WHAT SURVIVES A FAILURE. The raw payload is already durable before this
 * class runs — the controller banks it and answers 202 before dispatching.
 * So a failure here costs nothing permanent: the run row is marked
 * `failed` with a SANITISED fault description (IngestErrorText — a raw
 * database exception message carries the failing statement's bindings,
 * i.e. the readings), the bytes are untouched, and `ingest:replay` re-runs
 * once the parser is fixed. The run update sits OUTSIDE the write
 * transaction, or rolling back the bad write would roll back the record of
 * why it went bad.
 *
 * `ingest_runs` is keyed (session_id, sha256(body)) and upserts on that
 * key, so re-processing a payload updates its existing run rather than
 * growing a new one per replay. The sha is over the body as Postgres
 * returns it from jsonb — stable for a given row, not the sha of the
 * original request bytes, since jsonb normalises key order and whitespace.
 */
final readonly class RawPayloadProcessor
{
    public function __construct(
        private PayloadParser $parser = new PayloadParser,
        private HealthMetricWriter $metrics = new HealthMetricWriter,
        private SleepSessionWriter $sleep = new SleepSessionWriter,
        private WorkoutWriter $workouts = new WorkoutWriter,
    ) {}

    public function process(RawIngestPayload $payload): ParseResult
    {
        $headers = $payload->decodedHeaders();
        $body = (string) $payload->body;

        $run = $this->openRun($payload, $headers, hash('sha256', $body));

        try {
            $decoded = $payload->decodedBody();

            $result = DB::transaction(function () use ($decoded, $headers): ParseResult {
                $parsed = $this->parser->parse($decoded, $headers);
                $ingestedAt = CarbonImmutable::now();

                return new ParseResult(
                    metrics: $this->metrics->write($parsed->metricRows, $ingestedAt),
                    sleep: $this->sleep->write($parsed->sleepRows, $ingestedAt),
                    workouts: $this->workouts->write($parsed->workoutRows, $ingestedAt),
                    datapoints: $parsed->datapoints,
                    metricBlocks: $parsed->metricBlocks,
                    skipped: $parsed->skipped,
                    // Computed here, dispatched by the caller: this class
                    // runs inside a transaction and is shared with
                    // `ingest:replay`, so queueing a job from here would
                    // fire before the rows it reads are committed.
                    dirtyDates: $parsed->dirtyLocalDates((string) config('health.timezone')),
                );
            });
        } catch (Throwable $e) {
            // SANITISED, not verbatim (see IngestErrorText): the realistic
            // failure is a QueryException, whose message carries the
            // readings themselves as bindings.
            $reason = IngestErrorText::describe($e);

            $run->forceFill([
                'status'       => IngestRunStatus::Failed,
                'error'        => $reason,
                'processed_at' => CarbonImmutable::now(),
            ])->save();

            // Same treatment as the column: a log file is a second copy of
            // whatever goes in it, shipped somewhere else.
            Log::error('ingest.parse_failed', [
                'raw_ingest_payload_id' => $payload->id,
                'ingest_run_id'         => $run->id,
                'exception'             => $e::class,
                'reason'                => $reason,
            ]);

            throw $e;
        }

        $run->forceFill([
            // `empty` is the gap signal: parsed cleanly, produced nothing.
            'status' => $result->producedNothing()
                ? IngestRunStatus::Empty
                : IngestRunStatus::Completed,
            'row_count' => $result->rowCount(),
            // Non-null on a completed run means "warnings", not "failure" —
            // datapoints skipped, not failed to parse.
            'error'        => $result->skippedSummary(),
            'processed_at' => CarbonImmutable::now(),
        ])->save();

        return $result;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function openRun(RawIngestPayload $payload, array $headers, string $sha): IngestRun
    {
        return IngestRun::query()->updateOrCreate(
            [
                // `session-id` is present on all 107 captured requests, but
                // the column is NOT NULL and part of the unique key, so a
                // missing one needs a real value, not an empty string that
                // collides with every other header-less payload (the sha
                // keeps those apart; the placeholder only needs to be
                // stable).
                'session_id'  => $this->header($headers, 'session-id') ?? 'no-session-id',
                'body_sha256' => $sha,
            ],
            [
                'raw_ingest_payload_id' => $payload->id,
                'automation_name'       => $this->header($headers, 'automation-name'),
                'automation_id'         => $this->header($headers, 'automation-id'),
                // Bucket WIDTH. Named "aggregation" by HAE; see BucketWidth.
                'automation_aggregation' => $this->header($headers, 'automation-aggregation'),
                // Export WINDOW. Logged here and nowhere else.
                'automation_period' => $this->header($headers, 'automation-period'),
                'status'            => IngestRunStatus::Processing,
                'error'             => null,
                'received_at'       => $payload->received_at,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function header(array $headers, string $name): ?string
    {
        $value = $headers[$name] ?? null;

        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
