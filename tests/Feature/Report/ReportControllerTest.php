<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Models\HealthReport;
use App\Enums\HealthReportKind;
use Tests\Support\StressFixture;
use App\Enums\HealthReportStatus;
use App\Jobs\GenerateHealthReport;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The report tab, its two JSON endpoints, and the guard that stops a double-tap
 * costing twice.
 *
 * The button disabling itself is a courtesy that a lost response, a second tab
 * or the Monday cron all defeat — and a report is the most expensive call this
 * app makes. So the guard is a row, the response is a 409 carrying the id of
 * the one already running, and the page follows THAT rather than erroring about
 * a race the user never knew they were in.
 */
final class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00:00', StressFixture::TZ));

        $this->user = User::factory()->create();
    }

    public function test_the_tab_needs_a_session(): void
    {
        $this->get('/report')->assertRedirect('/login');
    }

    /**
     * A BODY, not a 302 — fetch() follows a redirect into the login page's HTML
     * and reads it as success.
     */
    public function test_the_json_endpoints_answer_401_rather_than_redirecting(): void
    {
        $report = HealthReport::factory()->create();

        $this->postJson('/api/reports', ['days' => 7])->assertUnauthorized();
        $this->getJson("/api/reports/{$report->id}")->assertUnauthorized();
        $this->getJson("/api/reports/{$report->id}/snapshot")->assertUnauthorized();
    }

    public function test_the_tab_shows_the_newest_readable_report_and_the_presets(): void
    {
        HealthReport::factory()->covering('2026-07-20', '2026-07-26')->create();
        $newest = HealthReport::factory()->covering('2026-08-03', '2026-08-09')->create();

        $this->actingAs($this->user)
            ->get('/report')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Report')
                ->where('report.id', $newest->id)
                ->where('report.body.summary', fn (string $s): bool => str_contains($s, 'Seven days'))
                ->has('reports', 2)
                ->has('presets', 4)
                ->where('presets.1.days', 7)
                ->where('presets.1.start', '2026-08-04')
                ->where('presets.1.end', '2026-08-10')
                ->where('maxRangeDays', 92)
                ->where('inFlight', null)
            );
    }

    /**
     * A failed row belongs in the LIST, where it can be regenerated — not as the
     * headline with a good report one row below it.
     */
    public function test_a_failed_report_is_not_what_the_tab_opens_on(): void
    {
        $good = HealthReport::factory()->covering('2026-08-03', '2026-08-09')->create();
        HealthReport::factory()->failed()->covering('2026-08-01', '2026-08-02')->create();

        $this->actingAs($this->user)
            ->get('/report')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.id', $good->id)
                ->has('reports', 2)
            );
    }

    public function test_one_report_can_be_opened_by_id(): void
    {
        $older = HealthReport::factory()->covering('2026-07-20', '2026-07-26')->create();
        HealthReport::factory()->covering('2026-08-03', '2026-08-09')->create();

        $this->actingAs($this->user)
            ->get("/report/{$older->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.id', $older->id)
                ->where('report.rangeLabel', '20–26 Jul 2026')
            );
    }

    public function test_the_provenance_line_says_what_wrote_it_and_what_it_cost(): void
    {
        $report = HealthReport::factory()->create();

        $this->actingAs($this->user)
            ->get("/report/{$report->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.provenance.model', 'claude-opus-5')
                ->where('report.provenance.promptVersion', 'v2-report')
                ->where('report.provenance.inputTokens', 14_200)
                ->where('report.provenance.costUsd', 0.1485)
                ->where('report.provenance.hasSnapshot', true)
            );
    }

    /**
     * A report keeps the version that wrote it, forever. The voice change left
     * `v1-report` a version this app no longer writes and still has rows from —
     * the point of stamping it. Such a report renders on the same screen and
     * names its own author rather than the prompt that happens to be current.
     */
    public function test_a_report_written_by_an_older_prompt_still_renders_and_keeps_its_version(): void
    {
        $report = HealthReport::factory()->create(['prompt_version' => 'v1-report']);

        $this->actingAs($this->user)
            ->get("/report/{$report->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.provenance.promptVersion', 'v1-report')
                ->where('report.provenance.model', 'claude-opus-5')
            );
    }

    public function test_generating_claims_a_row_queues_a_job_and_answers_202(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson('/api/reports', ['days' => 7])
            ->assertStatus(202)
            ->assertJsonPath('status', 'queued')
            ->assertJsonPath('report.status', 'pending')
            ->assertJsonPath('report.rangeStart', '2026-08-04')
            ->assertJsonPath('report.rangeEnd', '2026-08-10');

        $report = HealthReport::query()->sole();

        self::assertSame(HealthReportKind::Manual, $report->kind);

        Queue::assertPushed(GenerateHealthReport::class, 1);
    }

    public function test_a_custom_range_is_honoured(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson('/api/reports', ['from' => '2026-07-01', 'to' => '2026-07-14'])
            ->assertStatus(202);

        $report = HealthReport::query()->sole();

        self::assertSame('2026-07-01', $report->range_start->toDateString());
        self::assertSame('2026-07-14', $report->range_end->toDateString());
    }

    /**
     * ReportRange owns the rules and its message is what the user sees, so form
     * and assembler cannot disagree about what is legal.
     */
    public function test_a_range_past_the_ceiling_is_refused_with_the_range_rules_own_message(): void
    {
        Queue::fake();

        config()->set('health.report.max_range_days', 30);

        $this->actingAs($this->user)
            ->postJson('/api/reports', ['from' => '2026-01-01', 'to' => '2026-08-10'])
            ->assertStatus(422)
            // The second half arrived with focused reports: the range IS
            // available if narrowed, and a refusal that does not name the way
            // out is a dead end on a screen that has one.
            ->assertJsonPath(
                'errors.from.0',
                'A report can cover at most 30 days. Focusing it on stress, sleep, training or weight '
                .'— anything but food — allows a longer range.',
            );

        self::assertSame(0, HealthReport::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_request_with_no_range_at_all_is_refused(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson('/api/reports', [])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /**
     * 409 not 422: the request is fine, it conflicts with resource state — and
     * the body carries the running id so the page can follow it.
     */
    public function test_a_second_report_while_one_is_generating_is_a_409_naming_the_first(): void
    {
        Queue::fake();

        $inFlight = HealthReport::factory()->generating()->create();

        $this->actingAs($this->user)
            ->postJson('/api/reports', ['days' => 7])
            ->assertStatus(409)
            ->assertJsonPath('status', 'already_generating')
            ->assertJsonPath('report.id', $inFlight->id);

        self::assertSame(1, HealthReport::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_claimed_but_unsent_report_also_blocks_a_second_one(): void
    {
        Queue::fake();

        HealthReport::factory()->pending()->create();

        $this->actingAs($this->user)->postJson('/api/reports', ['days' => 7])->assertStatus(409);

        Queue::assertNothingPushed();
    }

    public function test_a_finished_report_does_not_block_a_new_one(): void
    {
        Queue::fake();

        HealthReport::factory()->create();
        HealthReport::factory()->failed()->create();

        $this->actingAs($this->user)->postJson('/api/reports', ['days' => 7])->assertStatus(202);

        Queue::assertPushed(GenerateHealthReport::class, 1);
    }

    public function test_the_poll_endpoint_answers_the_status_and_nothing_heavy(): void
    {
        $report = HealthReport::factory()->generating()->create();

        $this->actingAs($this->user)
            ->getJson("/api/reports/{$report->id}")
            ->assertOk()
            ->assertJsonPath('pending', true)
            ->assertJsonPath('report.status', 'generating')
            ->assertJsonMissingPath('report.body');
    }

    public function test_the_poll_endpoint_stops_being_pending_once_it_is_ready(): void
    {
        $report = HealthReport::factory()->create();

        $this->actingAs($this->user)
            ->getJson("/api/reports/{$report->id}")
            ->assertJsonPath('pending', false)
            ->assertJsonPath('report.headline', fn (string $h): bool => str_contains($h, 'steady week'))
            ->assertJsonPath('report.url', route('report.show', ['report' => $report->id]));
    }

    /** A health report that cannot be checked should not be acted on. */
    public function test_the_snapshot_endpoint_serves_the_facts_the_report_was_written_from(): void
    {
        $report = HealthReport::factory()->create([
            'input_snapshot' => ['schema_version' => 1, 'coverage' => ['days_in_range' => 7]],
        ]);

        $this->actingAs($this->user)
            ->getJson("/api/reports/{$report->id}/snapshot")
            ->assertOk()
            ->assertJsonPath('coverage.days_in_range', 7);
    }

    /**
     * Read off the SNAPSHOT, not recomputed: a late export would otherwise give
     * the report a coverage line disagreeing with the prose beside it.
     */
    public function test_the_coverage_strip_comes_from_the_stored_snapshot(): void
    {
        $report = HealthReport::factory()->create([
            'input_snapshot' => [
                'coverage' => [
                    'days_in_range'    => 7,
                    'food_logged_days' => ['n' => 4, 'pct' => 57.1],
                    'sleep_nights'     => ['n' => 6, 'pct' => 85.7],
                ],
            ],
        ]);

        $this->actingAs($this->user)
            ->get("/report/{$report->id}")
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.coverage.foodLoggedDays.n', 4)
                ->where('report.coverage.sleepNights.n', 6)
                ->where('report.coverage.weighIns', null)
            );
    }

    public function test_a_report_can_be_deleted(): void
    {
        $report = HealthReport::factory()->create();

        $this->actingAs($this->user)
            ->delete("/report/{$report->id}")
            ->assertRedirect(route('report.index'));

        self::assertSame(0, HealthReport::query()->count());
    }

    /** The cheapest ceiling on what a stuck retry loop can spend. */
    public function test_generation_is_rate_limited(): void
    {
        Queue::fake();

        $this->actingAs($this->user);

        // Four attempts against a limiter of three: the first is accepted, the
        // next two 409 (one in flight) but still consume the limiter — a loop is
        // throttled whatever the responses look like.
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/reports', ['days' => 7]);
        }

        $this->postJson('/api/reports', ['days' => 7])->assertStatus(429);
    }

    /** A report with no `output` must not 500 the list or the page. */
    public function test_a_failed_report_renders_without_a_body(): void
    {
        $report = HealthReport::factory()->failed('The report came back empty.')->create();

        $this->actingAs($this->user)
            ->get("/report/{$report->id}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report.status', 'failed')
                ->where('report.error', 'The report came back empty.')
                ->where('report.body', null)
            );
    }

    public function test_the_empty_state_renders_with_no_reports_at_all(): void
    {
        $this->actingAs($this->user)
            ->get('/report')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('report', null)
                ->has('reports', 0)
                ->has('presets', 4)
            );
    }

    public function test_an_in_flight_report_is_carried_separately_so_the_page_can_follow_it(): void
    {
        $inFlight = HealthReport::factory()->generating()->create();

        $this->actingAs($this->user)
            ->get('/report')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('inFlight.id', $inFlight->id)
                ->where('inFlight.pending', true)
            );
    }

    public function test_the_status_enum_agrees_with_itself_about_what_is_pending(): void
    {
        self::assertTrue(HealthReportStatus::Pending->isPending());
        self::assertTrue(HealthReportStatus::Generating->isPending());
        self::assertFalse(HealthReportStatus::Ready->isPending());
        self::assertFalse(HealthReportStatus::Failed->isPending());
    }
}
