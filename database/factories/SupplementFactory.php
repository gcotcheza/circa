<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplement>
 */
final class SupplementFactory extends Factory
{
    protected $model = Supplement::class;

    public function definition(): array
    {
        return [
            'name'  => $this->faker->randomElement(['Vitamine D3', 'Magnesium glycinate', 'Omega-3', 'Vitamine B12']),
            'brand' => $this->faker->randomElement(['Vitakruid', 'Solgar', 'Kruidvat', null]),
            // Verbatim, and the figures on `supplement_nutrients` are FOR this
            // serving. `units_per_day` counts these, not capsules.
            'serving_text'  => 'per capsule',
            'units_per_day' => 1,
            // The `labels/` prefix is load-bearing: it is what keeps a label
            // out of the meal-photo retention sweep. See PhotoStore.
            'photo_path' => 'labels/originals/2026/08/'.$this->faker->uuid().'.jpg',
            'position'   => 0,
            'active'     => true,
        ];
    }

    /**
     * Off the card: "stopped taking it" or "bought it, not started yet" —
     * the column doesn't distinguish them and doesn't need to.
     */
    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    /** Two of whatever `serving_text` names, every day. */
    public function taking(int $unitsPerDay): static
    {
        return $this->state(fn (): array => ['units_per_day' => $unitsPerDay]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn (): array => ['position' => $position]);
    }

    /** Read off a bare pill box: no brand, no serving, no photograph kept. */
    public function bare(): static
    {
        return $this->state(fn (): array => [
            'brand'        => null,
            'serving_text' => null,
            'photo_path'   => null,
        ]);
    }
}
