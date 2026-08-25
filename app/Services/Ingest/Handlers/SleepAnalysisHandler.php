<?php

declare(strict_types=1);

namespace App\Services\Ingest\Handlers;

use Carbon\CarbonImmutable;
use App\Services\Ingest\HaeDate;
use App\Services\Ingest\SleepRow;
use App\Services\Ingest\MetricBlock;
use App\Services\Ingest\MetricCatalog;
use App\Services\Ingest\SourceResolver;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * `sleep_analysis` night records — 108 captured over 100 distinct nights.
 *
 *   {date, rem, core, deep, awake, totalSleep, inBed, asleep,
 *    sleepStart, sleepEnd, inBedStart, inBedEnd, source}
 *
 * Produces exactly one `sleep_sessions` row. The night key is HAE's own
 * `date` field, read on the DEVICE's clock (always local midnight) and
 * stored as a plain date — converting to UTC first would move every
 * record's night back a day, the classic way to lose a night's sleep to a
 * timezone. `inBed` and `asleep` come back as 0 from the Apple Watch in
 * every captured record and are stored as the 0 that arrived, not NULL:
 * "the watch said zero" and "nothing was reported" are different facts,
 * and the columns stay nullable so the second stays expressible.
 */
final readonly class SleepAnalysisHandler implements DatapointHandler
{
    /** payload field => sleep_sessions column. Values arrive in hours. */
    private const STAGES = [
        'rem'        => 'rem_minutes',
        'core'       => 'core_minutes',
        'deep'       => 'deep_minutes',
        'awake'      => 'awake_minutes',
        'asleep'     => 'asleep_minutes',
        'inBed'      => 'in_bed_minutes',
        'totalSleep' => 'total_sleep_minutes',
    ];

    public function __construct(private SourceResolver $sources) {}

    public function supports(MetricBlock $block, array $datapoint): bool
    {
        if ($block->name === MetricCatalog::SLEEP) {
            return true;
        }

        // Shape-first, like the other handlers: a night record is
        // recognisable without trusting the metric name.
        return array_key_exists('sleepStart', $datapoint)
            || array_key_exists('totalSleep', $datapoint);
    }

    public function handle(MetricBlock $block, array $datapoint): array
    {
        $date = $datapoint['date'] ?? null;

        if (! is_string($date)) {
            throw MalformedDatapoint::missingDate($block->name);
        }

        // Parsed in the device's offset and NOT converted: format('Y-m-d') on
        // this instance is the night HAE means.
        $night = HaeDate::parse($date, $block->name);

        $source = $this->sources->resolve(
            is_string($datapoint['source'] ?? null) ? $datapoint['source'] : null
        );

        $stageHours = [];

        foreach (self::STAGES as $field => $column) {
            $stageHours[$column] = $this->hours($block->name, $datapoint, $field);
        }

        return [SleepRow::make(
            nightDate: $night,
            sourceId: $source->id,
            inBedStart: $this->boundary($block->name, $datapoint, 'inBedStart'),
            inBedEnd: $this->boundary($block->name, $datapoint, 'inBedEnd'),
            sleepStart: $this->boundary($block->name, $datapoint, 'sleepStart'),
            sleepEnd: $this->boundary($block->name, $datapoint, 'sleepEnd'),
            stageHours: $stageHours,
            deviceUtcOffsetMinutes: HaeDate::offsetMinutes($night),
        )];
    }

    /**
     * @param  array<string, mixed>  $datapoint
     *
     * @throws MalformedDatapoint
     */
    private function hours(string $metric, array $datapoint, string $field): ?float
    {
        if (! array_key_exists($field, $datapoint) || $datapoint[$field] === null) {
            return null;
        }

        $value = $datapoint[$field];

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw MalformedDatapoint::nonNumeric($metric, $field, $value);
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $datapoint
     */
    private function boundary(string $metric, array $datapoint, string $field): ?CarbonImmutable
    {
        $value = $datapoint[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return HaeDate::parse($value, $metric);
    }
}
