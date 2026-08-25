<?php

declare(strict_types=1);

namespace App\Services\Meals;

/**
 * How much of what was on the plate this person actually ate.
 *
 * The model estimates what's ON the plate, never how much the user ate — it
 * can't know, and inviting it to guess would put an invented number where a
 * tap belongs — so the share is applied afterwards, by this class alone.
 * `apply()` derives the eaten portion from the UNSCALED one every time
 * (`portion_g_* = portion_full_g_* × share_fraction`), never reading
 * `portion_g_*` when `portion_full_g_*` is present, which makes it
 * idempotent — run it twice or ten times and the answer doesn't move. That's
 * why it can sit on `MealItem::creating` and cover every write (proposal
 * writer, manual save, `vision:reproject`, `meals.repeat`'s already-scaled
 * copies, the factories) without anybody remembering it. A row with no
 * `portion_full_g_*` at all — every pre-feature caller, every hand-built
 * test item — reads as "this portion IS the estimate," which it was.
 *
 * Deliberately NOT scaled: the DENSITIES. Per 100 g is a fact about the
 * food, not how much was eaten — half a bowl of pho is still pho at the
 * same kcal per 100 g, and scaling densities too would halve the portion
 * AND the density, quartering the calories of a half-eaten plate. Absolute
 * kcal and macros are derived from portion × density by IntakeCalculator,
 * so they follow the portion on their own — the whole reason `meal_items`
 * stores densities rather than totals, paying for itself a second time.
 */
final readonly class ConsumptionShare
{
    /**
     * The floor, and why it's not zero: a share of 0 says "I ate none of
     * it," which isn't a shared plate but a line that shouldn't be in the
     * meal (the review sheet already has a button for that), and it would
     * also make the stored estimate unrecoverable through the UI, since
     * every fraction of a 0 g portion is 0 g. One percent is small enough
     * to cover a taste of someone's dessert and large enough to divide by.
     */
    public const MIN = 0.01;

    /** The columns this class owns, so nothing else has to name them. */
    public const COLUMNS = ['share_fraction', 'portion_full_g_min', 'portion_full_g_max'];

    private function __construct(public float $fraction) {}

    /**
     * A share from anything a client, column or JSON payload might hand
     * over. Anything unreadable is All: a missing share means an unshared
     * plate (the common case, which must never need saying), and a
     * nonsense one is a bug that would otherwise silently shrink someone's
     * dinner. Clamped rather than rejected, since validation already
     * refuses a bad share at the edge and by write time the only useful
     * behaviour is the safe one.
     */
    public static function of(mixed $value): self
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return new self(1.0);
        }

        $fraction = (float) $value;

        if (! is_finite($fraction)) {
            return new self(1.0);
        }

        return new self(max(self::MIN, min(1.0, $fraction)));
    }

    /** The whole plate. */
    public static function all(): self
    {
        return new self(1.0);
    }

    public function isWhole(): bool
    {
        return $this->fraction >= 1.0;
    }

    /**
     * How far a stored triple may be from `full × share` and still be
     * believed. Both portions are `decimal(10,3)`, so this class's own
     * rounding is the only discrepancy a coherent row can have — anything
     * larger means the three numbers were never computed together.
     */
    private const TOLERANCE = 0.002;

    /**
     * A `meal_items` row with the invariant restored.
     *
     * `portion_g_*` is what every producer in this app writes (the mapper,
     * the typed-item builder, memory pre-fill, every hand-built test item);
     * nothing posts `portion_full_g_*` over the wire, so when the two
     * disagree the freshly stated portion wins and the stale estimate
     * beside it is discarded. The exception is a row that ALREADY satisfies
     * the invariant — meaning it's been through here before
     * (`meals.repeat`'s verbatim copies, `vision:reproject`'s self-scaled
     * rows, a factory state) — which keeps its estimate, since it genuinely
     * is one, and that's what makes this idempotent. The alternative,
     * believing `portion_full_g_*` unconditionally, is subtly worse: a
     * caller that sets a portion and leaves a stale estimate beside it
     * would get that portion silently replaced by a number it never asked
     * for.
     *
     * @param  array<string, mixed>  $row  a `meal_items` row on its way in, or a model's own attributes
     * @return array<string, mixed>
     */
    public function apply(array $row): array
    {
        $fraction = round($this->fraction, 4);

        [$fullMin, $fullMax] = self::estimate($row);

        return [
            ...$row,
            // Rounded BEFORE multiplying, so the row's three numbers agree
            // exactly rather than to within a `decimal(5,4)` rounding loss.
            'share_fraction'     => $fraction,
            'portion_full_g_min' => round($fullMin, 3),
            'portion_full_g_max' => round($fullMax, 3),
            'portion_g_min'      => round($fullMin * $fraction, 3),
            'portion_g_max'      => round($fullMax * $fraction, 3),
        ];
    }

    /**
     * The plate's own portion: the stored estimate if it still explains the
     * stored portion, and otherwise the stored portion itself.
     *
     * @param  array<string, mixed>  $row
     * @return array{float, float}
     */
    private static function estimate(array $row): array
    {
        $portionMin = self::number($row['portion_g_min'] ?? null);
        $portionMax = self::number($row['portion_g_max'] ?? null);

        $fullMin = self::number($row['portion_full_g_min'] ?? null);
        $fullMax = self::number($row['portion_full_g_max'] ?? null);

        if ($fullMin === null || $fullMax === null) {
            return [$portionMin ?? 0.0, $portionMax ?? 0.0];
        }

        $stated = self::of($row['share_fraction'] ?? null)->fraction;

        $coherent = abs($fullMin * $stated - ($portionMin ?? 0.0)) <= self::TOLERANCE
            && abs($fullMax * $stated - ($portionMax ?? 0.0)) <= self::TOLERANCE;

        return $coherent ? [$fullMin, $fullMax] : [$portionMin ?? 0.0, $portionMax ?? 0.0];
    }

    private static function number(mixed $value): ?float
    {
        return $value === null || $value === '' || ! is_numeric($value) ? null : (float) $value;
    }

    /**
     * The share already recorded on a row, for a caller that wants to re-derive
     * without changing anybody's mind.
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return self::of($row['share_fraction'] ?? null);
    }
}
