<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StressBand;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\StressDailyFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One day's stress score, 1-99, higher meaning less stress. Fully derived
 * from `health_metrics`; safe to delete and rebuild.
 *
 * Keyed on `local_date` like `daily_summaries`: the generated columns on
 * `health_metrics` and `meals` produce that value, so joins never depend on
 * PHP agreeing with Postgres about where midnight is.
 *
 * @property CarbonImmutable $local_date
 * @property int $score
 * @property StressBand $band
 * @property numeric-string $z
 * @property numeric-string $hrv_ms
 * @property numeric-string $baseline_hrv_ms
 * @property numeric-string $baseline_spread_log
 * @property int $samples
 * @property numeric-string $covered_hours
 * @property int $baseline_days
 * @property string $confidence
 * @property string $method_version
 * @property CarbonImmutable $computed_at
 */
final class StressDaily extends Model
{
    /** @use HasFactory<StressDailyFactory> */
    use HasFactory;

    protected $table = 'stress_daily';

    protected $primaryKey = 'local_date';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'local_date'    => 'immutable_date',
            'computed_at'   => 'immutable_datetime',
            'band'          => StressBand::class,
            'score'         => 'integer',
            'samples'       => 'integer',
            'baseline_days' => 'integer',
        ];
    }

    /**
     * The day this score is about.
     *
     * Forward hook for the health report: `StressDaily::with('summary')`
     * puts stress beside intake, burn and weight without a hand-written
     * join. A BelongsTo on a shared key rather than a foreign key, since
     * neither table owns the other and a day can have one without the other.
     *
     * @return BelongsTo<DailySummary, $this>
     */
    public function summary(): BelongsTo
    {
        return $this->belongsTo(DailySummary::class, 'local_date', 'local_date');
    }

    /**
     * @param  Builder<StressDaily>  $query
     */
    public function scopeBetween(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('local_date', [$from, $to])->orderBy('local_date');
    }

    /**
     * Days at or below a band floor — "show me the bad days".
     *
     * @param  Builder<StressDaily>  $query
     */
    public function scopeAtOrBelow(Builder $query, StressBand $band): void
    {
        $query->where('score', '<=', $band->ceiling());
    }

    /**
     * Whether the number is thin. Stored, not recomputed, so the UI and a
     * later report can't disagree on which days to caveat.
     */
    public function isThin(): bool
    {
        return $this->confidence === 'low';
    }
}
