<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\HealthReport;
use App\Enums\HealthReportKind;
use App\Enums\HealthReportStatus;
use App\Jobs\GenerateHealthReport;
use Illuminate\Support\Facades\DB;

/**
 * Claim a row, then dispatch the job that fills it in.
 *
 * Three things ask for a report — the Generate button, `php artisan
 * report:generate`, and the Monday cron — and all three must get the same
 * three rules right: ONE AT A TIME, enforced by a row rather than a
 * disabled button, since a second report started mid-generation is two
 * paid calls for one tap; THE WEEKLY IS IDEMPOTENT, keyed off the week it
 * covers so a cron firing twice after a restart hits a unique-constraint
 * violation and returns the existing row (a manual run gets a random key,
 * since asking again is a decision, not a duplicate); and THE ROW EXISTS
 * BEFORE THE JOB DOES, so an at-least-once queue has something to check
 * against and the request can answer immediately with a pollable id.
 * Duplicating that across three callers is how two of them stay right.
 */
final class ReportLauncher
{
    /**
     * Start a report, or hand back the one already covering this ground.
     *
     * @return array{report: HealthReport, started: bool} `started` is false
     *                                                    when an existing row was returned rather than a new one created
     */
    public function launch(ReportRange $range, HealthReportKind $kind, ?ReportFocus $focus = null): array
    {
        $focus ??= ReportFocus::everything();

        /*
         * The weekly cron's re-entry: a report for this exact week already
         * exists, so that's the answer. Checked BEFORE the insert rather
         * than relying on the unique-key collision, since a collision can't
         * distinguish "already done" from "another worker is inserting
         * right now" — and this is the common path.
         */
        if ($kind === HealthReportKind::Weekly) {
            $existing = HealthReport::query()
                ->where('kind', $kind->value)
                ->where('range_start', $range->start)
                ->where('range_end', $range->end)
                ->newest()
                ->first();

            if ($existing !== null) {
                return ['report' => $existing, 'started' => false];
            }
        }

        $report = HealthReport::query()->create(self::attributes($range, $kind, $focus));

        /*
         * Dispatched AFTER the transaction the caller may be inside, so a
         * worker can't pick the job up before the row it names is visible —
         * `afterCommit` is a no-op outside a transaction, so this is correct
         * either way.
         */
        GenerateHealthReport::dispatch($report->id)->afterCommit();

        return ['report' => $report, 'started' => true];
    }

    /**
     * The report currently being generated, if there is one — the Generate
     * form's guard, asking the database rather than the page's own state,
     * since a tab left open for an hour has no idea what the phone in the
     * other pocket did.
     */
    public function inFlight(): ?HealthReport
    {
        return HealthReport::query()->unfinished()->newest()->first();
    }

    /**
     * Has a report over this exact range already been written? Used by the
     * UI to offer "you already have one" rather than refuse — asking again
     * is legitimate, since corrections can make the same range produce a
     * different report.
     */
    public function existingFor(ReportRange $range): ?HealthReport
    {
        return HealthReport::query()
            ->where('range_start', $range->start)
            ->where('range_end', $range->end)
            ->where('status', HealthReportStatus::Ready->value)
            ->newest()
            ->first();
    }

    /**
     * A report row for a range, without dispatching anything — exists for
     * `report:generate --sync`, which runs the job inline so a human at a
     * terminal sees the failure rather than finding it in Horizon later.
     */
    public function claim(ReportRange $range, HealthReportKind $kind, ?ReportFocus $focus = null): HealthReport
    {
        $focus ??= ReportFocus::everything();

        return DB::transaction(fn (): HealthReport => HealthReport::query()->create(
            self::attributes($range, $kind, $focus),
        ));
    }

    /**
     * The row a claim writes, in one place: `launch()` and `claim()` differ
     * only in whether a job is dispatched, so a column added here (e.g.
     * `focus_areas`) can't arrive on one path and not the other. FOCUS IS
     * STORED AS NULL WHEN THERE IS NONE, not `[]` — see the migration: one
     * state deserves one representation.
     *
     * @return array<string, mixed>
     */
    private static function attributes(ReportRange $range, HealthReportKind $kind, ReportFocus $focus): array
    {
        return [
            'range_start'     => $range->start,
            'range_end'       => $range->end,
            'kind'            => $kind,
            'status'          => HealthReportStatus::Pending,
            'focus_areas'     => $focus->areas === [] ? null : $focus->areaValues(),
            'focus_text'      => $focus->text,
            'idempotency_key' => $range->idempotencyKey($kind, $focus),
        ];
    }
}
