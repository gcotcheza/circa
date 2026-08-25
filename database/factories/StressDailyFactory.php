<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\StressBand;
use App\Models\StressDaily;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A stored stress score.
 *
 * The default is an ordinary day, because that is what most days are: z near
 * zero, a score in the Normal band, a full day of readings behind it. The
 * magnitudes match production — HRV around 33 ms, a day-to-day spread of ~0.16
 * in log units (about +-16%), 20 readings a day once the Watch is worn properly.
 *
 * @extends Factory<StressDaily>
 */
final class StressDailyFactory extends Factory
{
    protected $model = StressDaily::class;

    public function definition(): array
    {
        $z = $this->faker->randomFloat(3, -0.5, 0.5);

        return [
            'local_date' => CarbonImmutable::instance(
                $this->faker->unique()->dateTimeBetween('-120 days', 'yesterday')
            )->toDateString(),

            'score' => 65,
            'band'  => StressBand::Normal,
            'z'     => $z,

            'hrv_ms'              => 33.0,
            'baseline_hrv_ms'     => 33.0,
            'baseline_spread_log' => 0.16,

            'samples'       => 20,
            'covered_hours' => 20.0,
            'baseline_days' => 55,
            'confidence'    => 'high',

            'method_version' => 'v1',
            'computed_at'    => CarbonImmutable::now(),
        ];
    }

    public function onDate(string $date): static
    {
        return $this->state(fn (): array => ['local_date' => $date]);
    }

    /** A specific score, with its band kept consistent. */
    public function scoring(int $score): static
    {
        return $this->state(fn (): array => [
            'score' => $score,
            'band'  => StressBand::fromScore($score),
        ]);
    }

    /** A day the Watch was barely worn: four readings, and the badge says so. */
    public function thin(): static
    {
        return $this->state(fn (): array => [
            'samples'       => 4,
            'covered_hours' => 4.0,
            'confidence'    => 'low',
        ]);
    }
}
