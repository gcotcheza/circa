<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;
use App\Enums\MetricAggregation;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * One row destined for `health_metrics`, already canonicalised.
 *
 * Values are decimal STRINGS, not floats: `health_metrics.value` is
 * numeric(16,6), and rounding to six places once here means a replay
 * produces byte-identical values, so the upsert can prove idempotence by
 * finding nothing to change. A float left for the driver to stringify
 * would make "did this change?" depend on whichever binary representation
 * a division produced — 319.6423518... vs 319.642352, a difference
 * Postgres will happily write forever.
 */
final readonly class MetricRow implements UpsertRow
{
    /**
     * The column widths, restated in PHP.
     *
     * `metric` (`data.metrics[].name`), `unit` (falls back to delivered
     * `units`), and `period` (unrecognised header kept verbatim, see
     * BucketWidth) are all varchar and none was bounded here, so one long
     * string once reached Postgres, raised 22001, and failed the whole
     * transaction — dropping the ~250 good readings alongside it.
     *
     * Truncated rather than skipped: the fault is cosmetic, the reading
     * real, the bytes banked, and a name folded at 64 characters still
     * recognisable. Truncation happens BEFORE identity(), so a payload
     * carrying the same over-long name twice still collapses to one row
     * instead of tripping the upsert's "cannot affect row a second time".
     */
    private const MAX_METRIC = 64;   // health_metrics.metric

    private const MAX_UNIT = 32;     // health_metrics.unit

    private const MAX_PERIOD = 16;   // health_metrics.period

    /**
     * numeric(16,6): 6 digits after the point of 16 total, so it tops out
     * just under 1e10. INF (what `(float)` makes of "1e400") and NAN
     * aren't representable at all.
     */
    private const MAX_ABS_VALUE = 1e10;

    /** @param  numeric-string  $value */
    private function __construct(
        public string $metric,
        public MetricAggregation $aggregation,
        public string $period,
        public string $value,
        public string $unit,
        public CarbonImmutable $startedAt,
        public CarbonImmutable $endedAt,
        public int $deviceUtcOffsetMinutes,
        public int $sourceId,
        public bool $cumulative,
    ) {}

    /**
     * @param  CarbonImmutable  $bucketStart  in the device's own offset
     *
     * @throws MalformedDatapoint when the number cannot be stored at all
     */
    public static function make(
        string $metric,
        MetricAggregation $aggregation,
        string $period,
        float $value,
        string $unit,
        CarbonImmutable $bucketStart,
        int $sourceId,
    ): self {
        // Can't be folded into something storable: no honest truncation of
        // INF, and clamping to 1e10 would be a reading this app invented.
        // Skip it, keep the payload.
        if (! is_finite($value) || abs($value) >= self::MAX_ABS_VALUE) {
            throw MalformedDatapoint::outOfRange($metric, 'qty');
        }

        $metric = mb_substr($metric, 0, self::MAX_METRIC);
        $period = mb_substr($period, 0, self::MAX_PERIOD);
        $unit = mb_substr($unit, 0, self::MAX_UNIT);

        // Instant = zero-length interval; else spans the bucket width on
        // the device-local clock, so a DST-boundary hour still covers one
        // wall-clock hour.
        $endsAt = $aggregation === MetricAggregation::Instant
            ? $bucketStart
            : BucketWidth::endOf($bucketStart, $period);

        return new self(
            metric: $metric,
            aggregation: $aggregation,
            period: $period,
            value: self::decimal($value),
            unit: $unit,
            startedAt: $bucketStart->utc(),
            endedAt: $endsAt->utc(),
            deviceUtcOffsetMinutes: HaeDate::offsetMinutes($bucketStart),
            sourceId: $sourceId,
            // GREATEST() on write is decided by the metric, not the
            // aggregation enum — a min/avg/max row of a cumulative metric
            // (none observed, not forbidden) can't ratchet.
            cumulative: $aggregation === MetricAggregation::Sum && MetricCatalog::isCumulative($metric),
        );
    }

    /** The six-column unique key, as a string, for in-payload de-duplication. */
    public function identity(): string
    {
        return implode('|', [
            $this->metric,
            $this->aggregation->value,
            $this->period,
            $this->startedAt->format('Y-m-d H:i:sP'),
            $this->endedAt->format('Y-m-d H:i:sP'),
            $this->sourceId,
        ]);
    }

    /**
     * Ordered to match HealthMetricWriter::COLUMNS.
     *
     * @return list<string|int>
     */
    public function bindings(CarbonImmutable $ingestedAt): array
    {
        return [
            $this->metric,
            $this->aggregation->value,
            $this->period,
            $this->value,
            $this->unit,
            $this->startedAt->format('Y-m-d H:i:s.uP'),
            $this->endedAt->format('Y-m-d H:i:s.uP'),
            $this->deviceUtcOffsetMinutes,
            $this->sourceId,
            $ingestedAt->format('Y-m-d H:i:s.uP'),
        ];
    }

    /** Numeric comparison, for reducing duplicates inside one payload. */
    public function isLargerThan(self $other): bool
    {
        return bccomp($this->value, $other->value, 6) === 1;
    }

    /**
     * numeric(16,6), rendered locale-independently.
     *
     * @return numeric-string
     */
    private static function decimal(float $value): string
    {
        return sprintf('%.6F', $value);
    }
}
