<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use App\Models\RawIngestPayload;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ActsAsFreshUser;
use Inertia\Testing\AssertableInertia;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The last-successful-ingest indicator, as props.
 *
 * The threshold is the point: a locked iPhone has no HealthKit access, so an
 * eight-to-twelve hour gap is what a HEALTHY night looks like server-side.
 * Warning earlier goes orange most mornings, and stops being an indicator.
 */
final class IngestIndicatorTest extends TestCase
{
    use ActsAsFreshUser;
    use RefreshDatabase;

    public function test_a_recent_payload_is_reported_with_its_age_and_is_not_stale(): void
    {
        $this->payloadReceived(CarbonImmutable::now()->subHours(3));

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('ingest.stale', false)
                ->where('ingest.thresholdHours', 36)
                ->where('ingest.ageHours', fn (float $age): bool => $age > 2.9 && $age < 3.1)
        );
    }

    public function test_an_overnight_gap_does_not_warn(): void
    {
        // Phone locked from 23:00, morning sync not yet in — the normal case.
        $this->payloadReceived(CarbonImmutable::now()->subHours(11));

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page->where('ingest.stale', false)
        );
    }

    public function test_a_gap_past_the_threshold_warns(): void
    {
        $this->payloadReceived(CarbonImmutable::now()->subHours(40));

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page->where('ingest.stale', true)
        );
    }

    public function test_the_threshold_is_configurable_without_a_migration(): void
    {
        config()->set('health.ingest.stale_after_hours', 12);

        $this->payloadReceived(CarbonImmutable::now()->subHours(20));

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('ingest.stale', true)
                ->where('ingest.thresholdHours', 12)
        );
    }

    public function test_never_having_received_anything_reads_as_stale(): void
    {
        // A deploy whose HAE automation was never pointed at it looks exactly
        // like a healthy app with nothing to show. Hence this indicator.
        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('ingest.stale', true)
                ->where('ingest.ageHours', null)
                ->where('ingest.lastPayloadAt', null)
        );
    }

    public function test_the_indicator_reads_the_newest_payload_not_the_newest_row(): void
    {
        // A replay or out-of-order export can leave an older `received_at` on a
        // higher id, so ordering is by id: the primary key, monotonic, indexed.
        $this->payloadReceived(CarbonImmutable::now()->subHours(50));
        $this->payloadReceived(CarbonImmutable::now()->subHours(2));

        $this->get('/')->assertInertia(
            fn (AssertableInertia $page) => $page->where('ingest.stale', false)
        );
    }

    /** `received_at` has a DB default and is not fillable, so it is written directly. */
    private function payloadReceived(CarbonImmutable $at): void
    {
        $payload = RawIngestPayload::query()->create([
            'headers' => json_encode(['session-id' => 'test']),
            'body'    => json_encode(['data' => ['metrics' => []]]),
        ]);

        DB::table('raw_ingest_payloads')
            ->where('id', $payload->id)
            ->update(['received_at' => $at->toDateTimeString()]);
    }
}
