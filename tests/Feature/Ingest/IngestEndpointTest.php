<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\IngestRun;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use App\Jobs\ParseRawPayload;
use App\Enums\IngestRunStatus;
use App\Models\RawIngestPayload;
use Illuminate\Http\JsonResponse;
use Tests\Support\PayloadBuilder;
use Illuminate\Testing\TestResponse;
use Illuminate\Support\Facades\Queue;
use App\Services\Rollup\SummaryRebuilder;
use Illuminate\Support\Facades\RateLimiter;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The endpoint and the job that hangs off it: bank the bytes, answer 202, never
 * make Health Auto Export re-send something already stored.
 */
final class IngestEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'test-ingest-key';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.ingest.api_key' => self::KEY]);
    }

    /** @return TestResponse<JsonResponse> */
    private function sendPayload(PayloadBuilder $builder): TestResponse
    {
        return $this->call(
            'POST',
            '/api/ingest',
            server: $this->transformHeadersToServerVars(
                array_merge($builder->headers(), ['X-API-Key' => self::KEY])
            ),
            content: $builder->json()
        );
    }

    public function test_a_valid_post_banks_the_payload_and_queues_the_parse(): void
    {
        Queue::fake();

        $response = $this->sendPayload(
            PayloadBuilder::make()->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200)
        );

        $response->assertAccepted()->assertJsonStructure(['status', 'payload_id']);

        $payload = RawIngestPayload::query()->sole();

        Queue::assertPushed(
            ParseRawPayload::class,
            fn (ParseRawPayload $job): bool => $job->rawPayloadId === (int) $payload->id
        );
    }

    /** Nothing is parsed inside the request: the 202 is about durability, not understanding. */
    public function test_the_request_itself_writes_no_metrics(): void
    {
        Queue::fake();

        $this->sendPayload(PayloadBuilder::make()->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200));

        self::assertSame(0, HealthMetric::query()->count());
        self::assertSame(0, IngestRun::query()->count());
    }

    /** End to end, with the queue running inline. */
    public function test_the_queued_job_parses_the_banked_payload(): void
    {
        $this->sendPayload(
            PayloadBuilder::make()
                ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200)
                ->quantity('active_energy', 'kJ', '2026-08-07 10:00:00 +0200', 4184.0)
                ->minAvgMax('2026-08-07 10:00:00 +0200', 52, 74.36, 118)
                ->sleep()
        )->assertAccepted();

        self::assertSame(5, HealthMetric::query()->count());
        self::assertSame(1, SleepSession::query()->count());
        self::assertSame(IngestRunStatus::Completed, IngestRun::query()->sole()->status);
        self::assertSame('1000.000000', HealthMetric::query()->where('metric', 'active_energy')->sole()->value);
    }

    public function test_an_unauthenticated_post_banks_nothing(): void
    {
        Queue::fake();

        $this->call(
            'POST',
            '/api/ingest',
            server: $this->transformHeadersToServerVars(['X-API-Key' => 'wrong']),
            content: PayloadBuilder::make()->json()
        )->assertUnauthorized();

        self::assertSame(0, RawIngestPayload::query()->count());
        Queue::assertNothingPushed();
    }

    /**
     * THE CEILING IS CHECKED BEFORE THE DECODE, and the assertion proves the ORDER
     * rather than the outcome. The body is both too large and not JSON, so
     * `invalid_json` would mean json_decode ran — on a 100 MB body (the vhost's
     * client_max_body_size, sized for meal photos) that is PHP arrays for all of
     * it allocated before anything objected. `payload_too_large` is one strlen().
     */
    public function test_an_oversized_body_is_refused_before_it_is_decoded(): void
    {
        Queue::fake();

        config(['health.ingest.max_body_bytes' => 128]);

        $this->call(
            'POST',
            '/api/ingest',
            server: $this->transformHeadersToServerVars(['X-API-Key' => self::KEY]),
            content: str_repeat('x', 129)
        )
            ->assertStatus(413)
            ->assertExactJson(['error' => 'payload_too_large']);

        // Nothing banked or queued: the table this protects is permanent, never pruned.
        self::assertSame(0, RawIngestPayload::query()->count());
        Queue::assertNothingPushed();
    }

    /** A body inside the ceiling is unaffected by it. */
    public function test_a_body_at_the_ceiling_is_still_accepted(): void
    {
        Queue::fake();

        $json = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200)
            ->json();

        config(['health.ingest.max_body_bytes' => strlen($json)]);

        $this->call(
            'POST',
            '/api/ingest',
            server: $this->transformHeadersToServerVars(['X-API-Key' => self::KEY]),
            content: $json
        )->assertAccepted();
    }

    /**
     * THE ONLY UNAUTHENTICATED WRITE IN THE APP, and it was unmetered: no
     * `throttleApi()` in bootstrap/app.php, so the `api` group carries no limiter
     * and this route had none — the shared secret was guessable as fast as the
     * network allowed. `hash_equals` makes the COMPARISON constant-time; nothing
     * made the ATTEMPT expensive. Fired with a wrong key on purpose, which is the
     * attack and proves the throttle sits in front of the controller rather than
     * behind the auth check. 60/minute, so the 61st is refused.
     */
    public function test_key_guessing_is_rate_limited(): void
    {
        RateLimiter::clear('ingest');

        $guess = fn (): TestResponse => $this->call(
            'POST',
            '/api/ingest',
            server: $this->transformHeadersToServerVars(['X-API-Key' => 'wrong']),
            content: '{}'
        );

        for ($i = 0; $i < 60; $i++) {
            $guess()->assertUnauthorized();
        }

        $guess()->assertStatus(429);

        self::assertSame(0, RawIngestPayload::query()->count());
    }

    public function test_the_job_carries_only_an_id_never_the_body(): void
    {
        $job = new ParseRawPayload(42);

        self::assertSame(42, $job->rawPayloadId);
        self::assertSame('42', $job->uniqueId());

        // A several-hundred-kilobyte body must never be serialised into Redis beside the row holding it.
        self::assertLessThan(2000, mb_strlen(serialize($job)));
    }

    /** The row is the system of record: a job pointing at nothing is noise, and retrying conjures nothing. */
    public function test_a_job_for_a_missing_payload_is_a_no_op(): void
    {
        (new ParseRawPayload(999999))->handle(
            app(RawPayloadProcessor::class),
            app(SummaryRebuilder::class),
        );

        self::assertSame(0, IngestRun::query()->count());
    }

    /** A worker killed mid-parse leaves `processing` reading as "still working" forever; failed() sweeps. */
    public function test_the_failed_hook_marks_a_stuck_run_failed(): void
    {
        $payload = PayloadBuilder::make()->store();

        $run = IngestRun::factory()->pending()->create([
            'raw_ingest_payload_id' => $payload->id,
            'status'                => IngestRunStatus::Processing,
        ]);

        (new ParseRawPayload((int) $payload->id))->failed(new \RuntimeException('worker died'));

        $run->refresh();

        self::assertSame(IngestRunStatus::Failed, $run->status);
        self::assertStringContainsString('worker died', (string) $run->error);
        self::assertNotNull($run->processed_at);
    }

    /** A completed run must not be re-marked by a late failure hook. */
    public function test_the_failed_hook_leaves_completed_runs_alone(): void
    {
        $payload = PayloadBuilder::make()->store();

        $run = IngestRun::factory()->create([
            'raw_ingest_payload_id' => $payload->id,
            'status'                => IngestRunStatus::Completed,
        ]);

        (new ParseRawPayload((int) $payload->id))->failed(new \RuntimeException('late'));

        self::assertSame(IngestRunStatus::Completed, $run->refresh()->status);
    }
}
