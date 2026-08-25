<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Services\Fitage\FileOutcome;
use App\Services\Fitage\MetricTally;
use App\Services\Fitage\ImportOutcome;
use App\Services\Fitage\FitageImporter;
use App\Services\Fitage\Exceptions\UnreadableExport;

/**
 * Directory, not file: exports arrive as overlapping windows, so pointing
 * at all of them every time is a re-run, not a "what's new" decision.
 * Overlaps are free — see docs/rationale-app.md § "FitageImporter: why
 * near-duplicates aren't suppressed".
 */
final class FitageImportCommand extends Command
{
    protected $signature = 'fitage:import
                            {--dir= : Directory of .xlsx exports (default: config health.fitage.directory)}
                            {--file=* : Import only these files (repeatable); overrides --dir}
                            {--dry-run : Report what would be imported and write nothing}';

    protected $description = 'Import Fitage scale body-composition exports (.xlsx) into health_metrics';

    public function handle(FitageImporter $importer): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $paths = $this->paths();
        } catch (UnreadableExport $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($paths === []) {
            $this->warn(sprintf('No .xlsx files found in %s — nothing to import.', $this->directory()));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '<info>%s %d file(s)</info>',
            $dryRun ? 'Dry run over' : 'Importing',
            count($paths),
        ));
        $this->newLine();

        try {
            $outcome = $importer->import($paths, $dryRun);
        } catch (UnreadableExport $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->report($outcome);

        if (! $dryRun) {
            Log::info('fitage:import completed', [
                'files'        => count($paths),
                'measurements' => $outcome->measurements(),
                'values'       => $outcome->totals()->found,
                'new'          => $outcome->totals()->new,
                'changed'      => $outcome->totals()->changed,
                'duplicate'    => $outcome->totals()->duplicate,
                'days_rebuilt' => count($outcome->rebuiltDates),
            ]);
        }

        return self::SUCCESS;
    }

    private function report(ImportOutcome $outcome): void
    {
        foreach ($outcome->files as $file) {
            $this->reportFile($file);
        }

        $totals = $outcome->totals();

        $this->line('<comment>All files</comment>');
        $this->line(sprintf(
            '  %d measurement(s), %d value(s): %d new, %d changed, %d already stored',
            $outcome->measurements(),
            $totals->found,
            $totals->new,
            $totals->changed,
            $totals->duplicate,
        ));

        $dates = $outcome->dates();

        if ($dates !== []) {
            $this->line(sprintf('  %d local day(s): %s → %s', count($dates), $dates[0], end($dates)));
        }

        $this->line(sprintf(
            '  %d weigh-in(s) share an hour with a HealthKit scale sample (kept — see FitageImporter)',
            $outcome->sameHourAsHealthKit(),
        ));

        $tallies = $outcome->tallies();

        $this->newLine();
        $this->table(
            ['metric', 'found', 'new', 'changed', 'already stored'],
            array_map(
                static fn (string $metric, MetricTally $tally): array => [
                    $metric,
                    $tally->found,
                    $tally->new,
                    $tally->changed,
                    $tally->duplicate,
                ],
                array_keys($tallies),
                array_values($tallies),
            ),
        );

        $anomalies = $outcome->anomalies();

        if ($anomalies !== []) {
            $this->newLine();
            $this->line('<comment>Skipped</comment>');

            foreach ($anomalies as $anomaly) {
                $this->line('  '.$anomaly);
            }
        }

        $this->newLine();

        if ($outcome->dryRun) {
            $this->line('<info>Dry run — nothing was written and no summary was rebuilt.</info>');

            return;
        }

        $this->line(sprintf('<info>Rebuilt %d daily summary row(s).</info>', count($outcome->rebuiltDates)));
    }

    private function reportFile(FileOutcome $file): void
    {
        $this->line(sprintf('<comment>%s</comment>', $file->name()));

        $totals = $file->totals();

        $this->line(sprintf(
            '  %d row(s) → %d measurement(s), %d value(s): %d new, %d changed, %d already stored',
            $file->dataRows,
            $file->measurements,
            $totals->found,
            $totals->new,
            $totals->changed,
            $totals->duplicate,
        ));

        if ($file->firstAt !== null) {
            $this->line(sprintf('  %s → %s', $file->firstAt, $file->lastAt));
        }

        $this->newLine();
    }

    /**
     * Sorted so two runs of the same directory report in the same order.
     *
     * @return list<string>
     *
     * @throws UnreadableExport
     */
    private function paths(): array
    {
        /** @var list<string> $files */
        $files = (array) $this->option('file');

        if ($files !== []) {
            foreach ($files as $file) {
                if (! is_file($file) || ! is_readable($file)) {
                    throw UnreadableExport::missing($file);
                }
            }

            sort($files);

            return $files;
        }

        $directory = $this->directory();

        if (! is_dir($directory)) {
            throw UnreadableExport::missing($directory);
        }

        // Case-insensitive: a phone export can arrive as .XLSX. Lock files
        // and Excel's own ~$ temporaries are not exports.
        $found = array_values(array_filter(
            glob($directory.'/*') ?: [],
            static fn (string $path): bool => is_file($path)
                && ! str_starts_with(basename($path), '~$')
                && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'xlsx',
        ));

        sort($found);

        return $found;
    }

    private function directory(): string
    {
        $option = $this->option('dir');

        return is_string($option) && $option !== ''
            ? rtrim($option, '/')
            : rtrim((string) config('health.fitage.directory', storage_path('app/imports')), '/');
    }
}
