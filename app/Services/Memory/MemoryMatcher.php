<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Models\MealMemory;
use App\Models\MealMemoryItem;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Builder;

/**
 * Given the item names on a plate, which remembered meal is this?
 *
 * Exact fingerprint hits are checked first, by hash with an index, since
 * an identical set of slugs means the remembered portions describe this
 * plate rather than merely one like it. Everything else is scored by
 * CONTAINMENT, the Jaccard index of the two slug sets: score = |shared| /
 * |union|. Symmetric on purpose — the alternative, |shared| / |proposal|,
 * would score a two-item proposal as a perfect match for a remembered
 * eight-item feast that happens to contain both, pre-filling a snack with
 * somebody's Christmas dinner. Dividing by the union charges for items
 * either side lacks.
 *
 * The threshold is config('health.memory.containment_threshold'), default
 * 0.60 (the two cases it was chosen against are written out there); ties
 * break on `times_logged` — the more-often-eaten meal is the better guess.
 * Candidates are pre-filtered in SQL to memories sharing at least one item
 * slug (a memory with nothing in common scores zero, not worth the query)
 * and capped, since an unbounded loop inside the analysis job is a
 * spinner the user is watching.
 */
final class MemoryMatcher
{
    public function __construct(private readonly MealFingerprint $fingerprints = new MealFingerprint) {}

    /**
     * @param  iterable<string>  $names  the item names on the plate
     */
    public function forNames(iterable $names): ?MemoryMatch
    {
        return $this->forSlugs($this->fingerprints->slugs($names));
    }

    /**
     * @param  list<string>  $slugs
     */
    public function forSlugs(array $slugs): ?MemoryMatch
    {
        $slugs = $this->fingerprints->slugs($slugs);

        if ($slugs === []) {
            return null;
        }

        $exact = MealMemory::query()
            ->with('items')
            ->where('fingerprint', $this->fingerprints->forSlugs($slugs))
            ->first();

        if ($exact !== null) {
            return new MemoryMatch($exact, 1.0, true);
        }

        $threshold = (float) config('health.memory.containment_threshold', 0.6);

        $best = null;

        foreach ($this->candidates($slugs) as $memory) {
            $score = $this->containment($slugs, $memory);

            if ($score < $threshold) {
                continue;
            }

            // Candidates arrive ordered by times_logged descending, so a strict
            // `>` keeps the most-eaten of two equally similar meals.
            if ($best === null || $score > $best->score) {
                $best = new MemoryMatch($memory, $score, false);
            }
        }

        return $best;
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, MealMemory>
     */
    private function candidates(array $slugs): Collection
    {
        return MealMemory::query()
            ->with('items')
            ->whereHas('items', fn (Builder $query) => $query->whereIn('slug', $slugs))
            ->orderByDesc('times_logged')
            ->orderByDesc('last_logged_at')
            ->limit((int) config('health.memory.match_candidates', 200))
            ->get();
    }

    /**
     * @param  list<string>  $slugs
     */
    private function containment(array $slugs, MealMemory $memory): float
    {
        $remembered = $memory->items
            ->map(static fn (MealMemoryItem $item): string => (string) $item->slug)
            ->all();

        $union = count(array_unique([...$slugs, ...$remembered]));

        if ($union === 0) {
            return 0.0;
        }

        return count(array_intersect($slugs, $remembered)) / $union;
    }
}
