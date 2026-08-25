<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Database\Factories\SupplementFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * One bottle on the shelf.
 *
 * Reference data, not a log: created once from a label photo, then only
 * reordered, renamed or switched off. `supplement_intakes` is what changes
 * daily, one row per tap.
 *
 * @property int $id
 * @property string $name
 * @property string|null $brand
 * @property string|null $serving_text the serving the label states its figures for, verbatim
 * @property int $units_per_day how many of THAT serving are taken in a day
 * @property string|null $photo_path `labels/originals/…` on the meal-photos disk
 * @property string|null $data_source where the figures came from — see the migration
 * @property string|null $source_url
 * @property string|null $notes what the transcription could not settle
 * @property int $position
 * @property bool $active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class Supplement extends Model
{
    /** @use HasFactory<SupplementFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'position'      => 'integer',
            'units_per_day' => 'integer',
            'active'        => 'boolean',
            'created_at'    => 'immutable_datetime',
            'updated_at'    => 'immutable_datetime',
        ];
    }

    /**
     * What a day of this supplement contains, per label line: the printed
     * figure times servings taken. Computed for DISPLAY and never stored —
     * a stored total is a third number that must agree with the other two
     * forever, and wouldn't the first time `units_per_day` was edited.
     * `null` amounts stay null rather than acquiring a total by ×2.
     *
     * @return list<array{nutrient: string, amount: float|null, unit: string|null, perDay: float|null}>
     */
    public function dailyNutrients(): array
    {
        return array_values($this->nutrients->map(function (SupplementNutrient $line): array {
            $amount = $line->amount === null ? null : (float) $line->amount;

            return [
                'nutrient' => $line->nutrient,
                'amount'   => $amount,
                'unit'     => $line->unit,
                'perDay'   => $amount === null ? null : $amount * $this->units_per_day,
            ];
        })->all());
    }

    /**
     * The label's lines, in the label's own order.
     *
     * Ordered explicitly, like every other ordered relation here: unordered
     * means Postgres heap order, which moves under any UPDATE — a panel
     * that reshuffles when the user renames one line can't be proof-read.
     *
     * @return HasMany<SupplementNutrient, $this>
     */
    public function nutrients(): HasMany
    {
        return $this->hasMany(SupplementNutrient::class)->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<SupplementIntake, $this> */
    public function intakes(): HasMany
    {
        return $this->hasMany(SupplementIntake::class);
    }

    /**
     * The ones the day card draws, in the order they are taken.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true)->orderBy('position')->orderBy('id');
    }

    /**
     * The local day this supplement started existing.
     *
     * Adherence is scored against it: a bottle bought Thursday must not
     * make the preceding weeks read as missed doses. See SupplementAdherence.
     */
    public function startedOn(): string
    {
        return $this->created_at
            ->setTimezone((string) config('health.timezone'))
            ->toDateString();
    }
}
