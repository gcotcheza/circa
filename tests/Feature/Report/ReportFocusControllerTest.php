<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\User;
use Carbon\CarbonImmutable;
use App\Models\HealthReport;
use Tests\Support\StressFixture;
use App\Enums\HealthReportStatus;
use App\Jobs\GenerateHealthReport;
use App\Services\Report\ReportFocus;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Asking for a focused report, and finding it again afterwards.
 *
 * A chip that is accepted and then quietly ignored is the worst outcome here,
 * and it is invisible: the report comes back, reads perfectly, costs what a full
 * report costs, and is not the report that was asked for. So the assertions
 * follow the focus all the way through — validated at the edge, written to the
 * row, carried into the job, shown in the archive — rather than stopping at 202.
 */
final class ReportFocusControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-08-10 09:00:00', StressFixture::TZ));

        $this->user = User::factory()->create();
    }

    public function test_the_chips_and_the_question_land_on_the_row(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson('/api/reports', [
                'days'      => 30,
                'focus'     => ['sleep', 'stress'],
                'focusText' => '  compare my recovery before and after daily weigh-ins  ',
            ])
            ->assertStatus(202);

        $report = HealthReport::query()->sole();

        // Sorted into declaration order, so label and fingerprint do not depend
        // on the order the chips were tapped in.
        self::assertSame(['stress', 'sleep'], $report->focus_areas);
        self::assertSame('compare my recovery before and after daily weigh-ins', $report->focus_text);

        // And the key says what the row is, rather than only being unique.
        self::assertStringContainsString('stress-sleep', $report->idempotency_key);

        Queue::assertPushed(GenerateHealthReport::class);
    }

    /** NULL RATHER THAN `[]`: two spellings of "no focus" is one too many. */
    public function test_no_chips_stores_null_rather_than_an_empty_array(): void
    {
        Queue::fake();

        $this->actingAs($this->user)->postJson('/api/reports', ['days' => 7])->assertStatus(202);

        $report = HealthReport::query()->sole();

        self::assertNull($report->focus_areas);
        self::assertNull($report->focus_text);
        self::assertTrue($report->focus()->isEverything());
    }

    public function test_an_unknown_chip_is_rejected_rather_than_ignored(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson('/api/reports', ['days' => 7, 'focus' => ['stress', 'astrology']])
            ->assertStatus(422)
            ->assertJsonValidationErrors('focus.1');

        self::assertSame(0, HealthReport::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_the_question_is_capped(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson('/api/reports', [
                'days'      => 7,
                'focusText' => str_repeat('a', ReportFocus::MAX_TEXT + 1),
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('focusText');

        self::assertSame(0, HealthReport::query()->count());
    }

    /**
     * THE RANGE EXTENSION, END TO END. The same 182 days is legal with the Stress
     * chip and illegal without, and the refusal has to name the way out: a
     * rejection the user cannot act on is a dead end.
     */
    public function test_a_long_range_needs_a_focus_and_says_so(): void
    {
        Queue::fake();

        $this->actingAs($this->user)
            ->postJson('/api/reports', ['days' => 182, 'focus' => ['stress']])
            ->assertStatus(202);

        self::assertSame(182, HealthReport::query()->sole()->days());

        $response = $this->actingAs($this->user)
            ->postJson('/api/reports', ['days' => 182])
            ->assertStatus(422);

        self::assertStringContainsString('at most 92 days', $response->json('errors.from.0'));
        self::assertStringContainsString('Focusing it on stress', $response->json('errors.from.0'));
    }

    /** Food keeps the quarter: its block is one entry per item eaten, not per day. */
    public function test_a_long_range_is_still_refused_when_food_is_selected(): void
    {
        Queue::fake();

        $response = $this->actingAs($this->user)
            ->postJson('/api/reports', ['days' => 182, 'focus' => ['stress', 'food']])
            ->assertStatus(422);

        self::assertStringContainsString('Food-focused reports are limited to 92 days', $response->json('errors.from.0'));
        self::assertSame(0, HealthReport::query()->count());
    }

    /** The archive says why a report is all stress, without opening the row. */
    public function test_the_archive_line_carries_the_focus(): void
    {
        HealthReport::factory()->create([
            'range_start' => '2026-02-10',
            'range_end'   => '2026-08-09',
            'focus_areas' => ['stress', 'sleep'],
            'focus_text'  => 'why am I so tired',
            'status'      => HealthReportStatus::Ready,
        ]);

        $this->actingAs($this->user)
            ->get('/report')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('reports.0.focusLabel', 'Stress + Sleep')
                ->where('reports.0.focusAreas', ['stress', 'sleep'])
                ->where('reports.0.focusText', 'why am I so tired')
                ->where('reports.0.days', 181)
            );
    }

    public function test_an_unfocused_report_has_no_archive_badge(): void
    {
        HealthReport::factory()->create(['status' => HealthReportStatus::Ready]);

        $this->actingAs($this->user)
            ->get('/report')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('reports.0.focusLabel', null));
    }

    /** The form's ceilings come from the server, so the greyed chip is the refused one. */
    public function test_the_page_carries_the_focus_options_and_both_ceilings(): void
    {
        $this->actingAs($this->user)
            ->get('/report')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('focus.maxRangeDays.full', 92)
                ->where('focus.maxRangeDays.lean', 366)
                ->where('focus.maxTextLength', ReportFocus::MAX_TEXT)
                ->where('focus.areas.0.value', 'stress')
                ->where('focus.areas.0.label', 'Stress')
                ->has('focus.areas', 5)
                ->has('focus.longPresets')
            );
    }
}
