<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplement;
use App\Models\SupplementNutrient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplementNutrient>
 */
final class SupplementNutrientFactory extends Factory
{
    protected $model = SupplementNutrient::class;

    public function definition(): array
    {
        return [
            'supplement_id' => Supplement::factory(),
            // Dutch on purpose: the labels this reads are EU labels, and the
            // promise is that the app shows back what the bottle says rather
            // than a translation of it.
            'nutrient' => 'Vitamine D3',
            'amount'   => '25.0000',
            'unit'     => 'µg',
            'position' => 0,
        ];
    }

    /** A line with a number nobody could parse — "Bevat sporen van soja". */
    public function unquantified(): static
    {
        return $this->state(fn (): array => ['amount' => null, 'unit' => null]);
    }
}
