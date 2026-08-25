<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\WorkoutFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One training session, from HealthKit, keyed on its own UUID — a LABEL
 * OVER TIME. Its energy is already inside the day's active energy (the
 * Watch recorded it whether or not a workout was running), so nothing may
 * ever add `active_kcal` from here into an expenditure total. See the
 * migration for the full argument.
 *
 * @property int $id
 * @property string $apple_uuid
 * @property string $type
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable $ended_at
 * @property numeric-string $duration_s
 * @property numeric-string|null $distance_km
 * @property numeric-string|null $active_kcal
 * @property int|null $avg_hr
 * @property int|null $max_hr
 * @property int|null $step_count
 * @property numeric-string|null $elevation_up_m
 * @property bool|null $is_indoor
 * @property numeric-string|null $intensity
 * @property bool $is_implausible
 * @property int $device_utc_offset_minutes
 * @property array<string, mixed> $extras
 * @property CarbonImmutable $ingested_at
 * @property CarbonImmutable $local_date read-only, database-generated
 */
final class Workout extends Model
{
    /** @use HasFactory<WorkoutFactory> */
    use HasFactory;

    /**
     * `local_date` is a Postgres STORED GENERATED column. Postgres rejects any
     * write to it, so it is guarded to turn a silent bug into an obvious one.
     */
    protected $guarded = ['local_date'];

    /** `ingested_at` is the only timestamp; there is no created_at/updated_at. */
    public $timestamps = false;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Immutable: a session is a fact about the past.
            'started_at'     => 'immutable_datetime',
            'ended_at'       => 'immutable_datetime',
            'ingested_at'    => 'immutable_datetime',
            'local_date'     => 'immutable_date',
            'is_indoor'      => 'boolean',
            'is_implausible' => 'boolean',
            'extras'         => 'array',
            // Decimals deliberately NOT cast: `numeric` columns stay strings
            // end to end, as `health_metrics.value` does, since binary
            // floats would make the writer's IS DISTINCT FROM guard — and
            // the idempotence proof — subtly wrong.
            'avg_hr'                    => 'integer',
            'max_hr'                    => 'integer',
            'step_count'                => 'integer',
            'device_utc_offset_minutes' => 'integer',
        ];
    }

    /** Minutes, the unit every reader of this table actually wants. */
    public function durationMinutes(): float
    {
        return (float) $this->duration_s / 60;
    }

    /**
     * @param  Builder<Workout>  $query
     */
    public function scopeOnLocalDate(Builder $query, string $date): void
    {
        $query->where('local_date', $date);
    }

    /**
     * Sessions that are sessions. Every aggregate over training uses this:
     * the four-day hike is a real row and stays visible on its day, but a
     * total including it would be wrong by a factor of twenty. See
     * config/health.php 'workouts'.
     *
     * @param  Builder<Workout>  $query
     */
    public function scopePlausible(Builder $query): void
    {
        $query->where('is_implausible', false);
    }
}
