<?php

declare(strict_types=1);

namespace Database\Factories;

use Carbon\CarbonImmutable;
use App\Models\RawIngestPayload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A payload shaped exactly like the ones Health Auto Export actually sends —
 * the `data.metrics[]` envelope, the "YYYY-MM-DD HH:MM:SS +0200" date format,
 * and both observed datapoint shapes (`qty`, and heart_rate's Min/Avg/Max).
 *
 * Both columns are JSON *strings*, matching the model: RawIngestPayload
 * deliberately has no array casts, so that what is written is what arrived.
 *
 * @extends Factory<RawIngestPayload>
 */
final class RawIngestPayloadFactory extends Factory
{
    protected $model = RawIngestPayload::class;

    public function definition(): array
    {
        $bucket = CarbonImmutable::now('+02:00')->startOfHour();
        $stamp = static fn (CarbonImmutable $t): string => $t->format('Y-m-d H:i:s O');
        $watch = "Demo\u{2019}s Apple\u{00A0}Watch";

        $body = [
            'data' => [
                'metrics' => [
                    [
                        'name'  => 'step_count',
                        'units' => 'count',
                        'data'  => [[
                            'date'   => $stamp($bucket),
                            'qty'    => $this->faker->randomFloat(3, 1, 7319.39),
                            'source' => $watch."|Demo\u{2019}s iphone",
                        ]],
                    ],
                    [
                        'name'  => 'active_energy',
                        'units' => 'kJ',
                        'data'  => [[
                            'date'   => $stamp($bucket),
                            'qty'    => $this->faker->randomFloat(3, 0.059, 1337.383),
                            'source' => $watch,
                        ]],
                    ],
                    [
                        'name'  => 'heart_rate',
                        'units' => 'count/min',
                        'data'  => [[
                            'date'   => $stamp($bucket),
                            'Min'    => 52,
                            'Avg'    => 74.36,
                            'Max'    => 118,
                            'source' => $watch,
                        ]],
                    ],
                ],
            ],
        ];

        return [
            'headers' => json_encode([
                'session-id'             => mb_strtoupper($this->faker->uuid()),
                'automation-id'          => IngestRunFactory::AUTOMATION_ID,
                'automation-name'        => 'Health tracker',
                'automation-aggregation' => 'Hours',
                'automation-period'      => 'Since Last Sync',
                'content-type'           => 'application/json',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),

            'body' => json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),

            'received_at' => CarbonImmutable::now(),
        ];
    }
}
