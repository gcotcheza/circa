<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Database\Factories\SupplementNutrientFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One printed line of a supplement-facts panel.
 *
 * `amount` is deliberately NOT cast to float: the review screen shows the
 * user their own label back to proof-read against the bottle, and 0.0125
 * rendering as 0.012500000000000001 via a binary float would be the app
 * introducing an error into the one number it promised to copy exactly.
 *
 * @property int $id
 * @property int $supplement_id
 * @property string $nutrient as printed, in the label's own language
 * @property numeric-string|null $amount
 * @property string|null $unit as printed — "mg", "µg", "miljard KVE"
 * @property int $position
 */
final class SupplementNutrient extends Model
{
    /** @use HasFactory<SupplementNutrientFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Supplement, $this> */
    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }
}
