<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Models\HealthReport;
use App\Enums\HealthReportKind;
use Tests\Support\StressFixture;
use App\Enums\HealthReportStatus;
use App\Jobs\GenerateHealthReport;
use Tests\Support\FakeReportWriter;
use App\Services\Report\ReportWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The state machine, and the two properties that make a paid call safe.
 *
 * THE CLAIM. A queue is at-least-once; the row is claimed before dispatch and
 * the job returns unless it finds the row `pending`, so a re-delivered job is
 * free. Tested by delivering it twice and counting calls, because "it looked at
 * the status" is not the property — "it did not pay twice" is.
 *
 * THE AUDIT SURVIVES FAILURE. A refusal after a full read of the fact sheet has
 * been paid for; a truncation twice over. Usage is written on both paths, and so
 * is the snapshot — a failed report that cannot say what it was looking at is a
 * failure nobody can diagnose.
 */
final class GenerateHealthReportTest extends TestCase
{
    use RefreshDatabase;

    private FakeReportWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-10 04:10:00', StressFixture::TZ));

        $this->writer = new FakeReportWriter;
        $this->app->instance(ReportWriter::class, $this->writer);
    }

    public function test_it_writes_the_report_and_the_whole_audit_trail(): void
    {
        $report = $this->pendingReport();

        $this->generate($report);

        $report->refresh();

        self::assertSame(HealthReportStatus::Ready, $report->status);
        self::assertSame('claude-opus-5', $report->model);
        self::assertSame('v2.5-report', $report->prompt_version);
        self::assertSame(14_200, $report->input_tokens);
        self::assertSame(3_100, $report->output_tokens);
        self::assertSame(48_000, $report->latency_ms);
        self::assertNotNull($report->generated_at);
        self::assertNull($report->error);

        // 14 200 in at $5/MTok + 3 100 out at $25/MTok = $0.071 + $0.0775.
        self::assertEqualsWithDelta(0.1485, (float) $report->cost_usd, 0.000001);
    }

    public function test_the_answer_is_stored_verbatim_in_the_schemas_own_shape(): void
    {
        $report = $this->pendingReport();

        $this->generate($report);

        $output = $report->refresh()->output;
        self::assertNotNull($output);

        // snake_case as the schema defines it — WrittenReport camel-cases at
        // render time, so a report re-opened next month parses the same way.
        self::assertArrayHasKey('energy_balance', $output);
        self::assertArrayHasKey('data_gaps', $output);
        self::assertIsString($output['headline']);
    }

    /**
     * The facts are assembled by the JOB, not the request, so the snapshot
     * describes when the report was written, not when the button was tapped.
     */
    public function test_the_facts_are_assembled_and_handed_to_the_writer(): void
    {
        $report = $this->pendingReport();

        $this->generate($report);

        self::assertSame(1, $this->writer->callCount());

        $facts = $this->writer->lastFacts();

        self::assertSame('2026-08-03', $facts['range']['start']);
        self::assertSame('2026-08-09', $facts['range']['end']);
        self::assertCount(7, $facts['days']);
        self::assertArrayHasKey('baseline_drift', $facts);
        self::assertArrayHasKey('anchor_statistics', $facts);
    }

    /**
     * The stored snapshot IS the question, which is what makes a report
     * checkable at all (see the migration): if the two could differ, the
     * evidence at /api/reports/{id}/snapshot would belong to another report.
     *
     * `assertEquals` not `assertSame`: the column is `jsonb`, and Postgres does
     * not preserve object key ORDER there. Content must survive the round trip;
     * ordering is the database's business.
     */
    public function test_the_snapshot_stored_on_the_row_is_the_one_the_writer_was_given(): void
    {
        $report = $this->pendingReport();

        $this->generate($report);

        self::assertEquals($this->writer->lastFacts(), $report->refresh()->input_snapshot);
    }

    /**
     * The property, not the mechanism: a job delivered twice must cost one call.
     */
    public function test_a_redelivered_job_does_not_pay_twice(): void
    {
        $report = $this->pendingReport();

        $this->generate($report);
        $this->generate($report);

        self::assertSame(1, $this->writer->callCount());
    }

    public function test_a_job_for_a_deleted_report_is_a_no_op(): void
    {
        $report = $this->pendingReport();
        $id = $report->id;

        $report->delete();

        app()->call([new GenerateHealthReport($id), 'handle']);

        self::assertSame(0, $this->writer->callCount());
    }

    public function test_a_failure_lands_on_the_row_with_its_reason(): void
    {
        $this->writer->willFail('The report came back empty.');

        $report = $this->pendingReport();

        $this->generate($report);

        $report->refresh();

        self::assertSame(HealthReportStatus::Failed, $report->status);
        self::assertSame('The report came back empty.', $report->error);
        self::assertNull($report->output);
        self::assertNull($report->generated_at);
    }

    /**
     * The half of the audit easiest to forget: a refusal costs real money, and
     * a row reporting no tokens makes the ledger under-count exactly the calls
     * somebody most wants to examine.
     */
    public function test_a_failed_report_still_records_what_it_cost(): void
    {
        $this->writer->willFail('Writing this report was declined.', inputTokens: 14_000, outputTokens: 0);

        $report = $this->pendingReport();

        $this->generate($report);

        $report->refresh();

        self::assertSame(14_000, $report->input_tokens);
        self::assertSame(0, $report->output_tokens);
        self::assertEqualsWithDelta(0.07, (float) $report->cost_usd, 0.000001);
    }

    /**
     * On a refusal the stored facts are the only evidence there is.
     */
    public function test_a_failed_report_still_carries_the_facts_it_was_looking_at(): void
    {
        $this->writer->willFail();

        $report = $this->pendingReport();

        $this->generate($report);

        $snapshot = $report->refresh()->input_snapshot;

        self::assertIsArray($snapshot);
        self::assertCount(7, $snapshot['days']);
    }

    /**
     * The last line of defence: without it a timeout leaves the row in
     * `generating` forever, spinning, with no way to find out why.
     */
    public function test_the_failed_hook_rescues_a_row_left_generating(): void
    {
        $report = HealthReport::factory()->generating()->create();

        (new GenerateHealthReport($report->id))->failed(new \RuntimeException('worker died'));

        $report->refresh();

        self::assertSame(HealthReportStatus::Failed, $report->status);
        self::assertStringContainsString('interrupted', (string) $report->error);
    }

    /**
     * A late `failed()` — the job finished, then the worker was killed — must
     * not overwrite a report that is on screen.
     */
    public function test_the_failed_hook_leaves_a_finished_report_alone(): void
    {
        $report = HealthReport::factory()->create();

        (new GenerateHealthReport($report->id))->failed(new \RuntimeException('too late'));

        self::assertSame(HealthReportStatus::Ready, $report->refresh()->status);
    }

    /**
     * An assembly failure is this app's bug, not the model's, but it still has
     * to land on the row or the user gets a spinner that never stops.
     */
    public function test_an_assembly_failure_lands_on_the_row_rather_than_hanging(): void
    {
        // A range past the ceiling cannot be reconstructed — the shape every
        // assembly failure takes: something the row says is no longer legal.
        config()->set('health.report.max_range_days', 3);

        $report = HealthReport::factory()->pending()->covering('2026-08-03', '2026-08-09')->create();

        $this->generate($report);

        $report->refresh();

        self::assertSame(HealthReportStatus::Failed, $report->status);
        self::assertStringContainsString('could not be assembled', (string) $report->error);
        self::assertSame(0, $this->writer->callCount());
    }

    private function pendingReport(): HealthReport
    {
        return HealthReport::factory()
            ->pending()
            ->covering('2026-08-03', '2026-08-09')
            ->create(['kind' => HealthReportKind::Weekly]);
    }

    private function generate(HealthReport $report): void
    {
        app()->call([new GenerateHealthReport($report->id), 'handle']);
    }
}
