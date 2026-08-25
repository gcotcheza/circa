<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MealMemory;
use Illuminate\Support\Str;
use App\Models\MealMemoryItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealMemoryItem>
 */
final class MealMemoryItemFactory extends Factory
{
    protected $model = MealMemoryItem::class;

    /**
     * @var array<string, array{0: float, 1: float, 2: float, 3: float, 4: int}>
     */
    private const FOODS = [
        'Chicken breast'     => [165.0, 31.0, 0.0, 3.6, 150],
        'White rice, cooked' => [130.0, 2.7, 28.2, 0.3, 180],
        'Broccoli'           => [34.0, 2.8, 6.6, 0.4, 120],
        'Greek yoghurt'      => [59.0, 10.0, 3.6, 0.4, 200],
    ];

    public function definition(): array
    {
        $name = $this->faker->randomElement(array_keys(self::FOODS));
        [$kcal, $protein, $carbs, $fat, $portion] = self::FOODS[$name];

        return [
            'meal_memory_id'       => MealMemory::factory(),
            'name'                 => $name,
            'slug'                 => Str::slug($name),
            'kcal_per_100g_min'    => round($kcal * 0.9, 3),
            'kcal_per_100g_max'    => round($kcal * 1.1, 3),
            'protein_per_100g_min' => round($protein * 0.9, 3),
            'protein_per_100g_max' => round($protein * 1.1, 3),
            'carbs_per_100g_min'   => round($carbs * 0.9, 3),
            'carbs_per_100g_max'   => round($carbs * 1.1, 3),
            'fat_per_100g_min'     => round($fat * 0.9, 3),
            'fat_per_100g_max'     => round($fat * 1.1, 3),
            // A habit, not an uncertainty band: one number.
            'typical_portion_g' => $portion,
        ];
    }

    public function named(string $name): static
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::slug($name),
        ]);
    }
}
