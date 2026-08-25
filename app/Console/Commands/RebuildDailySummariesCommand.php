<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Services\Rollup\SummaryRebuilder;
use App\Services\Rollup\DailySummaryBuilder;

/**
 * The table is fully derived, so this is never a repair — move the
 * complete-log floor in config, run this, and the whole history reflects
 * the new rule. Synchronous by default: a backfill is watched by a human;
 * `--queue` is for a run that is NOT watched.
 */
final class RebuildDailySummariesCommand extends Command
{
    protected $signature = 'summaries:rebuild
                            {--date= : A single local date (YYYY-MM-DD)}
                            {--from= : First local date}
                            {--to= : Last local date}
                            {--queue : Dispatch RebuildDailySummary jobs instead of building inline}';

    protected $description = 'Rebuild daily_summaries rows from health_metrics and meals';

    public function handle(DailySummaryBuilder $builder, SummaryRebuilder $rebuilder): int
    {
        $dates = $this->dates();

        if ($dates === []) {
            $this->warn('No dates to rebuild — there is no ingested data and no meal.');

            return self::SUCCESS;
        }

        if ($this->option('queue')) {
            $rebuilder->queue($dates);

            $this->info(sprintf('Queued %d rebuild(s).', count($dates)));

            return self::SUCCESS;
        }

        $this->line(sprintf('<info>Rebuilding %d day(s): %s → %s</info>', count($dates), $dates[0], end($dates)));

        $bar = $this->output->createProgressBar(count($dates));
        $bar->start();

        $complete = 0;
        $partial = 0;

        foreach ($dates as $date) {
            $summary = $builder->build($date);

            $complete += $summary->is_complete_log ? 1 : 0;
            $partial += $summary->active_kcal_is_partial ? 1 : 0;

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->line(sprintf(
            'Rebuilt %d day(s). complete-log: %d   partial watch coverage: %d',
            count($dates),
            $complete,
            $partial,
        ));

        return self::SUCCESS;
    }

    /**
     * Union of the three tables feeding a summary — a day with only a meal
     * and no metrics is still a day, and so is a night with no waking data.
     *
     * @return list<string>
     */
    private function dates(): array
    {
        if ($this->option('date') !== null) {
            return [CarbonImmutable::parse((string) $this->option('date'))->toDateString()];
        }

        $query = DB::query()->fromSub(
            DB::table('health_metrics')->select('local_date as d')->distinct()
                ->union(DB::table('meals')->select('local_date as d')->distinct())
                ->union(DB::table('sleep_sessions')->select('night_date as d')->distinct()),
            'dates'
        )->select('d')->orderBy('d');

        if ($this->option('from') !== null) {
            $query->where('d', '>=', CarbonImmutable::parse((string) $this->option('from'))->toDateString());
        }

        if ($this->option('to') !== null) {
            $query->where('d', '<=', CarbonImmutable::parse((string) $this->option('to'))->toDateString());
        }

        return array_values($query->pluck('d')
            ->map(static fn (string $d): string => CarbonImmutable::parse($d)->toDateString())
            ->all());
    }
}
