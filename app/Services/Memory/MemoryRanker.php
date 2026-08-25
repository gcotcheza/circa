<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Models\MealMemory;
use App\Models\MealMemoryItem;
use Illuminate\Support\Collection;
use App\Services\Rollup\IntakeBand;
use Illuminate\Database\Eloquent\Builder;

/**
 * The "log it again" list: which remembered meals, in what order.
 *
 * FRECENCY: `score = ln(1 + times_logged) * 0.5 ^ (days_since_last /
 * half_life)`. Frequency alone ossifies the list (porridge eaten every
 * morning last year stays on top after months of eggs instead); recency
 * alone is just a history, burying a twice-weekly meal under six one-offs.
 * The logarithm keeps a 300x breakfast from being 300x as interesting as a
 * 3x dinner (ln(301)/ln(4) ≈ 4.1); the exponential decay has no cliff, so
 * nothing drops off on a particular day, it just stops competing. At the
 * default 14-day half-life, one meal eaten yesterday outranks four eaten
 * two months ago — the picker is for what the user is eating now.
 * `last_logged_at` falls back to `created_at`, not "infinitely old," so a
 * row with no log timestamp ranks by frequency rather than vanishing.
 *
 * SEARCH is pg_trgm similarity against the meal's own name AND its foods'
 * names, whichever scores higher — nobody remembers what the app called
 * their lunch, they remember it had salmon in it. Ordered by similarity
 * first, frecency second: what the user typed beats what they usually eat.
 */
final class MemoryRanker
{
    /**
     * The frequent/recent list, with no query behind it.
     *
     * @return Collection<int, MealMemory>
     */
    public function frequent(?int $limit = null): Collection
    {
        return $this->base()
            ->orderByDesc('frecency')
            ->orderByDesc('times_logged')
            ->limit($limit ?? (int) config('health.memory.picker_limit', 10))
            ->get();
    }

    /**
     * @return Collection<int, MealMemory>
     */
    public function search(string $term, ?int $limit = null): Collection
    {
        $term = trim($term);

        if ($term === '') {
            return $this->frequent($limit);
        }

        $threshold = (float) config('health.memory.search_similarity', 0.3);

        return $this->base()
            ->selectRaw($this->similaritySql().' as name_similarity', [$term, $term])
            /*
             * Two filters that look redundant and are not: `%` is
             * pg_trgm's own operator, the one the GIN indexes can answer,
             * keeping this from scanning the table as history grows — but
             * its threshold is the session GUC
             * `pg_trgm.similarity_threshold` (default 0.3), which a pooled
             * connection could arrive with anything set to. The explicit
             * comparison is the authoritative floor: the effective
             * threshold is the greater of the two, and the config default
             * deliberately matches Postgres' own so they agree unless
             * somebody changes one.
             */
            ->where(function (Builder $query) use ($term): void {
                $query
                    ->whereRaw('meal_memory.canonical_name % ?', [$term])
                    ->orWhereHas('items', fn (Builder $items) => $items->whereRaw('meal_memory_items.name % ?', [$term]));
            })
            ->whereRaw($this->similaritySql().' >= ?', [$term, $term, $threshold])
            ->orderByDesc('name_similarity')
            ->orderByDesc('frecency')
            ->limit($limit ?? (int) config('health.memory.search_limit', 20))
            ->get();
    }

    /**
     * What one remembered meal looks like to the front end. The kcal band
     * is derived the same way a real meal's is — the quadrature sum, not a
     * linear one — so the number on the picker card is the number the day
     * will move by.
     *
     * @return array<string, mixed>
     */
    public function props(MealMemory $memory): array
    {
        $ranges = array_values($memory->items
            ->map(static fn (MealMemoryItem $item): array => [
                'min' => (float) $item->typical_portion_g * (float) $item->kcal_per_100g_min / 100,
                'max' => (float) $item->typical_portion_g * (float) $item->kcal_per_100g_max / 100,
            ])
            ->all());

        return [
            'id'           => $memory->id,
            'label'        => $memory->canonical_name,
            'timesLogged'  => $memory->times_logged,
            'lastLoggedAt' => $memory->last_logged_at?->toIso8601String(),
            'itemCount'    => $memory->items->count(),
            'items'        => $memory->items->map(static fn (MealMemoryItem $item): array => [
                'name'     => $item->name,
                'portionG' => (float) $item->typical_portion_g,
            ])->values()->all(),
            'kcal' => IntakeBand::fromRanges($ranges)->toArray(0),
        ];
    }

    /**
     * @param  Collection<int, MealMemory>  $memories
     * @return list<array<string, mixed>>
     */
    public function propsFor(Collection $memories): array
    {
        return array_values($memories->map(fn (MealMemory $memory): array => $this->props($memory))->all());
    }

    /**
     * @return Builder<MealMemory>
     */
    private function base(): Builder
    {
        $halfLife = max(0.5, (float) config('health.memory.frecency_half_life_days', 14));

        return MealMemory::query()
            ->select('meal_memory.*')
            ->selectRaw(
                'ln(1 + times_logged) * power(0.5, extract(epoch from (now() - coalesce(last_logged_at, created_at))) / 86400.0 / ?) as frecency',
                [$halfLife]
            )
            ->with('items')
            // A memory with no items cannot be logged and cannot be drawn.
            ->whereHas('items');
    }

    /**
     * The meal's own name, or the best-matching food inside it.
     *
     * Two `?` placeholders, in that order, wherever this is used.
     *
     * @return literal-string
     */
    private function similaritySql(): string
    {
        return 'greatest(
            similarity(meal_memory.canonical_name, ?),
            coalesce((
                select max(similarity(mmi.name, ?))
                from meal_memory_items mmi
                where mmi.meal_memory_id = meal_memory.id
            ), 0)
        )';
    }
}
