<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use App\Jobs\ReadSupplementLabel;
use Illuminate\Http\JsonResponse;
use App\Enums\VisionRequestStatus;
use App\Services\Vision\PhotoStore;
use App\Services\Vision\ParsedLabel;
use App\Services\Vision\PromptV1Label;
use App\Services\Vision\VisionAnalyzer;
use App\Services\Vision\UnreadablePhoto;
use App\Http\Requests\SupplementLabelRequest;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Photograph a bottle, and poll the reading.
 *
 * JSON under /api for the same two reasons the meal photo endpoints are:
 * the page must not navigate while a 600 KB body is in flight, and a guest
 * needs a 401 with a body rather than a 302 that fetch() would follow as
 * the login page's HTML. Registered in routes/web.php inside the
 * session-auth group — routes/api.php is session-less, for the phone's
 * unattended export, and nothing here belongs there.
 *
 * CONFIRMING is not here — it's an ordinary Inertia form on
 * SupplementController, since by then the user is finishing an edit and
 * wants to land back on the list with the new bottle in it.
 *
 * The reading is not the supplement: nothing in `supplements` is written
 * here. A label read produces a `vision_requests` row and stops, and the
 * row's `raw_response` is what the review screen renders — the user
 * proof-reads it against the bottle in hand, and only then does a
 * supplement exist. That gap is why the poll route is keyed on the CLIENT
 * ID rather than anything server-side: there's no supplement yet to name
 * the reading after.
 */
final class SupplementLabelController extends Controller
{
    public function __construct(private readonly PhotoStore $photos) {}

    /**
     * Receive one label, claim its reading, queue the job.
     *
     * The idempotency key is checked FIRST, before anything is written — a
     * double-tap must cost one call to Anthropic, and the cheapest way to
     * guarantee that is to notice the second request before it's done
     * anything. Same shape as MealPhotoController::store, deliberately.
     */
    public function store(SupplementLabelRequest $request): JsonResponse
    {
        $key = $request->string('idempotency_key')->value();
        $clientId = $request->string('client_id')->value();

        $claimed = VisionRequest::query()->where('idempotency_key', $key)->first();

        if ($claimed !== null) {
            return response()->json($this->state($claimed), 200);
        }

        // One level down: a new key (a retry that regenerated it) for a
        // photograph already being read. The existing reading is the answer.
        $existing = $this->forClient($clientId);

        if ($existing !== null) {
            return response()->json($this->state($existing), 200);
        }

        try {
            $stored = $this->photos->storeLabel(
                clientId: $clientId,
                uploadedBytes: (string) $request->file('photo')?->get(),
            );
        } catch (UnreadablePhoto $e) {
            return response()->json(['status' => 'invalid_image', 'message' => $e->getMessage()], 422);
        }

        /** @var VisionAnalyzer $analyzer */
        $analyzer = app(VisionAnalyzer::class);

        try {
            $visionRequest = VisionRequest::query()->create([
                // No meal — see the migration that made the column nullable,
                // this is the row it was widened for.
                'meal_id'         => null,
                'meal_photo_id'   => null,
                'supplement_id'   => null,
                'request_kind'    => VisionRequestKind::Label,
                'idempotency_key' => $key,
                'model'           => $analyzer->model(),
                'prompt_version'  => $analyzer->labelPromptVersion() ?: PromptV1Label::VERSION,
                'image_sha256'    => $stored->sha256,
                /*
                 * Where the photograph is, and which capture it was. On the
                 * meal path the plate is a row the job looks up; here
                 * there's no row yet, so the audit row carries the path
                 * itself — same rule the meal path follows for its hint.
                 */
                'input_payload' => ['client_id' => $clientId, 'photo_path' => $stored->path],
                'status'        => VisionRequestStatus::Pending,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent replay. The winner's row is the
            // answer; the photograph isn't deleted, since the derived path
            // means both requests wrote the same bytes to the same place.
            $claimed = VisionRequest::query()->where('idempotency_key', $key)->first();

            return response()->json($this->state($claimed), 200);
        }

        ReadSupplementLabel::dispatch($visionRequest->id);

        return response()->json($this->state($visionRequest), 202);
    }

    /** Poll: has this label been read yet? */
    public function show(string $clientId): JsonResponse
    {
        $request = $this->forClient($clientId);

        if ($request === null) {
            return response()->json(['status' => 'unknown'], 404);
        }

        return response()->json($this->state($request));
    }

    /**
     * The most recent reading of one photograph.
     *
     * Most recent rather than only: "read it again" claims a NEW row
     * against the same client id, so the audit table can tell "again" from
     * "arrived twice". The screen wants the latest answer; the table keeps
     * both.
     */
    private function forClient(string $clientId): ?VisionRequest
    {
        return VisionRequest::query()
            ->where('request_kind', VisionRequestKind::Label)
            ->forClientId($clientId)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * What the review screen polls for.
     *
     * The transcription is decoded out of the stored `raw_response` through
     * ParsedLabel — the same class the analyzer decoded the live response
     * with — so the screen and the audit row can't drift apart. See
     * ParsedLabel.
     *
     * @return array<string, mixed>
     */
    private function state(?VisionRequest $request): array
    {
        if ($request === null) {
            return ['status' => 'unknown'];
        }

        $label = $request->status === VisionRequestStatus::Succeeded && is_array($request->raw_response)
            ? ParsedLabel::fromRawResponse($request->raw_response)
            : null;

        return [
            'status'        => $request->status->value,
            'clientId'      => (string) ($request->input_payload['client_id'] ?? ''),
            'model'         => $request->model,
            'promptVersion' => $request->prompt_version,
            // Shown to the user, so it is the friendly message the analyzer
            // produced rather than a stack trace.
            'error' => $request->error,
            'label' => $label?->toArray(),
        ];
    }
}
