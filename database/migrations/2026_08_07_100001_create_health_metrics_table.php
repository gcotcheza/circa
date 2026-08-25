<?php

declare(strict_types=1);

use App\Enums\MetricAggregation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Scalar health datapoints. One row per (metric, statistic, bucket, source).
 *
 * SHAPES OBSERVED across the captured payloads (tens of thousands of
 * datapoints over ~30 metrics):
 *
 *   31 metrics   { date, qty, source }             -> one row
 *   heart_rate   { date, Min, Avg, Max, source }    -> THREE rows (min/avg/max)
 *   sleep_analysis                                  -> not here; own table
 *
 * Every non-sleep `date` is hour-aligned (mm:ss = 00:00 in all 27,004 of
 * them), offset +0200 in 100% of datapoints. Body composition is
 * hour-bucketed too: with `automation-aggregation: Hours` there's no raw
 * instant coming out of HAE, only a bucket containing one sample.
 *
 * WHY started_at / ended_at ARE NOT NULL (the spec says "nullable"). The
 * spec's unique key is (metric, aggregation, period, started_at, ended_at,
 * source_id), and a nullable column silently destroys it: Postgres treats
 * NULL != NULL inside a unique index, so two rows with a NULL `ended_at`
 * never collide and every re-export of a partial trailing bucket inserts a
 * duplicate instead of upserting — the exact bug the upsert semantics exist
 * to prevent.
 *
 * Three ways out; the third is what this table does.
 *   a) Expression index on coalesce(ended_at, started_at). Rejected: every
 *      writer would have to repeat the expression verbatim or silently
 *      insert duplicates.
 *   b) UNIQUE NULLS NOT DISTINCT (Postgres 15+; this is 18.4, verified
 *      working). Correct, but inexpressible in Laravel's schema builder —
 *      raw DDL, and "what does a NULL ended_at mean?" stays unanswered.
 *   c) NOT NULL, and an instant is the zero-length interval started_at ==
 *      ended_at. A plain unique index and `ON CONFLICT` work directly, and
 *      `aggregation` already says which rows are instants — nothing lost
 *      by not overloading NULL to say it twice.
 *
 * UNITS ARE CANONICAL — STEP 2 REVERSED THIS. This block used to say the
 * opposite (store the device unit verbatim, convert on the way into
 * `daily_summaries`); step 2 converts on the way IN instead, and `unit`
 * holds the canonical unit. The old worry — that converting in makes
 * replay lossy — doesn't hold: `raw_ingest_payloads` keeps every original
 * byte, so the conversion is reproducible by definition, while a `value`
 * column mixing kJ and kcal by firmware can't be summed without every
 * reader re-implementing the conversion.
 *
 * In practice: active_energy and basal_energy_burned arrive in kJ (all
 * 5,052 captured datapoints) and are stored in kcal; everything else is
 * stored exactly as delivered. The per-metric map is
 * App\Services\Ingest\MetricCatalog — one place, or eventually two
 * different answers.
 */
return new class extends Migration
{
    /**
     * Baked into the generated-column DDL below. A Postgres generated
     * column must be IMMUTABLE, so it can't read config('health.timezone')
     * at runtime — the zone is a literal. Verified: `timezone(text,
     * timestamptz)` is provolatile='i' and handles the CET/CEST switch
     * correctly.
     *
     * !! KEEP IN SYNC WITH config/health.php 'timezone'. !!
     * Changing it means a migration that drops/recreates this column and
     * rebuilds every daily_summaries row — not a config edit.
     */
    private const APP_TIMEZONE = 'Europe/Amsterdam';

    public function up(): void
    {
        Schema::create('health_metrics', function (Blueprint $table): void {
            $table->id();

            // HAE metric name, snake_case, verbatim. 33 distinct so far;
            // new ones must not need a migration.
            $table->string('metric', 64);

            // The statistic this value represents. NOT the automation
            // header — see App\Enums\MetricAggregation.
            $table->enum('aggregation', MetricAggregation::values());

            // Bucket width, from the `automation-aggregation` request
            // header (observed "Hours" -> 'hour' in every captured payload).
            // Deliberately NOT from `automation-period`, which describes
            // the export window mode, not the row — SPEC.md v2 had these
            // two the wrong way round.
            $table->string('period', 16);

            // numeric, never float: values are compared and summed, and
            // 6dp is already past sensor precision (largest observed
            // magnitude 1337.383, smallest 0.001).
            $table->decimal('value', 16, 6);

            // Device unit, verbatim. 32 chars for multi-byte units.
            $table->string('unit', 32);

            // Bucket bounds. Both NOT NULL; instants set them equal (see
            // docblock).
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at');

            // Offset the phone was on when it exported (+0200 -> 120). The
            // timestamps above are absolute; this preserves what the clock
            // on the wrist said, reconstructing a DST-boundary bucket
            // later.
            $table->smallInteger('device_utc_offset_minutes');

            $table->foreignId('source_id')->constrained('sources')->restrictOnDelete();

            $table->timestampTz('ingested_at', 6)->default(DB::raw('now()'));

            // The ONLY join key to daily_summaries. Stored + generated so
            // the day boundary is computed once, by the database,
            // identically for every writer — a PHP-side date() call would
            // drift if two processes disagreed about their timezone.
            $table->date('local_date')->storedAs(
                sprintf("((started_at AT TIME ZONE '%s')::date)", self::APP_TIMEZONE)
            );

            // Idempotency. The trailing hour of every export is partial
            // and legitimately re-sent larger later; 1,935 of 27,112
            // captured datapoints are re-sends of a tuple seen earlier.
            // Cumulative metrics resolve with GREATEST(), everything else
            // last-writer-wins — enforced by the writer, made possible
            // here.
            $table->unique(
                ['metric', 'aggregation', 'period', 'started_at', 'ended_at', 'source_id'],
                'health_metrics_identity_unique'
            );

            // Daily rollups: "every row for this metric on this day".
            $table->index(['metric', 'local_date'], 'health_metrics_metric_local_date_index');
            $table->index('local_date');
            $table->index('source_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_metrics');
    }
};
