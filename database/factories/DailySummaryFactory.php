<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A rebuilt day. Energy here is kcal — the device's kJ has already
 * converted by the time a row lands in this table. Magnitudes match the
 * captured data: basal ≈ 48–344 kJ/h, active up to 1337 kJ/h come out
 * around 1300 kcal resting and a few hundred active over a day.
 *
 * @extends Factory<DailySummary>
 */
final class DailySummaryFactory extends Factory
{
    protected $model = DailySummary::class;

    public function definition(): array
    {
        $kcalMid = $this->faker->randomFloat(2, 1500, 2400);
        // Quadrature half-width, not a linear sum of item extremes.
        $halfWidth = $kcalMid * 0.12;

        return [
            'local_date' => CarbonImmutable::instance(
                $this->faker->unique()->dateTimeBetween('-120 days', 'yesterday')
            )->toDateString(),

            'kcal_in_min' => round($kcalMid - $halfWidth, 2),
            'kcal_in_mid' => round($kcalMid, 2),
            'kcal_in_max' => round($kcalMid + $halfWidth, 2),

            'protein_g_min' => round($kcalMid * 0.25 / 4 * 0.9, 2),
            'protein_g_mid' => round($kcalMid * 0.25 / 4, 2),
            'protein_g_max' => round($kcalMid * 0.25 / 4 * 1.1, 2),

            'carbs_g_min' => round($kcalMid * 0.45 / 4 * 0.9, 2),
            'carbs_g_mid' => round($kcalMid * 0.45 / 4, 2),
            'carbs_g_max' => round($kcalMid * 0.45 / 4 * 1.1, 2),

            'fat_g_min' => round($kcalMid * 0.30 / 9 * 0.9, 2),
            'fat_g_mid' => round($kcalMid * 0.30 / 9, 2),
            'fat_g_max' => round($kcalMid * 0.30 / 9 * 1.1, 2),

            'active_kcal'  => $this->faker->randomFloat(2, 200, 800),
            'resting_kcal' => $this->faker->randomFloat(2, 1250, 1450),
            'weight_kg'    => null,

            'is_complete_log'          => true,
            'has_full_metric_coverage' => true,
            'active_kcal_is_partial'   => false,

            'rebuilt_at' => CarbonImmutable::now(),
        ];
    }

    public function onDate(string $date): static
    {
        return $this->state(fn (): array => ['local_date' => $date]);
    }

    /** Most days have no weigh-in; some do. */
    public function withWeighIn(?float $kg = null): static
    {
        return $this->state(fn (): array => [
            'weight_kg' => $kg ?? $this->faker->randomFloat(3, 54.6, 57.0),
        ]);
    }

    /** Breakfast-only day: not a low-intake day, a day with no usable number. */
    public function incompleteLog(): static
    {
        return $this->state(fn (): array => ['is_complete_log' => false]);
    }

    /** Watch off the wrist: burn is an undercount and says so. */
    public function watchNotWorn(): static
    {
        return $this->state(fn (): array => [
            'has_full_metric_coverage' => false,
            'active_kcal_is_partial'   => true,
        ]);
    }
}
