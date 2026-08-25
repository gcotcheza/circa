<?php

declare(strict_types=1);

namespace App\Services\Rollup;

use stdClass;
use App\Enums\DeviceKind;
use Carbon\CarbonImmutable;

/**
 * One `health_metrics` row, as the rollup sees it: a half-open interval
 * [started_at, ended_at) carrying a value from one device kind.
 *
 * Half-open is the whole point. Health Auto Export's hourly buckets are
 * back-to-back — 09:00-10:00 and 10:00-11:00 — and a closed interval would call
 * those an overlap and throw one of them away.
 */
final readonly class MetricBucket
{
    public function __construct(
        public int $id,
        public string $metric,
        public int $sourceId,
        public DeviceKind $deviceKind,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $endedAt,
        public float $value,
    ) {}

    /**
     * One `health_metrics` row joined to its source, as the query builder
     * hands it over: an untyped `stdClass` carrying `id`, `metric`,
     * `source_id`, `device_kind`, `started_at`, `ended_at` and `value`.
     * Coerced here rather than trusted, since nothing between the SELECT
     * and this line knows what the columns are.
     */
    public static function fromRow(stdClass $row): self
    {
        return new self(
            id: (int) $row->id,
            metric: (string) $row->metric,
            sourceId: (int) $row->source_id,
            deviceKind: DeviceKind::from((string) $row->device_kind),
            startedAt: CarbonImmutable::parse((string) $row->started_at),
            endedAt: CarbonImmutable::parse((string) $row->ended_at),
            value: (float) $row->value,
        );
    }

    public function durationSeconds(): int
    {
        return $this->endedAt->getTimestamp() - $this->startedAt->getTimestamp();
    }

    /** An instant (weight, body fat) — zero-length, so it can never overlap. */
    public function isInstant(): bool
    {
        return $this->durationSeconds() === 0;
    }

    /**
     * Strict interval overlap on [start, end).
     *
     * Touching bounds do not overlap: that is what makes a tiled hourly day
     * survive selection intact.
     */
    public function overlaps(self $other): bool
    {
        if ($this->isInstant() || $other->isInstant()) {
            return false;
        }

        return $this->startedAt < $other->endedAt && $other->startedAt < $this->endedAt;
    }
}
