<?php

declare(strict_types=1);

namespace App\Services\Food;

/**
 * Reading per-100 g nutrition out of an Open Food Facts `nutriments`
 * object.
 *
 * Defensive on purpose: OFF is crowd-sourced, so the same field is
 * variously an int, float, numeric string or absent; energy arrives as
 * kcal, kJ, or a bare `energy_100g` whose unit is in a sibling key; and a
 * fat-finger produces 5390 kcal/100 g, which is a typo, not a food. Every
 * read passes three gates — finite number, non-negative, physically
 * possible per 100 g — and anything failing becomes NULL rather than
 * zero, since a missing macro and a genuine zero are different facts:
 * `food_products` is nullable throughout so the UI can say "no protein
 * figure" instead of quietly logging 0 g and shifting the day's total.
 */
final readonly class Nutriments
{
    /**
     * Thermochemical calorie, 4.184 J exactly — the same constant the ingest
     * pipeline uses for Apple's kJ energies (see MetricCatalog).
     */
    private const float KJ_PER_KCAL = 4.184;

    /**
     * @param  array<string, mixed>  $nutriments
     */
    public function __construct(private array $nutriments) {}

    /**
     * Energy per 100 g in kcal.
     *
     * Preference order: `energy-kcal_100g` is OFF's own kcal figure, used
     * verbatim when present so a number the contributor typed directly
     * isn't round-tripped through kJ. `energy-kj_100g` is explicitly kJ
     * and converted. `energy_100g` is the legacy field whose unit lives in
     * `energy_unit` and is kJ far more often than kcal — trusting it
     * blindly as kcal would inflate a jar of Nutella from 539 to 2228
     * kcal/100 g.
     */
    public function kcalPer100g(): ?float
    {
        $kcal = $this->number('energy-kcal_100g', $this->maxKcal());

        if ($kcal !== null) {
            return $kcal;
        }

        $kj = $this->number('energy-kj_100g', $this->maxKcal() * self::KJ_PER_KCAL);

        if ($kj !== null) {
            return $this->round($kj / self::KJ_PER_KCAL);
        }

        $unit = mb_strtolower(trim((string) ($this->nutriments['energy_unit'] ?? 'kj')));

        if ($unit === 'kcal') {
            return $this->number('energy_100g', $this->maxKcal());
        }

        $energy = $this->number('energy_100g', $this->maxKcal() * self::KJ_PER_KCAL);

        return $energy === null ? null : $this->round($energy / self::KJ_PER_KCAL);
    }

    public function proteinPer100g(): ?float
    {
        return $this->macro('proteins_100g');
    }

    public function carbsPer100g(): ?float
    {
        return $this->macro('carbohydrates_100g');
    }

    public function fatPer100g(): ?float
    {
        return $this->macro('fat_100g');
    }

    /** Grams of a macro per 100 g cannot exceed 100. */
    private function macro(string $key): ?float
    {
        return $this->number($key, (float) config('health.food.max_macro_per_100g', 100));
    }

    /**
     * Pure fat is 900 kcal/100 g; nothing edible is denser. Shared with
     * MealRequest's ceiling so a scanned item can't be accepted here and
     * rejected when the meal is saved.
     */
    private function maxKcal(): float
    {
        return (float) config('health.food.max_kcal_per_100g', 900);
    }

    /**
     * A finite, non-negative, physically-possible number, or null.
     */
    private function number(string $key, float $max): ?float
    {
        $value = $this->nutriments[$key] ?? null;

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            return null;
        }

        $number = (float) $value;

        if (! is_finite($number) || $number < 0 || $number > $max) {
            return null;
        }

        return $this->round($number);
    }

    /** food_products stores decimal(10,3); rounding here keeps PHP and Postgres agreeing. */
    private function round(float $value): float
    {
        return round($value, 3);
    }
}
