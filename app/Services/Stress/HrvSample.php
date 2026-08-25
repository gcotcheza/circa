<?php

declare(strict_types=1);

namespace App\Services\Stress;

use Carbon\CarbonImmutable;
use App\Services\Rollup\MetricBucket;

/**
 * One accepted HRV reading, in the terms the estimator thinks in.
 *
 * `lnValue` is precomputed and stored, not derived on use, because every
 * downstream stage — circadian profile, day level, baseline, heatmap —
 * works in log space, and a full rebuild would otherwise take the log of
 * each of the 13 000 production readings several times over.
 *
 * Log space at all: HRV is right-skewed (the same physiological change is
 * "+8 ms" at a good baseline, "+4 ms" at a poor one), so milliseconds aren't
 * comparable across levels while a RATIO is — the log turns that ratio into
 * a difference, the thing a mean, median or z-score is entitled to. Production
 * bears it out: ln(HRV) has sd 0.375 and is close to symmetric, where the raw
 * values are visibly skewed (mean 36.2 against a median of 33.7).
 */
final readonly class HrvSample
{
    private function __construct(
        /** Local calendar date the reading is attributed to (YYYY-MM-DD). */
        public string $localDate,
        /** Local hour the bucket STARTS in, 0-23. */
        public int $hour,
        /** ISO weekday of `localDate`: 1 = Monday … 7 = Sunday. */
        public int $weekday,
        public float $valueMs,
        public float $lnValue,
        public int $durationSeconds,
    ) {}

    /**
     * Null for a reading the log can't be taken of.
     *
     * A zero or negative HRV is a corrupt row, not a quiet day, so the
     * honest move is to drop it rather than clamp it to something
     * plausible. Never observed in production (min is 4.8 ms) — this is a
     * guard, not a workaround.
     */
    public static function fromBucket(MetricBucket $bucket, string $timezone): ?self
    {
        if ($bucket->value <= 0.0) {
            return null;
        }

        $local = $bucket->startedAt->setTimezone($timezone);

        return new self(
            localDate: $local->toDateString(),
            hour: (int) $local->format('G'),
            weekday: (int) $local->isoWeekday(),
            valueMs: $bucket->value,
            lnValue: log($bucket->value),
            // A zero-length instant means the metric isn't bucketed the way
            // this feature assumes; the real duration lets coverage report
            // what arrived rather than assume an hour.
            durationSeconds: $bucket->durationSeconds(),
        );
    }

    /** Escape hatch for tests and fixtures: a sample from plain values. */
    public static function make(
        string $localDate,
        int $hour,
        float $valueMs,
        int $durationSeconds = 3600,
        ?string $timezone = null,
    ): self {
        $weekday = (int) CarbonImmutable::parse($localDate, $timezone)->isoWeekday();

        return new self(
            localDate: $localDate,
            hour: $hour,
            weekday: $weekday,
            valueMs: $valueMs,
            lnValue: log($valueMs),
            durationSeconds: $durationSeconds,
        );
    }
}
