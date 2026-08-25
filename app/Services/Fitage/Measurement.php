<?php

declare(strict_types=1);

namespace App\Services\Fitage;

use Carbon\CarbonImmutable;

/**
 * One weigh-in: the instant the user stood on the scale, and everything the
 * scale had to say about it.
 *
 * `values` holds only what was actually measured — a weigh-in in socks
 * records weight and BMI and nothing else, so this object has two entries,
 * not nine with seven zeroed.
 */
final readonly class Measurement
{
    /**
     * @param  CarbonImmutable  $measuredAt  on the reporting timezone's wall clock
     * @param  array<string, float>  $values  metric => value, canonical units
     */
    public function __construct(
        public CarbonImmutable $measuredAt,
        public array $values,
    ) {}

    /**
     * The local day this weigh-in belongs to.
     *
     * Matches `health_metrics.local_date` (Postgres generated column) by
     * construction — `measuredAt` is already on the reporting zone's clock,
     * the same zone baked into that column's DDL.
     */
    public function localDate(): string
    {
        return $this->measuredAt->toDateString();
    }

    public function has(string $metric): bool
    {
        return array_key_exists($metric, $this->values);
    }
}
