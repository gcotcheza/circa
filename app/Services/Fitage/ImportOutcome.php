<?php

declare(strict_types=1);

namespace App\Services\Fitage;

/**
 * The whole run: every file, plus what the rollup did about it.
 */
final readonly class ImportOutcome
{
    /**
     * @param  list<FileOutcome>  $files
     * @param  list<string>  $rebuiltDates  empty on a dry run
     */
    public function __construct(
        public array $files,
        public array $rebuiltDates,
        public bool $dryRun,
    ) {}

    /** @return array<string, MetricTally> metric => counts across every file */
    public function tallies(): array
    {
        $tallies = [];

        foreach ($this->files as $file) {
            foreach ($file->tallies as $metric => $tally) {
                $tallies[$metric] ??= new MetricTally;
                $tallies[$metric]->add($tally);
            }
        }

        ksort($tallies);

        return $tallies;
    }

    public function totals(): MetricTally
    {
        $total = new MetricTally;

        foreach ($this->tallies() as $tally) {
            $total->add($tally);
        }

        return $total;
    }

    public function measurements(): int
    {
        return array_sum(array_map(static fn (FileOutcome $f): int => $f->measurements, $this->files));
    }

    /** @return list<string> every local date any file touched */
    public function dates(): array
    {
        $dates = [];

        foreach ($this->files as $file) {
            $dates = [...$dates, ...$file->dates];
        }

        $dates = array_values(array_unique($dates));

        sort($dates);

        return $dates;
    }

    /** @return list<string> */
    public function anomalies(): array
    {
        $anomalies = [];

        foreach ($this->files as $file) {
            foreach ($file->anomalies as $anomaly) {
                $anomalies[] = $file->name().': '.$anomaly;
            }
        }

        return $anomalies;
    }

    public function sameHourAsHealthKit(): int
    {
        return array_sum(array_map(static fn (FileOutcome $f): int => $f->sameHourAsHealthKit, $this->files));
    }
}
