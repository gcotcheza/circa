<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IngestRun;
use Carbon\CarbonImmutable;
use App\Enums\IngestRunStatus;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * The exact header set observed on every captured request.
 *
 * Note which value goes where: `automation-aggregation` is "Hours" (the bucket
 * width) and `automation-period` is "Since Last Sync" (the export window). They
 * are stored verbatim under those names so the interpretation stays auditable.
 *
 * @extends Factory<IngestRun>
 */
final class IngestRunFactory extends Factory
{
    protected $model = IngestRun::class;

    /** The single automation id seen across every captured payload. */
    public const AUTOMATION_ID = 'EFCACB12-48A7-4D2C-9C83-3DCD30807474';

    public function definition(): array
    {
        return [
            'raw_ingest_payload_id'  => null,
            'session_id'             => mb_strtoupper($this->faker->uuid()),
            'body_sha256'            => hash('sha256', $this->faker->unique()->sentence()),
            'automation_name'        => 'Health tracker',
            'automation_id'          => self::AUTOMATION_ID,
            'automation_aggregation' => 'Hours',
            'automation_period'      => 'Since Last Sync',
            'row_count'              => $this->faker->numberBetween(1, 315),
            'status'                 => IngestRunStatus::Completed,
            'error'                  => null,
            'received_at'            => CarbonImmutable::now(),
            'processed_at'           => CarbonImmutable::now(),
        ];
    }

    /** A run that parsed fine and produced nothing — the gap signal. */
    public function producedNothing(): static
    {
        return $this->state(fn (): array => [
            'row_count' => 0,
            'status'    => IngestRunStatus::Empty,
        ]);
    }

    public function failed(string $error = 'Malformed metrics array'): static
    {
        return $this->state(fn (): array => [
            'row_count'    => 0,
            'status'       => IngestRunStatus::Failed,
            'error'        => $error,
            'processed_at' => CarbonImmutable::now(),
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (): array => [
            'status'       => IngestRunStatus::Pending,
            'row_count'    => 0,
            'processed_at' => null,
        ]);
    }
}
