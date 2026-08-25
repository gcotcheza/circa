<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\MealMemory;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealMemory>
 */
final class MealMemoryFactory extends Factory
{
    protected $model = MealMemory::class;

    public function definition(): array
    {
        $names = $this->faker->randomElements(
            ['Chicken breast', 'White rice, cooked', 'Broccoli', 'Salmon fillet', 'Greek yoghurt'],
            $this->faker->numberBetween(2, 3)
        );

        return [
            // Derived from the names, exactly as production would derive it.
            'fingerprint'    => MealMemory::fingerprintFor($names),
            'canonical_name' => implode(' + ', $names),
            'times_logged'   => $this->faker->numberBetween(1, 40),
            'last_logged_at' => CarbonImmutable::instance($this->faker->dateTimeBetween('-30 days', 'now')),
        ];
    }

    /**
     * Build the memory around a known item set, so a test can assert the
     * fingerprint is order-independent.
     *
     * @param  list<string>  $names
     */
    public function forItems(array $names): static
    {
        return $this->state(fn (): array => [
            'fingerprint'    => MealMemory::fingerprintFor($names),
            'canonical_name' => implode(' + ', $names),
        ]);
    }
}
