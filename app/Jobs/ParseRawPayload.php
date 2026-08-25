<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Models\IngestRun;
use Carbon\CarbonImmutable;
use App\Enums\IngestRunStatus;
use App\Models\RawIngestPayload;
use Illuminate\Support\Facades\Log;
use App\Services\Ingest\IngestErrorText;
use App\Services\Rollup\SummaryRebuilder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Parse one banked payload, off the request.
 *
 * Dispatched after the bytes are durable, so the phone gets its 202
 * without waiting on ~250 upserts, and a broken parser costs a queue
 * retry rather than a lost export — Health Auto Export retries on
 * non-2xx, so a 500 from a choking parser is how a day of history goes
 * missing. Carries only the id, never the payload: a queued job is
 * serialised into Redis, and a several-hundred-kilobyte body would be a
 * stale duplicate of a row that's already the system of record.
 */
final class ParseRawPayload implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Transient failures (a Postgres restart, a lock timeout) are worth
     * retrying; a parser bug isn't. Either way the run row records the
     * last outcome and `ingest:replay` is the real recovery path.
     */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /** A full payload is ~250 upserts. 5 minutes is absurdly generous. */
    public int $timeout = 300;

    public function __construct(public readonly int $rawPayloadId) {}

    /**
     * One payload, one worker: two workers upserting the same rows would
     * be correct but would deadlock under Postgres's row locks often
     * enough to matter. Released when the job finishes or finally fails.
     */
    public function uniqueId(): string
    {
        return (string) $this->rawPayloadId;
    }

    public int $uniqueFor = 3600;

    public function handle(RawPayloadProcessor $processor, SummaryRebuilder $rebuilder): void
    {
        $payload = RawIngestPayload::query()->find($this->rawPayloadId);

        if ($payload === null) {
            // The table is append-only and permanent, so this should be
            // impossible. Logged rather than thrown: retrying can't
            // conjure a row, and a failed job here would be noise.
            Log::warning('ingest.payload_missing', ['raw_ingest_payload_id' => $this->rawPayloadId]);

            return;
        }

        $result = $processor->process($payload);

        // New rows mean the affected days' summaries are stale — one
        // unique, delayed job per date, so a batched export split across
        // payloads doesn't queue six rebuilds of the same day.
        $rebuilder->queue($result->dirtyDates);

        Log::info('ingest.payload_parsed', [
            'raw_ingest_payload_id' => $payload->id,
            ...$result->toArray(),
        ]);
    }

    /**
     * Last-resort marker.
     *
     * RawPayloadProcessor already flips the run to `failed` before
     * rethrowing, so this only catches what happens outside it — a
     * timeout killing the worker mid-parse, or the job never reaching the
     * processor — without which the run stays stuck `processing` forever,
     * reading as "still working". SANITISED through IngestErrorText like
     * the processor's own path: this hook only touches
     * `pending`/`processing` runs, so the processor's own QueryException
     * never reaches it, but a database exception's message can carry the
     * failing statement's bindings whichever path writes it.
     */
    public function failed(?Throwable $e): void
    {
        IngestRun::query()
            ->where('raw_ingest_payload_id', $this->rawPayloadId)
            ->whereIn('status', [IngestRunStatus::Pending, IngestRunStatus::Processing])
            ->update([
                'status' => IngestRunStatus::Failed->value,
                'error'  => $e === null
                    ? 'Job failed with no exception.'
                    : IngestErrorText::describe($e),
                'processed_at' => CarbonImmutable::now(),
                'updated_at'   => CarbonImmutable::now(),
            ]);
    }
}
