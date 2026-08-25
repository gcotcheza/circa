<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Meal;
use App\Models\MealPhoto;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MealPhoto>
 */
final class MealPhotoFactory extends Factory
{
    protected $model = MealPhoto::class;

    public function definition(): array
    {
        $clientId = (string) Str::uuid();

        return [
            'meal_id'   => Meal::factory(),
            'client_id' => $clientId,
            // The `originals/` prefix is load-bearing rather than decoration:
            // retention repoints the path at `thumbs/`, and the prefix is how
            // the rest of the app knows the full-size image is gone.
            'path'        => "originals/2026/08/{$clientId}.jpg",
            'thumb_path'  => "thumbs/2026/08/{$clientId}.jpg",
            'sha256'      => hash('sha256', $clientId),
            'position'    => 0,
            'model_notes' => null,
            'created_at'  => CarbonImmutable::now(),
        ];
    }

    /** Retention has been through: the original is gone, the thumbnail is not. */
    public function pruned(): static
    {
        return $this->state(fn (array $attributes): array => [
            'path' => $attributes['thumb_path'],
        ]);
    }

    public function atPosition(int $position): static
    {
        return $this->state(fn (): array => ['position' => $position]);
    }
}
