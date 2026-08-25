<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use App\Services\Memory\MealFingerprint;
use Database\Factories\MealMemoryFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * A meal shape seen before, identified by its item-name set.
 *
 * @property int $id
 * @property string $fingerprint
 * @property string $canonical_name
 * @property int $times_logged
 * @property CarbonImmutable|null $last_logged_at
 */
final class MealMemory extends Model
{
    /** @use HasFactory<MealMemoryFactory> */
    use HasFactory;

    protected $table = 'meal_memory';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'last_logged_at' => 'immutable_datetime',
            'times_logged'   => 'integer',
        ];
    }

    /**
     * The remembered food, in the order MemoryRecorder wrote it.
     *
     * That order is `ConfirmedMeal`'s, now the meal's own item order (plate,
     * then row) — so the picker lists a remembered dinner in the same order
     * the day card lists it from. Unordered would be Postgres heap order,
     * which moves under any UPDATE and would disagree with `canonical_name`,
     * built from the same list at write time and frozen in a column.
     *
     * @return HasMany<MealMemoryItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(MealMemoryItem::class, 'meal_memory_id')
            ->orderBy('meal_memory_items.id');
    }

    /**
     * Fingerprint a set of item names.
     *
     * Slugify, de-duplicate, sort, join, hash. Sorting makes it a set rather
     * than a sequence — the same three foods in a different order are the
     * same meal, and vision doesn't return them in a stable one.
     *
     * The rule lives in App\Services\Memory\MealFingerprint (step 6, which
     * added the unicode fallback and SORT_STRING guarantee); this just
     * delegates rather than keeping a second copy — two definitions of "same
     * meal" would drift silently, surfacing as a memory that stops matching
     * itself.
     *
     * @param  iterable<string>  $names
     */
    public static function fingerprintFor(iterable $names): string
    {
        return (new MealFingerprint)->forNames($names);
    }

    /**
     * Frequent first, then recent — the order the "log again" list is drawn in.
     *
     * @param  Builder<MealMemory>  $query
     */
    public function scopeMostUsed(Builder $query): void
    {
        $query->orderByDesc('times_logged')->orderByDesc('last_logged_at');
    }
}
