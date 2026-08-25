<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Database\Factories\SupplementIntakeFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * "I took this one, on that day."
 *
 * The row IS the fact. There is no `taken` boolean: a false would say
 * nothing that the row's absence doesn't already say, and it would break
 * the unique index's other job — making an undo replayed twice a no-op
 * rather than a resurrection.
 *
 * `local_date` is the day the user was looking at when they tapped;
 * `taken_at` is when the tap happened. See the migration for why those
 * are two columns.
 *
 * @property int $id
 * @property int $supplement_id
 * @property CarbonImmutable $local_date
 * @property CarbonImmutable $taken_at
 */
final class SupplementIntake extends Model
{
    /** @use HasFactory<SupplementIntakeFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'local_date' => 'immutable_date',
            'taken_at'   => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Supplement, $this> */
    public function supplement(): BelongsTo
    {
        return $this->belongsTo(Supplement::class);
    }

    /**
     * Every intake of these supplements over an inclusive local-date window
     * — one bulk read behind adherence on the trends page, the day table
     * and the micronutrient ledger.
     *
     * The two clauses belong together: a window is only ever read for a
     * KNOWN set of bottles, because an intake of something the caller isn't
     * scoring would be counted against a day it holds no expectation for,
     * and a deactivated supplement would keep appearing in a number that
     * says how many evenings went as planned.
     *
     * Grouping is deliberately left to the caller — by date for adherence,
     * by supplement for the ledger — since that is the part that differs.
     *
     * @param  Builder<SupplementIntake>  $query
     * @param  Collection<int, Supplement>  $supplements
     */
    public function scopeForSupplementsInRange(Builder $query, Collection $supplements, string $from, string $to): void
    {
        $query->whereIn('supplement_id', $supplements->pluck('id'))
            ->whereBetween('local_date', [$from, $to]);
    }
}
