<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\SupplementIntake;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupplementIntake>
 */
final class SupplementIntakeFactory extends Factory
{
    protected $model = SupplementIntake::class;

    public function definition(): array
    {
        $today = CarbonImmutable::now((string) config('health.timezone'))->toDateString();

        return [
            'supplement_id' => Supplement::factory(),
            'local_date'    => $today,
            'taken_at'      => CarbonImmutable::now(),
        ];
    }

    public function on(string $date): static
    {
        return $this->state(fn (): array => ['local_date' => $date]);
    }
}
