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
 * What the job does with a focused row — two things that would only ever fail in
 * a queue worker.
 *
 * The job rebuilds the range from the two date columns, and must read the focus
 * FIRST: a 182-day stress report is legal and the same 182 days without a focus
 * is not. Get the order wrong and every long report fails after its row has been
 * claimed, complaining about a ceiling the user has already satisfied, nowhere a
 * developer is watching.
 *
 * The size guard is the same problem in the other direction: it fires before the
 * call, or the thing it protects against has already been paid for.
 */
final class ReportFocusJobTest extends TestCase
{
    use RefreshDatabase;

    private FakeReportWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00:00', StressFixture::TZ));

        $this->writer = new FakeReportWriter;

        $this->app->instance(ReportWriter::class, $this->writer);
    }

    /**
     * The focus reaches the writer as a value object, not a nested array inside
     * the facts: that argument is what makes the output schema focus-aware.
     */
    public function test_the_focus_travels_from_the_row_to_the_writer(): void
    {
        $report = $this->claim(['stress', 'sleep'], 'why am I so tired');

        $this->generate($report);

        self::assertSame(['stress', 'sleep'], $this->writer->lastFocus()->areaValues());
        self::assertSame('why am I so tired', $this->writer->lastFocus()->text);

        // And into the snapshot, which is what makes the report replayable.
        $report->refresh();

        $snapshot = $this->snapshotOf($report);

        self::assertSame(['stress', 'sleep'], $snapshot['focus']['areas']);
        self::assertArrayNotHasKey('food', $snapshot);
        self::assertSame(HealthReportStatus::Ready, $report->status);
    }

    /**
     * The case the feature exists for, controller row to finished report: 181
     * days, which no unfocused report may cover. The day table comes back folded
     * into weekly buckets — a range this long is delivered by period (see
     * DayBuckets).
     */
    public function test_a_long_focused_row_generates_rather_than_failing_on_the_ceiling(): void
    {
        $report = $this->claim(['stress'], null, '2026-02-10', '2026-08-09');

        $this->generate($report);

        $report->refresh();

        self::assertSame(HealthReportStatus::Ready, $report->status, (string) $report->error);

        $snapshot = $this->snapshotOf($report);

        self::assertSame('weekly', $snapshot['range']['day_granularity']);
        self::assertLessThan(40, count($snapshot['days']));
    }

    /**
     * A pre-focus row still assembles the full document: the columns are
     * nullable and null means "the whole thing", which is what those rows were.
     */
    public function test_a_row_written_before_the_feature_is_a_full_report(): void
    {
        $report = $this->claim(null, null);

        $this->generate($report);

        $report->refresh();

        $snapshot = $this->snapshotOf($report);

        self::assertArrayHasKey('food', $snapshot);
        self::assertSame([], $snapshot['focus']['areas']);
        self::assertTrue($this->writer->lastFocus()->isEverything());
    }

    /**
     * THE SIZE GUARD. The ceiling is dropped below any real snapshot to stand in
     * for a document that has grown an order of magnitude: refused politely and,
     * crucially, WITHOUT PAYING FOR IT.
     */
    public function test_an_oversized_snapshot_is_refused_before_the_call(): void
    {
        config()->set('health.report.max_input_tokens', 50);

        $report = $this->claim(null, null);

        $this->generate($report);

        $report->refresh();

        self::assertSame(HealthReportStatus::Failed, $report->status);
        self::assertStringContainsString('past the 50', (string) $report->error);
        self::assertStringContainsString('Try a shorter range', (string) $report->error);

        // The writer was never reached, which is the whole point.
        self::assertSame(0, $this->writer->callCount());

        // The snapshot stays on the row: "what grew?" is the only useful question
        // after this failure, and it needs the document to answer.
        self::assertArrayHasKey('days', $this->snapshotOf($report));
    }

    /**
     * The stored snapshot, narrowed. `input_snapshot` is nullable — every
     * pending row legitimately has none — so assertions about its contents go
     * through one place that says out loud it expects one.
     *
     * @return array<string, mixed>
     */
    private function snapshotOf(HealthReport $report): array
    {
        $snapshot = $report->input_snapshot;

        self::assertIsArray($snapshot, 'the job should have stored a snapshot before the call');

        return $snapshot;
    }

    /**
     * @param  list<string>|null  $areas
     */
    private function claim(
        ?array $areas,
        ?string $text,
        string $start = '2026-08-03',
        string $end = '2026-08-09',
    ): HealthReport {
        return HealthReport::query()->create([
            'range_start'     => $start,
            'range_end'       => $end,
            'kind'            => HealthReportKind::Manual,
            'status'          => HealthReportStatus::Pending,
            'focus_areas'     => $areas,
            'focus_text'      => $text,
            'idempotency_key' => 'manual:'.$start.':'.$end.':'.bin2hex(random_bytes(6)),
        ]);
    }

    private function generate(HealthReport $report): void
    {
        app()->call([new GenerateHealthReport($report->id), 'handle']);
    }
}
