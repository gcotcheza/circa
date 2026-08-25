<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\TdeeEstimateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One TDEE estimate for one rolling window, as a range.
 *
 * @property int $id
 * @property CarbonImmutable $window_start
 * @property CarbonImmutable $window_end
 * @property numeric-string $tdee_min
 * @property numeric-string $tdee_mid
 * @property numeric-string $tdee_max
 * @property numeric-string $intake_mean_kcal
 * @property numeric-string $weight_slope_kg_per_day
 * @property int $n_days
 * @property int $n_weighins
 * @property string $method_version
 * @property CarbonImmutable $computed_at
 */
final class TdeeEstimate extends Model
{
    /** @use HasFactory<TdeeEstimateFactory> */
    use HasFactory;

    /**
     * The gate, as shipped. `config('health.tdee.min_complete_days')` and
     * `min_weighins` are the source of truth; these are their defaults,
     * kept as constants so a factory/test can reference them without
     * booting config.
     */

    /** Minimum complete-log days before an estimate is shown at all. */
    public const MIN_DAYS = 14;

    /** Minimum weigh-ins in the window; the slope is meaningless without them. */
    public const MIN_WEIGHINS = 8;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'window_start' => 'immutable_date',
            'window_end'   => 'immutable_date',
            'computed_at'  => 'immutable_datetime',
            'n_days'       => 'integer',
            'n_weighins'   => 'integer',
        ];
    }

    /**
     * Whether this estimate clears the gate. Below it, the UI says
     * "collecting data" — four weigh-ins is noise wearing a decimal point.
     */
    public function meetsGate(): bool
    {
        return $this->n_days >= (int) config('health.tdee.min_complete_days', self::MIN_DAYS)
            && $this->n_weighins >= (int) config('health.tdee.min_weighins', self::MIN_WEIGHINS);
    }

    /**
     * The value the equation produced — a stored column, NOT (min + max) / 2.
     * The range is asymmetric (the intake band it inherits is), so that
     * mean differs from the estimate and would move the answer by tens of
     * kcal for no explainable reason.
     */
    public function midpoint(): float
    {
        return (float) $this->tdee_mid;
    }

    /**
     * @param  Builder<TdeeEstimate>  $query
     */
    public function scopeLatestFirst(Builder $query): void
    {
        $query->orderByDesc('window_end')->orderByDesc('computed_at');
    }
}
