<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One night. Sleep is interval data — it is not shredded into scalars.
 *
 * Observed payload shape — one record per night, units "hr", fractional
 * hours:
 *
 *   {
 *     "date":       "2026-08-07 00:00:00 +0200",
 *     "rem":        1.9211879865659607,
 *     "core":       5.8720494314697058,
 *     "deep":       1.0106159175104565,
 *     "awake":      0.66838987082242973,
 *     "totalSleep": 8.8038533355461226,
 *     "inBed":      0,
 *     "asleep":     0,
 *     "sleepStart": "2026-08-06 23:00:36 +0200",
 *     "sleepEnd":   "2026-08-07 08:28:56 +0200",
 *     "inBedStart": "2026-08-06 23:00:36 +0200",
 *     "inBedEnd":   "2026-08-07 08:28:56 +0200",
 *     "source":     "Demo\u{2019}s Apple\u{00A0}Watch"
 *   }
 *
 * Notes that shaped the columns:
 *
 *  - `totalSleep` == rem + core + deep, excluding awake (1.921 + 5.872 +
 *    1.011 = 8.804); stored, not derived — it's the number HAE stands
 *    behind.
 *  - `inBed`/`asleep` are 0 in every captured record — the Watch doesn't
 *    populate them. Nullable columns kept anyway for a future export (or
 *    "AutoSleep") that might: a missing column costs a migration, an
 *    unused one costs nothing.
 *  - `date` is HAE's own night key, always midnight device-local. Stored
 *    as a plain `date` per spec: sleep keys on the night, never a
 *    derived local_date — a 23:00 start and a 00:39 start can share a
 *    night, and only HAE knows which.
 *  - Observed sources: the watch, and occasionally "AutoSleep" — two
 *    sources can report the same night, hence unique (night_date,
 *    source_id) not unique (night_date); the read side picks by priority.
 *
 * Stored in MINUTES, not the hours that arrive — SPEC.md fixes the column
 * semantics as minutes. numeric(9,3) keeps the fraction (5.872 hr →
 * 352.323 min) so nothing is lost against the raw payload.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sleep_sessions', function (Blueprint $table): void {
            $table->id();

            // HAE's own night key. Not generated from a timestamp.
            $table->date('night_date');

            $table->foreignId('source_id')->constrained('sources')->restrictOnDelete();

            // Nullable: an export may carry the totals without the boundaries.
            $table->timestampTz('in_bed_start')->nullable();
            $table->timestampTz('in_bed_end')->nullable();
            $table->timestampTz('sleep_start')->nullable();
            $table->timestampTz('sleep_end')->nullable();

            // Stage minutes, nullable so "not reported" stays distinguishable
            // from "reported as zero" — `awake` is near-zero on a good night;
            // `in_bed`/`asleep` are simply absent.
            $table->decimal('rem_minutes', 9, 3)->nullable();
            $table->decimal('core_minutes', 9, 3)->nullable();
            $table->decimal('deep_minutes', 9, 3)->nullable();
            $table->decimal('awake_minutes', 9, 3)->nullable();
            $table->decimal('asleep_minutes', 9, 3)->nullable();
            $table->decimal('in_bed_minutes', 9, 3)->nullable();
            $table->decimal('total_sleep_minutes', 9, 3)->nullable();

            $table->smallInteger('device_utc_offset_minutes')->nullable();

            $table->timestampTz('ingested_at', 6)->default(DB::raw('now()'));

            $table->unique(['night_date', 'source_id'], 'sleep_sessions_night_source_unique');
            $table->index('night_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sleep_sessions');
    }
};
