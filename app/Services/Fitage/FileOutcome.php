<?php

declare(strict_types=1);

namespace App\Services\Fitage;

/**
 * What one export file did (or, in a dry run, would do).
 *
 * Reported per file, not only in total — the user exports in windows, and
 * "the October file added three, the rest were already there" is what tells
 * them whether another export is worth doing.
 */
final readonly class FileOutcome
{
    /**
     * @param  array<string, MetricTally>  $tallies  metric => counts
     * @param  list<string>  $anomalies
     * @param  list<string>  $dates  local dates this file touches
     */
    public function __construct(
        public string $path,
        public int $dataRows,
        public int $measurements,
        public array $tallies,
        public array $anomalies,
        public array $dates,
        public ?string $firstAt,
        public ?string $lastAt,
        public int $sameHourAsHealthKit,
    ) {}

    public function name(): string
    {
        return basename($this->path);
    }

    public function totals(): MetricTally
    {
        $total = new MetricTally;

        foreach ($this->tallies as $tally) {
            $total->add($tally);
        }

        return $total;
    }
}
