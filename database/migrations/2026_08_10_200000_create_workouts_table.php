<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One workout session. A LABEL OVER TIME, not a source of new calories.
 *
 * WHERE THIS DATA COMES FROM: a SECOND Health Auto Export automation, same
 * /api/ingest endpoint and key, Data Type Workouts instead of Health
 * Metrics — payloads carry `data.workouts[]` where the metrics automation
 * carries `data.metrics[]`. The parser branches on the CONTENT of the body,
 * never the `automation-name` header (a label the user can rename in four
 * taps; the body shape can't change on a whim).
 *
 * SHAPES OBSERVED (86 payloads, 100 distinct workouts, 2025-02-16 to
 * 2026-08-09, 12 activity types, 1-2 workouts per payload):
 *
 *   scalars      duration (seconds, float)
 *                {qty, units} for distance(km), activeEnergyBurned(kJ),
 *                totalEnergy(kJ), avgHeartRate(bpm), maxHeartRate(bpm),
 *                elevationUp(m), flightsClimbed(count), intensity(kcal/hr.kg),
 *                stepCadence(count/min), speed/avgSpeed/maxSpeed, temperature,
 *                humidity
 *   strings      id (an Apple UUID), name, start, end, location
 *   booleans     isIndoor
 *   SAMPLE SERIES, one entry per minute-ish — why this table stays narrow:
 *                heartRateData (up to 1,340 entries), route (up to 11,383 GPS
 *                points), activeEnergy, basalEnergy, stepCount,
 *                heartRateRecovery, walkingAndRunningDistance, cyclingDistance
 *
 * NOT STORED HERE, AND NOT A LOSS: `heartRateData` AND `route` ARE NOT
 * COLUMNS AND NOT IN `extras`. One 3-hour run carries 11,383 GPS fixes and
 * 189 heart-rate windows; the captured history is ~24 MB of body, mostly
 * those two keys. `raw_ingest_payloads` is APPEND-ONLY AND PERMANENT, so
 * every sample stays addressable by the same `apple_uuid` this table is
 * keyed on, and `ingest:replay` can promote any of it into columns later —
 * the decision is reversible; storing it twice from the start would not
 * have been.
 *
 * `step_count` is the ONE exception, summed from per-minute `stepCount` on
 * the way in — verified against the export's own arithmetic: the 2026-04-19
 * run sums to 15,054 steps over 189.6 minutes (79.39/min), matching that
 * workout's own `stepCadence` of 79.387985 to five decimal places.
 *
 * ENERGY ARRIVES IN kJ, STORED IN kcal — the same gotcha as
 * `health_metrics`. An easy 6km run (2026-08-02) reports 1424, impossible
 * as kcal for a ~56kg person, exactly 340 kcal once divided by 4.184 (what
 * a 47-minute easy run costs). Conversion runs on the way IN through the
 * same MetricCatalog the metrics pipeline uses — one catalog, one answer.
 *
 * THIS TABLE MUST NEVER BE ADDED TO AN ENERGY TOTAL. A workout's active
 * kcal is ALREADY INSIDE `daily_summaries.active_kcal` — the Watch writes
 * `active_energy` continuously whether or not a workout is running;
 * starting one just puts a NAME on a stretch of time already being
 * recorded. Summing `workouts.active_kcal` into a day's expenditure would
 * double-count every session and poison the TDEE estimator. Nothing in the
 * rollup reads this table, and nothing should start.
 *
 * IDEMPOTENCY: `apple_uuid`, HealthKit's own workout UUID, stable across
 * exports — a manual re-export of two years of history is safe to receive
 * twice. Every other table here synthesises identity from (metric, bucket,
 * source); this one is handed one. Upsert on it, last-writer-wins on every
 * other column: a workout is restated whole when the Watch finishes
 * processing it, with no cumulative axis to protect.
 *
 * `is_implausible`: THE FOUR-DAY HIKE. One captured workout runs 2026-07-07
 * 20:56 to 2026-07-11 20:56, four days at an average HR of 70bpm — a timer
 * never stopped. Imported FAITHFULLY (the export is the record; dropping a
 * row is how a database starts disagreeing with its device) but flagged, so
 * readers computing over sessions skip it. The rule is one config threshold
 * (`health.workouts.max_plausible_hours`, 12h) plus a non-positive
 * duration, and it's a MARKER not a filter — the day view still shows the
 * row, with the caveat next to it.
 */
return new class extends Migration
{
    /**
     * Baked into the generated-column DDL, exactly as `health_metrics` and
     * `meals` bake it: a Postgres generated column must be IMMUTABLE, so it
     * can't read config('health.timezone') at runtime.
     *
     * !! KEEP IN SYNC WITH config/health.php 'timezone'. !!
     */
    private const APP_TIMEZONE = 'Europe/Amsterdam';

    public function up(): void
    {
        Schema::create('workouts', function (Blueprint $table): void {
            $table->id();

            // HealthKit's workout UUID, verbatim. 36 chars observed; 64
            // leaves room for a format that grows without a migration.
            $table->string('apple_uuid', 64)->unique('workouts_apple_uuid_unique');

            // HAE's `name`: "Outdoor Run", "Martial Arts", "Climbing".
            // Stored verbatim, not mapped to an enum — a new activity type
            // must never need a migration to land.
            $table->string('type', 64);

            $table->timestampTz('started_at');
            $table->timestampTz('ended_at');

            // Seconds, as delivered (fractional). NOT derived from the two
            // timestamps: they agree to within a second on every captured
            // workout, and where they disagree the export's figure is the
            // one the Watch stands behind.
            $table->decimal('duration_s', 12, 3);

            $table->decimal('distance_km', 10, 4)->nullable();

            // CONVERTED from the delivered kJ. See the docblock.
            $table->decimal('active_kcal', 10, 3)->nullable();

            // bpm. smallint: a heart rate that needs more than 32,767 is
            // not a heart rate.
            $table->smallInteger('avg_hr')->nullable();
            $table->smallInteger('max_hr')->nullable();

            // Summed from the per-minute `stepCount` series — the one
            // series this table reduces to a workout-level total.
            $table->integer('step_count')->nullable();

            $table->decimal('elevation_up_m', 8, 2)->nullable();

            // Nullable, three-valued on purpose: 41 of 100 captured
            // workouts carry no `isIndoor` (Climbing, Martial Arts,
            // Tennis), and "the export didn't say" isn't "outdoors".
            $table->boolean('is_indoor')->nullable();

            // kcal/hr.kg — Apple's own effort figure, already normalised
            // for body mass.
            $table->decimal('intensity', 8, 4)->nullable();

            // Plausibility marker. NOT NULL with a default so every row
            // has an answer without a coalesce; see the docblock for the
            // four-day hike it exists for.
            $table->boolean('is_implausible')->default(false);

            // What the clock on the wrist said (+0200 -> 120). The
            // timestamps above are absolute; this reconstructs the local
            // hour across a DST boundary or a trip.
            $table->smallInteger('device_utc_offset_minutes');

            /*
             * THE LONG TAIL, VERBATIM. Every scalar field not a column
             * above: speed/avgSpeed/maxSpeed, stepCadence, flightsClimbed,
             * temperature, humidity, location, metadata — kept exactly as
             * delivered, plus two derived keys (`min_hr`, `total_kcal`)
             * whose sources would otherwise be lost.
             *
             * Pass-through rather than an allow-list, so a field HAE adds
             * next year lands here without a migration. SAMPLE SERIES ARE
             * EXCLUDED BY SHAPE — any JSON list is dropped — keeping
             * `route`/`heartRateData` out permanently, not by remembering
             * to name them.
             *
             * NOT NULL with a '{}' default: "no extras" is an empty
             * object, not a second null check for every reader.
             */
            $table->jsonb('extras')->default(DB::raw("'{}'::jsonb"));

            $table->timestampTz('ingested_at', 6)->default(DB::raw('now()'));

            /*
             * Calendar day the session STARTED on, in the app's reporting
             * timezone. Generated by the database for the same reason
             * `health_metrics.local_date` is: one day-boundary definition,
             * computed once, identically for every writer. A session that
             * crosses midnight belongs to the day it began, keeping the
             * four-day hike on one row of the day view instead of five.
             */
            $table->date('local_date')->storedAs(
                sprintf("((started_at AT TIME ZONE '%s')::date)", self::APP_TIMEZONE)
            );

            // The day view's only query: "what did I train on this date".
            $table->index(['local_date', 'started_at'], 'workouts_local_date_started_at_index');

            // The report's range scan, and "how many Martial Arts sessions
            // since April" without a sequential scan of `type`.
            $table->index(['type', 'local_date'], 'workouts_type_local_date_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workouts');
    }
};
