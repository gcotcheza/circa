<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use stdClass;
use Carbon\CarbonImmutable;

/**
 * One row destined for `workouts`, already canonicalised.
 *
 * Decimals are carried as STRINGS for the same reason MetricRow does: the
 * columns are `numeric`, and rounding once here means the same payload
 * replayed a second time produces byte-identical values, so the upsert
 * can prove idempotence by finding nothing to change. A float left for
 * the driver to stringify makes "did this row change?" depend on which
 * binary representation came back from a division by 4.184.
 *
 * `extras` is the only field not canonicalised, on purpose (see the
 * migration): the delivered long tail, verbatim, so a field HAE adds next
 * year is banked without a code change.
 */
final readonly class WorkoutRow implements UpsertRow
{
    /**
     * @param  array<string, mixed>  $extras
     */
    public function __construct(
        public string $appleUuid,
        public string $type,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $endedAt,
        public string $durationS,
        public ?string $distanceKm,
        public ?string $activeKcal,
        public ?int $avgHr,
        public ?int $maxHr,
        public ?int $stepCount,
        public ?string $elevationUpM,
        public ?bool $isIndoor,
        public ?string $intensity,
        public bool $isImplausible,
        public int $deviceUtcOffsetMinutes,
        public array $extras,
    ) {}

    /**
     * The unique key, for de-duplicating inside one payload.
     *
     * Handed to us by HealthKit rather than synthesised, which is what makes a
     * manual re-export of two years of history idempotent by construction.
     */
    public function identity(): string
    {
        return $this->appleUuid;
    }

    /**
     * Ordered to match WorkoutWriter::COLUMNS.
     *
     * @return list<string|int|bool|null>
     */
    public function bindings(CarbonImmutable $ingestedAt): array
    {
        return [
            $this->appleUuid,
            $this->type,
            $this->startedAt->format('Y-m-d H:i:s.uP'),
            $this->endedAt->format('Y-m-d H:i:s.uP'),
            $this->durationS,
            $this->distanceKm,
            $this->activeKcal,
            $this->avgHr,
            $this->maxHr,
            $this->stepCount,
            $this->elevationUpM,
            $this->isIndoor,
            $this->intensity,
            $this->isImplausible,
            $this->deviceUtcOffsetMinutes,
            json_encode(
                // An empty long tail is '{}' and not '[]': the column is jsonb
                // and every reader treats it as an object.
                $this->extras === [] ? new stdClass : $this->extras,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            ),
            $ingestedAt->format('Y-m-d H:i:s.uP'),
        ];
    }
}
