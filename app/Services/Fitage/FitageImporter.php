<?php

declare(strict_types=1);

namespace App\Services\Fitage;

use App\Models\Source;
use App\Enums\DeviceKind;
use Carbon\CarbonImmutable;
use App\Enums\MetricAggregation;
use App\Services\Ingest\MetricRow;
use Illuminate\Support\Facades\DB;
use App\Services\Ingest\SourceResolver;
use App\Services\Rollup\SummaryRebuilder;
use App\Services\Ingest\HealthMetricWriter;

/**
 * Fitage .xlsx exports -> `health_metrics`, then rebuild the days they touched.
 *
 * Processes whatever is in the directory every time and is safe to re-run —
 * a new file adds only its own measurements — idempotent on the same
 * six-column unique key and HealthMetricWriter that HAE ingest uses,
 * so a measurement seen in two overlapping windows collides to one identity.
 * Near-duplicate rows already delivered via HealthKit are deliberately NOT
 * suppressed at import time (verified against production data); the
 * selection layer (BucketSelector::pickInstant) stays the one place that
 * decides what a day weighed, and collisions are counted, not discarded.
 * See docs/rationale-app.md § "FitageImporter: why near-duplicates aren't suppressed".
 */
final class FitageImporter
{
    /**
     * `period` is the bucket WIDTH, and these have none — the export gives
     * the instant a measurement was taken. 'instant' also keeps these rows
     * from colliding in the unique key with HAE's 'hour' rows for the same
     * weigh-in, which is correct since they're genuinely different
     * observations.
     */
    public const PERIOD = 'instant';

    public function __construct(
        private readonly HealthMetricWriter $writer = new HealthMetricWriter,
        private readonly SummaryRebuilder $rebuilder = new SummaryRebuilder,
    ) {}

    /**
     * @param  list<string>  $paths
     */
    public function import(array $paths, bool $dryRun = false): ImportOutcome
    {
        $timezone = (string) config('health.timezone');

        $source = $this->source($dryRun);

        $files = [];

        /*
         * Identities this run has already accounted for, carried ACROSS
         * files. A real run doesn't need this (file 2's lookup runs after
         * file 1's write), but a dry run writes nothing, so without it a
         * measurement in two overlapping windows would be reported as new
         * twice — exactly what the user's export habit produces.
         *
         * @var array<string, string> identity => value
         */
        $seen = [];

        foreach ($paths as $path) {
            $files[] = $this->importFile($path, $source, $timezone, $dryRun, $seen);
        }

        $outcome = new ImportOutcome($files, [], $dryRun);

        if ($dryRun) {
            return $outcome;
        }

        $dates = $outcome->dates();

        // The same rebuilder ingest uses, on its synchronous path — inline
        // since a human is watching at deploy time and the count should be a
        // fact, not a promise (RebuildDailySummariesCommand does the same).
        // Also fires the TDEE trigger; RecomputeTdee's lock is one constant
        // id, so forty-eight dirty days queue a single recompute.
        foreach ($dates as $date) {
            $this->rebuilder->now($date);
        }

        return new ImportOutcome($files, $dates, false);
    }

    /**
     * The `sources` row these metrics are attributed to.
     *
     * A dry run must not write, and creating this row IS a write, so a dry
     * run looks it up and, if absent, stands in an unsaved model with id 0
     * — nothing can be attributed to a source that doesn't exist, so every
     * value correctly reports as new and existingValues() exercises the
     * same code path the real run will.
     */
    private function source(bool $dryRun): Source
    {
        $rawName = (string) config('health.fitage.source_name', 'FITAGE export');

        if (! $dryRun) {
            return (new SourceResolver)->resolve($rawName);
        }

        $slug = Source::slugFor($rawName);

        return Source::query()->where('slug', $slug)->first() ?? new Source([
            'raw_name'    => $rawName,
            'name'        => Source::displayNameFor($rawName),
            'slug'        => $slug,
            'device_kind' => Source::kindFor($rawName),
        ]);
    }

    /**
     * @param  array<string, numeric-string>  $seen  identity => value, across files
     */
    private function importFile(
        string $path,
        Source $source,
        string $timezone,
        bool $dryRun,
        array &$seen,
    ): FileOutcome {
        $export = FitageExport::read($path, $timezone);

        $rows = $this->rowsFor($export, (int) $source->id);

        $existing = $this->existingValues($rows, (int) $source->id) + $seen;

        $tallies = [];

        foreach ($rows as $row) {
            $tally = $tallies[$row->metric] ??= new MetricTally;

            $tally->found++;

            $identity = $row->identity();
            $current = $existing[$identity] ?? null;

            if ($current === null) {
                $tally->new++;
            } elseif (bccomp($current, $row->value, 6) === 0) {
                $tally->duplicate++;
            } else {
                $tally->changed++;
            }

            // Whatever this run decided, a later file sees the value THIS one
            // is about to leave behind.
            $seen[$identity] = $row->value;
        }

        ksort($tallies);

        if (! $dryRun && $rows !== []) {
            $this->writer->write($rows, CarbonImmutable::now());
        }

        return new FileOutcome(
            path: $path,
            dataRows: $export->dataRows,
            measurements: count($export->measurements),
            tallies: $tallies,
            anomalies: $export->anomalies,
            dates: $export->dates(),
            firstAt: $export->firstAt(),
            lastAt: $export->lastAt(),
            sameHourAsHealthKit: $this->sameHourAsHealthKit($export, (int) $source->id),
        );
    }

    /**
     * Every measurement, flattened into upsertable rows.
     *
     * De-duplicated by identity within the file, last value winning — two
     * rows sharing an identity in one INSERT isn't a silent problem,
     * Postgres refuses the whole statement ("cannot affect row a second
     * time"), so one malformed export would otherwise fail the whole import.
     *
     * @return list<MetricRow>
     */
    private function rowsFor(FitageExport $export, int $sourceId): array
    {
        $units = FitageColumns::units();

        $rows = [];

        foreach ($export->measurements as $measurement) {
            foreach ($measurement->values as $metric => $value) {
                $row = MetricRow::make(
                    metric: $metric,
                    aggregation: MetricAggregation::Instant,
                    period: self::PERIOD,
                    value: $value,
                    unit: $units[$metric],
                    bucketStart: $measurement->measuredAt,
                    sourceId: $sourceId,
                );

                $rows[$row->identity()] = $row;
            }
        }

        return array_values($rows);
    }

    /**
     * The values already stored for these exact identities, from this source.
     *
     * One query per file, bounded to the identities about to be written, so
     * the dry run reports new/duplicate/changed without touching a row, and
     * the real run reports the same numbers rather than a second opinion.
     *
     * @param  list<MetricRow>  $rows
     * @return array<string, numeric-string> identity => the stored value
     */
    private function existingValues(array $rows, int $sourceId): array
    {
        if ($rows === []) {
            return [];
        }

        $instants = array_map(
            static fn (MetricRow $row): string => $row->startedAt->format('Y-m-d H:i:sP'),
            $rows
        );

        $stored = DB::table('health_metrics')
            ->where('source_id', $sourceId)
            ->whereIn('metric', array_values(array_unique(array_map(
                static fn (MetricRow $row): string => $row->metric,
                $rows
            ))))
            ->whereIn('started_at', array_values(array_unique($instants)))
            ->get(['metric', 'aggregation', 'period', 'started_at', 'ended_at', 'source_id', 'value']);

        $values = [];

        foreach ($stored as $row) {
            $identity = implode('|', [
                $row->metric,
                $row->aggregation,
                $row->period,
                CarbonImmutable::parse($row->started_at)->utc()->format('Y-m-d H:i:sP'),
                CarbonImmutable::parse($row->ended_at)->utc()->format('Y-m-d H:i:sP'),
                (int) $row->source_id,
            ]);

            $value = (string) $row->value;

            // `health_metrics.value` is numeric(16,6) and cannot read back as
            // anything else; saying so is what lets the tally compare it with
            // bccomp rather than through a float that would round.
            if (is_numeric($value)) {
                $values[$identity] = $value;
            }
        }

        return $values;
    }

    /**
     * Export weigh-ins whose hour already holds a scale weight from ANOTHER
     * source — the same physical measurement as HAE delivered it.
     *
     * Reported, never acted on (see the class docblock). The window is an
     * hour, not a few minutes, because HAE anchors its bucket at the top of
     * the hour, so a 15:38 weigh-in is stored at 15:00 and a tighter window
     * would miss it.
     */
    private function sameHourAsHealthKit(FitageExport $export, int $sourceId): int
    {
        $weighIns = array_values(array_filter(
            $export->measurements,
            static fn (Measurement $m): bool => $m->has('weight_body_mass')
        ));

        if ($weighIns === []) {
            return 0;
        }

        $instants = array_map(
            static fn (Measurement $m): CarbonImmutable => $m->measuredAt,
            $weighIns
        );

        $others = DB::table('health_metrics as hm')
            ->join('sources as s', 's.id', '=', 'hm.source_id')
            ->where('hm.metric', 'weight_body_mass')
            ->where('s.device_kind', DeviceKind::Scale->value)
            ->where('hm.source_id', '!=', $sourceId)
            ->whereBetween('hm.started_at', [
                min($instants)->utc()->subHour()->format('Y-m-d H:i:sP'),
                max($instants)->utc()->addHour()->format('Y-m-d H:i:sP'),
            ])
            ->pluck('hm.started_at')
            ->map(static fn (string $at): string => CarbonImmutable::parse($at)->setTimezone(
                (string) config('health.timezone')
            )->format('Y-m-d H'))
            ->all();

        $hours = array_flip($others);

        $collisions = 0;

        foreach ($weighIns as $measurement) {
            if (isset($hours[$measurement->measuredAt->format('Y-m-d H')])) {
                $collisions++;
            }
        }

        return $collisions;
    }
}
