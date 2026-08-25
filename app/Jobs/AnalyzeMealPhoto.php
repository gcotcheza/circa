<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Meal;
use App\Models\MealItem;
use App\Models\MealPhoto;
use App\Models\VisionRequest;
use App\Services\Vision\TypedMeal;
use App\Services\Vision\PhotoStore;
use App\Services\Vision\ProposedItem;
use App\Services\Vision\VisionOutcome;
use App\Services\Vision\VisionAnalyzer;
use App\Services\Meals\ConsumptionShare;

/**
 * One plate -> Claude -> that plate's proposed items.
 *
 * The state machine, idempotency claim, audit row and failure handling are
 * all in AnalyzeMeal, shared with the text-estimation path. What's left
 * here is only what's actually about photographs: reading the bytes back,
 * handling their absence, and telling the model what's already logged.
 *
 * One image per call, even though the API takes several: a main course, a
 * second helping and a dessert are three calls, not one with three images.
 * A combined answer can't be confirmed in pieces (the second helping
 * arrives after the main course is already logged), re-analysed in pieces
 * (fixing the dessert would mean paying for and re-reviewing the main
 * course too), or audited in pieces (`vision_requests` answers "what did
 * this photograph cost, and was it right?" — one row per three photos
 * answers neither). What the plates DO share is the double-count guard:
 * each call is told the names already on the meal, so a salad still in
 * shot behind the second helping isn't listed twice — context, not
 * batching.
 *
 * `PhotoStore` is resolved rather than injected: a queued job's
 * constructor arguments are SERIALISED into the payload, and a store is a
 * collaborator, not data.
 */
final class AnalyzeMealPhoto extends AnalyzeMeal
{
    protected function input(VisionRequest $request, Meal $meal): mixed
    {
        $photo = $this->photo($request);

        if ($photo === null) {
            return null;
        }

        return app(PhotoStore::class)->originalBytes($photo);
    }

    protected function unavailable(): string
    {
        return 'The photo could not be read back from storage.';
    }

    protected function send(VisionAnalyzer $analyzer, VisionRequest $request, Meal $meal, mixed $input): VisionOutcome
    {
        return $analyzer->analyze(
            (string) $input,
            $this->alreadyLogged($request, $meal),
            $this->hint($request),
        );
    }

    /**
     * What the user said about this plate — read off THE AUDIT ROW, not the
     * photo.
     *
     * The two can differ by the time this job runs: the note is editable,
     * and a user fixing a typo mid-analysis must not silently change what a
     * completed `vision_requests` row claims it asked. `input_payload` is
     * the record of the question, so it's also the source of it — as
     * EstimateMealNutrition reads the typed meal back rather than
     * re-deriving it from the items.
     *
     * Null for rows written before this existed, and for plates whose owner
     * said nothing, which is most of them.
     */
    private function hint(VisionRequest $request): ?string
    {
        $payload = $request->input_payload;

        if (! is_array($payload) || ! is_string($payload['hint'] ?? null)) {
            return null;
        }

        return $payload['hint'];
    }

    /**
     * The figures the user had already corrected on the review sheet,
     * restored over the fresh answer.
     *
     * The base class says a photograph "carries no user figures to
     * restore" — true only while re-analysing happened on an untouched
     * answer. That stopped being true once the review sheet let a portion
     * be corrected and then asked again: without this, the user's 250 g
     * would vanish the moment they tapped "Analyse this photo again" to
     * fix something else on the plate. So the sheet sends what it's
     * holding, the endpoint records it on the audit row, and this puts it
     * back — the same TypedMeal::preserve() the text path uses, matching by
     * name, applied last of the three claims (model, memory, user).
     *
     * The model is NOT told about these figures — it's asked what's in a
     * photograph, and the rows store DENSITIES, so pinning a portion to
     * 250 g rescales that item's totals coherently whatever weight the
     * model said.
     *
     * Null on rows written before this existed, and on re-analyses asked
     * for without editing anything, which is most of them.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    protected function preserve(VisionRequest $request, array $rows): array
    {
        $payload = $request->input_payload;

        if (! is_array($payload) || ! is_array($payload['given'] ?? null)) {
            return $rows;
        }

        return TypedMeal::fromArray($payload['given'])->preserve($rows);
    }

    /**
     * A re-analysed shared plate stays shared.
     *
     * The model is never told about the share — it estimates what's on the
     * plate, the only thing a photograph can settle. So a fresh answer
     * always describes the whole bowl, and the ENTRY's share (from
     * `meal_photos`) is re-applied to it, or "re-analyse" would quietly
     * double a dinner the user had been careful to split.
     *
     * Per-item overrides are deliberately NOT carried across: a new answer
     * is a new list of foods with no identity the old rows can be matched
     * to (the pho might come back as two lines, or broth and noodles
     * separately), and pinning "all mine" onto whichever row sorts third
     * would invent a decision about food the user hasn't seen yet. The chip
     * row comes back on ½; overrides are one tap each.
     *
     * `vision:reproject` is the one place that CAN match them, since it
     * rebuilds from the same stored answer that produced the rows — see
     * ReprojectProposalCommand.
     *
     * @param  list<ProposedItem>  $items
     * @return list<ProposedItem>
     */
    protected function complete(VisionRequest $request, array $items): array
    {
        $share = ConsumptionShare::of($this->photo($request)?->share_fraction);

        if ($share->isWhole()) {
            return $items;
        }

        return array_map(
            static fn (ProposedItem $item): ProposedItem => $item->withShare($share->fraction),
            $items
        );
    }

    /**
     * What the model must not count twice.
     *
     * Everything already on the meal EXCEPT this plate's own outstanding
     * proposal: otherwise a re-analysis would be handed its own previous
     * answer as though a human had agreed to it, and dutifully avoid
     * re-listing the very food it's being asked to look at.
     *
     * Confirmed items and other plates' proposals both count. A proposal
     * the user hasn't tapped yet isn't intake, but it IS about to be
     * reviewed and appended — listing the same salad on two review sheets
     * is the confusion this guard prevents.
     *
     * @return list<string>
     */
    private function alreadyLogged(VisionRequest $request, Meal $meal): array
    {
        return array_values(MealItem::query()
            ->where('meal_id', $meal->id)
            ->when(
                $request->meal_photo_id !== null,
                fn ($query) => $query->where(function ($q) use ($request): void {
                    $q->whereNull('meal_photo_id')
                        ->orWhere('meal_photo_id', '!=', $request->meal_photo_id);
                }),
            )
            ->orderBy('id')
            ->pluck('name')
            ->all());
    }

    private function photo(VisionRequest $request): ?MealPhoto
    {
        if ($request->meal_photo_id === null) {
            return null;
        }

        return MealPhoto::query()->find($request->meal_photo_id);
    }
}
