<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Meal;
use App\Models\MealItem;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use App\Enums\VisionRequestStatus;
use Illuminate\Support\Facades\DB;
use App\Services\Vision\ProposedItem;
use App\Services\Vision\StoredAnswer;

/**
 * Put back the plate an item came off, from the answer that named it.
 * Moves only `meal_photo_id`, only for items with no current plate; dry
 * run by default. See docs/rationale-app.md §
 * "RelinkPhotoProvenanceCommand: what broke and why this is a command,
 * not a migration".
 */
final class RelinkPhotoProvenanceCommand extends Command
{
    protected $signature = 'vision:relink
                            {meal : The meal uuid}
                            {--apply : Write the restored links instead of only showing them}';

    protected $description = 'Re-link a meal\'s items to the plates they came off, from the stored vision answers';

    public function handle(): int
    {
        $uuid = (string) $this->argument('meal');

        // `meals.uuid` is a Postgres uuid column, so a typo reaches the driver
        // as a cast error rather than as "not found". Answered here instead.
        if (! Str::isUuid($uuid)) {
            $this->error('That is not a uuid.');

            return self::FAILURE;
        }

        $meal = Meal::query()
            ->where('uuid', $uuid)
            ->with(['items', 'photos'])
            ->first();

        if ($meal === null) {
            $this->error('No meal with that uuid.');

            return self::FAILURE;
        }

        $scopes = $this->scopesBySlug($meal);

        if ($scopes === []) {
            $this->error('That meal has no readable analysis to re-link from.');

            return self::FAILURE;
        }

        $orphans = $meal->items->filter(
            static fn (MealItem $item): bool => $item->meal_photo_id === null
        )->values();

        $this->line("Meal {$meal->uuid} — {$meal->status->value}, {$meal->items->count()} item(s), {$meal->photos->count()} plate(s)");
        $this->line("Without provenance: {$orphans->count()}");
        $this->newLine();

        if ($orphans->isEmpty()) {
            $this->info('Nothing to re-link.');

            return self::SUCCESS;
        }

        $positions = $meal->photos->pluck('position', 'id');

        $matched = [];
        $skipped = [];

        foreach ($orphans as $item) {
            $candidates = $scopes[(string) $item->slug] ?? null;

            if ($candidates === null) {
                $skipped[] = [$item->name, 'no answer names it'];

                continue;
            }

            if (count($candidates) > 1) {
                $skipped[] = [$item->name, 'named by '.count($candidates).' entries'];

                continue;
            }

            $photoId = $candidates[0];

            // The single naming scope is the text path, where a null plate is
            // the correct and intended answer.
            if ($photoId === null) {
                $skipped[] = [$item->name, 'described, not photographed'];

                continue;
            }

            $matched[$item->id] = $photoId;

            $this->line(sprintf(
                '  photo %d  <-  %s',
                ((int) ($positions[$photoId] ?? 0)) + 1,
                $item->name,
            ));
        }

        $this->newLine();

        if ($skipped !== []) {
            $this->warn('Left with no plate:');
            $this->table(['item', 'why'], $skipped);
        }

        if ($matched === []) {
            $this->info('Nothing to re-link.');

            return self::SUCCESS;
        }

        if (! $this->option('apply')) {
            $this->info(sprintf(
                'Would re-link %d item(s). Nothing written. Re-run with --apply.',
                count($matched),
            ));

            return self::SUCCESS;
        }

        $this->write($matched);

        $this->info(sprintf('Re-linked %d item(s).', count($matched)));

        return self::SUCCESS;
    }

    /**
     * Reads each plate's most recent successful answer, not a superseded
     * one; values are deduplicated scopes, so one item named twice isn't
     * false ambiguity.
     *
     * @return array<string, list<int|null>>
     */
    private function scopesBySlug(Meal $meal): array
    {
        /** @var array<string, list<int|null>> $scopes */
        $scopes = [];

        $requests = $meal->visionRequests()
            ->where('status', VisionRequestStatus::Succeeded)
            ->whereNotNull('raw_response')
            ->orderBy('id')
            ->get();

        // Keyed by scope so the last row wins — orderBy('id') makes that the
        // latest attempt.
        $latest = [];

        foreach ($requests as $request) {
            $latest[$request->meal_photo_id === null ? 'text' : (string) $request->meal_photo_id] = $request;
        }

        foreach ($latest as $request) {
            $answer = StoredAnswer::fromRawResponse((array) $request->raw_response);

            if ($answer === null) {
                $this->warn("vision_requests #{$request->id} holds no readable answer; its plate is skipped.");

                continue;
            }

            foreach ($answer->items as $item) {
                $slug = self::slug($item);

                if ($slug === '') {
                    continue;
                }

                if (! in_array($request->meal_photo_id, $scopes[$slug] ?? [], true)) {
                    $scopes[$slug][] = $request->meal_photo_id;
                }
            }
        }

        return $scopes;
    }

    /**
     * The same key `meal_items.slug` was written with, so the two sides
     * can't drift — see ProposedItemMapper::toRow / TypedItem::slug.
     */
    private static function slug(ProposedItem $item): string
    {
        return str($item->name)->slug()->value();
    }

    /**
     * @param  array<int, int>  $matched  item id -> meal_photo_id
     */
    private function write(array $matched): void
    {
        DB::transaction(function () use ($matched): void {
            foreach ($matched as $itemId => $photoId) {
                // Bare column update, not a model save: MealItem::creating must not
                // recompute portions that are already right and already agreed to.
                MealItem::query()->whereKey($itemId)->update(['meal_photo_id' => $photoId]);
            }
        });
    }
}
