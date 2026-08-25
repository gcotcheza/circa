<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use Carbon\CarbonImmutable;
use App\Models\SleepSession;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A night shaped like the ones the Watch actually reports.
 *
 * Stage split follows the observed proportions (core ≈ 2/3, rem ≈ 1/5,
 * deep ≈ 1/8), total_sleep is the sum of the three excluding awake — as HAE
 * computes it — and in_bed / asleep are left null because the Watch reports
 * them as 0, i.e. not reported.
 *
 * @extends Factory<SleepSession>
 */
final class SleepSessionFactory extends Factory
{
    protected $model = SleepSession::class;

    public function definition(): array
    {
        $nightDate = CarbonImmutable::instance($this->faker->dateTimeBetween('-90 days', 'yesterday'))
            ->startOfDay();

        // Observed total sleep across 108 records: 0.62 h – 9.28 h.
        $totalHours = $this->faker->randomFloat(4, 5.0, 9.0);
        $core = $totalHours * 0.66;
        $rem = $totalHours * 0.21;
        $deep = $totalHours - $core - $rem;
        $awake = $this->faker->randomFloat(4, 0.05, 0.75);

        $sleepStart = $nightDate->subHours(1)->addMinutes($this->faker->numberBetween(0, 120));
        $sleepEnd = $sleepStart->addMinutes((int) round(($totalHours + $awake) * 60));

        return [
            'night_date'    => $nightDate->toDateString(),
            'source_id'     => Source::factory()->watch(),
            'in_bed_start'  => $sleepStart,
            'in_bed_end'    => $sleepEnd,
            'sleep_start'   => $sleepStart,
            'sleep_end'     => $sleepEnd,
            'rem_minutes'   => round($rem * 60, 3),
            'core_minutes'  => round($core * 60, 3),
            'deep_minutes'  => round($deep * 60, 3),
            'awake_minutes' => round($awake * 60, 3),
            // Reported as 0 by the Watch in every captured record, i.e. absent.
            'asleep_minutes'            => null,
            'in_bed_minutes'            => null,
            'total_sleep_minutes'       => round($totalHours * 60, 3),
            'device_utc_offset_minutes' => 120,
            'ingested_at'               => CarbonImmutable::now(),
        ];
    }

    public function forNight(string $date): static
    {
        return $this->state(fn (): array => ['night_date' => $date]);
    }

    public function fromSource(Source $source): static
    {
        return $this->state(fn (): array => ['source_id' => $source->id]);
    }
}
