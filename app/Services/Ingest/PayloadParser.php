<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Illuminate\Support\Facades\Log;
use App\Services\Ingest\Handlers\DatapointHandler;
use App\Services\Ingest\Handlers\MinAvgMaxHandler;
use App\Services\Ingest\Exceptions\MalformedDatapoint;
use App\Services\Ingest\Handlers\SleepAnalysisHandler;
use App\Services\Ingest\Handlers\SimpleQuantityHandler;

/**
 * Decoded payload -> rows. Writes nothing except new `sources`.
 *
 * TWO ENVELOPES, ONE ENDPOINT:
 *
 *   { "data": { "metrics":  [ { name, units, data: [ ... ] }, ... ] } }
 *   { "data": { "workouts": [ { id, name, start, end, ... }, ... ] } }
 *
 * THE BRANCH IS ON THE BODY, NEVER A HEADER. The workouts automation sends
 * `automation-name: Workout`, a string typed into a phone text box: it can
 * be renamed in four taps, is absent on a hand-crafted request, and nothing
 * validates it. Branching on it would let a rename silently route two years
 * of training history into the metrics parser, which finds no
 * `data.metrics`, produces zero rows, and marks the run `empty` — data loss
 * that looks like a quiet week. The SHAPE cannot be renamed: a `workouts`
 * key carries workouts. Both keys are read on every payload, and an export
 * that carries both lands both.
 *
 * WHY A BAD DATAPOINT DOES NOT FAIL THE PAYLOAD. The phone exports hourly,
 * unattended; if one unrecognised sample threw, one new HAE metric shape
 * would stop every subsequent export from landing, unnoticed until a week
 * of history was missing. So per-datapoint problems are skipped, counted,
 * logged, and summarised onto `ingest_runs.error`. The safety net is
 * already in the schema: a payload whose datapoints ALL skip produces zero
 * rows, status `empty`, the gap signal the spec alerts on — and nothing is
 * lost either way, since `raw_ingest_payloads` holds every byte and
 * `ingest:replay` re-runs the fixed parser over it. Errors that are NOT
 * per-datapoint (an undecodable body, a database failure) still propagate.
 *
 * FOUR THINGS USED TO ESCAPE THIS CATCH, none needing an attacker — each is
 * what a normal export looks like the week HAE ships something new:
 *
 *   - a number too large for numeric(16,6) (MetricRow now rejects it),
 *   - a unit with no conversion (UnknownUnitConversion is a
 *     MalformedDatapoint now, not a bare RuntimeException),
 *   - a metric name, unit or bucket width longer than its column (bounded
 *     in MetricRow),
 *   - a device slug longer than sources.slug (bounded in SourceResolver,
 *     which degrades to `unknown` rather than rethrowing).
 *
 * Each failed the transaction, so ONE new sample cost the ~250 good
 * readings that arrived with it — every hour, until somebody noticed.
 */
final class PayloadParser
{
    /**
     * @param  array<mixed>  $body  decoded request body
     * @param  array<string, mixed>  $headers  as banked on raw_ingest_payloads
     */
    public function parse(array $body, array $headers): ParsedPayload
    {
        $period = BucketWidth::fromHeaders($headers);

        // Fresh per payload: see SourceResolver on why the cache is scoped this
        // narrowly.
        $sources = new SourceResolver;

        /** @var list<DatapointHandler> $handlers */
        $handlers = [
            // Sleep first — its records have neither `qty` nor Min/Avg/Max;
            // cheapest question first keeps this readable.
            new SleepAnalysisHandler($sources),
            new MinAvgMaxHandler($sources),
            new SimpleQuantityHandler($sources),
        ];

        $metricRows = [];
        $sleepRows = [];
        $skipped = [];
        $datapoints = 0;
        $blocks = 0;

        foreach ($this->metricBlocks($body) as $rawBlock) {
            $name = $rawBlock['name'] ?? null;

            if (! is_string($name) || $name === '') {
                $skipped[] = 'Metric block with no usable `name`.';

                continue;
            }

            $blocks++;

            $block = new MetricBlock(
                name: $name,
                deliveredUnit: is_string($rawBlock['units'] ?? null) ? $rawBlock['units'] : '',
                period: $period,
            );

            $data = $rawBlock['data'] ?? [];

            if (! is_array($data)) {
                $skipped[] = sprintf('Metric [%s]: `data` is not an array.', $name);

                continue;
            }

            foreach ($data as $datapoint) {
                $datapoints++;

                if (! is_array($datapoint)) {
                    $skipped[] = sprintf('Metric [%s]: datapoint is not an object.', $name);

                    continue;
                }

                try {
                    $rows = $this->route($handlers, $block, $datapoint);
                } catch (MalformedDatapoint $e) {
                    $skipped[] = $e->getMessage();

                    Log::warning('ingest.datapoint_skipped', [
                        'metric' => $name,
                        'reason' => $e->getMessage(),
                    ]);

                    continue;
                }

                foreach ($rows as $row) {
                    if ($row instanceof SleepRow) {
                        $this->collectNight($sleepRows, $row);

                        continue;
                    }

                    $this->collect($metricRows, $row);
                }
            }
        }

        $workoutRows = $this->workouts($body, $skipped, $datapoints);

        return new ParsedPayload(
            metricRows: array_values($metricRows),
            sleepRows: array_values($sleepRows),
            workoutRows: array_values($workoutRows),
            datapoints: $datapoints,
            metricBlocks: $blocks,
            skipped: $skipped,
        );
    }

    /**
     * `data.workouts[]` -> rows, de-duplicated on the HealthKit uuid.
     *
     * Same per-record tolerance as the metric loop, for the same reason: an
     * unreadable workout must not cost the others in the POST or stop
     * tomorrow's export.
     *
     * Collapsing inside the payload is not tidiness — a multi-row `INSERT
     * ... ON CONFLICT` whose VALUES share a conflict key fails outright
     * with "cannot affect row a second time". Last one wins, the same rule
     * WorkoutWriter applies across payloads.
     *
     * @param  array<mixed>  $body
     * @param  list<string>  $skipped
     * @return array<string, WorkoutRow>
     */
    private function workouts(array $body, array &$skipped, int &$datapoints): array
    {
        $reader = new WorkoutReader;
        $rows = [];

        foreach ($this->workoutRecords($body) as $record) {
            $datapoints++;

            try {
                $row = $reader->read($record);
            } catch (MalformedDatapoint $e) {
                $skipped[] = $e->getMessage();

                Log::warning('ingest.workout_skipped', ['reason' => $e->getMessage()]);

                continue;
            }

            $rows[$row->identity()] = $row;
        }

        return $rows;
    }

    /**
     * @param  list<DatapointHandler>  $handlers
     * @param  array<string, mixed>  $datapoint
     * @return list<MetricRow|SleepRow>
     *
     * @throws MalformedDatapoint
     */
    private function route(array $handlers, MetricBlock $block, array $datapoint): array
    {
        foreach ($handlers as $handler) {
            if ($handler->supports($block, $datapoint)) {
                return $handler->handle($block, $datapoint);
            }
        }

        throw MalformedDatapoint::unrecognisedShape($block->name, $datapoint);
    }

    /**
     * Collapse a duplicate identity inside one payload the same way the
     * database would resolve it across payloads.
     *
     * @param  array<string, MetricRow>  $rows
     */
    private function collect(array &$rows, MetricRow $row): void
    {
        $key = $row->identity();
        $existing = $rows[$key] ?? null;

        if ($existing === null) {
            $rows[$key] = $row;

            return;
        }

        $rows[$key] = $row->cumulative
            ? ($row->isLargerThan($existing) ? $row : $existing)
            : $row;
    }

    /**
     * The same collapse for a night, under SleepRow's completeness predicate.
     *
     * Not "keep the last": a payload carrying both the full night and a clipped
     * restatement of it must collapse to the full one, or the truncation the
     * writer refuses across payloads would walk in inside a single one.
     *
     * @param  array<string, SleepRow>  $rows
     */
    private function collectNight(array &$rows, SleepRow $row): void
    {
        $key = $row->identity();
        $existing = $rows[$key] ?? null;

        $rows[$key] = $existing === null || $row->supersedes($existing)
            ? $row
            : $existing;
    }

    /**
     * `data.metrics[]`, tolerating the shapes a future export version might use.
     *
     * @param  array<mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function metricBlocks(array $body): array
    {
        $metrics = $body['data']['metrics'] ?? $body['metrics'] ?? [];

        if (! is_array($metrics)) {
            return [];
        }

        return array_values(array_filter($metrics, is_array(...)));
    }

    /**
     * `data.workouts[]`, tolerating the same alternative nesting the metric
     * blocks tolerate.
     *
     * @param  array<mixed>  $body
     * @return list<array<string, mixed>>
     */
    private function workoutRecords(array $body): array
    {
        $workouts = $body['data']['workouts'] ?? $body['workouts'] ?? [];

        if (! is_array($workouts)) {
            return [];
        }

        return array_values(array_filter($workouts, is_array(...)));
    }
}
