<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Enums\MetricAggregation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\HealthMetricFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One scalar datapoint for one bucket from one source.
 *
 * @property int $id
 * @property string $metric
 * @property MetricAggregation $aggregation
 * @property string $period
 * @property numeric-string $value
 * @property string $unit
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $ended_at
 * @property int $device_utc_offset_minutes
 * @property int $source_id
 * @property CarbonImmutable $ingested_at
 * @property CarbonImmutable $local_date read-only, database-generated
 */
final class HealthMetric extends Model
{
    /** @use HasFactory<HealthMetricFactory> */
    use HasFactory;

    /**
     * `local_date` is a Postgres STORED GENERATED column. Postgres rejects any
     * write to it, so it is guarded to turn a silent bug into an obvious one.
     */
    protected $guarded = ['local_date'];

    /** `ingested_at` is the only timestamp; there is no created_at/updated_at. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'aggregation' => MetricAggregation::class,
            // Immutable: a datapoint's bucket is a fact about the past —
            // ->addHour() should return a new value, never rewrite the
            // row's identity.
            'started_at'  => 'immutable_datetime',
            'ended_at'    => 'immutable_datetime',
            'ingested_at' => 'immutable_datetime',
            'local_date'  => 'immutable_date',
            // NOT cast to float — `value` is numeric(16,6) and stays a
            // string end to end, since binary floats would make GREATEST()
            // upserts and equality comparisons subtly wrong.
            'device_utc_offset_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /** True when this row is a point sample rather than a bucket. */
    public function isInstant(): bool
    {
        return $this->aggregation === MetricAggregation::Instant;
    }

    /**
     * @param  Builder<HealthMetric>  $query
     */
    public function scopeMetric(Builder $query, string $metric): void
    {
        $query->where('metric', $metric);
    }

    /**
     * @param  Builder<HealthMetric>  $query
     */
    public function scopeOnLocalDate(Builder $query, string $date): void
    {
        $query->where('local_date', $date);
    }
}
