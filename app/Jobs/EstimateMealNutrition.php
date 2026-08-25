<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Meal;
use App\Models\VisionRequest;
use App\Services\Vision\TypedMeal;
use App\Services\Vision\VisionOutcome;
use App\Services\Vision\VisionAnalyzer;

/**
 * "Estimate it for me" — the typed meal -> Claude -> proposed items.
 *
 * Input comes off the audit row, not the meal: by the time this runs the
 * meal's items are already written (unconfirmed, sitting there while the
 * estimate is in flight), and reading those back would lose the one
 * distinction the feature turns on — `meal_items` density columns are NOT
 * NULL, so a blank kcal and a typed 0 are the same 0.000 in the row. So the
 * typed form is captured before it becomes rows, into
 * `vision_requests.input_payload`, and read back here — which also makes
 * the row replayable: prompt v2-text can run against the exact input
 * v1-text saw, same as `image_sha256` gives the photo path.
 *
 * The two overrides below keep the prompt's promise in code: nothing the
 * user listed can be dropped, nothing typed can be changed — not by the
 * model, not by meal memory.
 */
final class EstimateMealNutrition extends AnalyzeMeal
{
    protected function input(VisionRequest $request, Meal $meal): mixed
    {
        $typed = $this->typed($request);

        // No payload, or nothing in it: no meal to describe, and an empty list would buy an invented dinner.
        return $typed === null || $typed->items === [] ? null : $typed->describe();
    }

    protected function unavailable(): string
    {
        return 'There was nothing left to estimate from.';
    }

    protected function send(VisionAnalyzer $analyzer, VisionRequest $request, Meal $meal, mixed $input): VisionOutcome
    {
        return $analyzer->estimate((string) $input);
    }

    /** {@inheritDoc} */
    protected function complete(VisionRequest $request, array $items): array
    {
        return $this->typed($request)?->complete($items) ?? $items;
    }

    /** {@inheritDoc} */
    protected function preserve(VisionRequest $request, array $rows): array
    {
        return $this->typed($request)?->preserve($rows) ?? $rows;
    }

    private function typed(VisionRequest $request): ?TypedMeal
    {
        $payload = $request->input_payload;

        return is_array($payload) ? TypedMeal::fromArray($payload) : null;
    }
}
