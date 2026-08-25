<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\SleepSessionFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One night, from one source.
 *
 * @property int $id
 * @property CarbonImmutable $night_date
 * @property int $source_id
 * @property-read Source $source the device: `source_id` is NOT NULL behind a restricting foreign key, so the row is always there
 * @property CarbonImmutable|null $in_bed_start
 * @property CarbonImmutable|null $in_bed_end
 * @property CarbonImmutable|null $sleep_start
 * @property CarbonImmutable|null $sleep_end
 * @property numeric-string|null $rem_minutes
 * @property numeric-string|null $core_minutes
 * @property numeric-string|null $deep_minutes
 * @property numeric-string|null $awake_minutes
 * @property numeric-string|null $asleep_minutes
 * @property numeric-string|null $in_bed_minutes
 * @property numeric-string|null $total_sleep_minutes
 */
final class SleepSession extends Model
{
    /** @use HasFactory<SleepSessionFactory> */
    use HasFactory;

    protected $guarded = [];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            // A date, not a timestamp — HAE's night key; a timezone shift
            // would move nights around.
            'night_date'                => 'immutable_date',
            'in_bed_start'              => 'immutable_datetime',
            'in_bed_end'                => 'immutable_datetime',
            'sleep_start'               => 'immutable_datetime',
            'sleep_end'                 => 'immutable_datetime',
            'ingested_at'               => 'immutable_datetime',
            'device_utc_offset_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Source, $this> */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Source::class);
    }

    /**
     * Hours asleep, the number a human actually wants.
     *
     * Prefers HAE's own `totalSleep` (rem + core + deep, awake excluded —
     * verified against captured payloads), falling back to summing the
     * stages when it's absent.
     */
    public function totalSleepHours(): ?float
    {
        if ($this->total_sleep_minutes !== null) {
            return (float) $this->total_sleep_minutes / 60;
        }

        $stages = array_filter(
            [$this->rem_minutes, $this->core_minutes, $this->deep_minutes],
            static fn (mixed $v): bool => $v !== null
        );

        if ($stages === []) {
            return null;
        }

        return array_sum(array_map('floatval', $stages)) / 60;
    }
}
