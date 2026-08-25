<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Meal;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use App\Services\Meals\ConsumptionShare;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Real foods with real per-100g densities, so derived kcal comes out plausible
 * rather than random — a test that asserts on a 4000 kcal bowl of broccoli
 * teaches nothing.
 *
 * @extends Factory<MealItem>
 */
final class MealItemFactory extends Factory
{
    protected $model = MealItem::class;

    /**
     * name => [kcal, protein, carbs, fat] per 100 g, and a typical portion.
     *
     * @var array<string, array{0: float, 1: float, 2: float, 3: float, 4: int}>
     */
    private const FOODS = [
        'Chicken breast'     => [165.0, 31.0, 0.0, 3.6, 150],
        'White rice, cooked' => [130.0, 2.7, 28.2, 0.3, 180],
        'Broccoli'           => [34.0, 2.8, 6.6, 0.4, 120],
        'Salmon fillet'      => [208.0, 20.4, 0.0, 13.4, 140],
        'Whole wheat bread'  => [247.0, 13.0, 41.0, 3.4, 60],
        'Greek yoghurt'      => [59.0, 10.0, 3.6, 0.4, 200],
        'Olive oil'          => [884.0, 0.0, 0.0, 100.0, 10],
        'Banana'             => [89.0, 1.1, 22.8, 0.3, 120],
    ];

    public function definition(): array
    {
        $name = $this->faker->randomElement(array_keys(self::FOODS));
        [$kcal, $protein, $carbs, $fat, $portion] = self::FOODS[$name];

        // Vision returns ranges, never point estimates — ±15% on portion is
        // about what "a photo of a plate" honestly supports.
        $portionMin = round($portion * 0.85, 3);
        $portionMax = round($portion * 1.15, 3);

        return [
            'meal_id'       => Meal::factory(),
            'name'          => $name,
            'slug'          => Str::slug($name),
            'portion_g_min' => $portionMin,
            'portion_g_max' => $portionMax,

            /*
             * The shared plate, stated here rather than left to
             * `MealItem::creating` — several tests build items inside
             * `MealItem::withoutEvents()` to keep the meal-memory observer
             * quiet, so an unshared row must be valid without the hook's
             * help; a factory correct only while an event happens to fire
             * produces NULLs in exactly the tests least likely to notice.
             */
            'share_fraction'       => 1,
            'portion_full_g_min'   => $portionMin,
            'portion_full_g_max'   => $portionMax,
            'kcal_per_100g_min'    => round($kcal * 0.9, 3),
            'kcal_per_100g_max'    => round($kcal * 1.1, 3),
            'protein_per_100g_min' => round($protein * 0.9, 3),
            'protein_per_100g_max' => round($protein * 1.1, 3),
            'carbs_per_100g_min'   => round($carbs * 0.9, 3),
            'carbs_per_100g_max'   => round($carbs * 1.1, 3),
            'fat_per_100g_min'     => round($fat * 0.9, 3),
            'fat_per_100g_max'     => round($fat * 1.1, 3),
            'confidence'           => $this->faker->randomFloat(3, 0.55, 0.98),
            'confirmed_at'         => CarbonImmutable::now(),
            'food_product_id'      => null,
        ];
    }

    /** Barcode entries are exact: min == max, and no confidence score. */
    public function exact(): static
    {
        return $this->state(function (array $attributes): array {
            return [
                'portion_g_max' => $attributes['portion_g_min'],
                // Exact too — a packet declares one weight, not a band, and
                // the two must stay consistent or the row claims an unchosen
                // share.
                'portion_full_g_max'   => $attributes['portion_full_g_min'],
                'kcal_per_100g_max'    => $attributes['kcal_per_100g_min'],
                'protein_per_100g_max' => $attributes['protein_per_100g_min'],
                'carbs_per_100g_max'   => $attributes['carbs_per_100g_min'],
                'fat_per_100g_max'     => $attributes['fat_per_100g_min'],
                'confidence'           => null,
            ];
        });
    }

    /** Proposed by vision, not yet tapped through. */
    public function unconfirmed(): static
    {
        return $this->state(fn (): array => ['confirmed_at' => null]);
    }

    /**
     * A plate the user only had part of. The estimate stays whole and the
     * eaten portion derives from it — the invariant every reader depends
     * on — so this applies the same arithmetic the app does rather than
     * inventing its own pair of numbers.
     */
    public function shared(float $fraction = 0.5): static
    {
        return $this->state(fn (array $attributes): array => ConsumptionShare::of($fraction)->apply($attributes));
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
