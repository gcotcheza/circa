<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\FoodProductFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A cached Open Food Facts product, keyed by its barcode.
 *
 * @property string $barcode
 * @property string $name
 * @property string|null $brand
 * @property numeric-string|null $kcal_per_100g
 * @property numeric-string|null $protein_per_100g
 * @property numeric-string|null $carbs_per_100g
 * @property numeric-string|null $fat_per_100g
 * @property array<string, mixed>|null $raw
 * @property CarbonImmutable $fetched_at
 */
final class FoodProduct extends Model
{
    /** @use HasFactory<FoodProductFactory> */
    use HasFactory;

    protected $primaryKey = 'barcode';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            // jsonb ↔ PHP array. Fine to re-serialise here (unlike
            // raw_ingest_payloads) — a cached remote document, not evidence.
            'raw'        => 'array',
            'fetched_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<MealItem, $this> */
    public function mealItems(): HasMany
    {
        return $this->hasMany(MealItem::class, 'food_product_id', 'barcode');
    }
}
