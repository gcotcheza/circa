<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Meal;
use Illuminate\Support\Str;
use App\Models\VisionRequest;
use App\Enums\VisionRequestStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VisionRequest>
 */
final class VisionRequestFactory extends Factory
{
    protected $model = VisionRequest::class;

    public function definition(): array
    {
        return [
            'meal_id' => Meal::factory()->photo(),
            // Client-generated at capture: a double-tap must not double-pay.
            'idempotency_key' => (string) Str::uuid(),
            'model'           => 'claude-opus-5',
            'prompt_version'  => 'v1',
            'image_sha256'    => hash('sha256', $this->faker->unique()->sentence()),
            'raw_response'    => [
                'items' => [
                    [
                        'name'          => 'Chicken breast',
                        'portion_g'     => ['min' => 120, 'max' => 180],
                        'kcal_per_100g' => ['min' => 150, 'max' => 180],
                        'confidence'    => 0.86,
                    ],
                ],
                'notes' => 'Plate diameter used as scale reference.',
            ],
            'input_tokens'  => $this->faker->numberBetween(1200, 2600),
            'output_tokens' => $this->faker->numberBetween(150, 700),
            'latency_ms'    => $this->faker->numberBetween(1800, 9000),
            'status'        => VisionRequestStatus::Succeeded,
            'error'         => null,
        ];
    }

    /** The row exists to claim the idempotency key; nothing sent yet. */
    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status'        => VisionRequestStatus::Pending,
            'raw_response'  => null,
            'input_tokens'  => null,
            'output_tokens' => null,
            'latency_ms'    => null,
        ]);
    }

    public function failed(string $error = 'overloaded_error'): static
    {
        return $this->state(fn (): array => [
            'status'       => VisionRequestStatus::Failed,
            'raw_response' => null,
            'error'        => $error,
        ]);
    }
}
