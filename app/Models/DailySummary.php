<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\DailySummaryFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One local calendar day, fully derived — safe to delete and rebuild.
 *
 * Keyed on `local_date`, the same value the generated columns on
 * `health_metrics` and `meals` produce, so joins never depend on PHP and
 * every writer agrees on the day boundary.
 *
 * @property CarbonImmutable $local_date
 * @property numeric-string|null $kcal_in_min
 * @property numeric-string|null $kcal_in_mid
 * @property numeric-string|null $kcal_in_max
 * @property numeric-string|null $active_kcal
 * @property numeric-string|null $resting_kcal
 * @property numeric-string|null $weight_kg
 * @property bool $is_complete_log
 * @property bool $has_full_metric_coverage
 * @property bool $active_kcal_is_partial
 * @property CarbonImmutable|null $rebuilt_at
 */
final class DailySummary extends Model
{
    /** @use HasFactory<DailySummaryFactory> */
    use HasFactory;

    protected $primaryKey = 'local_date';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'local_date'               => 'immutable_date',
            'rebuilt_at'               => 'immutable_datetime',
            'is_complete_log'          => 'boolean',
            'has_full_metric_coverage' => 'boolean',
            'active_kcal_is_partial'   => 'boolean',
        ];
    }

    /** @return HasMany<Meal, $this> */
    public function meals(): HasMany
    {
        return $this->hasMany(Meal::class, 'local_date', 'local_date');
    }

    /** @return HasMany<HealthMetric, $this> */
    public function healthMetrics(): HasMany
    {
        return $this->hasMany(HealthMetric::class, 'local_date', 'local_date');
    }

    /**
     * Days the TDEE estimator may average intake over — a breakfast-only day
     * has no usable intake number, not just a low one, and mustn't skew the
     * mean.
     *
     * @param  Builder<DailySummary>  $query
     */
    public function scopeCompleteLogs(Builder $query): void
    {
        $query->where('is_complete_log', true);
    }

    /** Total burn, when both halves are known. */
    public function totalKcalOut(): ?float
    {
        if ($this->active_kcal === null || $this->resting_kcal === null) {
            return null;
        }

        return (float) $this->active_kcal + (float) $this->resting_kcal;
    }

    /** Whether the burn figure can be shown without a caveat attached. */
    public function expenditureIsTrustworthy(): bool
    {
        return $this->has_full_metric_coverage && ! $this->active_kcal_is_partial;
    }
}
