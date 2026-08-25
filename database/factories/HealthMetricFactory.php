<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Source;
use Carbon\CarbonImmutable;
use App\Models\HealthMetric;
use App\Enums\MetricAggregation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Hour-bucketed datapoints in the units and magnitudes actually
 * observed. Defaults to step_count — cumulative, re-sent larger for the
 * trailing hour, reported by both Watch and phone: the metric with the
 * most interesting behaviour.
 *
 * @extends Factory<HealthMetric>
 */
final class HealthMetricFactory extends Factory
{
    protected $model = HealthMetric::class;

    /**
     * metric => [unit, aggregation, min, max] — ranges are the real min/max
     * across the 27 112 captured datapoints.
     *
     * @var array<string, array{0: string, 1: MetricAggregation, 2: float, 3: float}>
     */
    private const OBSERVED = [
        'step_count'               => ['count', MetricAggregation::Sum, 1.0, 7319.39],
        'walking_running_distance' => ['km', MetricAggregation::Sum, 0.001, 5.608],
        // STEP 3 CORRECTION: kcal not kJ. Step 2 moved conversion to the way
        // IN, so `unit` holds the CANONICAL unit — captured kJ ranges
        // (0.059-1337.383, 48.748-343.735) divide by 4.184 to get here; a
        // kJ-labelled row would hand rollup tests data the DB can't hold.
        'active_energy'           => ['kcal', MetricAggregation::Sum, 0.014, 319.642],
        'basal_energy_burned'     => ['kcal', MetricAggregation::Sum, 11.651, 82.155],
        'heart_rate_variability'  => ['ms', MetricAggregation::Avg, 9.197, 123.909],
        'respiratory_rate'        => ['count/min', MetricAggregation::Avg, 7.5, 25.5],
        'blood_oxygen_saturation' => ['%', MetricAggregation::Avg, 87.0, 100.0],
        'weight_body_mass'        => ['kg', MetricAggregation::Instant, 54.6, 57.0],
        'body_fat_percentage'     => ['%', MetricAggregation::Instant, 23.3, 28.3],
        'lean_body_mass'          => ['kg', MetricAggregation::Instant, 40.869, 41.878],
        'body_mass_index'         => ['count', MetricAggregation::Instant, 21.3, 22.3],
        // The Fitage-only four (`fitage:import`), ranges from imported rows.
        // muscle_mass is what the body card draws; the other three are here
        // so the next consumer doesn't have to query the DB for a plausible
        // value.
        'muscle_mass'           => ['kg', MetricAggregation::Instant, 38.52, 40.3],
        'body_water_percentage' => ['%', MetricAggregation::Instant, 51.8, 53.5],
        'bone_mass'             => ['kg', MetricAggregation::Instant, 2.47, 2.61],
        'visceral_fat'          => ['count', MetricAggregation::Instant, 3.0, 5.0],
    ];

    public function definition(): array
    {
        [$unit, $aggregation, $min, $max] = self::OBSERVED['step_count'];

        $startedAt = CarbonImmutable::instance($this->faker->dateTimeBetween('-90 days', 'now'))
            ->startOfHour();

        return [
            'metric'      => 'step_count',
            'aggregation' => $aggregation,
            'period'      => 'hour',
            'value'       => $this->faker->randomFloat(3, $min, $max),
            'unit'        => $unit,
            'started_at'  => $startedAt,
            'ended_at'    => $startedAt->addHour(),
            // +0200 in 100% of captured datapoints.
            'device_utc_offset_minutes' => 120,
            'source_id'                 => Source::factory()->watch(),
            'ingested_at'               => CarbonImmutable::now(),
        ];
    }

    /** Any of the observed metrics, with its real unit and magnitude. */
    public function metric(string $metric): static
    {
        if (! isset(self::OBSERVED[$metric])) {
            throw new \InvalidArgumentException("No observed reference data for metric [{$metric}].");
        }

        [$unit, $aggregation, $min, $max] = self::OBSERVED[$metric];

        return $this->state(fn (): array => [
            'metric'      => $metric,
            'unit'        => $unit,
            'aggregation' => $aggregation,
            'value'       => $this->faker->randomFloat(3, $min, $max),
        ])->when(
            $aggregation === MetricAggregation::Instant,
            fn ($factory) => $factory->instant()
        );
    }

    /**
     * A point sample: started_at == ended_at — the convention keeping the
     * six-column unique key working, since nullable bounds would make
     * every re-send a fresh row.
     */
    public function instant(): static
    {
        return $this->state(function (array $attributes): array {
            $at = $attributes['started_at'] ?? CarbonImmutable::now()->startOfHour();

            return ['started_at' => $at, 'ended_at' => $at];
        });
    }

    /** Pin the bucket, e.g. to build a deliberate unique-key collision. */
    public function inHourBucket(CarbonImmutable $startedAt): static
    {
        $start = $startedAt->startOfHour();

        return $this->state(fn (): array => [
            'started_at' => $start,
            'ended_at'   => $start->addHour(),
        ]);
    }

    public function fromSource(Source $source): static
    {
        return $this->state(fn (): array => ['source_id' => $source->id]);
    }
}
