<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Models\VisionRequest;
use App\Enums\VisionRequestStatus;
use App\Services\Vision\PhotoStore;
use Illuminate\Support\Facades\Log;
use App\Services\Vision\VisionAnalyzer;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * One photograph of a bottle -> Claude -> the label as printed.
 *
 * Doesn't extend AnalyzeMeal: the two look alike for the first thirty
 * lines — claim the pending row, read the bytes, send, write usage and
 * latency onto the audit row — then stop having anything in common.
 * AnalyzeMeal's second half is about a MEAL: mapping items to densities,
 * meal memory, restoring what the user typed, moving `meals.status`, and
 * calling ProposalWriter. None of that applies here; a label read produces
 * no items, touches no meal, and ends with a document nobody's agreed to
 * yet. Inheriting would mean six abstract methods returning null and a
 * base class whose docblock stopped being true — so sharing happens only
 * where it matters: one audit table, one idempotency claim, one analyzer seam.
 *
 * Where the answer goes: `vision_requests.raw_response`, and nowhere else.
 * There's no drafts table — the reading is already stored verbatim in the
 * row that had to exist anyway, and the review screen decodes it back
 * through ParsedLabel, the same class the analyzer decoded the live
 * response with. A second copy in a second table would be a second thing
 * to keep in step, and the stale one would be the one the user confirmed.
 *
 * One attempt, same as the meal jobs: `tries = 1` on top of the SDK's
 * single retry for 429/5xx. A photograph the model couldn't read won't
 * read better in thirty seconds, and the person holding the bottle has a
 * better idea — take it again in better light, or type it in.
 */
final class ReadSupplementLabel implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /** Comfortably past the HTTP client's ceiling plus its one retry. */
    public int $timeout = 300;

    /**
     * Carries an id, not a model — a serialised Eloquent model in a payload
     * is a snapshot at dispatch time, and this job changes that row; reading
     * it fresh is what makes the pending-status guard mean anything.
     */
    public function __construct(public readonly int $visionRequestId) {}

    public function handle(VisionAnalyzer $analyzer, PhotoStore $photos): void
    {
        $request = VisionRequest::query()->find($this->visionRequestId);

        if ($request === null) {
            return;
        }

        // Claiming the row makes a re-delivered job safe — queues are at-least-once,
        // and paying twice for one photograph is the exact failure `idempotency_key` prevents.
        if ($request->status !== VisionRequestStatus::Pending) {
            return;
        }

        $bytes = $photos->bytesAt($this->photoPath($request));

        if ($bytes === null) {
            $this->markFailed($request, 'The label photo could not be read back from storage.');

            return;
        }

        $request->status = VisionRequestStatus::Sent;
        $request->save();

        try {
            $outcome = $analyzer->readLabel($bytes);
        } catch (Throwable $e) {
            // AnthropicVisionAnalyzer turns its own errors into outcomes, so reaching here
            // means something further out broke — still has to land on the row, not just a log.
            Log::error('Label reading threw', ['vision_request' => $request->id, 'message' => $e->getMessage()]);

            $this->markFailed($request, 'The label reading did not complete.');

            return;
        }

        $request->raw_response = $outcome->raw;
        $request->input_tokens = $outcome->inputTokens;
        $request->output_tokens = $outcome->outputTokens;
        $request->latency_ms = $outcome->latencyMs;

        if (! $outcome->succeeded) {
            $this->markFailed($request, (string) $outcome->error);

            return;
        }

        /*
         * An empty reading is a success. "That is a photograph of a cat" is the
         * correct transcription of a photograph of a cat, and `notes` says so —
         * marking it failed would show a retry button when nothing went wrong.
         * The review screen instead reads `nutrients: []` and says "that
         * doesn't look like a label", the honest, actionable thing.
         */
        $request->status = VisionRequestStatus::Succeeded;
        $request->error = null;
        $request->save();
    }

    /**
     * The last line of defence: a timeout, an OOM, a worker restart.
     *
     * Without it the review screen would poll a `pending` row forever with a
     * spinner on it and no way to find out why.
     */
    public function failed(?Throwable $e): void
    {
        $request = VisionRequest::query()->find($this->visionRequestId);

        if ($request === null || $request->status === VisionRequestStatus::Succeeded) {
            return;
        }

        $this->markFailed($request, 'The label reading was interrupted before it finished.');
    }

    /**
     * Where the photograph is — read off the audit row.
     *
     * Same rule as AnalyzeMealPhoto's hint: the row is the record of the
     * question, so it's also the source of it — and there's no supplement
     * yet to hang the path on.
     */
    private function photoPath(VisionRequest $request): ?string
    {
        $payload = $request->input_payload;

        if (! is_array($payload) || ! is_string($payload['photo_path'] ?? null)) {
            return null;
        }

        return $payload['photo_path'];
    }

    private function markFailed(VisionRequest $request, string $error): void
    {
        $request->status = VisionRequestStatus::Failed;
        $request->error = $error;
        $request->save();
    }
}
