<?php

declare(strict_types=1);

namespace App\Services\Meals;

use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Create, edit and re-log meals.
 *
 * Idempotency is the point (SPEC.md, build order step 3): iOS WebKit has no
 * Background Sync API, so offline logging is an in-page IndexedDB queue
 * flushed when the app comes back to the foreground. A flush that is
 * interrupted retries, and a retry that creates a second dinner is worse
 * than one that fails loudly. The client therefore generates the uuid
 * BEFORE the row exists, and this class treats an already-present uuid as
 * "already logged": it returns the existing meal untouched and appends
 * nothing. `meals.uuid` is UNIQUE, so the guarantee survives two requests
 * racing — the loser catches the constraint violation and re-reads the
 * winner's row rather than retrying its insert.
 *
 * On a duplicate the items are NOT merged, re-created or updated: a retry
 * carries the same payload by definition, so the second request has
 * nothing to add, and treating it as an edit would let a stale offline
 * copy overwrite a correction made in between.
 *
 * Manual entries are `confirmed` from birth. "The model proposes, the user
 * confirms" is about the vision path — a human typing the food IS the
 * confirmation, and leaving these as drafts would keep them out of the
 * intake band they were typed in order to affect.
 *
 * @phpstan-import-type MealItemRow from MealItem
 */
final class MealWriter
{
    /**
     * The columns a re-log copies, named rather than subtracted.
     *
     * `meal_photo_id` is absent and has to be: it points at a row on the
     * SOURCE meal's plate, and the copy is a different meal on a different
     * day with no photographs at all. Carrying it across would leave
     * Tuesday's lunch claiming to have come off Monday's photograph — false
     * on its face, and worse than untidy: `MealPhoto::items()` is a plain
     * hasMany on this column, so removing Monday's plate with "remove its
     * items too" would reach across and delete Tuesday's food. Re-logging a
     * meal is the user saying they ate the same thing again, not that they
     * photographed it again — the copy is a typed item, exactly what a null
     * here means. `confirmed_at` is re-stamped by writeItems(), and `id`,
     * `meal_id` and the timestamps belong to the new row.
     *
     * An allowlist rather than the denylist this was, because it is also
     * the thing that makes the copy a CHECKED write: every name here is a
     * `meal_items` column, so a column added to the table has to be
     * considered rather than riding along unread. An allowlist fails the
     * other way round from a denylist, though — a column left off it is
     * silently LOST rather than silently carried — so RelogColumnsTest
     * asserts this list plus the six the copy must not take against the
     * table itself. `ALTER TABLE ... ADD COLUMN` fails that test until
     * somebody decides which side the new column belongs on. Public for
     * exactly that reason: the list is the contract, not an implementation
     * detail.
     *
     * @var list<'name'|'slug'|'portion_g_min'|'portion_g_max'|'portion_full_g_min'|'portion_full_g_max'|'share_fraction'|'kcal_per_100g_min'|'kcal_per_100g_max'|'protein_per_100g_min'|'protein_per_100g_max'|'carbs_per_100g_min'|'carbs_per_100g_max'|'fat_per_100g_min'|'fat_per_100g_max'|'confidence'|'food_product_id'|'memory_adjusted'>
     */
    public const COPIED = [
        'name',
        'slug',
        'portion_g_min',
        'portion_g_max',
        'portion_full_g_min',
        'portion_full_g_max',
        'share_fraction',
        'kcal_per_100g_min',
        'kcal_per_100g_max',
        'protein_per_100g_min',
        'protein_per_100g_max',
        'carbs_per_100g_min',
        'carbs_per_100g_max',
        'fat_per_100g_min',
        'fat_per_100g_max',
        'confidence',
        'food_product_id',
        'memory_adjusted',
    ];

    /**
     * @param  list<MealItemRow>  $items
     * @return array{meal: Meal, created: bool}
     */
    public function create(
        string $uuid,
        CarbonImmutable $eatenAt,
        ?string $mealType,
        ?string $notes,
        array $items,
    ): array {
        // UTC on the way in. `meals.eaten_at` is timestamptz, but Eloquent
        // serialises Carbon with the connection's plain date format, which
        // carries no offset — a 13:20 Europe/Amsterdam instant would reach
        // Postgres as the bare "13:20:00" and read back as 13:20 UTC,
        // moving `local_date` (generated from this column) around
        // midnight.
        $eatenAt = $eatenAt->utc();

        $existing = Meal::query()->where('uuid', $uuid)->first();

        if ($existing !== null) {
            return ['meal' => $existing, 'created' => false];
        }

        try {
            $meal = DB::transaction(function () use ($uuid, $eatenAt, $mealType, $notes, $items): Meal {
                $meal = Meal::query()->create([
                    'uuid'      => $uuid,
                    'status'    => MealStatus::Confirmed,
                    'source'    => MealSource::Manual,
                    'eaten_at'  => $eatenAt,
                    'meal_type' => $mealType,
                    'notes'     => $notes,
                ]);

                $this->writeItems($meal, $items);

                return $meal;
            });
        } catch (UniqueConstraintViolationException) {
            // Lost a race with another flush of the same queued meal. The
            // winner's row is the answer; this one has nothing to add.
            return ['meal' => Meal::query()->where('uuid', $uuid)->firstOrFail(), 'created' => false];
        }

        return ['meal' => $meal, 'created' => true];
    }

    /**
     * @param  list<MealItemRow>  $items
     */
    public function update(
        Meal $meal,
        CarbonImmutable $eatenAt,
        ?string $mealType,
        ?string $notes,
        array $items,
    ): Meal {
        $eatenAt = $eatenAt->utc();

        return DB::transaction(function () use ($meal, $eatenAt, $mealType, $notes, $items): Meal {
            $meal->update([
                'eaten_at'  => $eatenAt,
                'meal_type' => $mealType,
                'notes'     => $notes,
            ]);

            /*
             * Provenance read before the rows are thrown away. `meal_photo_id`
             * is the one column on an item the edit sheet cannot restate:
             * every other field round-trips through the props, but which
             * PLATE a line came off is not something the form asks about,
             * so it never posts it.
             *
             * Without this the wholesale replace below silently nulled it
             * on every edited meal — not cosmetic, since
             * `MealPhoto::entryState()` reads "has anything been confirmed
             * off this plate" straight off these ids, so a fully-confirmed
             * three-course dinner came back reading as three unreviewed
             * plates. See the relink command for the repair of meals this
             * already happened to.
             */
            $items = $this->withProvenance($meal, $items);

            // Replace rather than diff. Items have no client-side identity
            // — matching the posted list back onto rows by name would
            // silently merge two portions of the same food. Rows are
            // cheap; a wrong merge is not.
            $meal->items()->delete();

            $this->writeItems($meal, $items);

            /*
             * An edit touches the meal, even when only its items changed.
             * `update()` above is a no-op when time, type and notes all came
             * back identical — Eloquent skips a save on a model that isn't
             * dirty, `updated_at` included — which would leave `meals`
             * untouched after swapping the rice for potatoes, and the
             * meal-memory observer (hangs off `saved`,
             * App\Services\Memory\MemoryRecorder) would never hear this meal
             * changed. Recording it on `updated_at` is also just true: the
             * row's meaning changed.
             */
            $meal->touch();

            return $meal->refresh();
        });
    }

    /**
     * Log a previous meal again, on another day.
     *
     * Copies the stored items verbatim, ranges included. Rebuilding them from
     * the display values would flatten a vision-proposed item's range into a
     * point estimate — re-logging something uncertain does not make it certain.
     *
     * @return array{meal: Meal, created: bool}
     */
    public function repeat(Meal $source, string $uuid, CarbonImmutable $eatenAt, ?string $mealType): array
    {
        $items = [];

        foreach ($source->items as $item) {
            $items[] = array_intersect_key($item->getAttributes(), array_flip(self::COPIED));
        }

        return $this->create(
            uuid: $uuid,
            eatenAt: $eatenAt,
            mealType: $mealType ?? $source->meal_type?->value,
            notes: $source->notes,
            items: $items,
        );
    }

    /**
     * The posted items, each wearing the plate its predecessor came off.
     *
     * Matched by slug, the only identity an item has. `update()` replaces
     * rather than diffs because a posted list has no ids, so merging by
     * name would fold two portions of the same food into one — that
     * reasoning is about the NUMBERS. Provenance is a different kind of
     * claim and can be matched where the numbers cannot, because a wrong
     * answer here is cheap and recoverable where a wrong merge is neither:
     * the worst case is two lines of a three-course dinner sharing a slug
     * and swapping plates, both still confirmed, counted, and reading
     * `settled`.
     *
     * FIFO per slug, so a name that genuinely appears twice pairs in a
     * stated order rather than an arbitrary one — the RELATION's order,
     * the same order the props were serialised in and the sheet posted
     * back. The n-th "rice" in the posted list meets the n-th "rice" in
     * the table because both are sorted by plate then row (it used to be
     * `orderBy('id')` against unordered props, which paired by luck). A
     * slug with no stored counterpart is a line added during the edit: no
     * plate, and null is the truth about it.
     *
     * Read BEFORE the delete, and in its own query rather than off
     * `$meal->items` — the relation may have loaded before the edit began,
     * and the rows here must describe the table now.
     *
     * @param  list<MealItemRow>  $items
     * @return list<MealItemRow>
     */
    private function withProvenance(Meal $meal, array $items): array
    {
        /** @var array<string, list<int|null>> $plates */
        $plates = [];

        foreach ($meal->items()->get(['slug', 'meal_photo_id']) as $stored) {
            $plates[(string) $stored->slug][] = $stored->meal_photo_id;
        }

        return array_map(static function (array $item) use (&$plates): array {
            $slug = (string) ($item['slug'] ?? '');

            if ($slug === '' || ($plates[$slug] ?? []) === []) {
                return $item;
            }

            $photoId = array_shift($plates[$slug]);

            // A stored line with no plate hands nothing on. Writing the
            // null explicitly would be the same value, but leaving the key
            // off keeps "says nothing about provenance" distinguishable
            // from "says it had none".
            return $photoId === null ? $item : [...$item, 'meal_photo_id' => $photoId];
        }, $items);
    }

    /**
     * @param  list<MealItemRow>  $items
     */
    private function writeItems(Meal $meal, array $items): void
    {
        $now = CarbonImmutable::now();

        foreach ($items as $item) {
            $row = $meal->items()->make($item);

            // Per-item confirmation exists for the vision flow, where the
            // user accepts some proposals and edits others. A typed item is
            // confirmed the moment it's saved — stamped on the model rather
            // than mixed into the posted attributes, so what this writer
            // decides stays visibly separate from what it was handed.
            $row->confirmed_at = $now;

            $row->save();
        }
    }
}
