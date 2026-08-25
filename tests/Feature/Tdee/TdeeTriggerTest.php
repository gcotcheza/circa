<?php

declare(strict_types=1);

namespace Tests\Feature\Tdee;

use Tests\TestCase;
use App\Models\Meal;
use App\Jobs\RecomputeTdee;
use Carbon\CarbonImmutable;
use App\Models\TdeeEstimate;
use App\Jobs\RebuildDailySummary;
use App\Services\Tdee\TdeeTrigger;
use App\Services\Tdee\TdeeRecorder;
use Illuminate\Support\Facades\Queue;
use App\Services\Rollup\SummaryRebuilder;
use App\Services\Rollup\DailySummaryBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What makes the recorded estimate stale, and what happens next.
 *
 * `is_complete_log` and `weight_kg` are written by exactly one thing, a
 * daily-summary rebuild, so a rebuild is the trigger — filtered to dates inside
 * the window, since an April rebuild cannot move a fit over the last 28 days.
 */
final class TdeeTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-06-15 09:00:00', 'Europe/Amsterdam'));
    }

    public function test_a_rebuild_inside_the_window_queues_a_recompute(): void
    {
        Queue::fake();

        $fired = app(TdeeTrigger::class)->afterRebuild('2026-06-14');

        self::assertTrue($fired);
        Queue::assertPushed(RecomputeTdee::class, 1);
    }

    public function test_a_rebuild_outside_the_window_queues_nothing(): void
    {
        Queue::fake();

        // Window: 2026-05-19 … 2026-06-15. April cannot move a four-week fit,
        // and a whole-history replay would queue one full fit per day of it.
        self::assertFalse(app(TdeeTrigger::class)->afterRebuild('2026-04-02'));
        self::assertFalse(app(TdeeTrigger::class)->afterRebuild('2026-05-18'));

        // Tomorrow is outside too: the window ends today.
        self::assertFalse(app(TdeeTrigger::class)->afterRebuild('2026-06-16'));

        Queue::assertNotPushed(RecomputeTdee::class);
    }

    public function test_the_queued_rebuild_job_fires_the_trigger_after_building(): void
    {
        Queue::fake();

        (new RebuildDailySummary('2026-06-15'))->handle(
            app(DailySummaryBuilder::class),
            app(TdeeTrigger::class),
        );

        Queue::assertPushed(RecomputeTdee::class, 1);
    }

    public function test_the_inline_rebuild_path_fires_it_too(): void
    {
        Queue::fake();

        // Saving dinner flips `is_complete_log` — inline, so the redirect totals right.
        app(SummaryRebuilder::class)->now('2026-06-15');

        Queue::assertPushed(RecomputeTdee::class, 1);
    }

    public function test_saving_a_meal_ends_up_recomputing_the_estimate(): void
    {
        Queue::fake();

        Meal::factory()->eatenAt(
            CarbonImmutable::parse('2026-06-15 19:00:00', 'Europe/Amsterdam')
        )->create();

        // The observer queues a rebuild; running it is what reaches the trigger.
        (new RebuildDailySummary('2026-06-15'))->handle(
            app(DailySummaryBuilder::class),
            app(TdeeTrigger::class),
        );

        Queue::assertPushed(RecomputeTdee::class);
    }

    public function test_the_job_is_unique_across_dates_and_delayed_to_coalesce(): void
    {
        Queue::fake();

        // Three dirty days, one estimate to recompute. RebuildDailySummary is
        // unique PER DATE; this is unique full stop — there is one window.
        app(TdeeTrigger::class)->afterRebuild('2026-06-13');
        app(TdeeTrigger::class)->afterRebuild('2026-06-14');
        app(TdeeTrigger::class)->afterRebuild('2026-06-15');

        $job = new RecomputeTdee;

        self::assertSame('current-window', $job->uniqueId());
        self::assertSame(config('health.tdee.recompute_unique_for'), $job->uniqueFor);

        Queue::assertPushed(fn (RecomputeTdee $job): bool => $job->delay !== null);
    }

    public function test_the_job_records_when_it_runs(): void
    {
        // No fake: the real job below the gate must no-op, not throw on an empty fit.
        (new RecomputeTdee)->handle(app(TdeeRecorder::class));

        self::assertSame(0, TdeeEstimate::query()->count());
    }
}
