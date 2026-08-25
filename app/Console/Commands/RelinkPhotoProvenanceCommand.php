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
 *
 * Repairs meals where `MealWriter::update()` used to drop `meal_photo_id`
 * on every edit (fixed at the write path; this repairs the rows already
 * written). Moves ONLY `meal_photo_id` — never portions, shares,
 * `confirmed_at`, or totals — and only for items with NO current plate, via
 * a bare column update that bypasses `MealItem::creating`'s portion
 * arithmetic. Identity is the slug (`meal_items.slug` / `Str::slug`);
 * ambiguous or absent matches are left alone and reported rather than
 * guessed. Dry run by default:
 *
 *   php artisan vision:relink 3d408782-…            # show what it would link
 *   php artisan vision:relink 3d408782-… --apply    # write it
 *
 * See docs/rationale-app.md § "RelinkPhotoProvenanceCommand: what broke and
 * why this is a command, not a migration" for the full incident and design
 * reasoning.
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
     * Every slug the model named, and which entries named it. One entry per
     * PLATE (plus the text path, as null), read from that entry's most
     * recent successful answer — a re-analysed plate is described by what
     * it says now, not the answer that was replaced. Values are
     * deduplicated scopes, not a count: a single answer listing "rice"
     * twice is still one entry naming it, not the ambiguity this guards
     * against.
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

        // Keyed by scope so the last row wins, which `orderBy('id')` makes the
        // latest attempt on that plate.
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
     * The same key `meal_items.slug` was written with, so the two sides of the
     * match cannot drift — see ProposedItemMapper::toRow and TypedItem::slug.
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
                /*
                 * A bare column update, not a model save: `MealItem::creating`
                 * guards portion = full x share on the way in, and this row's
                 * portions are already right and already agreed to — nothing
                 * here may recompute them.
                 */
                MealItem::query()->whereKey($itemId)->update(['meal_photo_id' => $photoId]);
            }
        });
    }
}
