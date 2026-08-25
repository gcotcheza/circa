<?php

declare(strict_types=1);

namespace App\Services\Ingest;

/**
 * The outcome of parsing and writing one payload.
 *
 * `rowCount()` (-> `ingest_runs.row_count`) is rows REPRESENTED, not rows
 * changed. If it meant "changed", every replay of an already-parsed
 * payload would report 0 and trip the zero-rows gap alert. Represented is
 * stable across replays, which is exactly what the gap detector needs: 0
 * then means "this payload had no datapoints" — the real silence worth
 * alerting on.
 */
final class ParseResult
{
    public function __construct(
        public UpsertStats $metrics = new UpsertStats,
        public UpsertStats $sleep = new UpsertStats,
        /**
         * Workouts REPRESENTED, under the same rule as the two above: a
         * replayed session that changes nothing still counts, so the run
         * reports its real size instead of reading as the silence the gap
         * detector alerts on.
         */
        public UpsertStats $workouts = new UpsertStats,
        public int $datapoints = 0,
        public int $metricBlocks = 0,
        /** @var list<string> */
        public array $skipped = [],
        /**
         * Local dates whose `daily_summaries` row this payload invalidated,
         * carried out so the caller can dispatch one rebuild per date
         * instead of guessing or rebuilding everything.
         *
         * @var list<string>
         */
        public array $dirtyDates = [],
    ) {}

    public function rowCount(): int
    {
        return $this->metrics->total() + $this->sleep->total() + $this->workouts->total();
    }

    public function touched(): int
    {
        return $this->metrics->touched() + $this->sleep->touched() + $this->workouts->touched();
    }

    public function producedNothing(): bool
    {
        return $this->rowCount() === 0;
    }

    public function add(self $other): void
    {
        $this->metrics->add($other->metrics);
        $this->sleep->add($other->sleep);
        $this->workouts->add($other->workouts);
        $this->datapoints += $other->datapoints;
        $this->metricBlocks += $other->metricBlocks;
        $this->skipped = array_merge($this->skipped, $other->skipped);
        $this->dirtyDates = array_values(array_unique(
            array_merge($this->dirtyDates, $other->dirtyDates)
        ));
    }

    /**
     * Short human summary of skipped datapoints, for `ingest_runs.error`.
     *
     * Capped: a systematically broken export would otherwise write thousands of
     * near-identical lines into a text column nobody can read.
     */
    public function skippedSummary(int $examples = 3): ?string
    {
        if ($this->skipped === []) {
            return null;
        }

        return sprintf(
            'Skipped %d datapoint(s). First %d: %s',
            count($this->skipped),
            min($examples, count($this->skipped)),
            implode(' | ', array_slice($this->skipped, 0, $examples))
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric_blocks' => $this->metricBlocks,
            'datapoints'    => $this->datapoints,
            'metrics'       => $this->metrics->toArray(),
            'sleep'         => $this->sleep->toArray(),
            'workouts'      => $this->workouts->toArray(),
            'skipped'       => count($this->skipped),
            'row_count'     => $this->rowCount(),
        ];
    }
}
