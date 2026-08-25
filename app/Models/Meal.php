<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MealType;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use Carbon\CarbonImmutable;
use Database\Factories\MealFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One eating event.
 *
 * @property int $id
 * @property string $uuid
 * @property MealStatus $status
 * @property MealSource $source
 * @property CarbonImmutable $eaten_at
 * @property MealType|null $meal_type
 * @property string|null $photo_path
 *
 * @deprecated Superseded by `meal_photos` (a meal has several plates, not one
 *             photo). Unread; kept so the migration that moved its contents
 *             stays reversible, dropping it later.
 *
 * @property string|null $notes what the USER wrote; only a request they sent changes it
 * @property string|null $model_notes what the MODEL said about its own answer; read-only commentary
 * @property CarbonImmutable $local_date read-only, database-generated
 * @property string|null $memory_fingerprint which meal_memory row this log was counted under
 * @property int|null $memory_match_id the memory a vision proposal was matched against
 * @property-read numeric-string|null $memory_match_score 1.000 exact, else containment
 * @property-write float|null $memory_match_score read back as a decimal string; the matcher hands it a float
 */
final class Meal extends Model
{
    /** @use HasFactory<MealFactory> */
    use HasFactory;

    /** `local_date` is a Postgres STORED GENERATED column — see HealthMetric. */
    protected $guarded = ['local_date'];

    /**
     * The client generates the uuid so an offline retry is idempotent; route
     * binding uses it so no internal id is ever exposed to the PWA.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    protected function casts(): array
    {
        return [
            'status'     => MealStatus::class,
            'source'     => MealSource::class,
            'meal_type'  => MealType::class,
            'eaten_at'   => 'immutable_datetime',
            'local_date' => 'immutable_date',
        ];
    }

    /**
     * What was eaten, in the order a human would read it off the table.
     *
     * The order is part of the answer, so it's pinned here rather than left
     * to Postgres heap order (whatever the last sequential scan returned),
     * which is stable until any UPDATE rewrites a row at the end of the
     * heap — the photo-provenance relink (fix/photo-provenance) did exactly
     * that to a few thousand rows, scrambling a three-course dinner's item
     * order, which the user then re-SAVED from the edit sheet, making the
     * scrambling outlive the query that caused it.
     *
     * Ordered by (1) the POSITION of the plate the item came off — starter,
     * main, pudding, matching photograph and review order — then (2)
     * `meal_items.id`, the order the model listed items and the user
     * confirmed them within one plate. Typed/scanned/text-estimated items
     * have no plate, so the subquery is NULL for them, and Postgres sorts
     * NULLs last, putting "and two beers" after the photographed courses —
     * the right end, since the pictures are the meal and typed lines are
     * additions.
     *
     * Ordered in the RELATION, not at each call site, for the reason
     * `photos()` gives below: one unordered read is one screen that lies,
     * and there are nine of them.
     *
     * @return HasMany<MealItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MealItem::class)
            ->orderBy(
                MealPhoto::query()
                    ->select('position')
                    ->whereColumn('meal_photos.id', 'meal_items.meal_photo_id')
            )
            ->orderBy('meal_items.id');
    }

    /** @return HasMany<VisionRequest, $this> */
    public function visionRequests(): HasMany
    {
        return $this->hasMany(VisionRequest::class);
    }

    /**
     * The plates, in the order they were photographed. Always ordered here,
     * not at each call site: `position` is what "photo 2" means on screen
     * and what the next upload appends after, so one unordered read renders
     * the dessert first. `id` is a tie-break that should never be reached —
     * `meal_photos` has a UNIQUE index on (meal_id, position), so a racing
     * upload duplicating a slot is a constraint violation instead (caught
     * by MealPhotoController::store) — but it's written anyway so this
     * order doesn't silently depend on a migration three directories away.
     *
     * @return HasMany<MealPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(MealPhoto::class)
            ->orderBy('position')
            ->orderBy('meal_photos.id');
    }

    /**
     * Items that a human has actually agreed to. Stopped being cosmetic
     * once a CONFIRMED meal could hold an unconfirmed proposal (dessert
     * photographed after the main course) — "nothing counts toward intake
     * without a tap" is now a per-ITEM claim, and this is it.
     *
     * @return HasMany<MealItem, $this>
     */
    public function confirmedItems(): HasMany
    {
        return $this->items()->whereNotNull('confirmed_at');
    }

    /**
     * The remembered meal this one was matched against as a proposal
     * (step 6) — deliberately not `memory_fingerprint`, which says what
     * this meal turned out to BE once confirmed. This says what the
     * analysis job compared it to, letting the review screen say "seen
     * before, 7x" before the user agrees to anything.
     *
     * @return BelongsTo<MealMemory, $this>
     */
    public function memoryMatch(): BelongsTo
    {
        return $this->belongsTo(MealMemory::class, 'memory_match_id');
    }

    /**
     * The most recent analysis, the one the UI is talking about. A meal
     * accumulates a row per Re-analyze, but "why did this fail?" is always
     * about the last attempt, and loading all of them would make the daily
     * view's query grow with user persistence.
     *
     * @return HasOne<VisionRequest, $this>
     */
    public function latestVisionRequest(): HasOne
    {
        return $this->hasOne(VisionRequest::class)->latestOfMany();
    }

    /**
     * Only confirmed meals count toward intake — nothing is logged without a tap.
     *
     * @param  Builder<Meal>  $query
     */
    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', MealStatus::Confirmed);
    }

    /**
     * @param  Builder<Meal>  $query
     */
    public function scopeOnLocalDate(Builder $query, string $date): void
    {
        $query->where('local_date', $date);
    }
}
