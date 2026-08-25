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
 * `php artisan report:generate` — the weekly cron's entry point, and the way a
 * human asks for one from a terminal.
 *
 *   report:generate                          the previous Monday-Sunday, as a
 *                                            weekly report. What the schedule
 *                                            runs, with no arguments at all.
 *   report:generate --days=14                the last fourteen days, manual.
 *   report:generate --from=… --to=…          an explicit window, manual.
 *   report:generate --sync                   run it here instead of queueing,
 *                                            so a failure is visible now.
 *   report:generate --days=182 --focus=stress
 *                                            a focused report, which is also the
 *                                            only way to ask for a range past
 *                                            the ordinary quarter.
 *
 * The no-argument case being the cron's case is deliberate: a scheduled
 * command whose behaviour depends on flags gets edited in routes/console.php
 * and subtly wrong, so the schedule entry should read as the thing it does.
 *
 * `--sync` exists because the queue is the wrong place to discover a prompt
 * change broke the schema: Horizon shows a job that ran, the row says
 * `failed`, and the reason is a sentence written for an end user. Run inline
 * and the exception, token counts and timing land on the terminal.
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
            // Before the range: focus decides the range's own ceiling, so a
            // 182-day request is legal or not depending on what came back here.
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

        /*
         * The weekly path goes through the launcher so a second run over the
         * same week returns the existing row instead of paying again; the
         * manual path does too, for the one-at-a-time rule and the dispatch.
         */
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

        // Resolved from the container, not newed, so it gets the bound writer —
        // the fake in a test, the real client with real timeouts in production.
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
     * What the report is asked to be about.
     *
     * Validated here rather than left to ReportFocus's own tolerance: the value
     * object silently drops chips it doesn't recognise, right for a row read
     * back from the database, wrong for a human at a terminal — a typo would
     * quietly produce a full report, indistinguishable from a focused request
     * except in the bill.
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
