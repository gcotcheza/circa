<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\Source;
use App\Models\IngestRun;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use App\Models\RawIngestPayload;
use Tests\Support\PayloadBuilder;
use Tests\Support\FailingMetricConnection;
use App\Services\Ingest\HealthMetricWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `ingest:replay` — the reason `raw_ingest_payloads` is permanent.
 */
final class ReplayCommandTest extends TestCase
{
    use RefreshDatabase;

    private function bankThreeHours(): void
    {
        foreach (['10', '11', '12'] as $i => $hour) {
            PayloadBuilder::make()
                ->sessionId('SESSION-'.$i)
                ->quantity('step_count', 'count', "2026-08-07 {$hour}:00:00 +0200", 100 * ($i + 1))
                ->quantity('active_energy', 'kJ', "2026-08-07 {$hour}:00:00 +0200", 418.4)
                ->minAvgMax("2026-08-07 {$hour}:00:00 +0200", 52, 74, 118)
                ->store();
        }

        PayloadBuilder::make()->sessionId('SESSION-SLEEP')->sleep()->store();
    }

    public function test_a_replay_builds_every_row_from_the_banked_payloads(): void
    {
        $this->bankThreeHours();

        $this->runArtisan('ingest:replay')
            ->assertSuccessful();

        // 3 hours x (1 step + 1 energy + 3 heart-rate) = 15
        self::assertSame(15, HealthMetric::query()->count());
        self::assertSame(1, SleepSession::query()->count());
        self::assertSame(4, IngestRun::query()->count());
        self::assertSame('100.000000', HealthMetric::query()
            ->where('metric', 'step_count')->orderBy('started_at')->firstOrFail()->value);
    }

    /**
     * The idempotence proof. Not "the counts matched" — the second pass writes
     * nothing, because the upserts carry a WHERE that skips no-ops.
     */
    public function test_a_second_replay_is_a_provable_no_op(): void
    {
        $this->bankThreeHours();

        $this->runArtisan('ingest:replay')->assertSuccessful();

        $before = [
            HealthMetric::query()->count(),
            SleepSession::query()->count(),
            Source::query()->count(),
            IngestRun::query()->count(),
        ];

        $ids = HealthMetric::query()->orderBy('id')->pluck('id')->all();
        $values = HealthMetric::query()->orderBy('id')->pluck('value')->all();

        $this->runArtisan('ingest:replay')
            ->expectsOutputToContain('Nothing changed')
            ->assertSuccessful();

        self::assertSame($before, [
            HealthMetric::query()->count(),
            SleepSession::query()->count(),
            Source::query()->count(),
            IngestRun::query()->count(),
        ]);

        // Same rows, not merely the same number of them.
        self::assertSame($ids, HealthMetric::query()->orderBy('id')->pluck('id')->all());
        self::assertSame($values, HealthMetric::query()->orderBy('id')->pluck('value')->all());
    }

    public function test_a_dry_run_keeps_nothing(): void
    {
        $this->bankThreeHours();

        $this->runArtisan('ingest:replay --dry-run')
            ->expectsOutputToContain('rolled back')
            ->assertSuccessful();

        self::assertSame(0, HealthMetric::query()->count());
        self::assertSame(0, SleepSession::query()->count());
        self::assertSame(0, IngestRun::query()->count());
        self::assertSame(0, Source::query()->count());
    }

    public function test_from_id_replays_only_the_tail(): void
    {
        $this->bankThreeHours();

        $lastId = (int) RawIngestPayload::query()->max('id');

        $this->runArtisan("ingest:replay --from-id={$lastId}")->assertSuccessful();

        self::assertSame(0, HealthMetric::query()->count());
        self::assertSame(1, SleepSession::query()->count());
        self::assertSame(1, IngestRun::query()->count());
    }

    public function test_replaying_nothing_is_not_an_error(): void
    {
        $this->runArtisan('ingest:replay')
            ->expectsOutputToContain('No payloads matched')
            ->assertSuccessful();
    }

    /** A replay after a parser change must agree with the live pipeline, or the paths have diverged. */
    public function test_a_replay_over_already_parsed_payloads_agrees_with_the_live_pipeline(): void
    {
        $this->bankThreeHours();

        // First pass stands in for the queued job.
        $this->runArtisan('ingest:replay')->assertSuccessful();

        $snapshot = HealthMetric::query()
            ->orderBy('metric')->orderBy('aggregation')->orderBy('started_at')
            ->get(['metric', 'aggregation', 'value', 'unit', 'started_at', 'ended_at'])
            ->toArray();

        // Wipe the derived tables entirely and rebuild from the raw bytes.
        HealthMetric::query()->delete();
        SleepSession::query()->delete();

        $this->runArtisan('ingest:replay')->assertSuccessful();

        self::assertSame($snapshot, HealthMetric::query()
            ->orderBy('metric')->orderBy('aggregation')->orderBy('started_at')
            ->get(['metric', 'aggregation', 'value', 'unit', 'started_at', 'ended_at'])
            ->toArray());
    }

    public function test_a_failing_payload_does_not_stop_the_replay(): void
    {
        /*
         * A DATABASE error, which is what a payload-level failure IS now that
         * per-datapoint faults are skipped rather than thrown. Keyed on one
         * metric name, so the payloads either side write normally and the command
         * is shown carrying on rather than merely surviving. See
         * Tests\Support\FailingMetricConnection.
         */
        $this->app->bind(
            HealthMetricWriter::class,
            fn (): HealthMetricWriter => FailingMetricConnection::writerRefusing('doomed_metric')
        );

        PayloadBuilder::make()->sessionId('GOOD')
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 100)
            ->store();

        PayloadBuilder::make()->sessionId('BAD')
            ->quantity('doomed_metric', 'count', '2026-08-07 10:00:00 +0200', 100)
            ->store();

        PayloadBuilder::make()->sessionId('ALSO-GOOD')
            ->quantity('step_count', 'count', '2026-08-07 11:00:00 +0200', 200)
            ->store();

        // Non-zero exit: something failed and the operator must see it.
        $this->runArtisan('ingest:replay')
            ->expectsOutputToContain('FAILED')
            ->assertFailed();

        // But the payloads either side of it still landed.
        self::assertSame(2, HealthMetric::query()->count());
    }
}
