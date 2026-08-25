<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\MealItemFactory;
use Illuminate\Database\Eloquent\Model;
use App\Services\Meals\ConsumptionShare;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One food in one meal, stored as per-100g density ranges.
 *
 * @property int $id
 * @property int $meal_id
 * @property int|null $meal_photo_id which plate this came off; NULL = typed, scanned, or estimated from a description
 * @property string $name
 * @property string|null $slug
 * @property numeric-string $portion_g_min what was EATEN: portion_full × share
 * @property numeric-string $portion_g_max
 * @property numeric-string $portion_full_g_min what was on the plate, before any share was applied
 * @property numeric-string $portion_full_g_max
 * @property numeric-string $share_fraction 0.01–1.0; 1.0 is "all of it", which is almost every row
 * @property numeric-string $kcal_per_100g_min
 * @property numeric-string $kcal_per_100g_max
 * @property numeric-string $protein_per_100g_min
 * @property numeric-string $protein_per_100g_max
 * @property numeric-string $carbs_per_100g_min
 * @property numeric-string $carbs_per_100g_max
 * @property numeric-string $fat_per_100g_min
 * @property numeric-string $fat_per_100g_max
 * @property numeric-string|null $confidence
 * @property CarbonImmutable|null $confirmed_at
 * @property string|null $food_product_id
 * @property bool $memory_adjusted true when meal_memory tightened what vision proposed
 *
 * @phpstan-type MealItemRow array<'name'|'slug'|'meal_photo_id'|'portion_g_min'|'portion_g_max'|'portion_full_g_min'|'portion_full_g_max'|'share_fraction'|'kcal_per_100g_min'|'kcal_per_100g_max'|'protein_per_100g_min'|'protein_per_100g_max'|'carbs_per_100g_min'|'carbs_per_100g_max'|'fat_per_100g_min'|'fat_per_100g_max'|'confidence'|'confirmed_at'|'food_product_id'|'memory_adjusted', mixed>
 *   A row on its way INTO this table, as the five writers build one.
 *   Keys, not values: the columns are typed above and the values arrive
 *   from five different shapes (typed sheet, proposal, memory, re-log,
 *   reprojection), but every one of them is writing THIS table — so a
 *   misspelled column is a build failure here rather than a Postgres
 *   error at the end of a meal.
 */
final class MealItem extends Model
{
    /** @use HasFactory<MealItemFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'confirmed_at'    => 'immutable_datetime',
            'memory_adjusted' => 'boolean',
        ];
    }

    /**
     * The shared-plate invariant, enforced where it cannot be forgotten.
     *
     * `portion_g_* = portion_full_g_* × share_fraction` must hold on every
     * row, and five different paths write one (proposal writer, manual
     * writer, `vision:reproject`, the memory picker's re-log, factories) —
     * calling ConsumptionShare from each would mean five chances to forget,
     * invisibly: the row still looks like a row, and the day's total is
     * quietly wrong.
     *
     * `ConsumptionShare::apply()` is IDEMPOTENT (it derives the eaten
     * portion from the unscaled one, never the reverse), so running it on
     * every insert is safe regardless of what the caller already did — a
     * share the caller set is applied once; nothing set defaults to share
     * 1; a repeat that copies an already-scaled row gets the same numbers
     * back, since the unscaled portion came with it.
     *
     * `creating`, not `saving`: portions are never edited in place. Every
     * path that changes a meal deletes its items and writes new ones,
     * since proposed items have no identity the client holds onto — the
     * updates that DO happen (retention repointing a path, a removed photo
     * nulling provenance) must not have a portion recomputed underneath
     * them.
     */
    protected static function booted(): void
    {
        self::creating(function (self $item): void {
            $item->forceFill(ConsumptionShare::of($item->share_fraction)->apply($item->getAttributes()));
        });
    }

    /**
     * The plate this came off, before the share was taken out of it — both
     * the review and edit sheets need it, since changing "I ate ½" to "all
     * of it" must rescale from the ESTIMATE, not the stored half, or the
     * number shrinks with every change of mind.
     *
     * @return array{min: float, max: float}
     */
    public function fullRange(string $nutrient): array
    {
        $min = $nutrient === 'kcal' ? 'kcal_per_100g_min' : "{$nutrient}_per_100g_min";
        $max = $nutrient === 'kcal' ? 'kcal_per_100g_max' : "{$nutrient}_per_100g_max";

        return [
            'min' => (float) $this->portion_full_g_min * (float) $this->{$min} / 100,
            'max' => (float) $this->portion_full_g_max * (float) $this->{$max} / 100,
        ];
    }

    /** True when the user said they only had part of this. */
    public function isShared(): bool
    {
        return (float) $this->share_fraction < 1.0;
    }

    /** @return BelongsTo<Meal, $this> */
    public function meal(): BelongsTo
    {
        return $this->belongsTo(Meal::class);
    }

    /**
     * The plate this item came off, when it came off one — NULL, the common
     * case, means "not from a photograph" (typed, scanned, or estimated),
     * and is what protects those items when a plate is re-analysed or
     * removed.
     *
     * @return BelongsTo<MealPhoto, $this>
     */
    public function mealPhoto(): BelongsTo
    {
        return $this->belongsTo(MealPhoto::class);
    }

    /** @return BelongsTo<FoodProduct, $this> */
    public function foodProduct(): BelongsTo
    {
        return $this->belongsTo(FoodProduct::class, 'food_product_id', 'barcode');
    }

    /**
     * Absolute kcal range for the stored portion — derived, never stored,
     * which is the whole point of keeping densities: editing the portion
     * changes the answer without rewriting the row.
     *
     * @return array{min: float, max: float}
     */
    public function kcalRange(): array
    {
        return [
            'min' => (float) $this->portion_g_min * (float) $this->kcal_per_100g_min / 100,
            'max' => (float) $this->portion_g_max * (float) $this->kcal_per_100g_max / 100,
        ];
    }

    /**
     * @return array{min: float, max: float}
     */
    public function macroRange(string $macro): array
    {
        $min = "{$macro}_per_100g_min";
        $max = "{$macro}_per_100g_max";

        return [
            'min' => (float) $this->portion_g_min * (float) $this->{$min} / 100,
            'max' => (float) $this->portion_g_max * (float) $this->{$max} / 100,
        ];
    }
}
