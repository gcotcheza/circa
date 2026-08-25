<?php

declare(strict_types=1);

namespace App\Console\Commands;

use InvalidArgumentException;
use App\Enums\ReportFocusArea;
use App\Enums\HealthReportKind;
use Illuminate\Console\Command;
use App\Enums\HealthReportStatus;
use App\Jobs\GenerateHealthReport;
use App\Services\Report\ReportFocus;
use App\Services\Report\ReportRange;
use App\Services\Report\ReportLauncher;

/**
 * The weekly cron's entry point (routes/console.php); no-argument behaviour
 * must equal the cron's, or a flag edit changes the schedule invisibly.
 * `--sync` runs inline, since Horizon otherwise shows only `failed` plus a
 * user sentence, not the exception or timing.
 */
final class GenerateHealthReportCommand extends Command
{
    protected $signature = 'report:generate
        {--from= : First local date of the range, YYYY-MM-DD}
        {--to= : Last local date of the range, YYYY-MM-DD; defaults to today}
        {--days= : Shorthand for the last N days ending today}
        {--kind= : manual|weekly; defaults to weekly when no range is given, manual otherwise}
        {--focus=* : Areas to focus on (stress|sleep|food|training|weight); repeatable. None means everything}
        {--focus-text= : A question to steer the writing, in the reader\'s own words}
        {--sync : Run the generation inline instead of queueing it}';

    protected $description = 'Write a health report over a range of days (defaults to last week)';

    public function handle(ReportLauncher $launcher): int
    {
        try {
            // Focus decides the range's own ceiling, so it must resolve first.
            $focus = $this->focus();
            $range = $this->range($focus);
            $kind = $this->kind();
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Range: <info>{$range->start}</info> to <info>{$range->end}</info> ({$range->days} days, {$kind->value})");

        if (! $focus->isEverything() || $focus->text !== null) {
            $this->line('Focus: <info>'.($focus->label() ?? 'everything').'</info>'
                .($focus->text === null ? '' : " — \"{$focus->text}\"")
                .'   blocks: '.implode(', ', $focus->blocks()));
        }

        // Both paths go through the launcher, so a repeat run pays nothing.
        if (! $this->option('sync')) {
            ['report' => $report, 'started' => $started] = $launcher->launch($range, $kind, $focus);

            if (! $started) {
                $this->line("A {$kind->value} report for this range already exists (#{$report->id}, {$report->status->value}). Nothing dispatched.");

                return self::SUCCESS;
            }

            $this->info("Queued report #{$report->id}.");

            return self::SUCCESS;
        }

        // --sync: the same claim, then the same job, run here.
        if ($kind === HealthReportKind::Weekly) {
            ['report' => $report, 'started' => $started] = $launcher->launch($range, $kind, $focus);

            if (! $started) {
                $this->line("A weekly report for this range already exists (#{$report->id}, {$report->status->value}).");

                return self::SUCCESS;
            }
        } else {
            $report = $launcher->claim($range, $kind, $focus);
        }

        $this->line("Generating report #{$report->id} inline…");

        $started = hrtime(true);

        // From the container, not newed, so it gets the bound writer — the
        // fake in a test, the real client in production.
        app()->call([new GenerateHealthReport($report->id), 'handle']);

        $elapsed = (int) round((hrtime(true) - $started) / 1_000_000_000);

        $report->refresh();

        if ($report->status !== HealthReportStatus::Ready) {
            $this->error("Report #{$report->id} {$report->status->value}: {$report->error}");

            return self::FAILURE;
        }

        $this->info("Report #{$report->id} ready in {$elapsed}s.");
        $this->line(sprintf(
            '  tokens: %s in / %s out   cost: $%s   model latency: %ss',
            $report->input_tokens ?? '?',
            $report->output_tokens ?? '?',
            $report->cost_usd === null ? '?' : number_format((float) $report->cost_usd, 4),
            $report->latency_ms === null ? '?' : number_format($report->latency_ms / 1000, 1),
        ));

        $headline = $report->output['headline'] ?? null;

        if (is_string($headline) && $headline !== '') {
            $this->line("  <comment>{$headline}</comment>");
        }

        return self::SUCCESS;
    }

    /**
     * Validated here, not left to ReportFocus's silent tolerance — a typo
     * at the terminal would otherwise reach a full report, wrong only in
     * the bill.
     *
     * @throws InvalidArgumentException
     */
    private function focus(): ReportFocus
    {
        /** @var list<string> $areas */
        $areas = (array) $this->option('focus');

        foreach ($areas as $area) {
            if (ReportFocusArea::tryFrom($area) === null) {
                throw new InvalidArgumentException(
                    '--focus must be one of: '.implode(', ', ReportFocusArea::values())." (got '{$area}')."
                );
            }
        }

        $text = $this->option('focus-text');

        if (is_string($text) && mb_strlen(trim($text)) > ReportFocus::MAX_TEXT) {
            throw new InvalidArgumentException('--focus-text must be under '.ReportFocus::MAX_TEXT.' characters.');
        }

        return ReportFocus::of($areas, is_string($text) ? $text : null);
    }

    /**
     * @throws InvalidArgumentException
     */
    private function range(ReportFocus $focus): ReportRange
    {
        $from = $this->option('from');
        $to = $this->option('to');
        $days = $this->option('days');

        if (is_string($days) && $days !== '') {
            if (! ctype_digit($days) || (int) $days < 1) {
                throw new InvalidArgumentException('--days must be a positive whole number.');
            }

            return ReportRange::lastDays((int) $days, null, $focus);
        }

        if (is_string($from) && $from !== '') {
            // A --from with no --to means "from there to today" — requiring
            // both would be pedantry.
            return ReportRange::between($from, is_string($to) && $to !== '' ? $to : now()->toDateString(), null, $focus);
        }

        if (is_string($to) && $to !== '') {
            throw new InvalidArgumentException('--to needs a --from (or use --days).');
        }

        // No range at all: the cron's case.
        return ReportRange::previousWeek();
    }

    /**
     * @throws InvalidArgumentException
     */
    private function kind(): HealthReportKind
    {
        $kind = $this->option('kind');

        if (is_string($kind) && $kind !== '') {
            return HealthReportKind::tryFrom($kind)
                ?? throw new InvalidArgumentException('--kind must be one of: '.implode(', ', HealthReportKind::values()));
        }

        // No range asked for last week, the weekly report; any range given
        // means somebody chose a window — a manual one.
        $gaveRange = ($this->option('from') ?? '') !== ''
            || ($this->option('to') ?? '') !== ''
            || ($this->option('days') ?? '') !== '';

        return $gaveRange ? HealthReportKind::Manual : HealthReportKind::Weekly;
    }
}
