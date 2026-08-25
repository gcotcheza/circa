<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Database\Factories\MealMemoryItemFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One remembered food inside a remembered meal.
 *
 * Densities carry MealItem's per-100g min/max shape so a re-log pre-fills
 * straight across; `typical_portion_g` is one number since a habitual
 * portion is a memory, not an uncertainty band.
 *
 * @property int $id
 * @property int $meal_memory_id
 * @property string $name
 * @property string $slug
 * @property numeric-string $typical_portion_g
 */
final class MealMemoryItem extends Model
{
    /** @use HasFactory<MealMemoryItemFactory> */
    use HasFactory;

    protected $table = 'meal_memory_items';

    protected $guarded = [];

    /** @return BelongsTo<MealMemory, $this> */
    public function memory(): BelongsTo
    {
        return $this->belongsTo(MealMemory::class, 'meal_memory_id');
    }
}
