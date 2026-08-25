<?php

declare(strict_types=1);

namespace Tests\Feature\Rollup;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use Carbon\CarbonImmutable;
use App\Jobs\ParseRawPayload;
use App\Jobs\RebuildDailySummary;
use Tests\Support\PayloadBuilder;
use Illuminate\Support\Facades\Queue;
use App\Services\Rollup\SummaryRebuilder;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * What makes a `daily_summaries` row stale. Two triggers, and between them the
 * complete set: an ingest that wrote metric rows, and a change to a meal.
 * Anything skipping both is a summary that silently stops matching its rows.
 */
final class RebuildTriggerTest extends TestCase
{
    use RefreshDatabase;

    public function test_parsing_a_payload_queues_one_rebuild_per_touched_local_date(): void
    {
        Queue::fake();

        // Three buckets, two local days: 2026-06-14 23:00 +0200 is still the
        // 14th; the next morning is the 15th.
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', PayloadBuilder::at('2026-06-14 22:00:00'), 500)
            ->quantity('active_energy', 'kJ', PayloadBuilder::at('2026-06-14 23:00:00'), 210.5)
            ->quantity('basal_energy_burned', 'kJ', PayloadBuilder::at('2026-06-15 00:00:00'), 240.0)
            ->store();

        (new ParseRawPayload((int) $payload->id))->handle(
            app(RawPayloadProcessor::class),
            app(SummaryRebuilder::class),
        );

        Queue::assertPushed(RebuildDailySummary::class, 2);
        Queue::assertPushed(fn (RebuildDailySummary $job): bool => $job->date === '2026-06-14');
        Queue::assertPushed(fn (RebuildDailySummary $job): bool => $job->date === '2026-06-15');
    }

    public function test_a_sleep_record_dirties_its_own_night(): void
    {
        Queue::fake();

        $payload = PayloadBuilder::make()->sleep('2026-08-07')->store();

        (new ParseRawPayload((int) $payload->id))->handle(
            app(RawPayloadProcessor::class),
            app(SummaryRebuilder::class),
        );

        // Sleep keys on HAE's night date, not a derived local_date: a 23:00 start
        // belongs to the morning it ended on, and only HAE knows which night.
        Queue::assertPushed(fn (RebuildDailySummary $job): bool => $job->date === '2026-08-07');
    }

    public function test_the_job_is_unique_per_date_and_delayed_to_coalesce(): void
    {
        Queue::fake();

        app(SummaryRebuilder::class)->queue(['2026-06-15', '2026-06-15', '2026-06-16']);

        // One dispatch, before the queue's unique lock is even consulted.
        Queue::assertPushed(RebuildDailySummary::class, 2);

        $job = new RebuildDailySummary('2026-06-15');

        self::assertSame('2026-06-15', $job->uniqueId());
        self::assertSame(config('health.rollup.rebuild_unique_for'), $job->uniqueFor);

        Queue::assertPushed(fn (RebuildDailySummary $job): bool => $job->delay !== null);
    }

    public function test_creating_editing_and_deleting_a_meal_all_dirty_the_day(): void
    {
        Queue::fake();

        $meal = Meal::factory()->eatenAt(
            CarbonImmutable::parse('2026-06-15 12:00:00', 'Europe/Amsterdam')
        )->create();

        Queue::assertPushed(fn (RebuildDailySummary $job): bool => $job->date === '2026-06-15');

        MealItem::factory()->create(['meal_id' => $meal->id]);
        $meal->update(['notes' => 'edited']);
        $meal->delete();

        // All three are intake changes. Count left loose on purpose — the job is
        // unique per date — but a missing trigger is a wrong total on screen.
        Queue::assertPushed(RebuildDailySummary::class, function (RebuildDailySummary $job): bool {
            return $job->date === '2026-06-15';
        });
    }

    public function test_moving_a_meal_to_another_day_dirties_both(): void
    {
        Queue::fake();

        $meal = Meal::factory()->eatenAt(
            CarbonImmutable::parse('2026-06-15 12:00:00', 'Europe/Amsterdam')
        )->create();

        $meal->update([
            'eaten_at' => CarbonImmutable::parse('2026-06-17 12:00:00', 'Europe/Amsterdam'),
        ]);

        // The day it left is wrong too, and nothing else would ever dirty it.
        Queue::assertPushed(fn (RebuildDailySummary $job): bool => $job->date === '2026-06-15');
        Queue::assertPushed(fn (RebuildDailySummary $job): bool => $job->date === '2026-06-17');
    }
}
