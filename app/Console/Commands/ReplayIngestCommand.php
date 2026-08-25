<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Throwable;
use App\Models\Source;
use App\Models\Workout;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use Illuminate\Console\Command;
use App\Models\RawIngestPayload;
use Illuminate\Support\Facades\DB;
use App\Services\Ingest\ParseResult;
use App\Services\Rollup\SummaryRebuilder;
use App\Services\Ingest\RawPayloadProcessor;

/**
 * Re-run the parser over every banked payload.
 *
 * This is why `raw_ingest_payloads` is permanent: when the parser is
 * wrong or a classification changes, the fix is to change the code and
 * replay, not patch derived rows by hand — everything downstream is
 * reproducible from this command. IT IS ALSO THE BACKFILL: the scope is
 * deliberately EVERY payload with no filter, which is what made two years
 * of workout history land the moment the workout parser existed, since
 * bytes marked `empty` for years turned into rows on one replay.
 * Synchronous, through the same RawPayloadProcessor the queued job uses,
 * so a replay never tests a code path production doesn't run.
 *
 *   php artisan ingest:replay                 # everything
 *   php artisan ingest:replay --from-id=90    # tail only
 *   php artisan ingest:replay --dry-run       # parse, report, roll back
 *
 * Run it twice to prove idempotence: the second pass must report 0
 * inserted, 0 updated, identical counts — stronger than matching totals,
 * since upserts carry a WHERE that skips no-op updates.
 */
final class ReplayIngestCommand extends Command
{
    protected $signature = 'ingest:replay
                            {--from-id= : Only payloads with id >= this}
                            {--to-id= : Only payloads with id <= this}
                            {--chunk=25 : Payloads to load per query}
                            {--dry-run : Parse and report, then roll everything back}
                            {--no-rebuild : Skip queueing RebuildDailySummary for the replayed dates}';

    protected $description = 'Reprocess stored Health Auto Export payloads into health_metrics / sleep_sessions / workouts';

    public function handle(RawPayloadProcessor $processor, SummaryRebuilder $rebuilder): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $before = $this->tableCounts();

        $query = RawIngestPayload::query()->orderBy('id');

        if ($this->option('from-id') !== null) {
            $query->where('id', '>=', (int) $this->option('from-id'));
        }

        if ($this->option('to-id') !== null) {
            $query->where('id', '<=', (int) $this->option('to-id'));
        }

        $total = (clone $query)->count();

        if ($total === 0) {
            $this->warn('No payloads matched.');

            return self::SUCCESS;
        }

        $this->line(sprintf(
            '<info>Replaying %d payload(s)%s.</info>',
            $total,
            $dryRun ? ' <comment>(dry run — nothing will be kept)</comment>' : ''
        ));
        $this->newLine();

        // A dry run is a real run thrown away: same writes, conflicts and
        // statistics, rolled back at the end — anything less would report
        // what the parser INTENDS, not what it does.
        if ($dryRun) {
            DB::beginTransaction();
        }

        $totals = new ParseResult;
        $failed = 0;

        try {
            $query->chunkById((int) $this->option('chunk'), function ($payloads) use ($processor, $totals, &$failed): void {
                foreach ($payloads as $payload) {
                    try {
                        $result = $processor->process($payload);
                    } catch (Throwable $e) {
                        $failed++;
                        $this->line(sprintf(
                            '  <fg=red>#%-6d FAILED</> %s',
                            $payload->id,
                            $e->getMessage()
                        ));

                        continue;
                    }

                    $totals->add($result);
                    $this->reportPayload((int) $payload->id, (string) $payload->received_at, $result);
                }
            });
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        $this->newLine();
        $this->summarise($totals, $failed, $before, $dryRun);

        // A replay rewrites history, so every touched day has a stale
        // summary. Unique + delayed jobs mean 112 payloads over 100 days
        // queue 100 rebuilds, not 112; a dry run queues none, since nothing
        // it did survived the rollback.
        if (! $dryRun && ! $this->option('no-rebuild') && $totals->dirtyDates !== []) {
            $rebuilder->queue($totals->dirtyDates);

            $this->line(sprintf(
                'Queued RebuildDailySummary for %d local date(s).',
                count($totals->dirtyDates)
            ));
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function reportPayload(int $id, string $receivedAt, ParseResult $result): void
    {
        $this->line(sprintf(
            '  #%-6d %s  blocks %-3d  datapoints %-5d  metrics +%-5d ~%-5d =%-5d  sleep +%d ~%d =%d  workouts +%d ~%d =%d%s',
            $id,
            mb_substr($receivedAt, 0, 19),
            $result->metricBlocks,
            $result->datapoints,
            $result->metrics->inserted,
            $result->metrics->updated,
            $result->metrics->unchanged,
            $result->sleep->inserted,
            $result->sleep->updated,
            $result->sleep->unchanged,
            $result->workouts->inserted,
            $result->workouts->updated,
            $result->workouts->unchanged,
            $result->skipped === [] ? '' : sprintf('  <comment>skipped %d</comment>', count($result->skipped)),
        ));
    }

    /**
     * @param  array<string, int>  $before
     */
    private function summarise(ParseResult $totals, int $failed, array $before, bool $dryRun): void
    {
        $after = $this->tableCounts();

        $this->table(
            ['', 'inserted', 'updated', 'unchanged', 'total'],
            [
                [
                    'health_metrics',
                    $totals->metrics->inserted,
                    $totals->metrics->updated,
                    $totals->metrics->unchanged,
                    $totals->metrics->total(),
                ],
                [
                    'sleep_sessions',
                    $totals->sleep->inserted,
                    $totals->sleep->updated,
                    $totals->sleep->unchanged,
                    $totals->sleep->total(),
                ],
                [
                    'workouts',
                    $totals->workouts->inserted,
                    $totals->workouts->updated,
                    $totals->workouts->unchanged,
                    $totals->workouts->total(),
                ],
            ]
        );

        $rows = [];

        foreach ($before as $table => $count) {
            $rows[] = [$table, $count, $after[$table], $after[$table] - $count];
        }

        $this->table(['table', 'before', 'after', 'delta'], $rows);

        $this->line(sprintf(
            'datapoints read: %d   metric blocks: %d   skipped datapoints: %d   failed payloads: %d',
            $totals->datapoints,
            $totals->metricBlocks,
            count($totals->skipped),
            $failed,
        ));

        foreach (array_slice($totals->skipped, 0, 5) as $reason) {
            $this->line('  <comment>skipped:</comment> '.$reason);
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn('Dry run: everything above was rolled back.');
        }

        if ($totals->touched() === 0 && $failed === 0) {
            $this->newLine();
            $this->info('Nothing changed — this replay was a provable no-op.');
        }
    }

    /** @return array<string, int> */
    private function tableCounts(): array
    {
        return [
            'health_metrics' => HealthMetric::query()->count(),
            'sleep_sessions' => SleepSession::query()->count(),
            'workouts'       => Workout::query()->count(),
            'sources'        => Source::query()->count(),
        ];
    }
}
