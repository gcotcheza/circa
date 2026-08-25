<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Meal;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\MealPhoto;
use Illuminate\Http\Request;
use App\Models\VisionRequest;
use App\Jobs\AnalyzeMealPhoto;
use App\Enums\VisionRequestKind;
use Illuminate\Http\JsonResponse;
use App\Enums\VisionRequestStatus;
use App\Services\Vision\TypedMeal;
use Illuminate\Support\Facades\DB;
use App\Services\Vision\PhotoStore;
use App\Services\Vision\VisionState;
use App\Services\Vision\PromptV3Photo;
use App\Http\Requests\MealPhotoRequest;
use App\Services\Vision\VisionAnalyzer;
use App\Services\Meals\ConsumptionShare;
use App\Services\Vision\UnreadablePhoto;
use App\Http\Requests\MealReanalyzeRequest;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * The photo half of the vision pipeline: upload a plate, poll it, look at it,
 * re-analyse it, remove it.
 *
 * JSON under /api, not Inertia: a live upload status must not navigate away
 * mid-flight the way a form redirect would, and /api makes bootstrap/app.php
 * render exceptions as JSON, so a guest gets a 401 body rather than a 302
 * that fetch() follows into the login page's HTML. Routed in routes/web.php's
 * session-auth group; routes/api.php stays session-less for the phone's
 * unattended export.
 *
 * A meal is a series of plates analysed as they arrive — a second helping to
 * an already-confirmed meal is analysed, proposed and confirmed on its own,
 * so `store()` no longer refuses a confirmed meal. `idempotency_key` makes
 * the ANALYSIS idempotent; `client_id` makes the PHOTO idempotent, so an
 * offline queue's duplicate uploads are told apart from new plates.
 * Confirming stays in MealProposalController's Inertia form.
 */
final class MealPhotoController extends Controller
{
    public function __construct(
        private readonly PhotoStore $photos,
        private readonly VisionState $state,
    ) {}

    /**
     * Receive one plate, claim its analysis, queue the job.
     *
     * The idempotency key is checked before anything is written, so a
     * double-tapped shutter or an offline-queue replay costs one Anthropic
     * call: the second request is caught before it has done anything.
     */
    public function store(MealPhotoRequest $request): JsonResponse
    {
        $key = $request->string('idempotency_key')->value();
        $uuid = $request->string('uuid')->value();
        $clientId = $request->clientId();

        $claimed = VisionRequest::query()->with('meal')->where('idempotency_key', $key)->first();

        if ($claimed !== null) {
            // Already ours: no second photo, job or invoice, and a 200
            // rather than an error since the requested analysis is under way.
            return response()->json(($this->state)($claimed->meal, $claimed), 200);
        }

        // One level down, same idea: a new analysis key (e.g. a retry)
        // doesn't mean a new photo — writing this exact image again would
        // duplicate the plate.
        $existing = MealPhoto::query()->with('meal')->where('client_id', $clientId)->first();

        if ($existing !== null) {
            return response()->json(($this->state)($existing->meal, $existing->latestVisionRequest), 200);
        }

        try {
            $stored = $this->photos->store(
                mealUuid: $uuid,
                clientId: $clientId,
                uploadedBytes: (string) $request->file('photo')?->get(),
                at: $request->eatenAt(),
            );
        } catch (UnreadablePhoto $e) {
            return response()->json(['status' => 'invalid_image', 'message' => $e->getMessage()], 422);
        }

        try {
            [$meal, $visionRequest] = DB::transaction(function () use ($uuid, $clientId, $request, $stored, $key): array {
                $meal = Meal::query()->firstOrCreate(
                    ['uuid' => $uuid],
                    [
                        'status' => MealStatus::Analyzing,
                        'source' => MealSource::Photo,
                        // UTC — `local_date` is generated from this column,
                        // and Eloquent hands Postgres an offset-less string.
                        // See MealWriter.
                        'eaten_at' => $request->eatenAt()->utc(),
                    ],
                );

                $photo = $meal->photos()->create([
                    'client_id'  => $clientId,
                    'path'       => $stored->path,
                    'thumb_path' => $stored->thumbPath,
                    'sha256'     => $stored->sha256,
                    // What the user said this plate is, if anything — stored
                    // on the PLATE since it's editable afterwards and a
                    // re-analysis must read it back. See the migration.
                    'hint' => $request->hint(),
                    // Append: removals renumber, so positions stay
                    // contiguous from zero and the count IS the next slot.
                    'position' => $meal->photos()->count(),
                ]);

                /*
                 * A confirmed meal stays confirmed — its items are already
                 * in the day's totals. The new plate's own state lives in
                 * its `vision_requests` row, which the day view polls and
                 * the review sheet reads.
                 */
                if ($meal->status !== MealStatus::Confirmed) {
                    $meal->status = MealStatus::Analyzing;
                    $meal->save();
                }

                return [$meal, $this->claim($meal, $photo, $key, $stored->sha256, $request->hint())];
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent flush — the winner's rows are
            // the answer. Deliberately NOT deleting the photo: the path is
            // derived from the meal uuid and client id, so both requests
            // wrote the same file, and cleanup here would delete the
            // winner's image out from under the job reading it.
            $claimed = VisionRequest::query()->with('meal')->where('idempotency_key', $key)->first();

            return response()->json(($this->state)($claimed?->meal, $claimed), 200);
        }

        AnalyzeMealPhoto::dispatch($visionRequest->id);

        return response()->json(($this->state)($meal, $visionRequest), 202);
    }

    /**
     * Poll: has anything on this meal finished?
     *
     * Deliberately thin — proposed items return through the Inertia props
     * on the next refresh, since a second serialisation would let the
     * ranges render differently.
     */
    public function show(Meal $meal): JsonResponse
    {
        return response()->json(($this->state)($meal, $meal->latestVisionRequest));
    }

    /**
     * The first photograph, full size, at its original URL for
     * pre-thumbnail-route clients and the PWA's cached entries. "First" is
     * `photos()`, ordered by `position` then id (see Meal::photos), so the
     * answer doesn't move on every UPDATE. Authenticated and streamed from a
     * non-public disk: nothing under /storage reaches these bytes.
     */
    public function photo(Request $request, Meal $meal): StreamedResponse
    {
        $photo = $meal->photos()->first();

        abort_if($photo === null, 404);

        /*
         * Revalidate every time, unlike the two routes below: this URL
         * names a POSITION, not a photograph, so deleting the starter makes
         * the same URL a different picture. `no-cache` plus the ETag keeps
         * the common case a 304 while never serving the wrong plate.
         */
        return $this->stream($request, $photo->path, 'private, no-cache');
    }

    /** One specific plate, full size, for the review sheet. */
    public function photoAt(Request $request, Meal $meal, MealPhoto $photo): StreamedResponse
    {
        abort_if($photo->meal_id !== $meal->id, 404);

        /*
         * Five minutes, as before, now with a validator: the refetch after
         * expiry is a 304, not another 190 KB. Not `immutable` like the
         * thumbnail, since retention repoints this path when the original
         * is deleted.
         */
        return $this->stream($request, $photo->path, 'private, max-age=300');
    }

    /**
     * One plate's THUMBNAIL — 256 px on its longest edge, ~13 KB.
     *
     * Exists because the day card renders a 96 px slot but was loading the
     * full 768x1024, 190 KB original to paint it. Cached for a year,
     * safely: PhotoStore::store() writes each thumbnail once and never
     * rewrites it — retention deletes the ORIGINAL and repoints `path`,
     * leaving `thumb_path` untouched, and only deleting the plate removes
     * the file. A plate predating thumbnails, or missing one, falls back to
     * `path`, dropping the long cache since `path` is the mutable one.
     */
    public function thumb(Request $request, Meal $meal, MealPhoto $photo): StreamedResponse
    {
        abort_if($photo->meal_id !== $meal->id, 404);

        $hasThumb = $photo->thumb_path !== ''
            && $photo->thumb_path !== $photo->path
            && $this->photos->disk()->exists($photo->thumb_path);

        return $hasThumb
            ? $this->stream($request, $photo->thumb_path, 'private, max-age=31536000, immutable')
            : $this->stream($request, $photo->path, 'private, max-age=300');
    }

    /**
     * Analyse ONE plate again, as a genuinely new request.
     *
     * A new idempotency key and `vision_requests` row, since "try again"
     * and "it arrived twice" are intentions the audit table must tell
     * apart; the previous, unconfirmed answer for this plate is discarded,
     * and everything else on the meal survives untouched.
     *
     * A hint added after the fact lands here: editing the note alone
     * changes no numbers — asking again is what applies it, and that's the
     * user's call, not a silent re-run spending money on a keystroke. It
     * needn't wait for the first answer: a note typed after the shutter
     * normally lands mid-analysis, so a re-ask that changes the question
     * supersedes rather than queues. See the guard below and AnalyzeMeal
     * for what "supersedes" costs the older call.
     */
    public function reanalyze(MealReanalyzeRequest $request, Meal $meal, MealPhoto $photo): JsonResponse
    {
        $validated = $request->validated();

        $claimed = VisionRequest::query()->with('meal')->where('idempotency_key', $validated['idempotency_key'])->first();

        if ($claimed !== null) {
            return response()->json(($this->state)($claimed->meal, $claimed), 200);
        }

        if ($photo->meal_id !== $meal->id) {
            return response()->json(['status' => 'not_found', 'message' => 'That photo is not on this meal.'], 404);
        }

        // Same rule, same reason: silence leaves the note alone; `''`
        // clears it to NULL. Resolved before the in-flight check below,
        // since what this request ASKS decides whether it's let past one.
        $hint = $request->has('hint')
            ? self::note($validated['hint'] ?? null)
            : $photo->hint;

        /*
         * A re-ask that changes the question supersedes; one that repeats
         * it waits. The 409 double-spend guard is right for "the same
         * question twice," but wrong here: the capture sheet's note is
         * filled in AFTER the shutter (logs put every upload 13-23 seconds
         * after a cold launch), so it used to arrive mid-analysis and get
         * refused for the ten-to-thirty seconds analysis takes — the user
         * just retyped it later and paid twice. So a note the in-flight
         * call didn't get is let through and supersedes rather than
         * duplicates it; AnalyzeMeal records but doesn't apply it. The
         * comparison reads `input_payload['hint']` off the in-flight row,
         * per AnalyzeMealPhoto::hint(), since the plate's `hint` column may
         * have moved.
         */
        if ($photo->entryState() === 'analyzing' && ! self::changesTheQuestion($photo, $hint)) {
            return response()->json([
                'status'  => 'conflict',
                'message' => 'That photo is still being analysed.',
            ], 409);
        }

        if ($this->photos->originalBytes($photo) === null) {
            // Retention deleted the full-size image — re-analysing the
            // 256 px thumbnail alone would present a guess as full quality.
            return response()->json([
                'status'  => 'photo_gone',
                'message' => 'The full-size photo has been deleted by retention. Edit the items by hand instead.',
            ], 409);
        }

        // Absent means "the sheet said nothing" — a plate already marked ½
        // stays at ½ rather than resetting to All.
        $share = $request->has('share_fraction')
            ? ConsumptionShare::of($validated['share_fraction'] ?? null)
            : null;

        /*
         * The sheet's figures, as a TypedMeal — same class the text path
         * uses, so "a value the user stated" has one definition. The model
         * is NOT told about them (that wouldn't improve "what's in this
         * photo?"); they're applied AFTERWARDS via
         * AnalyzeMealPhoto::preserve(), rescaling coherently since rows
         * store densities.
         */
        $given = isset($validated['items'])
            ? TypedMeal::fromSheet(
                items: $validated['items'],
                mealType: $meal->meal_type?->value,
                time: $meal->eaten_at->setTimezone((string) config('health.timezone'))->format('H:i'),
                notes: $meal->notes,
            )
            : null;

        try {
            $visionRequest = DB::transaction(function () use ($meal, $photo, $validated, $share, $hint, $given): VisionRequest {
                /*
                 * The stale PROPOSAL goes now; confirmed rows wait for the
                 * answer. Clearing unconfirmed rows stops the review sheet
                 * showing a stale answer mid-flight; confirmed rows stand
                 * until ProposalWriter::propose replaces them, so a failed
                 * re-analysis costs nothing already agreed to.
                 */
                $photo->items()->whereNull('confirmed_at')->delete();

                // Recorded before the job runs, since the job reads it back
                // and AnalyzeMealPhoto re-applies the share to the fresh
                // answer. Saved in the same breath so the plate and the
                // claimed request agree, and the sheet still shows it after
                // reload.
                $photo->forceFill([
                    'share_fraction' => $share->fraction ?? $photo->share_fraction,
                    'hint'           => $hint,
                ])->saveQuietly();

                if ($meal->status !== MealStatus::Confirmed) {
                    $meal->status = MealStatus::Analyzing;
                    $meal->save();
                }

                return $this->claim($meal, $photo, $validated['idempotency_key'], null, $hint, $given);
            });
        } catch (UniqueConstraintViolationException) {
            $claimed = VisionRequest::query()->with('meal')->where('idempotency_key', $validated['idempotency_key'])->first();

            return response()->json(($this->state)($claimed?->meal, $claimed), 200);
        }

        AnalyzeMealPhoto::dispatch($visionRequest->id);

        return response()->json(($this->state)($meal, $visionRequest), 202);
    }

    /**
     * Take one plate off the meal.
     *
     * UNCONFIRMED items always go — nobody agreed to an answer for a
     * photograph being deleted. CONFIRMED items go only if the caller says
     * so (`remove_items`): deleting the photo is a statement about the
     * photo, not about a `confirmed_at` claim the user made, so the default
     * keeps them (with `meal_photo_id` nulled, reading as a typed line). A
     * meal left with nothing at all, and never confirmed, is discarded
     * entirely.
     */
    public function destroy(Request $request, Meal $meal, MealPhoto $photo): JsonResponse
    {
        abort_if($photo->meal_id !== $meal->id, 404);

        $removeItems = $request->boolean('remove_items');

        DB::transaction(function () use ($meal, $photo, $removeItems): void {
            $photo->items()->whereNull('confirmed_at')->delete();

            if ($removeItems) {
                $photo->items()->delete();
            }

            $this->photos->forgetPhoto($photo);

            $photo->delete();

            // Close the gap so `position` stays 0..n-1 and "photo 2" keeps
            // meaning the second one — ascending, so each row moves into a
            // slot the row before it already left.
            $meal->photos()->where('position', '>', $photo->position)->orderBy('position')
                ->get()
                ->each(fn (MealPhoto $later) => $later->forceFill(['position' => $later->position - 1])->saveQuietly());

            $meal->refresh();

            if ($meal->status !== MealStatus::Confirmed && ! $meal->items()->exists() && ! $meal->photos()->exists()) {
                $meal->delete();

                return;
            }

            /*
             * A meal left with nothing pending is back where it was:
             * removing the only plate of an unconfirmed meal with typed
             * items would otherwise leave `analyzing`/`proposed` set with
             * nothing pending — a spinner nobody can clear. `draft` is the
             * honest status.
             */
            if ($meal->status !== MealStatus::Confirmed && ! $this->hasPendingEntry($meal)) {
                $meal->status = $meal->items()->whereNull('confirmed_at')->exists()
                    ? MealStatus::Proposed
                    : MealStatus::Draft;

                $meal->save();
            }
        });

        $fresh = Meal::query()->where('uuid', $meal->uuid)->first();

        return response()->json(($this->state)($fresh, $fresh?->latestVisionRequest), 200);
    }

    private function hasPendingEntry(Meal $meal): bool
    {
        foreach ($meal->photos()->get() as $photo) {
            if ($photo->isPending()) {
                return true;
            }
        }

        return false;
    }

    /**
     * A posted note, or null when the box was empty.
     *
     * `?:` would be shorter and wrong: a note of "0" is falsy in PHP and
     * would read as "nothing said" — only an EMPTY string means that.
     */
    private static function note(?string $hint): ?string
    {
        $hint = trim((string) $hint);

        return $hint === '' ? null : $hint;
    }

    /**
     * Is this re-ask telling the model something the in-flight call wasn't
     * told?
     *
     * Two load-bearing conditions: there IS a note (nothing new repeats the
     * identical question, and letting it past would buy a duplicate call —
     * what the 409 guards against), and it's NOT the one already asked
     * (double taps, dropped-connection retries, or both sheets repeating a
     * question already being answered all wait). Read off the in-flight
     * row's `input_payload`, per AnalyzeMealPhoto::hint(), since the
     * plate's own `hint` column may have moved by the time this runs.
     */
    private static function changesTheQuestion(MealPhoto $photo, ?string $hint): bool
    {
        if ($hint === null) {
            return false;
        }

        $payload = $photo->latestVisionRequest()->first()?->input_payload;

        $asked = is_array($payload) && is_string($payload['hint'] ?? null)
            ? $payload['hint']
            : null;

        return $hint !== $asked;
    }

    /**
     * Stream one file off the private disk, with a validator.
     *
     * The path is the ETag: every file is content-addressed by
     * construction (`originals/Y/m/{meal}/{client-id}.jpg`, written once),
     * and nothing rewrites one — retention DELETES the original and
     * repoints `meal_photos.path` at the thumbnail instead. So hashing the
     * path is a strong validator costing no stat call. It buys the day
     * view a 304 instead of re-sending 190 KB per plate on every
     * five-minute expiry or reload.
     */
    private function stream(Request $request, string $path, string $cacheControl): StreamedResponse
    {
        abort_if(! $this->photos->disk()->exists($path), 404);

        $response = $this->photos->disk()->response($path, headers: [
            'Cache-Control' => $cacheControl,
        ]);

        $response->setEtag(sha1($path));

        // Mutates the response into a bodyless 304 when the client's copy still
        // matches; leaves it alone otherwise.
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Claim a row for this analysis, before anything is sent.
     *
     * `pending` locks the row: the job refuses a non-pending one, so an
     * at-least-once queue delivering it twice still buys one API call.
     *
     * `input_payload` makes a hinted answer auditable — `image_sha256`
     * alone used to be the whole input, but a note changes the question,
     * so a row with only the sha256 would make two different analyses
     * (120 g of tuna vs. 90-160 g) look like the same call. The note is
     * recorded here, and the job sends what THIS ROW says, which can drift
     * from the plate's `hint` before the job runs. NULL when there's no
     * note, so unhinted rows are unchanged.
     */
    private function claim(
        Meal $meal,
        MealPhoto $photo,
        string $key,
        ?string $sha256,
        ?string $hint = null,
        ?TypedMeal $given = null,
    ): VisionRequest {
        /** @var VisionAnalyzer $analyzer */
        $analyzer = app(VisionAnalyzer::class);

        return VisionRequest::query()->create([
            'meal_id' => $meal->id,
            // Which plate — without it, a dessert re-analysis has no way to
            // know which proposal it's replacing.
            'meal_photo_id' => $photo->id,
            // Stated rather than inferred from a null sha256 — a photo
            // re-analysis also claims its row without one. See the migration.
            'request_kind'    => VisionRequestKind::Photo,
            'idempotency_key' => $key,
            'model'           => $analyzer->model(),
            'prompt_version'  => $analyzer->promptVersion() ?: PromptV3Photo::VERSION,
            'image_sha256'    => $sha256,
            /*
             * Two things a plate can say, both recorded: the note in the
             * user's own words, which the model IS told, and figures the
             * user already corrected, which it's NOT (applied afterwards).
             * Null when neither was said, so ordinary rows stay
             * byte-identical to before.
             */
            'input_payload' => array_filter([
                'hint'  => $hint,
                'given' => $given?->toArray(),
            ], static fn (mixed $value): bool => $value !== null) ?: null,
            'status' => VisionRequestStatus::Pending,
        ]);
    }
}
