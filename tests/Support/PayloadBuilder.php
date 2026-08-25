<?php

declare(strict_types=1);

namespace Tests\Support;

use Carbon\CarbonImmutable;
use App\Models\RawIngestPayload;
use Database\Factories\IngestRunFactory;

/**
 * Builds Health Auto Export payloads the way HAE builds them.
 *
 * A builder rather than inline arrays so every test agrees on the envelope
 * (`data.metrics[]`), the date format ("Y-m-d H:i:s O", offset always present)
 * and the header set — a failing test then fails about the thing it names, not a
 * hand-typed date that drifted.
 *
 * Source strings default to the real ones, U+2019 and U+00A0 included. Tests
 * with tidier strings are not testing the data this app receives.
 */
final class PayloadBuilder
{
    public const WATCH = "Demo\u{2019}s Apple\u{00A0}Watch";

    public const PHONE = "Demo\u{2019}s iphone";

    public const COMPOSITE = "Demo\u{2019}s Apple\u{00A0}Watch|Demo\u{2019}s iphone";

    /** @var list<array<string, mixed>> */
    private array $metrics = [];

    /** @var list<array<string, mixed>> */
    private array $workouts = [];

    /** @var array<string, string> */
    private array $headers = [
        'session-id'      => 'B0F5B3D4-0000-4000-8000-000000000001',
        'automation-id'   => IngestRunFactory::AUTOMATION_ID,
        'automation-name' => 'Health tracker',
        // Counterintuitive pair: aggregation is the bucket WIDTH, period the
        // export WINDOW. See App\Services\Ingest\BucketWidth.
        'automation-aggregation' => 'Hours',
        'automation-period'      => 'Since Last Sync',
        'content-type'           => 'application/json',
    ];

    public static function make(): self
    {
        return new self;
    }

    public function header(string $name, ?string $value): self
    {
        if ($value === null) {
            unset($this->headers[$name]);
        } else {
            $this->headers[$name] = $value;
        }

        return $this;
    }

    public function sessionId(string $id): self
    {
        return $this->header('session-id', $id);
    }

    /**
     * @param  list<array<string, mixed>>  $data
     */
    public function metric(string $name, string $units, array $data): self
    {
        $this->metrics[] = ['name' => $name, 'units' => $units, 'data' => $data];

        return $this;
    }

    /** `{date, qty, source}` — the 31-metric shape. */
    public function quantity(
        string $name,
        string $units,
        string $date,
        float $qty,
        string $source = self::WATCH,
    ): self {
        return $this->metric($name, $units, [[
            'date'   => $date,
            'qty'    => $qty,
            'source' => $source,
        ]]);
    }

    /** `{date, Min, Avg, Max, source}` — heart_rate. */
    public function minAvgMax(
        string $date,
        float $min,
        float $avg,
        float $max,
        string $source = self::WATCH,
        string $name = 'heart_rate',
        string $units = 'count/min',
    ): self {
        return $this->metric($name, $units, [[
            'date'   => $date,
            'Min'    => $min,
            'Avg'    => $avg,
            'Max'    => $max,
            'source' => $source,
        ]]);
    }

    /**
     * One night record, in the observed field set and units (fractional hours).
     *
     * @param  array<string, mixed>  $overrides
     */
    public function sleep(
        string $nightDate = '2026-08-07',
        string $offset = '+0200',
        string $source = self::WATCH,
        array $overrides = [],
    ): self {
        $record = array_merge([
            'date'       => $nightDate.' 00:00:00 '.$offset,
            'rem'        => 1.9211879865659607,
            'core'       => 5.8720494314697058,
            'deep'       => 1.0106159175104565,
            'awake'      => 0.66838987082242973,
            'totalSleep' => 8.8038533355461226,
            // 0 from the Watch in every captured record — stored as the 0 it is.
            'inBed'      => 0,
            'asleep'     => 0,
            'sleepStart' => '2026-08-06 23:00:36 '.$offset,
            'sleepEnd'   => '2026-08-07 08:28:56 '.$offset,
            'inBedStart' => '2026-08-06 23:00:36 '.$offset,
            'inBedEnd'   => '2026-08-07 08:28:56 '.$offset,
            'source'     => $source,
        ], $overrides);

        return $this->metric('sleep_analysis', 'hr', [$record]);
    }

    /**
     * One `data.workouts[]` record, in the field set the workouts automation
     * actually sends.
     *
     * SYNTHETIC, DELIBERATELY. The shape is real — 31 keys, energy in kJ,
     * `{qty, units}` scalars, sample SERIES for stepCount / heartRateData /
     * route, "Y-m-d H:i:s O" dates — and the values are invented, because real
     * coordinates are somebody's front door and no test needs them to prove a
     * parser reads a list. One entry per series by default is enough to prove
     * they are summed (stepCount) or ignored (the other two) without putting
     * 11 000 GPS fixes in a fixture.
     *
     * @param  array<string, mixed>  $overrides  merged last: pass `null` in one
     *                                           to delete the key entirely
     */
    public function workout(
        string $uuid = '2D91369C-E0C9-43ED-9664-C7D7663F9258',
        string $name = 'Outdoor Run',
        string $start = '2026-08-02 08:53:47 +0200',
        string $end = '2026-08-02 09:43:46 +0200',
        float $durationSeconds = 2999.0,
        array $overrides = [],
        string $source = self::WATCH,
    ): self {
        $record = [
            'id'       => $uuid,
            'name'     => $name,
            'start'    => $start,
            'end'      => $end,
            'duration' => $durationSeconds,

            // kJ — the point of the conversion test.
            'activeEnergyBurned' => ['qty' => 1424.0, 'units' => 'kJ'],
            'totalEnergy'        => ['qty' => 1856.5, 'units' => 'kJ'],

            'distance'     => ['qty' => 6.0301234, 'units' => 'km'],
            'avgHeartRate' => ['qty' => 156.72, 'units' => 'bpm'],
            'maxHeartRate' => ['qty' => 174, 'units' => 'bpm'],
            'heartRate'    => [
                'avg' => ['qty' => 156.72, 'units' => 'bpm'],
                'max' => ['qty' => 174, 'units' => 'bpm'],
                'min' => ['qty' => 98, 'units' => 'bpm'],
            ],
            'elevationUp'    => ['qty' => 41, 'units' => 'm'],
            'flightsClimbed' => ['qty' => 1, 'units' => 'count'],
            'intensity'      => ['qty' => 7.1408929, 'units' => 'kcal/hr·kg'],
            'stepCadence'    => ['qty' => 158.4, 'units' => 'count/min'],
            'speed'          => ['qty' => 7.24, 'units' => 'km/hr'],
            'avgSpeed'       => ['qty' => 6.98, 'units' => 'km'],
            'maxSpeed'       => ['qty' => 12.59, 'units' => 'km'],
            'temperature'    => ['qty' => 17.4, 'units' => 'degC'],
            'humidity'       => ['qty' => 73, 'units' => '%'],
            'isIndoor'       => false,
            'location'       => 'Outdoor',
            'metadata'       => [],

            // --- sample series: summed (steps) or dropped (everything else) --
            'stepCount' => [
                ['date' => $start, 'qty' => 21, 'units' => 'steps', 'source' => $source],
                ['date' => $start, 'qty' => 158.5, 'units' => 'steps', 'source' => $source],
            ],
            'heartRateData' => [
                ['date' => $start, 'Avg' => 121.02, 'Max' => 136, 'Min' => 113, 'units' => 'bpm', 'source' => $source],
            ],
            'route' => [
                [
                    'timestamp'          => $start,
                    'latitude'           => 52.0,
                    'longitude'          => 6.0,
                    'altitude'           => 12.03,
                    'speed'              => 0.02,
                    'course'             => 148.98,
                    'speedAccuracy'      => 0.58,
                    'courseAccuracy'     => 1462.26,
                    'verticalAccuracy'   => 1.41,
                    'horizontalAccuracy' => 2.36,
                ],
            ],
            'activeEnergy' => [
                ['date' => $start, 'qty' => 4.96, 'units' => 'kJ', 'source' => $source],
            ],
            'basalEnergy' => [
                ['date' => $start, 'qty' => 4.65, 'units' => 'kJ', 'source' => $source],
            ],
            'heartRateRecovery' => [
                ['date' => $end, 'Avg' => 66, 'Max' => 66, 'Min' => 66, 'units' => 'bpm', 'source' => $source],
            ],
            'walkingAndRunningDistance' => [
                ['date' => $start, 'qty' => 0.015, 'units' => 'km', 'source' => $source],
            ],
        ];

        foreach ($overrides as $key => $value) {
            if ($value === null) {
                unset($record[$key]);

                continue;
            }

            $record[$key] = $value;
        }

        $this->workouts[] = $record;

        return $this;
    }

    /**
     * The envelope, whichever automation sent it: `metrics`, `workouts`, or
     * both. Empty keys are omitted rather than sent as `[]`, because a real
     * export never sends an envelope for a data type it was not asked for and
     * the parser must not depend on one being there.
     *
     * @return array<string, mixed>
     */
    public function body(): array
    {
        $data = [];

        if ($this->metrics !== [] || $this->workouts === []) {
            $data['metrics'] = $this->metrics;
        }

        if ($this->workouts !== []) {
            $data['workouts'] = $this->workouts;
        }

        return ['data' => $data];
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->headers;
    }

    public function json(): string
    {
        return json_encode(
            $this->body(),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );
    }

    public function store(?CarbonImmutable $receivedAt = null): RawIngestPayload
    {
        $payload = RawIngestPayload::query()->create([
            'headers' => json_encode(
                $this->headers,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            'body'        => $this->json(),
            'received_at' => $receivedAt ?? CarbonImmutable::now(),
        ]);

        // The processor hashes the body as Postgres hands it back from jsonb, so
        // without a refresh the sha (and every whitespace-sensitive assertion)
        // is taken over the wrong string.
        return $payload->refresh();
    }

    /** An hour-aligned HAE timestamp, offset included. */
    public static function at(string $localDateTime, string $offset = '+0200'): string
    {
        return $localDateTime.' '.$offset;
    }
}
