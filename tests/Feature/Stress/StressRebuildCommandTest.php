<?php

declare(strict_types=1);

namespace Tests\Feature\Stress;

use Tests\TestCase;
use App\Enums\StressBand;
use App\Models\StressDaily;
use Carbon\CarbonImmutable;
use Tests\Support\StressFixture;
use Illuminate\Support\Facades\DB;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `stress:rebuild` — the nightly job, and what you run after editing a
 * threshold.
 *
 * Idempotency matters most, because the scheduler can fire twice after a restart
 * and the page recomputes the same days on every render. Running it repeatedly
 * must leave one row per day with one `computed_at`, or "as of last night"
 * silently becomes "as of nine seconds ago" on a row nothing had changed.
 */
final class StressRebuildCommandTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-06-15';

    private const CYCLE = [-0.2, -0.1, 0.0, 0.1, 0.2];

    private const BASE_MS = 33.0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 03:50:00', StressFixture::TZ));
    }

    public function test_it_writes_a_row_for_every_day_it_can_score(): void
    {
        $this->seedHistory(70);

        $this->runArtisan('stress:rebuild', ['--days' => 7])
            ->assertSuccessful();

        // Seven asked for, seven scored: each has 60 days behind it.
        self::assertSame(7, StressDaily::query()->count());

        $row = StressDaily::query()->find(self::TODAY);

        self::assertNotNull($row);
        self::assertSame('v1', $row->method_version);
        self::assertInstanceOf(StressBand::class, $row->band);
        self::assertGreaterThanOrEqual(1, $row->score);
        self::assertLessThanOrEqual(99, $row->score);
    }

    public function test_running_it_again_changes_nothing_at_all(): void
    {
        $this->seedHistory(70);

        $this->runArtisan('stress:rebuild', ['--days' => 7])->assertSuccessful();

        $before = StressDaily::query()->findOrFail(self::TODAY);
        $stamp = $before->computed_at;
        $score = $before->score;

        // Time moves on; the answer does not.
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 09:30:00', StressFixture::TZ));

        $this->runArtisan('stress:rebuild', ['--days' => 7])->assertSuccessful();

        $after = StressDaily::query()->findOrFail(self::TODAY);

        self::assertSame($score, $after->score);
        self::assertTrue(
            $stamp->equalTo($after->computed_at),
            'computed_at moved on a recompute that changed nothing'
        );
        self::assertSame(1, StressDaily::query()->where('local_date', self::TODAY)->count());
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->seedHistory(70);

        $this->runArtisan('stress:rebuild', ['--days' => 7, '--dry-run' => true])
            ->assertSuccessful();

        self::assertSame(0, StressDaily::query()->count());
    }

    public function test_one_date_can_be_rebuilt_on_its_own(): void
    {
        $this->seedHistory(70);

        $this->runArtisan('stress:rebuild', ['--date' => self::TODAY])->assertSuccessful();

        self::assertSame(1, StressDaily::query()->count());
        self::assertNotNull(StressDaily::query()->find(self::TODAY));
    }

    public function test_all_covers_every_day_that_has_readings_and_can_be_scored(): void
    {
        config()->set('health.stress.min_baseline_days', 21);

        $this->seedHistory(40);

        $this->runArtisan('stress:rebuild', ['--all' => true])->assertSuccessful();

        // Forty days of readings; the first 21 have too little history to be
        // scored, so 19 rows and no placeholders for the rest.
        self::assertSame(19, StressDaily::query()->count());

        $first = StressDaily::query()->orderBy('local_date')->firstOrFail();

        self::assertSame(
            CarbonImmutable::parse(self::TODAY)->subDays(18)->toDateString(),
            $first->local_date->toDateString()
        );
    }

    public function test_a_row_whose_day_no_longer_qualifies_is_removed(): void
    {
        $this->seedHistory(70);

        $this->runArtisan('stress:rebuild', ['--days' => 7])->assertSuccessful();

        self::assertNotNull(StressDaily::query()->find(self::TODAY));

        // Today's readings are withdrawn — a corrected export, a replay — and
        // the stale score must not survive the rebuild.
        DB::table('health_metrics')
            ->where('metric', StressFixture::METRIC)
            ->where('local_date', self::TODAY)
            ->delete();

        $this->runArtisan('stress:rebuild', ['--days' => 7])->assertSuccessful();

        self::assertNull(StressDaily::query()->find(self::TODAY));
    }

    public function test_it_says_so_rather_than_failing_when_there_is_no_hrv_at_all(): void
    {
        $this->runArtisan('stress:rebuild', ['--all' => true])
            ->expectsOutputToContain('No HRV readings')
            ->assertSuccessful();

        self::assertSame(0, StressDaily::query()->count());
    }

    public function test_a_window_with_no_history_behind_it_writes_nothing_and_says_why(): void
    {
        config()->set('health.stress.min_baseline_days', 21);

        $this->seedHistory(5);

        $this->runArtisan('stress:rebuild', ['--days' => 5])
            ->expectsOutputToContain('none scored')
            ->assertSuccessful();

        self::assertSame(0, StressDaily::query()->count());
    }

    public function test_it_is_wired_into_the_nightly_schedule(): void
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'stress:rebuild'));

        self::assertCount(1, $events, 'stress:rebuild is not scheduled');

        $event = $events->first();
        self::assertNotNull($event);

        // 03:50 Europe/Amsterdam: after the local day rolls over AND after the
        // export carrying its last hour lands. See routes/console.php.
        self::assertSame('50 3 * * *', $event->expression);

        $timezone = $event->timezone instanceof \DateTimeZone
            ? $event->timezone->getName()
            : (string) $event->timezone;

        self::assertSame('Europe/Amsterdam', $timezone);
    }

    private function seedHistory(int $days): void
    {
        StressFixture::days(
            self::TODAY,
            $days,
            fn (int $i): float => self::BASE_MS * exp(self::CYCLE[$i % 5]),
        );
    }
}
