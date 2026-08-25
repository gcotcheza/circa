<?php

declare(strict_types=1);

namespace App\Services\Ingest\Handlers;

use App\Enums\MetricAggregation;
use App\Services\Ingest\HaeDate;
use App\Services\Ingest\MetricRow;
use App\Services\Ingest\MetricBlock;
use App\Services\Ingest\MetricCatalog;
use App\Services\Ingest\SourceResolver;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * `{date, Min, Avg, Max, source}` — heart_rate, 2437 captured datapoints.
 *
 * Fans out into THREE rows sharing a bucket and source, separated only by
 * `aggregation` — in the six-column unique key precisely so they coexist;
 * without it the second row would overwrite the first.
 *
 * All three are last-writer-wins: a re-exported partial hour can
 * legitimately report a LOWER max than before (a different sample set), so
 * GREATEST would ratchet the maximum upward forever and turn one bad
 * reading into a permanent record.
 */
final readonly class MinAvgMaxHandler implements DatapointHandler
{
    /** field in the payload => statistic it represents */
    private const FIELDS = [
        'Min' => MetricAggregation::Min,
        'Avg' => MetricAggregation::Avg,
        'Max' => MetricAggregation::Max,
    ];

    public function __construct(private SourceResolver $sources) {}

    public function supports(MetricBlock $block, array $datapoint): bool
    {
        if (array_key_exists('qty', $datapoint)) {
            return false;
        }

        foreach (array_keys(self::FIELDS) as $field) {
            if ($this->field($datapoint, $field) !== null) {
                return true;
            }
        }

        return false;
    }

    public function handle(MetricBlock $block, array $datapoint): array
    {
        $date = $datapoint['date'] ?? null;

        if (! is_string($date)) {
            throw MalformedDatapoint::missingDate($block->name);
        }

        $bucketStart = HaeDate::parse($date, $block->name);

        $source = $this->sources->resolve(
            is_string($datapoint['source'] ?? null) ? $datapoint['source'] : null
        );

        $rows = [];

        foreach (self::FIELDS as $field => $aggregation) {
            $raw = $this->field($datapoint, $field);

            // Carrying only some of the three isn't an error — emit what's
            // there; inventing a missing Min is worse than lacking one.
            if ($raw === null) {
                continue;
            }

            [$value, $unit] = MetricCatalog::toCanonical(
                $block->name,
                (float) $raw,
                $block->deliveredUnit
            );

            $rows[] = MetricRow::make(
                metric: $block->name,
                aggregation: $aggregation,
                period: $block->period,
                value: $value,
                unit: $unit,
                bucketStart: $bucketStart,
                sourceId: $source->id,
            );
        }

        return $rows;
    }

    /**
     * Case-tolerant field read: HAE capitalises these ("Min"/"Avg"/"Max")
     * while every other key is lower-case — one release from being tidied
     * up, not worth risking a silent all-zero heart rate over.
     *
     * @param  array<string, mixed>  $datapoint
     */
    private function field(array $datapoint, string $name): int|float|string|null
    {
        foreach ([$name, mb_strtolower($name), mb_strtoupper($name)] as $candidate) {
            $value = $datapoint[$candidate] ?? null;

            if (is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))) {
                return $value;
            }
        }

        return null;
    }
}
