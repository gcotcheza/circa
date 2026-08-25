<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a `health_metrics.value` *is* statistically, over its bucket.
 *
 * NOT the `automation-aggregation` header — that's bucket *width* ("Hours"),
 * landing in `health_metrics.period`. This is a property of the metric and
 * the field the value came from:
 *
 *   - `qty` on a cumulative metric (step_count, active_energy)  → SUM
 *   - `qty` on a rate/level metric (heart_rate_variability, %)  → AVG
 *   - `qty` on a body-composition metric (weight_body_mass)     → INSTANT
 *   - `Min` / `Avg` / `Max` on heart_rate                       → MIN/AVG/MAX
 *
 * heart_rate is the only metric with the triple shape, landing as three
 * rows per bucket — why this column is part of the unique key.
 *
 * The metric → aggregation classification is parser work (step 2).
 */
enum MetricAggregation: string
{
    /** A point-in-time sample. `started_at` == `ended_at`. */
    case Instant = 'instant';

    /** Total accumulated over the bucket. Upserts with GREATEST(). */
    case Sum = 'sum';

    /** Mean over the bucket. Last-writer-wins. */
    case Avg = 'avg';

    /** Lowest sample in the bucket. Last-writer-wins. */
    case Min = 'min';

    /** Highest sample in the bucket. Last-writer-wins. */
    case Max = 'max';

    /**
     * Cumulative metrics grow when a partial trailing bucket is re-exported,
     * so their upsert keeps the larger value. Everything else is a
     * restatement and takes the newest.
     */
    public function isCumulative(): bool
    {
        return $this === self::Sum;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(static fn (self $c): string => $c->value, self::cases());
    }
}
