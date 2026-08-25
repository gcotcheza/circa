<?php

declare(strict_types=1);

namespace App\Jobs;

use Throwable;
use Carbon\CarbonImmutable;
use App\Models\HealthReport;
use App\Enums\HealthReportStatus;
use App\Services\Report\ReportCost;
use Illuminate\Support\Facades\Log;
use App\Services\Report\ReportFacts;
use App\Services\Report\ReportRange;
use App\Services\Report\ReportBudget;
use App\Services\Report\ReportWriter;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Assemble a window of facts, ask Claude to read them back, store both.
 *
 * Queued rather than done in the request because the call takes thirty
 * seconds to two minutes, and a phone on mobile data holding an HTTP
 * request open that long will be cut off by something — the radio, a
 * proxy, iOS backgrounding the tab — with the worst possible failure
 * mode: the report generated and paid for, spinner stopped. So the
 * request claims a row, answers immediately, and the page polls a
 * status it can read.
 *
 * ONE ATTEMPT, as the vision jobs are, for the same reasons: the SDK
 * already retries once for 429/5xx/connection errors, and what that
 * can't fix — a refusal, a truncation, an unparseable answer — fails
 * identically thirty seconds later, paid for twice. A failed report
 * becomes a `failed` row with the reason on it and a Generate button.
 *
 * The job carries an ID, not a model, because a serialised Eloquent
 * model in a queue payload snapshots a row at dispatch time, and this
 * job is the thing that changes that row — reading it fresh is what
 * makes the pending-status claim mean anything.
 *
 * Facts are assembled HERE, not at the request: assembly is several
 * indexed queries and a year of HRV samples, which the controller
 * doesn't need to carry, and — more importantly — the snapshot should
 * describe the moment the report was written, not the moment the
 * button was tapped.
 */
final class GenerateHealthReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Comfortably past the HTTP client's ceiling (300s) plus the SDK's
     * one retry, so the timeout that fires carries a message rather
     * than being the queue's anonymous one.
     */
    public int $timeout = 900;

    public function __construct(public readonly int $healthReportId) {}

    public function handle(ReportFacts $facts, ReportWriter $writer): void
    {
        $report = HealthReport::query()->find($this->healthReportId);

        if ($report === null) {
            // Deleted while the job sat in the queue — nothing to do,
            // nothing wrong.
            return;
        }

        // Claiming the row makes a re-delivered job safe: queues are
        // at-least-once, and paying twice for the same week is what
        // `idempotency_key` exists to prevent.
        if ($report->status !== HealthReportStatus::Pending) {
            return;
        }

        $report->status = HealthReportStatus::Generating;
        $report->model = $writer->model();
        $report->prompt_version = $writer->promptVersion();
        $report->save();

        /*
         * Read before the range is rebuilt — order is load-bearing. The
         * range ceiling depends on focus (a 182-day stress report is
         * legal, a 182-day food report isn't), so reconstructing
         * without it would throw on exactly the reports this feature
         * exists for, in a queue worker, after the row was claimed. A
         * row from before focused reports existed has neither column,
         * which reads back as the full assembly it was.
         */
        $focus = $report->focus();

        try {
            $range = ReportRange::between(
                $report->range_start->toDateString(),
                $report->range_end->toDateString(),
                null,
                $focus,
            );

            $snapshot = $facts->assemble($range, $report->kind, null, $focus);
        } catch (Throwable $e) {
            // Assembly failing is a bug in this app, not the model, so
            // it's logged loudly — but it still has to land on the row,
            // or the user gets a spinner that never stops.
            Log::error('Report facts could not be assembled', [
                'health_report' => $report->id,
                'exception'     => $e::class,
                'message'       => $e->getMessage(),
            ]);

            $this->markFailed($report, 'The data for this report could not be assembled.');

            return;
        }

        /*
         * Written BEFORE the call: if the call then fails, the failed
         * row still carries the exact facts sent — the difference
         * between "the report failed" and "here is what it was looking
         * at." On a refusal that's the only evidence there is.
         */
        $report->input_snapshot = $snapshot;
        $report->save();

        /*
         * The size guard sits here: after the snapshot is stored (so a
         * refused report still carries the too-big document — the only
         * way to find out what grew) and before a token is paid for.
         *
         * ReportFocus's range ceilings bound ROWS; this bounds the
         * DOCUMENT, which is what's billed, and the two only track
         * each other while rows stay today's size. Without it, an
         * order-of-magnitude growth in any block shows up as a 400
         * after full assembly, or worse, an accepted request that
         * comes back truncated. See ReportBudget for why the estimate
         * is chars/4 rather than a second network call.
         */
        $refusal = ReportBudget::refusal($snapshot, $focus);

        if ($refusal !== null) {
            Log::warning('Report refused before the call: snapshot past the input ceiling', [
                'health_report'    => $report->id,
                'estimated_tokens' => ReportBudget::estimateTokens($snapshot),
                'ceiling'          => ReportBudget::ceiling(),
                'days'             => $range->days,
                'focus'            => $focus->areaValues(),
            ]);

            $this->markFailed($report, $refusal);

            return;
        }

        try {
            $outcome = $writer->write($snapshot, $focus);
        } catch (Throwable $e) {
            // AnthropicReportWriter turns its own errors into outcomes,
            // so reaching here means something further out broke — it
            // still has to land on the row, not just a log nobody reads.
            Log::error('Report generation threw', [
                'health_report' => $report->id,
                'message'       => $e->getMessage(),
            ]);

            $this->markFailed($report, 'The report did not complete.');

            return;
        }

        /*
         * Usage is recorded on both paths: a refusal after a full read
         * has been paid for, and a truncation paid for twice over. A
         * failure reporting no tokens would under-count spend by
         * exactly the calls somebody most wants to know about.
         */
        $report->input_tokens = $outcome->inputTokens;
        $report->output_tokens = $outcome->outputTokens;
        $report->cost_usd = ReportCost::usd($outcome->inputTokens, $outcome->outputTokens);
        $report->latency_ms = $outcome->latencyMs;

        if (! $outcome->succeeded) {
            $this->markFailed($report, (string) $outcome->error);

            return;
        }

        /*
         * The decoded answer, verbatim — deliberately not the whole
         * message. `vision_requests.raw_response` keeps the entire
         * message because the answer there is a handful of numbers and
         * the envelope is most of the value; here the answer IS the
         * payload (several kilobytes of prose), and everything the
         * envelope adds is already a column on this row. Storing the
         * message whole would double the largest table in the app to
         * keep a `stop_reason` that's `end_turn` on every row.
         *
         * Stored snake_case, exactly as the schema defines it, and read
         * through WrittenReport at render time, so a report re-opened
         * next month is parsed by the same code that parsed it first.
         */
        $report->output = $outcome->report;
        $report->status = HealthReportStatus::Ready;
        $report->error = null;
        $report->generated_at = CarbonImmutable::now();
        $report->save();
    }

    /**
     * The last line of defence: a timeout, an OOM, a worker restart.
     * Without it the report sits in `generating` forever with a
     * spinner and no way for the user to find out why.
     */
    public function failed(?Throwable $e): void
    {
        $report = HealthReport::query()->find($this->healthReportId);

        if ($report === null || $report->status === HealthReportStatus::Ready) {
            return;
        }

        $this->markFailed($report, 'The report was interrupted before it finished.');
    }

    private function markFailed(HealthReport $report, string $error): void
    {
        $report->status = HealthReportStatus::Failed;
        $report->error = $error;
        $report->save();
    }
}
