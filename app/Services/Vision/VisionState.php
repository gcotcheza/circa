<?php

declare(strict_types=1);

namespace App\Services\Vision;

use App\Models\Meal;
use App\Models\MealPhoto;
use Carbon\CarbonImmutable;
use App\Models\VisionRequest;

/**
 * The JSON the front end polls for: where is this meal, and where are the
 * analyses that are moving it.
 *
 * Shared by the photo and estimate endpoints because the polling loop in
 * Day.vue is one loop — two serialisations of the same rows would be two
 * chances for `analyzing` to mean something subtly different depending on
 * which button started it, and the poller can't tell them apart since it
 * watches one shape either way.
 *
 * `pending` is a COUNT, not a status, because `meals.status` stopped
 * being able to answer "is anything happening?" the moment a CONFIRMED
 * meal could hold an analysing dessert — it says whether the meal is
 * committed at all, and deliberately doesn't go backwards out of
 * `confirmed` since the daily rollup selects on it and a dessert must not
 * pull the main course out of the day's totals. So the poller watches
 * `pending` (plates still analysing or awaiting a tap) — zero means stop
 * polling and reload, whatever `meals.status` says.
 *
 * Deliberately thin otherwise: proposed items come back through Inertia
 * props on the next page refresh, one shape of a meal, not two.
 */
final class VisionState
{
    public function __construct(private readonly PhotoStore $photos) {}

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?Meal $meal, ?VisionRequest $request): array
    {
        $photos = $meal?->photos()->with('latestVisionRequest')->get();

        return [
            'meal' => $meal === null ? null : [
                'uuid'   => $meal->uuid,
                'status' => $meal->status->value,
                'date'   => CarbonImmutable::parse($meal->eaten_at)
                    ->setTimezone((string) config('health.timezone'))
                    ->toDateString(),
                'itemCount'  => $meal->items()->count(),
                'photoCount' => $photos?->count() ?? 0,
                // What the poller actually watches. See the class docblock.
                'pending' => $photos?->filter(fn (MealPhoto $photo): bool => $photo->isPending())->count() ?? 0,
                'photos'  => $photos?->map(fn (MealPhoto $photo): array => [
                    'clientId'    => $photo->client_id,
                    'position'    => $photo->position,
                    'state'       => $photo->entryState(),
                    'hasOriginal' => $this->photos->isOriginal($photo->path),
                ])->values()->all() ?? [],
            ],
            'analysis' => $request === null ? null : [
                'status'        => $request->status->value,
                'kind'          => $request->request_kind->value,
                'model'         => $request->model,
                'promptVersion' => $request->prompt_version,
                // Shown to the user, so it's the friendly error the analyzer
                // produced, not a stack trace.
                'error' => $request->error,
            ],
        ];
    }
}
