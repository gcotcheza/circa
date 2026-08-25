<?php

declare(strict_types=1);

namespace Tests\Feature\Tdee;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Models\TdeeEstimate;
use Tests\Support\TdeeFixture;
use App\Services\Tdee\EstimatedTdee;
use App\Services\Tdee\TdeeEstimator;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `php artisan tdee:estimate` — the nightly run and what it leaves behind.
 *
 * IDEMPOTENCE matters most: the scheduler can fire it twice after a restart, the
 * queue can run it alongside a page load, and a user's own recompute can land in
 * the middle — all of which must converge on one row saying one thing.
 */
final class TdeeCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-15';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 03:40:00', 'Europe/Amsterdam'));
    }

    private function loggedWindow(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.1 / 7 * $i,
        ]);
    }

    public function test_it_records_one_row_matching_what_the_estimator_computed(): void
    {
        $this->loggedWindow();

        $expected = app(TdeeEstimator::class)->estimate();
        // 28 complete-log days clear the gate, so this is the ranged shape,
        // not CollectingData — the interface alone has no min/mid/max.
        self::assertInstanceOf(EstimatedTdee::class, $expected);

        $this->runArtisan('tdee:estimate')->assertSuccessful();

        $row = TdeeEstimate::query()->sole();

        self::assertSame('2026-05-19', $row->window_start->toDateString());
        self::assertSame(self::TODAY, $row->window_end->toDateString());
        self::assertSame('v1', $row->method_version);
        self::assertSame(28, $row->n_days);
        self::assertSame(28, $row->n_weighins);

        self::assertEqualsWithDelta($expected->mid(), (float) $row->tdee_mid, 0.01);
        self::assertEqualsWithDelta($expected->min(), (float) $row->tdee_min, 0.01);
        self::assertEqualsWithDelta($expected->max(), (float) $row->tdee_max, 0.01);
        self::assertEqualsWithDelta(2000.0, (float) $row->intake_mean_kcal, 0.01);
        self::assertEqualsWithDelta($expected->slopeKgPerDay, (float) $row->weight_slope_kg_per_day, 1e-5);

        // The stored midpoint is the equation's answer, not the mean of the ends.
        self::assertEqualsWithDelta($expected->mid(), $row->midpoint(), 0.01);
        self::assertTrue($row->meetsGate());
    }

    public function test_running_it_again_changes_nothing_at_all(): void
    {
        $this->loggedWindow();

        $this->runArtisan('tdee:estimate')->assertSuccessful();

        $first = TdeeEstimate::query()->sole();
        $computedAt = $first->computed_at;

        // An hour later, same data.
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 04:40:00', 'Europe/Amsterdam'));

        $this->runArtisan('tdee:estimate')->assertSuccessful();
        $this->runArtisan('tdee:estimate')->assertSuccessful();

        self::assertSame(1, TdeeEstimate::query()->count());

        $second = TdeeEstimate::query()->sole();

        self::assertEqualsWithDelta((float) $first->tdee_mid, (float) $second->tdee_mid, 0.001);
        // `computed_at` means "when this became true", so a recompute that did
        // not move the answer deliberately leaves it alone.
        self::assertTrue($computedAt->equalTo($second->computed_at));
    }

    public function test_an_answer_that_moved_updates_the_row_and_the_clock(): void
    {
        $this->loggedWindow();
        $this->runArtisan('tdee:estimate')->assertSuccessful();

        $before = TdeeEstimate::query()->sole();

        // The user corrects a day: 2 000 becomes 2 600.
        TdeeFixture::day(self::TODAY, [
            'intake'          => 2600.0,
            'band'            => 100.0,
            'is_complete_log' => true,
            'weight_kg'       => 70 - 0.1 / 7 * 27,
        ]);

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 05:00:00', 'Europe/Amsterdam'));
        $this->runArtisan('tdee:estimate')->assertSuccessful();

        // Same window, same method: an UPDATE, never a second row.
        self::assertSame(1, TdeeEstimate::query()->count());

        $after = TdeeEstimate::query()->sole();

        self::assertGreaterThan((float) $before->tdee_mid, (float) $after->tdee_mid);
        self::assertTrue($after->computed_at->greaterThan($before->computed_at));
    }

    public function test_below_the_gate_it_writes_nothing_and_says_how_far_off_it_is(): void
    {
        TdeeFixture::days(self::TODAY, 28, fn (int $i): array => [
            'intake'          => 2000.0,
            'is_complete_log' => $i >= 22,
            'weight_kg'       => $i % 8 === 0 ? 70.0 : null,
        ]);

        $this->runArtisan('tdee:estimate')
            ->expectsOutputToContain('Collecting data: 6/14 complete-log days, 4/8 weigh-ins')
            ->assertSuccessful();

        self::assertSame(0, TdeeEstimate::query()->count());
    }

    public function test_dry_run_computes_and_writes_nothing(): void
    {
        $this->loggedWindow();

        $this->runArtisan('tdee:estimate --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        self::assertSame(0, TdeeEstimate::query()->count());
    }

    public function test_a_past_date_estimates_the_window_that_ended_then(): void
    {
        $this->loggedWindow();

        $this->runArtisan('tdee:estimate --date=2026-06-10')->assertSuccessful();

        $row = TdeeEstimate::query()->sole();

        self::assertSame('2026-06-10', $row->window_end->toDateString());
        // 23 of the 28 fixture days fall inside the window ending on the 10th.
        self::assertSame(23, $row->n_days);
    }

    public function test_it_is_scheduled_nightly_after_the_day_has_rolled_over(): void
    {
        $events = collect(Schedule::events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'tdee:estimate'));

        self::assertCount(1, $events);

        $event = $events->first();
        self::assertNotNull($event);

        // 03:40 Europe/Amsterdam: yesterday's last hourly export and its summary
        // rebuild have both run, and the number is fresh before breakfast.
        self::assertSame('40 3 * * *', $event->expression);

        $timezone = $event->timezone instanceof \DateTimeZone
            ? $event->timezone->getName()
            : $event->timezone;
        self::assertSame('Europe/Amsterdam', $timezone);
    }
}
