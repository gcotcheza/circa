<?php

declare(strict_types=1);

namespace Tests\Feature\Ingest;

use Tests\TestCase;
use App\Models\IngestRun;
use App\Models\ClientError;
use Carbon\CarbonImmutable;
use App\Enums\IngestRunStatus;
use App\Models\RawIngestPayload;
use Tests\Support\PayloadBuilder;
use Illuminate\Support\Facades\Route;
use App\Services\Ingest\RawPayloadProcessor;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * `GET /api/health` — the "last successful ingest" indicator the spec asks for.
 * Two separate "last"s on purpose: bytes ARRIVING and bytes being UNDERSTOOD
 * fail independently, and one alone cannot tell a dead phone from a wedged
 * worker. IT IS UNAUTHENTICATED, so half of what this file asserts is what the
 * response does NOT contain — see
 * test_the_public_body_carries_no_free_text_and_no_totals.
 */
final class HealthStatusEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_ok_on_an_empty_database(): void
    {
        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('ingest.last_payload', null)
            ->assertJsonPath('ingest.last_parsed_run', null)
            ->assertJsonPath('ingest.unparsed_payloads', 0);
    }

    public function test_it_reports_the_latest_arrival_and_the_latest_parse(): void
    {
        $payload = PayloadBuilder::make()
            ->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1200)
            ->minAvgMax('2026-08-07 10:00:00 +0200', 52, 74, 118)
            ->sleep()
            ->store(CarbonImmutable::parse('2026-08-07 12:34:56Z'));

        app(RawPayloadProcessor::class)->process($payload);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ingest.last_payload.id', (int) $payload->id)
            ->assertJsonPath('ingest.last_parsed_run.status', 'completed')
            ->assertJsonPath('ingest.last_parsed_run.row_count', 5)
            ->assertJsonPath('ingest.last_parsed_run.raw_ingest_payload_id', (int) $payload->id)
            ->assertJsonPath('ingest.runs.completed', 1)
            ->assertJsonPath('ingest.unparsed_payloads', 0);
    }

    /** The signal that matters most: bytes arriving but not parsed — a wedged worker. */
    public function test_a_banked_but_unparsed_payload_is_counted(): void
    {
        PayloadBuilder::make()->quantity('step_count', 'count', '2026-08-07 10:00:00 +0200', 1)->store();

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ingest.unparsed_payloads', 1)
            ->assertJsonPath('ingest.last_parsed_run', null);
    }

    public function test_a_failed_run_is_surfaced_separately(): void
    {
        $payload = RawIngestPayload::factory()->create();

        IngestRun::factory()->failed('kaboom')->create([
            'raw_ingest_payload_id' => $payload->id,
        ]);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ingest.last_failed_run.raw_ingest_payload_id', (int) $payload->id)
            ->assertJsonPath('ingest.runs.failed', 1)
            ->assertJsonPath('ingest.unparsed_payloads', 1);
    }

    /**
     * THE ANONYMOUS BODY IS A CONTRACT: pipeline timestamps, statuses and
     * counters, nothing else. `ingest_runs.error` made this necessary — it held
     * the verbatim exception message, and a QueryException from the metric
     * upsert carries the failing statement's BINDINGS, which is metric names,
     * values and timestamps, readable by anyone who could curl the endpoint.
     * The client-error message and url leaked the same way from the other
     * direction, and the table totals said how much health data there is to
     * steal. Asserted against the RAW JSON rather than field by field, so a
     * field added later that carries free text fails here rather than quietly
     * widening the endpoint.
     */
    public function test_the_public_body_carries_no_free_text_and_no_totals(): void
    {
        $payload = RawIngestPayload::factory()->create();

        IngestRun::factory()->failed('QueryException: insert into "health_metrics" ... 319.642352')->create([
            'raw_ingest_payload_id' => $payload->id,
        ]);

        ClientError::query()->create([
            'kind'    => 'vue',
            'message' => "Cannot read properties of undefined (reading 'kcal')",
            'url'     => 'https://health.example.com/day/2026-08-07',
            'build'   => 'deadbeefcafe',
        ]);

        $response = $this->getJson('/api/health')->assertOk();

        // Still a working uptime check.
        $response->assertJsonPath('status', 'ok')
            ->assertJsonPath('ingest.runs.failed', 1)
            ->assertJsonPath('client.errors.total', 1);

        $body = (array) $response->json();

        self::assertArrayNotHasKey('counts', (array) ($body['ingest'] ?? []));
        self::assertArrayNotHasKey('error', (array) ($body['ingest']['last_failed_run'] ?? []));
        self::assertArrayNotHasKey('session_id', (array) ($body['ingest']['last_parsed_run'] ?? []));

        foreach (['message', 'url', 'build'] as $field) {
            self::assertArrayNotHasKey($field, (array) ($body['client']['errors']['latest'] ?? []));
        }

        // And nothing anywhere in the document, at any depth, under any name.
        $json = (string) $response->getContent();

        foreach (['319.642352', 'insert into', 'undefined', 'deadbeefcafe', '/day/2026-08-07'] as $leak) {
            self::assertStringNotContainsString($leak, $json);
        }
    }

    /** Eight aggregate queries a hit is not something to serve unmetered. */
    public function test_the_endpoint_is_throttled(): void
    {
        self::assertContains(
            'throttle:60,1',
            Route::getRoutes()->getByName('api.health')?->gatherMiddleware() ?? []
        );
    }

    /** A status key that appears only once something broke is one nobody watches. */
    public function test_every_run_status_is_always_present(): void
    {
        $response = $this->getJson('/api/health')->assertOk();

        foreach (IngestRunStatus::cases() as $status) {
            $response->assertJsonPath("ingest.runs.{$status->value}", 0);
        }
    }

    /** An `empty` run still counts as a successful parse — it is a real answer. */
    public function test_an_empty_run_counts_as_parsed(): void
    {
        $payload = PayloadBuilder::make()->store();

        app(RawPayloadProcessor::class)->process($payload);

        $this->getJson('/api/health')
            ->assertOk()
            ->assertJsonPath('ingest.last_parsed_run.status', 'empty')
            ->assertJsonPath('ingest.unparsed_payloads', 0);
    }
}
