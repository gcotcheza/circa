<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FoodProduct;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A cached Open Food Facts product.
 *
 * `raw` mirrors the shape of the v3 response so anything reading it in tests is
 * reading the real key names, not invented ones.
 *
 * @extends Factory<FoodProduct>
 */
final class FoodProductFactory extends Factory
{
    protected $model = FoodProduct::class;

    public function definition(): array
    {
        $kcal = $this->faker->randomFloat(1, 30, 550);
        $protein = $this->faker->randomFloat(1, 0, 30);
        $carbs = $this->faker->randomFloat(1, 0, 70);
        $fat = $this->faker->randomFloat(1, 0, 40);

        return [
            // EAN-13. String, not int: leading zeros are significant.
            'barcode'          => (string) $this->faker->unique()->numerify('#############'),
            'name'             => $this->faker->words(3, true),
            'brand'            => $this->faker->company(),
            'kcal_per_100g'    => $kcal,
            'protein_per_100g' => $protein,
            'carbs_per_100g'   => $carbs,
            'fat_per_100g'     => $fat,
            'raw'              => [
                'product' => [
                    'product_name' => $this->faker->words(3, true),
                    'nutriments'   => [
                        'energy-kcal_100g'   => $kcal,
                        'proteins_100g'      => $protein,
                        'carbohydrates_100g' => $carbs,
                        'fat_100g'           => $fat,
                    ],
                ],
                'status' => 'success',
            ],
            'fetched_at' => CarbonImmutable::now(),
        ];
    }

    /** OFF entries are crowd-sourced and routinely missing macros. */
    public function incomplete(): static
    {
        return $this->state(fn (): array => [
            'protein_per_100g' => null,
            'carbs_per_100g'   => null,
            'fat_per_100g'     => null,
        ]);
    }
}
