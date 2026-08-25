<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * One entry of `data.metrics[]`, minus its datapoints.
 *
 *   { "name": "active_energy", "units": "kJ", "data": [ ... ] }
 *
 * The unit lives on the block, not the datapoint, so it must be carried
 * alongside every sample — this does that carrying, together with the
 * bucket width derived from the request headers.
 */
final readonly class MetricBlock
{
    public function __construct(
        public string $name,
        public string $deliveredUnit,
        public string $period,
    ) {}
}
