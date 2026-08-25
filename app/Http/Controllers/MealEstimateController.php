<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Meal;
use App\Enums\MealType;
use App\Models\MealItem;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use App\Models\VisionRequest;
use App\Enums\VisionRequestKind;
use Illuminate\Http\JsonResponse;
use App\Enums\VisionRequestStatus;
use App\Services\Vision\TypedItem;
use App\Services\Vision\TypedMeal;
use Illuminate\Support\Facades\DB;
use App\Jobs\EstimateMealNutrition;
use App\Services\Vision\VisionState;
use App\Services\Vision\PromptV1Text;
use App\Services\Vision\VisionAnalyzer;
use App\Http\Requests\MealEstimateRequest;
use App\Http\Requests\MealReestimateRequest;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * "Describe it" — the third way into the same pipeline.
 *
 * Not a flag on POST /meals: that write confirms a meal and redirects, while
 * this claims an analysis and returns 202 on a meal nobody's agreed to yet —
 * two state machines behind one route is how a queued replay ends up
 * guessing which it's talking to. So it mirrors the photo upload exactly
 * (JSON, /api, `throttle:vision`, idempotency-key-first, 202 with the same
 * state body), and the offline queue treats it like any other write.
 *
 * The meal is born `analyzing`, not `confirmed`: MealWriter confirms typed
 * meals because typing the food IS the confirmation, but that doesn't hold
 * when the numbers are about to be replaced — counting, then not counting
 * while the model thinks, then counting differently would flicker the
 * balance for no visible reason. Items go in exactly as typed,
 * `confirmed_at` null, waiting at `analyzing` like a photograph. If the
 * estimate fails, the meal goes to `failed` with the typed items intact;
 * Confirm is a one-tap "save it as typed".
 */
final class MealEstimateController extends Controller
{
    public function __construct(private readonly VisionState $state) {}

    /**
     * Save the typed meal and ask for the blanks.
     *
     * The idempotency key is checked before anything is written — a
     * double-tap or offline-queue replay costs one Anthropic call, caught
     * before it does anything.
     */
    public function store(MealEstimateRequest $request): JsonResponse
    {
        $key = $request->string('idempotency_key')->value();
        $uuid = $request->string('uuid')->value();

        $claimed = VisionRequest::query()->with('meal')->where('idempotency_key', $key)->first();

        if ($claimed !== null) {
            return response()->json(($this->state)($claimed->meal, $claimed), 200);
        }

        $existing = Meal::query()->where('uuid', $uuid)->first();

        if ($existing !== null && $existing->status === MealStatus::Confirmed) {
            // Same rule as the photo path: a confirmed meal is a decision
            // already made, and reopening it quietly isn't what "save"
            // meant. Re-estimate below is the deliberate version.
            return response()->json([
                'status'  => 'conflict',
                'message' => 'That meal is already logged. Open it to estimate the missing values.',
            ], 409);
        }

        $typed = $request->typedMeal();

        try {
            [$meal, $visionRequest] = DB::transaction(function () use ($uuid, $request, $typed, $key): array {
                $meal = Meal::query()->firstOrCreate(
                    ['uuid' => $uuid],
                    [
                        'status' => MealStatus::Analyzing,
                        'source' => MealSource::Manual,
                        // UTC — `local_date` is generated from this column,
                        // and Eloquent hands Postgres an offset-less string.
                        // See MealWriter.
                        'eaten_at' => $request->eatenAt()->utc(),
                    ],
                );

                $meal->status = MealStatus::Analyzing;
                $meal->eaten_at = $request->eatenAt()->utc();
                // Validated against the enum before it got here, and the cast
                // would call from() anyway. See ProposalWriter.
                $mealType = $request->input('meal_type');

                $meal->meal_type = $mealType === null ? null : MealType::from((string) $mealType);
                $meal->notes = $request->input('notes');
                $meal->save();

                // Unconfirmed on purpose: the user's words, waiting for
                // numbers — replaced wholesale when the proposal lands, left
                // as-is if it never does.
                $meal->items()->delete();

                foreach ($typed->toRows() as $row) {
                    $item = $meal->items()->make($row);

                    $item->confirmed_at = null;

                    $item->save();
                }

                return [$meal, $this->claim($meal, $key, $typed)];
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with a concurrent flush of the same save — the
            // winner's rows are the answer.
            $claimed = VisionRequest::query()->with('meal')->where('idempotency_key', $key)->first();

            return response()->json(($this->state)($claimed?->meal, $claimed), 200);
        }

        EstimateMealNutrition::dispatch($visionRequest->id);

        return response()->json(($this->state)($meal, $visionRequest), 202);
    }

    /**
     * Estimate the blanks on a meal that is already saved.
     *
     * Same semantics as re-analysing a photograph: a NEW idempotency key and
     * `vision_requests` row, since "try again" and "arrived twice" are
     * intentions the audit table must tell apart. The typed input is
     * reconstructed from the stored rows, where a blank is indistinguishable
     * from a typed zero — an item genuinely entered as 0 kcal gets estimated
     * too, the cost of a NOT NULL column, visible in the review sheet and
     * one Confirm tap away.
     */
    public function reestimate(MealReestimateRequest $request, Meal $meal): JsonResponse
    {
        $validated = $request->validated();

        $claimed = VisionRequest::query()->with('meal')->where('idempotency_key', $validated['idempotency_key'])->first();

        if ($claimed !== null) {
            return response()->json(($this->state)($claimed->meal, $claimed), 200);
        }

        if ($meal->status === MealStatus::Analyzing) {
            return response()->json([
                'status'  => 'conflict',
                'message' => 'That meal is already being estimated.',
            ], 409);
        }

        $meal->load('items');

        $typed = isset($validated['items'])
            ? $this->sheetGivens($meal, $validated['items'])
            : $this->typedFrom($meal);

        if ($typed->items === []) {
            return response()->json([
                'status'  => 'conflict',
                'message' => 'There is nothing in that meal to estimate.',
            ], 409);
        }

        if (! $typed->hasBlanks()) {
            // Every item already has a calorie figure — estimating anyway
            // would buy an answer immediately overwritten, a bill for
            // nothing.
            return response()->json([
                'status'  => 'conflict',
                'message' => 'Every item in that meal already has its calories.',
            ], 409);
        }

        try {
            $visionRequest = DB::transaction(function () use ($meal, $validated, $typed): VisionRequest {
                $meal->status = MealStatus::Analyzing;
                $meal->save();

                return $this->claim($meal, $validated['idempotency_key'], $typed);
            });
        } catch (UniqueConstraintViolationException) {
            $claimed = VisionRequest::query()->with('meal')->where('idempotency_key', $validated['idempotency_key'])->first();

            return response()->json(($this->state)($claimed?->meal, $claimed), 200);
        }

        EstimateMealNutrition::dispatch($visionRequest->id);

        return response()->json(($this->state)($meal, $visionRequest), 202);
    }

    /**
     * The meal as the REVIEW SHEET has it, including edits nobody has confirmed.
     *
     * Without this, re-estimating read the STORED items — the previous
     * answer — so a correction typed into a box but never sent (e.g. fixing
     * a bad portion) never reached the model, and the same bad answer would
     * come back looking like the app had reverted the fix.
     *
     * What arrives here is a TypedMeal like any other: `describe()` prints
     * it as "GIVEN — use exactly this", `complete()` won't let the model
     * drop a line the user put a number on, `preserve()` writes it back
     * over both the model and meal memory, and `input_payload` records it
     * so the answer stays explicable afterwards. The CLIENT decides which
     * boxes count as claims (`statedItems` in ProposalReview.vue) — a value
     * the user touched and settled on, never a model-proposed range;
     * anything unsent stays estimable. The meal's type, time and notes come
     * from the row, not the sheet, since the sheet posts those with the
     * confirm, and this isn't one.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function sheetGivens(Meal $meal, array $items): TypedMeal
    {
        return TypedMeal::fromSheet(
            items: $items,
            mealType: $meal->meal_type?->value,
            time: $meal->eaten_at->setTimezone((string) config('health.timezone'))->format('H:i'),
            notes: $meal->notes,
        );
    }

    /**
     * What the user actually said about this meal, as opposed to what's
     * currently stored against it.
     *
     * The stored rows aren't an answer to this: after a second ask, they
     * hold the model's numbers for items whose input differed (a preserved
     * figure looks identical to an estimated one, both being decimals in a
     * NOT NULL column) — deriving input from those rows would hand the
     * model its own previous answer as if the user had typed it. So the
     * original input is reused while its proposal is still unconfirmed;
     * once confirmed, the row values ARE the user's (they tapped Confirm)
     * and become the honest source.
     */
    private function typedFrom(Meal $meal): TypedMeal
    {
        $stillProposed = $meal->items->contains(
            static fn (MealItem $item): bool => $item->confirmed_at === null
        );

        if ($stillProposed) {
            $previous = $meal->visionRequests()
                ->where('request_kind', VisionRequestKind::Text)
                ->whereNotNull('input_payload')
                ->latest('id')
                ->first();

            if ($previous !== null && is_array($previous->input_payload)) {
                return TypedMeal::fromArray($previous->input_payload);
            }
        }

        return new TypedMeal(
            items: array_values($meal->items->map(static fn (MealItem $item): TypedItem => self::typedItemFrom($item))->all()),
            mealType: $meal->meal_type?->value,
            time: $meal->eaten_at->setTimezone((string) config('health.timezone'))->format('H:i'),
            notes: $meal->notes,
        );
    }

    /**
     * One stored row read back as the form entry that produced it.
     *
     * Three of the four columns can't answer honestly alone, each resolved
     * toward the reading that leaves something to estimate rather than
     * inventing a claim the user never made: a zero density (`meal_items`
     * densities are NOT NULL, so "no calories" and a typed 0 are both
     * 0.000) reads as blank; a range is a previous estimate, and
     * re-estimating asks to replace it; and a 100 g portion is the BASIS
     * `TypedItem::toRow()` writes for absolute-mode items (where density
     * equals the absolute value), not a claim the food weighed 100 g —
     * reading it as a claimed portion is how a blank line came back pinned
     * at "Portion: 100 g (GIVEN)", 0 kcal.
     *
     * The 100 g rule matches `MealSheet.vue`'s own inference
     * (`perHundred = item.portionGMin !== 100`), deliberately: "what did the
     * user type" must have one answer, or the screen and the model
     * disagree about the same row. A user who genuinely weighed 100 g in
     * per-100 g mode pays the same cost the sheet has always charged — the
     * portion is visible in the review sheet before anything is confirmed.
     */
    private static function typedItemFrom(MealItem $item): TypedItem
    {
        $values = [];

        foreach (['kcal', 'protein', 'carbs', 'fat'] as $nutrient) {
            $min = (float) $item->{$nutrient.'_per_100g_min'};
            $max = (float) $item->{$nutrient.'_per_100g_max'};

            $values[$nutrient] = $min > 0.0 && $min === $max ? $min : null;
        }

        /*
         * The portion as TYPED, before any share came off — a shared item's
         * `portion_g_*` is the half eaten, so reading that here would ask
         * about half a cappuccino and then apply the share twice.
         * `portion_full_g_*` is what the user actually entered. The 100 g
         * basis test below depends on it too: a quick-mode item shared in
         * half is stored at 50 g, and against `portion_g_min` it would stop
         * looking like the basis it is.
         */
        $portionMin = (float) $item->portion_full_g_min;
        $portionMax = (float) $item->portion_full_g_max;

        $zeroWidth = $portionMin > 0.0 && $portionMin === $portionMax;

        // At the 100 g basis the density IS the absolute, so an
        // absolute-mode item round-trips exactly: preserve() divides the
        // typed total back across whatever portion the model proposes.
        if ($zeroWidth && $portionMin === 100.0) {
            return new TypedItem(
                name: $item->name,
                basis: 'absolute',
                grams: null,
                values: $values,
                foodProductId: $item->food_product_id,
                // Carried, so re-estimating a half portion asks about the
                // whole thing and stores half the answer, rather than
                // restoring the plate unasked.
                shareFraction: (float) $item->share_fraction,
            );
        }

        return new TypedItem(
            name: $item->name,
            basis: 'per_100g',
            // A ranged portion is a band, not a stated weight. Densities can
            // still be claims (a preserved one is zero-width), so they
            // survive while portion goes back to being a question.
            grams: $zeroWidth ? $portionMin : null,
            values: $values,
            foodProductId: $item->food_product_id,
            shareFraction: (float) $item->share_fraction,
        );
    }

    /**
     * Claim a row for this estimate, before anything is sent.
     *
     * `pending` locks the row: the job refuses a non-pending one, so an
     * at-least-once queue delivering it twice still buys one API call.
     * `input_payload` makes the row replayable — the text equivalent of
     * `image_sha256`, and the only surviving record of which figures the
     * user supplied. See EstimateMealNutrition.
     */
    private function claim(Meal $meal, string $key, TypedMeal $typed): VisionRequest
    {
        /** @var VisionAnalyzer $analyzer */
        $analyzer = app(VisionAnalyzer::class);

        return VisionRequest::query()->create([
            'meal_id'         => $meal->id,
            'request_kind'    => VisionRequestKind::Text,
            'idempotency_key' => $key,
            'model'           => $analyzer->model(),
            'prompt_version'  => $analyzer->textPromptVersion() ?: PromptV1Text::VERSION,
            'image_sha256'    => null,
            'input_payload'   => $typed->toArray(),
            'status'          => VisionRequestStatus::Pending,
        ]);
    }
}
