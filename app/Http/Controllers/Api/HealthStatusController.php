<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\IngestRun;
use App\Models\ClientError;
use App\Enums\IngestRunStatus;
use App\Models\RawIngestPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Controller;

/**
 * `GET /api/health` — liveness, and the server side of "last successful ingest".
 *
 * The spec asks for a last-ingest indicator because the failure mode is silent:
 * a locked phone, Low Power Mode, or Background App Refresh switched off each
 * stop the exports without saying so. The server's clock against its last
 * arrival is the only honest source of truth.
 *
 * Two "last"s, deliberately separate:
 *
 *   last_payload    bytes ARRIVED. Proves the phone and the network are alive.
 *   last_parsed_run bytes were UNDERSTOOD. Proves the queue and parser are too.
 *
 * A gap between them means the worker is wedged, indistinguishable from a dead
 * phone if you track only one. The step-3 UI reads this; nothing renders here.
 *
 * THIS ENDPOINT HAS NO AUTHENTICATION — an uptime check must reach it, so
 * anybody can — and every field below is chosen under the rule that follows:
 *
 *   TIMESTAMPS, STATUSES AND COUNTERS ABOUT THE PIPELINE. NEVER A MEASUREMENT,
 *   NEVER FREE TEXT, NEVER A ROW COUNT OF THE OWNER'S DATA.
 *
 * Three breaches are gone. `last_failed_run.error` was the verbatim exception
 * message, and the realistic failure is a QueryException — Laravel builds those
 * by interpolating the statement's BINDINGS: metric names, values and
 * timestamps straight off the wrist (now sanitised at source by
 * IngestErrorText, and still not published here). The latest client error's
 * `message`, `url` and `build` named the undefined property, the page somebody
 * was on, and the deployed commit. `counts` gave whole-table totals — a
 * statement about somebody's health record, not about whether the server is up.
 * Anything revealing a measurement, or the size of the pile of them, stays
 * behind the app's own auth.
 */
final class HealthStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $lastPayload = RawIngestPayload::query()
            ->orderByDesc('id')
            ->first(['id', 'received_at']);

        $lastRun = IngestRun::query()
            ->succeeded()
            ->orderByDesc('processed_at')
            ->first();

        $lastFailure = IngestRun::query()
            ->where('status', IngestRunStatus::Failed)
            ->orderByDesc('processed_at')
            ->first();

        return response()->json([
            'status' => 'ok',
            'time'   => now()->toIso8601String(),

            'ingest' => [
                'last_payload' => $lastPayload === null ? null : [
                    'id'          => (int) $lastPayload->id,
                    'received_at' => (string) $lastPayload->received_at,
                ],

                'last_parsed_run' => $lastRun === null ? null : [
                    'id'                    => $lastRun->id,
                    'raw_ingest_payload_id' => $lastRun->raw_ingest_payload_id,
                    'status'                => $lastRun->status->value,
                    'row_count'             => $lastRun->row_count,
                    'received_at'           => $lastRun->received_at->toIso8601String(),
                    'processed_at'          => $lastRun->processed_at?->toIso8601String(),
                ],

                // No `error` (free text with the readings in it) and no
                // `session_id` (the phone automation's own identifier).
                // "Failed at this time, on this payload" is all a watcher can
                // act on; the payload id is the handle for the rest.
                'last_failed_run' => $lastFailure === null ? null : [
                    'id'                    => $lastFailure->id,
                    'raw_ingest_payload_id' => $lastFailure->raw_ingest_payload_id,
                    'processed_at'          => $lastFailure->processed_at?->toIso8601String(),
                ],

                // Payloads banked but never carried to a terminal run state.
                // Non-zero and not falling is the "worker is wedged" signal.
                'unparsed_payloads' => $this->unparsedPayloadCount(),

                'runs' => $this->runCountsByStatus(),
            ],

            /*
             * The OTHER silent failure, mirroring the one above. `ingest` asks
             * "is the phone still sending?"; this asks "can the phone still
             * draw anything?" — the app renders every pixel in JavaScript from
             * an empty `#app`, so a crash during boot or render is a blank
             * white screen that returns 200 for the HTML, 200 for the bundle,
             * and nothing anywhere else.
             *
             * Watch `last_24h`: `total` accumulates forever and says nothing
             * about now. Which BUILD a report was filed against — a live bug,
             * or a tab left open on an old one — is answered from the table,
             * not here: see latestClientError() below and
             * App\Http\Controllers\Api\ClientErrorController.
             */
            'client' => [
                'errors' => [
                    'total'    => ClientError::query()->count(),
                    'last_24h' => ClientError::query()
                        ->where('created_at', '>=', now()->subDay())
                        ->count(),
                    'latest' => $this->latestClientError(),
                ],
            ],
        ]);
    }

    /**
     * That the newest client-side crash exists, and when — not what it said.
     *
     * Never the stack: a 4 KB minified trace on every poll is a health check
     * dearer than the thing it reports on. Never the message, url or build
     * either, for the reason in the class docblock — this response is public.
     * `id`, `kind` and a timestamp still say "look in the table", which is all
     * this ever had to do; the detail lives there, behind the app's own auth.
     *
     * @return array<string, mixed>|null
     */
    private function latestClientError(): ?array
    {
        $latest = ClientError::query()->orderByDesc('id')->first();

        if ($latest === null) {
            return null;
        }

        return [
            'id'         => $latest->id,
            'kind'       => $latest->kind,
            'created_at' => $latest->created_at->toIso8601String(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function runCountsByStatus(): array
    {
        $counts = IngestRun::query()
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            // Down to the query builder: `total` is this SELECT's own alias,
            // not a column of the model, and there is no row to hydrate.
            ->toBase()
            ->pluck('total', 'status')
            ->all();

        $out = [];

        // Every status listed, always. A key that only appears once something
        // has gone wrong is a key nobody's dashboard is watching.
        foreach (IngestRunStatus::cases() as $status) {
            $out[$status->value] = (int) ($counts[$status->value] ?? 0);
        }

        return $out;
    }

    private function unparsedPayloadCount(): int
    {
        return RawIngestPayload::query()
            ->whereDoesntHave('ingestRuns', function ($query): void {
                $query->whereIn('status', [
                    IngestRunStatus::Completed,
                    IngestRunStatus::Empty,
                ]);
            })
            ->count();
    }
}
