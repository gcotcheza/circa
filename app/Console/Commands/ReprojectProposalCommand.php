<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Meal;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use Illuminate\Console\Command;
use App\Enums\VisionRequestKind;
use App\Enums\VisionRequestStatus;
use App\Services\Vision\TypedMeal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\Vision\ProposedItem;
use App\Services\Vision\StoredAnswer;
use App\Services\Meals\ConsumptionShare;
use App\Services\Vision\ProposedItemMapper;

/**
 * Rebuilds a meal's proposal from the answer already on its audit row — no
 * API call, a no-op where the rule hasn't changed. Reported and NOT written
 * on a confirmed meal unless `--force`. See docs/rationale-app.md §
 * "ReprojectProposalCommand: what a rebuild carries across, and what it
 * doesn't".
 */
final class ReprojectProposalCommand extends Command
{
    protected $signature = 'vision:reproject
                            {meal : The meal uuid}
                            {--apply : Write the rebuilt items instead of only showing them}
                            {--force : Allow --apply to change numbers on an already-confirmed meal}';

    protected $description = 'Rebuild a meal proposal from its stored answer and typed input, without calling the model';

    public function handle(ProposedItemMapper $mapper): int
    {
        $uuid = (string) $this->argument('meal');

        // `meals.uuid` is a Postgres uuid column: a typo would otherwise
        // reach the driver as a cast error.
        if (! Str::isUuid($uuid)) {
            $this->error('That is not a uuid.');

            return self::FAILURE;
        }

        $meal = Meal::query()->where('uuid', $uuid)->with('items')->first();

        if ($meal === null) {
            $this->error('No meal with that uuid.');

            return self::FAILURE;
        }

        $request = $meal->visionRequests()
            ->where('status', VisionRequestStatus::Succeeded)
            ->whereNotNull('raw_response')
            ->with('mealPhoto')
            ->latest('id')
            ->first();

        if ($request === null) {
            $this->error('That meal has no successful analysis to rebuild from.');

            return self::FAILURE;
        }

        $answer = StoredAnswer::fromRawResponse((array) $request->raw_response);

        if ($answer === null) {
            $this->error("vision_requests #{$request->id} holds no readable answer.");

            return self::FAILURE;
        }

        $typed = $this->typedInput($request);

        $items = $typed === null ? $answer->items : $typed->complete($answer->items);

        // Shares are carried across, not rebuilt. See docs/rationale-app.md
        // § "ReprojectProposalCommand: what a rebuild carries across, and
        // what it doesn't".
        $items = $this->withStoredShares($items, $meal, $request);

        $rows = $mapper->toRows($items);

        if ($typed !== null) {
            $rows = $typed->preserve($rows);
        }

        // Applied here too, not just at insert time: without it every shared
        // item's rebuilt (whole-plate) portion would falsely diff against
        // its stored (eaten) one.
        $rows = array_map(
            static fn (array $row): array => ConsumptionShare::fromRow($row)->apply($row),
            $rows
        );

        // Scope is this request's one plate; diffing the whole meal would report
        // other plates' items as "lost" and delete them. Null id = the text path.
        $scoped = $meal->items
            ->filter(static fn (MealItem $item): bool => $item->meal_photo_id === $request->meal_photo_id)
            ->values();

        $this->line("Meal {$meal->uuid} — {$meal->status->value}, from vision_requests #{$request->id} ({$request->request_kind->value}, {$request->prompt_version})");
        $this->line(sprintf(
            'Scope: %s (%d of %d item(s) on this meal)',
            $request->meal_photo_id === null ? 'typed / described items' : 'photo '.($request->mealPhoto->position ?? 0) + 1,
            $scoped->count(),
            $meal->items->count(),
        ));
        $this->newLine();

        $this->render('Stored now', array_values($scoped->map(static fn (MealItem $item): array => [
            $item->name,
            self::band((float) $item->portion_g_min, (float) $item->portion_g_max),
            self::share((float) $item->share_fraction),
            self::band((float) $item->kcal_per_100g_min, (float) $item->kcal_per_100g_max),
        ])->all()));

        $this->render('Rebuilt', array_map(static fn (array $row): array => [
            (string) $row['name'],
            self::band((float) $row['portion_g_min'], (float) $row['portion_g_max']),
            self::share((float) $row['share_fraction']),
            self::band((float) $row['kcal_per_100g_min'], (float) $row['kcal_per_100g_max']),
        ], $rows));

        $survivors = $this->survivors($scoped, $rows);

        if ($survivors !== []) {
            $this->warn('These rows keep their name but change their numbers:');

            foreach ($survivors as $name) {
                $this->line("  - {$name}");
            }
        }

        if (! $this->option('apply')) {
            $this->newLine();
            $this->info('Nothing written. Re-run with --apply.');

            return self::SUCCESS;
        }

        if ($survivors !== [] && $meal->status->value === 'confirmed' && ! $this->option('force')) {
            $this->error('Refusing to change numbers on a confirmed meal. Re-run with --force if that is genuinely intended.');

            return self::FAILURE;
        }

        $this->write($meal, $request, $scoped, $rows, $answer->notes);

        $this->info('Rebuilt '.count($rows).' item'.(count($rows) === 1 ? '' : 's').'.');

        return self::SUCCESS;
    }

    /**
     * Matched by slug against stored rows, the one definition of identity
     * `survivors()` also uses; an unmatched item falls back to the plate's
     * share, or All on the text path.
     *
     * @param  list<ProposedItem>  $items
     * @return list<ProposedItem>
     */
    private function withStoredShares(array $items, Meal $meal, VisionRequest $request): array
    {
        $stored = $meal->items
            ->filter(static fn (MealItem $item): bool => $item->meal_photo_id === $request->meal_photo_id)
            ->keyBy('slug');

        $entry = ConsumptionShare::of($request->mealPhoto?->share_fraction);

        return array_map(static function (ProposedItem $item) use ($stored, $entry): ProposedItem {
            $slug = str($item->name)->slug()->value();

            // One lookup, not has()+get(): an unmatched slug is a line added since.
            $storedItem = $stored->get($slug);

            $share = $storedItem === null
                ? $entry
                : ConsumptionShare::of($storedItem->share_fraction);

            return $share->isWhole() ? $item : $item->withShare($share->fraction);
        }, $items);
    }

    /** The typed meal this estimate was asked about, if it was a text estimate at all. */
    private function typedInput(VisionRequest $request): ?TypedMeal
    {
        if ($request->request_kind !== VisionRequestKind::Text || ! is_array($request->input_payload)) {
            return null;
        }

        return TypedMeal::fromArray($request->input_payload);
    }

    /**
     * Rows that exist on both sides under the same name but with different
     * numbers — the only kind of change that needs a human to look at it.
     *
     * @param  Collection<int, MealItem>  $scoped  this entry's items, not the meal's
     * @param  list<array<string, mixed>>  $rows
     * @return list<string>
     */
    private function survivors(Collection $scoped, array $rows): array
    {
        $changed = [];

        foreach ($rows as $row) {
            $stored = $scoped->firstWhere('slug', $row['slug']);

            if ($stored === null) {
                continue;
            }

            foreach (['portion_g_min', 'portion_g_max', 'kcal_per_100g_min', 'kcal_per_100g_max'] as $column) {
                if (abs((float) $stored->{$column} - (float) $row[$column]) > 0.0005) {
                    $changed[] = (string) $row['name'];

                    continue 2;
                }
            }
        }

        return $changed;
    }

    /**
     * @param  Collection<int, MealItem>  $scoped  this entry's items, not the meal's
     * @param  list<array<string, mixed>>  $rows
     */
    private function write(Meal $meal, VisionRequest $request, Collection $scoped, array $rows, string $notes): void
    {
        DB::transaction(function () use ($meal, $request, $scoped, $rows, $notes): void {
            // Carried, not re-stamped: `confirmed_at` records the user's own agreement.
            $confirmedAt = $scoped
                ->filter(static fn (MealItem $item): bool => $item->confirmed_at !== null)
                ->min('confirmed_at');

            // Only this entry's rows — other plates and typed lines are not
            // what this answer was about, and not this command's to rewrite.
            $meal->items()
                ->when(
                    $request->meal_photo_id === null,
                    fn ($query) => $query->whereNull('meal_photo_id'),
                    fn ($query) => $query->where('meal_photo_id', $request->meal_photo_id),
                )
                ->delete();

            foreach ($rows as $row) {
                $item = $meal->items()->make($row);

                $item->meal_photo_id = $request->meal_photo_id;
                $item->confirmed_at = $confirmedAt === null ? null : CarbonImmutable::parse($confirmedAt);

                $item->save();
            }

            $meal->model_notes = $notes === '' ? null : $notes;

            $meal->save();

            $request->mealPhoto?->forceFill(['model_notes' => $notes === '' ? null : $notes])->saveQuietly();
        });
    }

    /**
     * @param  list<array<int, string>>  $rows
     */
    private function render(string $title, array $rows): void
    {
        $this->line($title);
        $this->table(['item', 'portion g', 'ate', 'kcal/100 g'], $rows);
    }

    /** The share as the review sheet says it, so the two read the same. */
    private static function share(float $fraction): string
    {
        return $fraction >= 1.0 ? 'all' : rtrim(rtrim(number_format($fraction * 100, 1, '.', ''), '0'), '.').'%';
    }

    private static function band(float $min, float $max): string
    {
        $format = static fn (float $value): string => rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');

        return $min === $max ? $format($min) : $format($min).'–'.$format($max);
    }
}
