<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use App\Models\Meal;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use App\Models\VisionRequest;
use App\Enums\VisionRequestStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Services\Vision\ProposedItem;
use App\Services\Memory\MemoryPrefill;
use App\Services\Vision\VisionOutcome;
use App\Services\Vision\ProposalWriter;
use App\Services\Vision\VisionAnalyzer;
use Illuminate\Foundation\Queue\Queueable;
use App\Services\Vision\ProposedItemMapper;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Everything that is the same whether the evidence was a photograph or a
 * sentence.
 *
 * A base class, not two jobs: the paths differ only in where the input
 * comes from and what can overrule the answer afterwards. Everything else —
 * the pending-status claim that makes an at-least-once queue safe, writing
 * usage/latency onto the audit row, failure handling, memory pre-fill, the
 * write that moves the meal to `proposed` — is identical, and duplicating
 * it would mean two state machines that fail silently out of sync: a fix
 * applied to one, a meal stuck in `analyzing` forever on the other.
 *
 * One attempt, on purpose (`tries = 1`): the SDK already retries once for
 * 429/5xx/connection errors, which covers everything a retry can fix. It
 * can't fix a declined photo, a foodless description, or an unparseable
 * answer, and retrying those costs money and delays the answer for nothing
 * — so a failure becomes a `failed` meal with the reason and a retry
 * button, and the person who typed it judges better than a backoff would.
 *
 * The job carries an id, not a model: a serialised Eloquent model in a
 * payload snapshots the row at dispatch time, but this job is the thing
 * that CHANGES that row, and reading it fresh is what makes the
 * pending-status guard mean anything.
 */
abstract class AnalyzeMeal implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Comfortably past the HTTP client's own ceiling (120 s) plus one retry,
     * so the timeout that fires carries a message rather than being the
     * queue's.
     */
    public int $timeout = 300;

    public function __construct(public readonly int $visionRequestId) {}

    public function handle(
        VisionAnalyzer $analyzer,
        ProposedItemMapper $mapper,
        ProposalWriter $writer,
        MemoryPrefill $memory,
    ): void {
        $request = VisionRequest::query()->with('meal')->find($this->visionRequestId);

        if ($request === null) {
            // The meal was discarded while the job sat in the queue; the row
            // cascaded away with it. Nothing to do and nothing wrong.
            return;
        }

        // Claiming the row makes a re-delivered job safe: a queue is
        // at-least-once, and paying twice for the same meal is exactly what
        // `idempotency_key` exists to prevent.
        if ($request->status !== VisionRequestStatus::Pending) {
            return;
        }

        $meal = $request->meal;

        if ($meal === null) {
            $this->markFailed($request, 'The meal this analysis belonged to is gone.');

            return;
        }

        $input = $this->input($request, $meal);

        if ($input === null) {
            $this->markFailed($request, $this->unavailable());
            $this->failMeal($meal, $request);

            return;
        }

        $request->status = VisionRequestStatus::Sent;
        $request->save();

        try {
            $outcome = $this->send($analyzer, $request, $meal, $input);
        } catch (Throwable $e) {
            // AnthropicVisionAnalyzer turns its own errors into outcomes, so
            // reaching here means something further out broke — it still
            // has to land on the meal, not just a log nobody reads.
            Log::error('Meal analysis threw', ['vision_request' => $request->id, 'message' => $e->getMessage()]);

            $this->markFailed($request, 'The analysis did not complete.');
            $this->failMeal($meal, $request);

            return;
        }

        $request->raw_response = $outcome->raw;
        $request->input_tokens = $outcome->inputTokens;
        $request->output_tokens = $outcome->outputTokens;
        $request->latency_ms = $outcome->latencyMs;

        if (! $outcome->succeeded) {
            $this->markFailed($request, (string) $outcome->error);
            $this->failMeal($meal, $request);

            return;
        }

        $request->status = VisionRequestStatus::Succeeded;
        $request->error = null;
        $request->save();

        /*
         * The order of the three claims, weakest first. The vision_requests
         * row above is already written and never touched again — the
         * model's opinion, kept verbatim so a prompt change can be
         * evaluated against history.
         *
         *   1. the model      what came back, mapped to densities.
         *   2. meal memory    "you've had this before, and it was 180 g" —
         *                     ours, applied BEFORE the proposal is stored so
         *                     confirming an untouched proposal stays a
         *                     no-op. Flagged via `meal_items.memory_adjusted`.
         *   3. the user       anything typed thirty seconds ago, restored
         *                     over both (text path only).
         */
        $items = $this->complete($request, $outcome->items);

        $prefilled = $memory->apply($mapper->toRows($items));

        $meal->memory_match_id = $prefilled->match?->memory->id;
        $meal->memory_match_score = $prefilled->match?->score;

        /*
         * An answer that's been superseded is recorded, not applied: two
         * analyses of one plate can be in flight at once (a note-carrying
         * re-ask is let past an in-flight call — see
         * MealPhotoController::reanalyze), and completion order isn't
         * guaranteed, so the newest row (MealPhoto::latestVisionRequest,
         * MAX(id)) owns the plate's items while an older row still
         * completes as an audit-only write, under a lock on the plate to
         * avoid a check-then-write race. See docs/rationale-app.md
         * § "AnalyzeMeal: why a superseded answer is recorded, not applied".
         */
        DB::transaction(function () use ($request, $meal, $writer, $prefilled, $outcome): void {
            $this->lockPlate($request);

            if ($this->superseded($request)) {
                return;
            }

            // Scoped to the entry this request looked at: the proposal
            // replaces THIS plate's previous answer and nothing else — a
            // null `meal_photo_id` is the text path, scoped to items from a
            // description rather than a photograph.
            $writer->propose(
                meal: $meal,
                photoId: $request->meal_photo_id,
                rows: $this->preserve($request, $prefilled->rows),
                notes: $outcome->notes,
            );
        });
    }

    /**
     * The last line of defence: a timeout, an OOM, a worker restart. Without
     * this the meal would sit in `analyzing` forever with a spinner and no
     * way for the user to find out why.
     */
    public function failed(?Throwable $e): void
    {
        $request = VisionRequest::query()->with('meal')->find($this->visionRequestId);

        if ($request === null || $request->status === VisionRequestStatus::Succeeded) {
            return;
        }

        $this->markFailed($request, 'The analysis was interrupted before it finished.');

        $meal = $request->meal;

        if ($meal !== null && $meal->status === MealStatus::Analyzing && ! $this->superseded($request)) {
            $meal->status = MealStatus::Failed;
            $meal->save();
        }
    }

    /**
     * The meal could not be moved forward — but only if it hadn't already
     * moved forward for good.
     *
     * A CONFIRMED meal stays confirmed: failing one photo's call must not
     * mark the whole dinner `failed`, since `Meal::scopeConfirmed()` is what
     * the daily rollup selects on — the main course shouldn't drop out of
     * the day's total because a picture of pudding didn't parse. The
     * failure isn't lost; it lands on the entry's own `vision_requests`
     * row, which is where per-plate state lives and what the review sheet
     * reads (see MealPhoto::entryState()).
     *
     * A SUPERSEDED failure isn't the meal's failure either: when a
     * note-carrying re-ask has overtaken this call, the plate's state
     * belongs to the newer, still-working row, and marking the dinner
     * `failed` on the way past would flash a wrong red card. The failure
     * still lands on this row.
     */
    private function failMeal(Meal $meal, VisionRequest $request): void
    {
        if ($meal->status === MealStatus::Confirmed) {
            return;
        }

        if ($this->superseded($request)) {
            return;
        }

        $meal->status = MealStatus::Failed;
        $meal->save();
    }

    /**
     * Has a newer analysis of this plate been asked for since this one
     * started?
     *
     * MAX(id), matching `MealPhoto::latestVisionRequest()` (`latestOfMany()`
     * defaults to the key column, not `created_at`) — the two must agree,
     * since the review sheet reads state off the newest row, or a plate
     * could show one answer while holding another's items. Ids are a
     * monotonic bigserial; `created_at` is a timestamp two rows can share.
     * The text path has no plate and no newer row to lose to — its own
     * idempotency key keeps duplicates from existing.
     */
    protected function superseded(VisionRequest $request): bool
    {
        if ($request->meal_photo_id === null) {
            return false;
        }

        return VisionRequest::query()
            ->where('meal_photo_id', $request->meal_photo_id)
            ->where('id', '>', $request->id)
            ->exists();
    }

    /**
     * Hold the plate for the length of the proposal write.
     *
     * Locked on `meal_photos`, not the audit rows, since the plate is what
     * the two jobs fight over. Taken FIRST, before `ProposalWriter::propose`
     * touches the meal, so both completions take locks in the same order
     * and can't deadlock.
     */
    private function lockPlate(VisionRequest $request): void
    {
        if ($request->meal_photo_id === null) {
            return;
        }

        MealPhoto::query()->whereKey($request->meal_photo_id)->lockForUpdate()->first();
    }

    /**
     * The evidence to send, or null when it's no longer available.
     *
     * `mixed` rather than `?string` since the two paths carry different
     * evidence (image bytes vs. a rendered description); only the `null`
     * check is shared, each subclass hands its own value to `send()`.
     */
    abstract protected function input(VisionRequest $request, Meal $meal): mixed;

    /** Shown to the user when `input()` comes back null. */
    abstract protected function unavailable(): string;

    /**
     * The call itself.
     *
     * Takes the row and meal as well as the evidence, since the photo path
     * needs both: `meal_photo_id` says which plate, the meal supplies the
     * already-logged names.
     */
    abstract protected function send(VisionAnalyzer $analyzer, VisionRequest $request, Meal $meal, mixed $input): VisionOutcome;

    /**
     * A last chance to add items the model did not return. Identity by default:
     * a photograph has no list to compare its answer against.
     *
     * @param  list<ProposedItem>  $items
     * @return list<ProposedItem>
     */
    protected function complete(VisionRequest $request, array $items): array
    {
        return $items;
    }

    /**
     * The final word on the numbers, after the model and after memory.
     * Identity by default, for the same reason.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function preserve(VisionRequest $request, array $rows): array
    {
        return $rows;
    }

    protected function markFailed(VisionRequest $request, string $error): void
    {
        $request->status = VisionRequestStatus::Failed;
        $request->error = $error;
        $request->save();
    }
}
