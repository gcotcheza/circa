<?php

declare(strict_types=1);

namespace App\Services\Ingest\Handlers;

use App\Services\Ingest\SleepRow;
use App\Services\Ingest\MetricRow;
use App\Services\Ingest\MetricBlock;

/**
 * One datapoint shape.
 *
 * Health Auto Export sends three, distinguished by the KEYS of the
 * datapoint object, not the metric name:
 *
 *   {date, qty, source}                     31 of the 33 observed metrics
 *   {date, Min, Avg, Max, source}           heart_rate
 *   {date, rem, core, deep, ..., source}    sleep_analysis
 *
 * Handlers dispatch on shape: if HAE ever gives another metric the
 * min/avg/max treatment (plausible — a summarisation feature, not a
 * heart-rate one) it fans out without a list to touch. Name is consulted
 * only where shape is genuinely ambiguous.
 */
interface DatapointHandler
{
    /**
     * @param  array<string, mixed>  $datapoint
     */
    public function supports(MetricBlock $block, array $datapoint): bool;

    /**
     * @param  array<string, mixed>  $datapoint
     * @return list<MetricRow|SleepRow>
     */
    public function handle(MetricBlock $block, array $datapoint): array;
}
