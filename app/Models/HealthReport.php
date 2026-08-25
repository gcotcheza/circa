<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use App\Enums\HealthReportKind;
use App\Enums\HealthReportStatus;
use App\Services\Report\ReportFocus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\HealthReportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One written report over one range of days.
 *
 * @property int $id
 * @property CarbonImmutable $range_start
 * @property CarbonImmutable $range_end
 * @property HealthReportKind $kind
 * @property HealthReportStatus $status
 * @property list<string>|null $focus_areas the chips this report was asked for
 * @property string|null $focus_text the reader's own question, verbatim
 * @property string $idempotency_key
 * @property array<string, mixed>|null $input_snapshot the facts the model was handed
 * @property array<string, mixed>|null $output the structured answer, verbatim
 * @property string|null $model
 * @property string|null $prompt_version
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property-read numeric-string|null $cost_usd what this report cost, frozen at generation
 * @property-write float|null $cost_usd read back as a decimal string; ReportCost hands it a float
 * @property int|null $latency_ms
 * @property string|null $error
 * @property CarbonImmutable|null $generated_at
 */
final class HealthReport extends Model
{
    /** @use HasFactory<HealthReportFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'range_start'    => 'immutable_date',
            'range_end'      => 'immutable_date',
            'kind'           => HealthReportKind::class,
            'status'         => HealthReportStatus::class,
            'focus_areas'    => 'array',
            'input_snapshot' => 'array',
            'output'         => 'array',
            'input_tokens'   => 'integer',
            'output_tokens'  => 'integer',
            'latency_ms'     => 'integer',
            'generated_at'   => 'immutable_datetime',
            'created_at'     => 'immutable_datetime',
            'updated_at'     => 'immutable_datetime',
        ];
    }

    /**
     * Local days the range covers, inclusive — computed, not stored, since
     * it's derived from two columns that can't disagree, unlike a third
     * column that could drift.
     */
    public function days(): int
    {
        return (int) $this->range_start->diffInDays($this->range_end) + 1;
    }

    /**
     * "3–9 Aug" / "28 Jul – 3 Aug" — the one string the list and header both
     * print, so a report isn't labelled two ways on two screens.
     */
    public function rangeLabel(): string
    {
        $start = $this->range_start;
        $end = $this->range_end;

        if ($start->isSameDay($end)) {
            return $start->isoFormat('D MMM YYYY');
        }

        if ($start->year !== $end->year) {
            return $start->isoFormat('D MMM YYYY').' – '.$end->isoFormat('D MMM YYYY');
        }

        return $start->month === $end->month
            ? $start->isoFormat('D').'–'.$end->isoFormat('D MMM YYYY')
            : $start->isoFormat('D MMM').' – '.$end->isoFormat('D MMM YYYY');
    }

    /**
     * What this report was asked to be about.
     *
     * Read from the two columns, not the snapshot: focus decides which
     * blocks get assembled, so it's needed BEFORE the snapshot exists. A
     * pre-feature row has neither, reading back as `everything()` — correct.
     */
    public function focus(): ReportFocus
    {
        return ReportFocus::fromStored($this->focus_areas, $this->focus_text);
    }

    /** Still moving: the UI polls on this, and so does the one-at-a-time guard. */
    public function isPending(): bool
    {
        return $this->status->isPending();
    }

    /**
     * Newest first — the only order the list is ever read in.
     *
     * @param  Builder<self>  $query
     */
    public function scopeNewest(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Claimed or in flight.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUnfinished(Builder $query): void
    {
        $query->whereIn('status', [
            HealthReportStatus::Pending->value,
            HealthReportStatus::Generating->value,
        ]);
    }
}
