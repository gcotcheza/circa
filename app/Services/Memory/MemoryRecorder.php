<?php

declare(strict_types=1);

namespace App\Services\Memory;

use App\Models\Meal;
use App\Enums\MealStatus;
use App\Models\MealMemory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use App\Services\Rollup\IntakeCalculator;

/**
 * Confirming a meal is what teaches the app. This is the only place
 * that writes `meal_memory`.
 *
 * ONE HOOK, NOT THREE CODE PATHS. A meal gets confirmed three ways —
 * typed or scanned (MealWriter) and photographed
 * (ProposalWriter::confirm) — and step 6 adds a fourth, re-logging from
 * the memory picker. Calling a recorder from each would guarantee the
 * fifth path, whenever it arrives, forgets to. So the trigger is a
 * model event: MealMemoryObserver watches `saved` on Meal, and every
 * path ends by saving it. The observer does NOT record inline —
 * `schedule()` defers to `DB::afterCommit`, which matters twice over:
 * ORDERING (MealWriter writes the meal row, then its items, in one
 * transaction — recording at `saved` would fingerprint a meal with no
 * items yet) and ATOMICITY (a rolled-back transaction must not leave a
 * memory of a meal never logged). Outside a transaction `DB::afterCommit`
 * runs immediately, which is correct for a factory-built meal in a
 * test: no items, nothing recorded.
 *
 * EDIT SEMANTICS — memories are aggregates, and history is not
 * rewritten. `meals.memory_fingerprint` records which memory a meal has
 * already been counted under. On every save:
 *
 *   same fingerprint  -> nothing happens. Editing notes, time or
 *                        portions is not another dinner; `times_logged`
 *                        counts meals eaten, not forms submitted.
 *   new fingerprint   -> the NEW memory is incremented and
 *                        re-snapshotted, and the meal repointed at it.
 *                        The old memory keeps its count.
 *
 * Not decrementing the old one is deliberate: `times_logged` is a claim
 * about the past ("this shape has been eaten n times"), and correcting
 * today's entry doesn't un-eat the other n-1 dinners. Decrementing
 * would also need a cleanup rule for zero, deleting remembered portions
 * the user spent weeks tuning over one mistyped name.
 */
final class MemoryRecorder
{
    /**
     * Meal ids with a flush already queued for the current transaction.
     *
     * @var array<int, true>
     */
    private array $pending = [];

    public function __construct(private readonly MealFingerprint $fingerprints = new MealFingerprint) {}

    /**
     * Record this meal once the write it belongs to has committed.
     */
    public function schedule(Meal $meal): void
    {
        // Only a confirmed meal is a fact — a draft is an upload in
        // flight, a proposal is the model's opinion — and remembering
        // either would teach the app from guesses it exists to make
        // the user check.
        if ($meal->status !== MealStatus::Confirmed) {
            return;
        }

        $id = (int) $meal->id;

        if ($id === 0 || isset($this->pending[$id])) {
            return;
        }

        $this->pending[$id] = true;

        DB::afterCommit(function () use ($id): void {
            unset($this->pending[$id]);

            $this->record($id);
        });
    }

    /**
     * Fingerprint a confirmed meal and fold it into `meal_memory`.
     *
     * Re-read from the database rather than trusting the saved model:
     * this runs after commit, the caller replaced the meal's items
     * along the way, and a stale in-memory relation would snapshot the
     * items from BEFORE the edit that triggered this.
     */
    public function record(Meal|int $meal): ?MealMemory
    {
        $meal = $meal instanceof Meal
            ? $meal->fresh(['items'])
            : Meal::query()->with('items')->find($meal);

        if ($meal === null || $meal->status !== MealStatus::Confirmed) {
            return null;
        }

        $confirmed = ConfirmedMeal::from($meal);

        if ($confirmed === null) {
            return null;
        }

        $fingerprint = $this->fingerprints->forSlugs($confirmed->slugs());

        if ($meal->memory_fingerprint === $fingerprint) {
            // Already counted. See the class docblock: an edit is not a meal.
            return MealMemory::query()->where('fingerprint', $fingerprint)->first();
        }

        return DB::transaction(function () use ($meal, $confirmed, $fingerprint): MealMemory {
            $memory = MealMemory::query()->firstOrCreate(
                ['fingerprint' => $fingerprint],
                ['canonical_name' => $confirmed->canonicalName(), 'times_logged' => 0],
            );

            $memory->times_logged = $memory->times_logged + 1;

            // The most recent human-readable name wins: renaming an item in the
            // meal sheet should rename it in the picker.
            $memory->canonical_name = $confirmed->canonicalName();

            /*
             * `eaten_at`, not `now()`: recency means "when did I last
             * eat this" (what frecency decay asks), so logging Sunday's
             * dinner on Tuesday shouldn't look fresher than Monday's.
             * `max()` so back-filling an old day can't rewind a memory
             * eaten since.
             */
            $eatenAt = CarbonImmutable::parse($meal->eaten_at);

            if ($memory->last_logged_at === null || $eatenAt->greaterThan($memory->last_logged_at)) {
                $memory->last_logged_at = $eatenAt;
            }

            $memory->save();

            $this->snapshotItems($memory, $confirmed);

            /*
             * Quietly, without touching `updated_at`: this is
             * bookkeeping about the meal, not a change to it. A normal
             * save would re-fire `saved`, land back in this recorder,
             * and queue a rebuild of a day whose intake hasn't moved.
             */
            Meal::query()->whereKey($meal->id)->update(['memory_fingerprint' => $fingerprint]);

            return $memory;
        });
    }

    /**
     * Replace the remembered items with this meal's snapshot.
     *
     * Densities are REPLACED, not merged: the most recent confirmation
     * is the user's latest, best statement about what this food
     * contains, and a barcode scan that tightened "150-190 kcal/100 g"
     * to "168" shouldn't be dragged back out by an average with the
     * guess it replaced.
     *
     * `typical_portion_g` is the exception: a RUNNING MEAN over this
     * fingerprint's logs, since the column answers "how much of this
     * do I usually have" and one hungry Tuesday should move that by a
     * fraction, not redefine it.
     */
    private function snapshotItems(MealMemory $memory, ConfirmedMeal $confirmed): void
    {
        /** @var array<string, float> $previous slug => remembered portion */
        $previous = $memory->items()->pluck('typical_portion_g', 'slug')
            ->map(static fn (mixed $portion): float => (float) $portion)
            ->all();

        $memory->items()->delete();

        $logs = max(1, $memory->times_logged);

        foreach ($confirmed->items as $item) {
            $typical = isset($previous[$item->slug])
                // Incremental mean: mean_n = mean_(n-1) + (x - mean_(n-1)) / n.
                // Exact, and needs no history table to compute.
                ? $previous[$item->slug] + ($item->portionG - $previous[$item->slug]) / $logs
                : $item->portionG;

            $row = [
                'name'              => $item->name,
                'slug'              => $item->slug,
                'typical_portion_g' => round($typical, 3),
            ];

            foreach (IntakeCalculator::NUTRIENTS as $nutrient) {
                $row[$nutrient.'_per_100g_min'] = round($item->density($nutrient, 'min'), 3);
                $row[$nutrient.'_per_100g_max'] = round($item->density($nutrient, 'max'), 3);
            }

            $memory->items()->create($row);
        }
    }
}
