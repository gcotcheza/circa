<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Raw Health Auto Export capture.
 *
 * THIS TABLE IS PERMANENT. It is not a step-0 scratch buffer. Every
 * payload the phone ever sends is banked here untouched, and everything
 * downstream (health_metrics, sleep_sessions, daily summaries, TDEE) is
 * a derivation of it — the replay source: when the parser is wrong,
 * when a metric turns out to matter later, when a backfill has to be
 * re-run, the fix is to reprocess these rows. There is no second copy.
 *
 * Never prune it, never truncate it, never "clean it up" once the real
 * schema lands. Storage is a few MB a month; the data is unrecoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('raw_ingest_payloads', function (Blueprint $table): void {
            // bigserial: this table grows for the life of the app.
            $table->id();

            // jsonb, not json/text — queryable and indexable while we work out
            // what the payload shape actually is.
            $table->jsonb('headers');
            $table->jsonb('body');

            // Microsecond precision: batched exports land several POSTs inside
            // the same second and their arrival order is diagnostic.
            $table->timestampTz('received_at', 6)->default(DB::raw('now()'));

            $table->index('received_at');
        });
    }

    public function down(): void
    {
        // Present only so the migration is well-formed. Rolling this back
        // destroys the replay source — see the note above.
        Schema::dropIfExists('raw_ingest_payloads');
    }
};
