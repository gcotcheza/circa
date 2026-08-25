<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use DateTimeZone;
use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Models\HealthReport;
use App\Enums\HealthReportKind;
use Tests\Support\StressFixture;
use App\Enums\HealthReportStatus;
use App\Jobs\GenerateHealthReport;
use Tests\Support\FakeReportWriter;
use App\Services\Report\ReportWriter;
use Illuminate\Support\Facades\Queue;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `report:generate`, and the Monday cron that runs it with no arguments.
 *
 * Idempotency is the property worth the most: the scheduler can fire twice
 * after a restart, and a report is the most expensive call this app makes, so a
 * second run over the same week must find its row and dispatch nothing.
 */
final class ReportCommandTest extends TestCase
{
    use RefreshDatabase;

    private FakeReportWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();

        // A Monday at 04:10, which is exactly when the schedule fires.
        $this->travelTo(CarbonImmutable::parse('2026-08-10 04:10:00', StressFixture::TZ));

        $this->writer = new FakeReportWriter;
        $this->app->instance(ReportWriter::class, $this->writer);
    }

    public function test_with_no_arguments_it_asks_for_last_week(): void
    {
        Queue::fake();

        $this->runArtisan('report:generate')->assertSuccessful();

        $report = HealthReport::query()->sole();

        self::assertSame(HealthReportKind::Weekly, $report->kind);
        self::assertSame('2026-08-03', $report->range_start->toDateString());
        self::assertSame('2026-08-09', $report->range_end->toDateString());
        self::assertSame('weekly:2026-08-03:2026-08-09', $report->idempotency_key);

        Queue::assertPushed(GenerateHealthReport::class, 1);
    }

    /** The restart case. */
    public function test_a_second_weekly_run_over_the_same_week_dispatches_nothing(): void
    {
        Queue::fake();

        $this->runArtisan('report:generate')->assertSuccessful();
        $this->runArtisan('report:generate')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();

        self::assertSame(1, HealthReport::query()->count());

        Queue::assertPushed(GenerateHealthReport::class, 1);
    }

    public function test_days_asks_for_the_last_n_days_as_a_manual_report(): void
    {
        Queue::fake();

        $this->runArtisan('report:generate --days=14')->assertSuccessful();

        $report = HealthReport::query()->sole();

        self::assertSame(HealthReportKind::Manual, $report->kind);
        self::assertSame('2026-07-28', $report->range_start->toDateString());
        self::assertSame('2026-08-10', $report->range_end->toDateString());
    }

    /**
     * The same fortnight twice by hand IS two reports: the manual key is random,
     * because after a week of corrections the range gives a different answer.
     */
    public function test_two_manual_runs_over_the_same_range_are_two_reports(): void
    {
        Queue::fake();

        $this->runArtisan('report:generate --days=7')->assertSuccessful();
        $this->runArtisan('report:generate --days=7')->assertSuccessful();

        self::assertSame(2, HealthReport::query()->count());
    }

    public function test_an_explicit_window_is_honoured(): void
    {
        Queue::fake();

        $this->runArtisan('report:generate --from=2026-07-01 --to=2026-07-07')->assertSuccessful();

        $report = HealthReport::query()->sole();

        self::assertSame('2026-07-01', $report->range_start->toDateString());
        self::assertSame('2026-07-07', $report->range_end->toDateString());
    }

    public function test_a_from_with_no_to_runs_to_today(): void
    {
        Queue::fake();

        $this->runArtisan('report:generate --from=2026-08-08')->assertSuccessful();

        self::assertSame('2026-08-10', HealthReport::query()->sole()->range_end->toDateString());
    }

    public function test_an_illegal_range_fails_with_the_range_rule_as_its_message(): void
    {
        Queue::fake();

        config()->set('health.report.max_range_days', 10);

        $this->runArtisan('report:generate --from=2026-01-01')
            ->expectsOutputToContain('at most 10 days')
            ->assertFailed();

        self::assertSame(0, HealthReport::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_bad_date_fails_rather_than_guessing(): void
    {
        Queue::fake();

        $this->runArtisan('report:generate --from=last-tuesday')->assertFailed();

        self::assertSame(0, HealthReport::query()->count());
    }

    /** The queue is the wrong place to discover a prompt change broke the schema. */
    public function test_sync_runs_the_generation_inline_and_reports_the_cost(): void
    {
        $this->runArtisan('report:generate --days=7 --sync')
            ->expectsOutputToContain('tokens: 14200 in / 3100 out')
            ->assertSuccessful();

        $report = HealthReport::query()->sole();

        self::assertSame(HealthReportStatus::Ready, $report->status);
        self::assertSame(1, $this->writer->callCount());
    }

    public function test_sync_reports_a_failure_as_a_failed_exit(): void
    {
        $this->writer->willFail('The report came back empty.');

        $this->runArtisan('report:generate --days=7 --sync')
            ->expectsOutputToContain('The report came back empty.')
            ->assertFailed();

        self::assertSame(HealthReportStatus::Failed, HealthReport::query()->sole()->status);
    }

    /**
     * An unregistered cron is a feature that silently never runs, and nothing
     * else in the suite would notice.
     */
    public function test_the_weekly_report_is_scheduled_for_monday_morning(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'report:generate'));

        self::assertCount(1, $events, 'report:generate is not scheduled.');

        $event = $events->first();
        self::assertNotNull($event);

        // 04:10 Europe/Amsterdam on day 1 (Monday).
        self::assertSame('10 4 * * 1', $event->expression);
        self::assertSame(StressFixture::TZ, $this->timezoneName($event->timezone));

        // No flags: the no-argument case is last week by construction.
        self::assertStringNotContainsString('--days', (string) $event->command);
        self::assertStringNotContainsString('--from', (string) $event->command);
    }

    public function test_the_weekly_report_does_not_overlap_itself(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'report:generate'));
        self::assertNotNull($event);

        // Not just "set" — actually enabled, now that $event is known non-null.
        self::assertTrue($event->withoutOverlapping);
        self::assertTrue($event->onOneServer);
    }

    /** Event::$timezone is set as a string everywhere this app schedules a command. */
    private function timezoneName(DateTimeZone|string $timezone): string
    {
        return $timezone instanceof DateTimeZone ? $timezone->getName() : $timezone;
    }
}
