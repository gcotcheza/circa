<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use Throwable;
use Illuminate\Http\Request;
use App\Jobs\ParseRawPayload;
use Illuminate\Http\Response;
use App\Models\RawIngestPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\Controller;

/**
 * Health Auto Export's ingest endpoint: bank the bytes verbatim, answer 202,
 * hand the interpreting to a worker.
 *
 * The order is the whole design — the payload is durable in
 * `raw_ingest_payloads` BEFORE anything tries to understand it, so no parser
 * bug, schema change or queue outage can cost an export. Everything downstream
 * (health_metrics, sleep_sessions, daily summaries) is a derivation, rebuildable
 * with `artisan ingest:replay`. The dispatch is wrapped because a queue that is
 * down must not turn a banked payload into a 500 that makes the phone retry a
 * POST we already have.
 */
final class IngestController extends Controller
{
    /**
     * Request headers worth keeping alongside the body.
     *
     * HAE's own metadata rides on `automation-*` and `session-id`;
     * content-type/-length keep a truncated or misdeclared body diagnosable.
     */
    private const INTERESTING_HEADERS = [
        'session-id',
        'content-type',
        'content-length',
    ];

    private const INTERESTING_HEADER_PREFIX = 'automation-';

    public function __invoke(Request $request): JsonResponse
    {
        if (! $this->authenticated($request)) {
            // No detail, no echo of the supplied key: an unauthenticated caller
            // learns only that it failed.
            return response()->json(
                ['error' => 'unauthorized'],
                Response::HTTP_UNAUTHORIZED
            );
        }

        $raw = $request->getContent();

        if ($raw === '') {
            return response()->json(
                ['error' => 'empty_body'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        /*
         * SIZE BEFORE DECODE. `json_decode` on a 100 MB body allocates the PHP
         * arrays for all of it before returning — roughly ten times the string
         * — so the refusal must happen while the cost is still one strlen().
         * The vhost's client_max_body_size cannot stand in: it is 100 MB
         * because meal photos cross the same server block.
         *
         * The fixed token tells a caller that guessed the key correctly that
         * the request was too big, and nothing about where the ceiling is.
         */
        if (strlen($raw) > (int) config('health.ingest.max_body_bytes')) {
            return response()->json(
                ['error' => 'payload_too_large'],
                Response::HTTP_REQUEST_ENTITY_TOO_LARGE
            );
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return response()->json(
                ['error' => 'invalid_json'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        // A bare scalar ("3", "\"x\"", "null") is valid JSON but never a real
        // export; jsonb would accept it and it would only pollute the replay set.
        if (! is_array($decoded)) {
            return response()->json(
                ['error' => 'invalid_json'],
                Response::HTTP_UNPROCESSABLE_ENTITY
            );
        }

        $payload = RawIngestPayload::store(
            headers: $this->interestingHeaders($request),
            rawBody: $raw,
        );

        $this->dispatchParsing($payload);

        return response()->json(
            ['status' => 'accepted', 'payload_id' => $payload->id],
            Response::HTTP_ACCEPTED
        );
    }

    /**
     * Queue the parse; never let queueing failure reach the phone.
     *
     * The bytes are already committed, so 202 stays correct even with Redis
     * unreachable — `ingest:replay` picks the payload up later. A 500 would
     * tell Health Auto Export to re-send a body we have, and with Batch
     * Requests on, the whole batch with it.
     */
    private function dispatchParsing(RawIngestPayload $payload): void
    {
        try {
            ParseRawPayload::dispatch($payload->id);
        } catch (Throwable $e) {
            Log::error('ingest.dispatch_failed', [
                'raw_ingest_payload_id' => $payload->id,
                'exception'             => $e::class,
                'message'               => $e->getMessage(),
            ]);
        }
    }

    /**
     * Shared-secret check against INGEST_API_KEY.
     *
     * hash_equals is constant-time: a plain `===` leaks the matching prefix
     * length through response timing, enough to recover the key byte-by-byte
     * given enough requests.
     */
    private function authenticated(Request $request): bool
    {
        $expected = (string) config('services.ingest.api_key');

        // An unset key must never mean "everything is authorised".
        if ($expected === '') {
            return false;
        }

        $provided = (string) $request->header('X-API-Key', '');

        return hash_equals($expected, $provided);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    private function interestingHeaders(Request $request): array
    {
        $kept = [];

        foreach ($request->headers->all() as $name => $values) {
            $name = strtolower((string) $name);

            $wanted = str_starts_with($name, self::INTERESTING_HEADER_PREFIX)
                || in_array($name, self::INTERESTING_HEADERS, true);

            if (! $wanted) {
                continue;
            }

            $values = array_values(array_filter($values, static fn ($v) => $v !== null));

            $kept[$name] = count($values) === 1 ? (string) $values[0] : $values;
        }

        return $kept;
    }
}
