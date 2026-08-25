<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Enums\IngestRunStatus;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\IngestRunFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One processed POST body. Append-only.
 *
 * @property int $id
 * @property int|null $raw_ingest_payload_id
 * @property string $session_id
 * @property string $body_sha256
 * @property string|null $automation_name
 * @property string|null $automation_id
 * @property string|null $automation_aggregation
 * @property string|null $automation_period
 * @property int $row_count
 * @property IngestRunStatus $status
 * @property string|null $error
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 */
final class IngestRun extends Model
{
    /** @use HasFactory<IngestRunFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'       => IngestRunStatus::class,
            'received_at'  => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'row_count'    => 'integer',
        ];
    }

    /** @return BelongsTo<RawIngestPayload, $this> */
    public function rawIngestPayload(): BelongsTo
    {
        return $this->belongsTo(RawIngestPayload::class);
    }

    /**
     * Runs that parsed cleanly and produced nothing — the gap signal.
     *
     * @param  Builder<IngestRun>  $query
     */
    public function scopeProducedNothing(Builder $query): void
    {
        $query->where('status', IngestRunStatus::Empty);
    }

    /**
     * @param  Builder<IngestRun>  $query
     */
    public function scopeSucceeded(Builder $query): void
    {
        $query->whereIn('status', [IngestRunStatus::Completed, IngestRunStatus::Empty]);
    }
}
