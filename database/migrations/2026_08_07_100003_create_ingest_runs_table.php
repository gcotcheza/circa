<?php

declare(strict_types=1);

use App\Enums\IngestRunStatus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Append-only log of processed POST bodies. Never a rejection gate.
 *
 * HAE's `session-id` semantics are ambiguous — with Batch Requests ON,
 * several POSTs from one export can share it. (In every captured
 * payload it was unique per request, but that's one automation's
 * behaviour on one day, not a guarantee.) So the key is
 * (session_id, sha256(body)): a collision means "this exact body was
 * already logged" — a no-op, not an error. Row-level upsert into
 * health_metrics remains the sole dedup mechanism.
 *
 * Headers observed on all 107 requests, no exceptions and no omissions:
 *
 *   session-id             UUID, distinct per request
 *   automation-id          "EFCACB12-48A7-4D2C-9C83-3DCD30807474" (constant)
 *   automation-name        "Health tracker" (106) / "New Automation" (1)
 *   automation-aggregation "Hours"            ← bucket WIDTH  → health_metrics.period
 *   automation-period      "Since Last Sync"  ← export WINDOW → this table only
 *   content-type           "application/json"
 *   content-length
 *
 * That aggregation/period split is the opposite of what SPEC.md v2 assumed
 * ("period (from automation-period header)"); both are kept here verbatim so
 * the interpretation stays auditable and reversible.
 *
 * `row_count` is the gap detector: a run that parsed cleanly and produced
 * zero rows is status `empty`, and that's what gets alerted on — trusting
 * the phone to report silence doesn't work, since a phone that isn't
 * syncing also isn't telling you so.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingest_runs', function (Blueprint $table): void {
            $table->id();

            // Which banked payload this run processed — additive, points at
            // raw_ingest_payloads without touching it. Nullable: a replay may
            // be synthesised from elsewhere.
            $table->foreignId('raw_ingest_payload_id')
                ->nullable()
                ->constrained('raw_ingest_payloads')
                ->nullOnDelete();

            $table->string('session_id', 128);

            // sha256 of the exact request body, lowercase hex.
            $table->char('body_sha256', 64);

            $table->string('automation_name', 255)->nullable();
            $table->string('automation_id', 128)->nullable();
            $table->string('automation_aggregation', 64)->nullable();
            $table->string('automation_period', 64)->nullable();

            // Rows written to health_metrics + sleep_sessions by this run.
            $table->integer('row_count')->default(0);

            $table->enum('status', IngestRunStatus::values())
                ->default(IngestRunStatus::Pending->value);

            $table->text('error')->nullable();

            $table->timestampTz('received_at', 6);
            $table->timestampTz('processed_at', 6)->nullable();

            $table->timestampsTz();

            $table->unique(['session_id', 'body_sha256'], 'ingest_runs_session_body_unique');

            // "Has anything landed lately?" — the last-successful-ingest
            // indicator and the gap alert both read this way round.
            $table->index(['status', 'received_at']);
            $table->index('received_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingest_runs');
    }
};
