<?php

declare(strict_types=1);

namespace App\Services\Ingest\Handlers;

use App\Services\Ingest\HaeDate;
use App\Services\Ingest\MetricRow;
use App\Services\Ingest\MetricBlock;
use App\Services\Ingest\MetricCatalog;
use App\Services\Ingest\SourceResolver;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * `{date, qty, source}` — 31 of the 33 observed metrics, 24,675 of the
 * 27,112 captured datapoints.
 *
 * One datapoint, one row: the only decisions are which statistic the
 * number is (MetricCatalog, which also decides bucket zero-length) and
 * which unit to store it in.
 */
final readonly class SimpleQuantityHandler implements DatapointHandler
{
    public function __construct(private SourceResolver $sources) {}

    public function supports(MetricBlock $block, array $datapoint): bool
    {
        return array_key_exists('qty', $datapoint);
    }

    public function handle(MetricBlock $block, array $datapoint): array
    {
        $qty = $datapoint['qty'];

        if (! is_int($qty) && ! is_float($qty) && ! (is_string($qty) && is_numeric($qty))) {
            throw MalformedDatapoint::nonNumeric($block->name, 'qty', $qty);
        }

        $date = $datapoint['date'] ?? null;

        if (! is_string($date)) {
            throw MalformedDatapoint::missingDate($block->name);
        }

        $bucketStart = HaeDate::parse($date, $block->name);

        [$value, $unit] = MetricCatalog::toCanonical(
            $block->name,
            (float) $qty,
            $block->deliveredUnit
        );

        $source = $this->sources->resolve(
            is_string($datapoint['source'] ?? null) ? $datapoint['source'] : null
        );

        return [MetricRow::make(
            metric: $block->name,
            aggregation: MetricCatalog::aggregationFor($block->name),
            period: $block->period,
            value: $value,
            unit: $unit,
            bucketStart: $bucketStart,
            sourceId: $source->id,
        )];
    }
}
