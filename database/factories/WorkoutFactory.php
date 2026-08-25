<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Workout;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A session shaped like the ones the Watch actually reports.
 *
 * Ranges follow captured history: 12-300 minutes, 1.2-21 km where there
 * is a distance, avg HR 86-159 bpm, energy in KCAL (what the column
 * holds — the export's kJ converts on the way in and never reaches this
 * table; seeding 1 424 "kcal" would quietly teach every test the wrong
 * unit).
 *
 * @extends Factory<Workout>
 */
final class WorkoutFactory extends Factory
{
    protected $model = Workout::class;

    /** The twelve activity types observed across two years. */
    public const TYPES = [
        'Outdoor Walk',
        'Outdoor Run',
        'Martial Arts',
        'Climbing',
        'Tennis',
        'Outdoor Cycling',
        'Tai Chi',
        'Rowing',
        'Pilates',
        'Hiking',
        'High Intensity Interval Training',
        'Functional Strength Training',
    ];

    public function definition(): array
    {
        /*
         * STORED IN UTC, DELIBERATELY: Eloquent's datetime cast formats an
         * instance in ITS OWN timezone and drops the offset, so a Carbon in
         * Europe/Amsterdam would write as a naked "07:27:00" and read back
         * as 07:27 UTC — two hours adrift, showing up only in the generated
         * `local_date` on days it crosses a boundary. Every timestamp here
         * is converted first.
         */
        $startedAt = CarbonImmutable::instance($this->faker->dateTimeBetween('-90 days', 'now'))
            ->utc()
            ->startOfMinute();

        $minutes = $this->faker->randomFloat(2, 12, 180);

        return [
            'apple_uuid'                => mb_strtoupper($this->faker->uuid()),
            'type'                      => $this->faker->randomElement(self::TYPES),
            'started_at'                => $startedAt,
            'ended_at'                  => $startedAt->addSeconds((int) round($minutes * 60)),
            'duration_s'                => round($minutes * 60, 3),
            'distance_km'               => $this->faker->randomFloat(4, 1.2, 21),
            'active_kcal'               => round($minutes * $this->faker->randomFloat(2, 4, 9), 3),
            'avg_hr'                    => $this->faker->numberBetween(86, 159),
            'max_hr'                    => $this->faker->numberBetween(104, 187),
            'step_count'                => $this->faker->numberBetween(0, 16000),
            'elevation_up_m'            => $this->faker->randomFloat(2, 0, 180),
            'is_indoor'                 => false,
            'intensity'                 => $this->faker->randomFloat(4, 2, 8),
            'is_implausible'            => false,
            'device_utc_offset_minutes' => 120,
            'extras'                    => [],
            'ingested_at'               => CarbonImmutable::now(),
        ];
    }

    public function ofType(string $type): static
    {
        return $this->state(fn (): array => ['type' => $type]);
    }

    /**
     * A session at a wall-clock time on a local date. The offset is stated
     * explicitly, not left to app timezone — `local_date` is generated
     * from the absolute instant, so a test meaning "07:30 on the 4th"
     * must say which 07:30.
     */
    public function startingAt(string $localDate, string $time = '07:30', float $minutes = 45): static
    {
        return $this->state(function () use ($localDate, $time, $minutes): array {
            $local = CarbonImmutable::parse($localDate.' '.$time, 'Europe/Amsterdam');
            $startedAt = $local->utc();

            return [
                'started_at' => $startedAt,
                'ended_at'   => $startedAt->addSeconds((int) round($minutes * 60)),
                'duration_s' => round($minutes * 60, 3),
                // The wrist clock's own reading, from the LOCAL instance —
                // stored timestamp is UTC, offset 0.
                'device_utc_offset_minutes' => (int) ($local->getOffset() / 60),
            ];
        });
    }

    /** No distance: the indoor half of the history (Climbing, Martial Arts). */
    public function withoutDistance(): static
    {
        return $this->state(fn (): array => ['distance_km' => null, 'elevation_up_m' => null]);
    }

    /**
     * The forgotten timer. Four days, exactly like the captured Hiking artifact.
     */
    public function implausible(): static
    {
        return $this->state(function (array $attributes): array {
            /** @var CarbonImmutable $startedAt */
            $startedAt = $attributes['started_at'];

            return [
                'ended_at'       => $startedAt->addDays(4),
                'duration_s'     => 4 * 24 * 3600,
                'is_implausible' => true,
            ];
        });
    }
}
