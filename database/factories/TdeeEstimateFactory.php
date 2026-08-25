<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use App\Models\TdeeEstimate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TdeeEstimate>
 */
final class TdeeEstimateFactory extends Factory
{
    protected $model = TdeeEstimate::class;

    public function definition(): array
    {
        $end = CarbonImmutable::today();
        $start = $end->subDays(27);
        $mid = $this->faker->randomFloat(2, 1800, 2300);

        return [
            'window_start'            => $start->toDateString(),
            'window_end'              => $end->toDateString(),
            'tdee_min'                => round($mid - 180, 2),
            'tdee_mid'                => round($mid, 2),
            'tdee_max'                => round($mid + 180, 2),
            'intake_mean_kcal'        => round($mid - 120, 2),
            'weight_slope_kg_per_day' => -0.02,
            'n_days'                  => $this->faker->numberBetween(TdeeEstimate::MIN_DAYS, 28),
            'n_weighins'              => $this->faker->numberBetween(TdeeEstimate::MIN_WEIGHINS, 20),
            'method_version'          => 'v1',
            'computed_at'             => CarbonImmutable::now(),
        ];
    }

    /** Below the gate: the UI shows "collecting data", not a number. */
    public function belowGate(): static
    {
        return $this->state(fn (): array => [
            'n_days'     => 6,
            'n_weighins' => 3,
        ]);
    }

    public function forWindow(string $start, string $end): static
    {
        return $this->state(fn (): array => [
            'window_start' => $start,
            'window_end'   => $end,
        ]);
    }
}
