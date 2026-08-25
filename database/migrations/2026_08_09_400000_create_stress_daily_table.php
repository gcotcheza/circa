<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One stress score per local day, 1-99, higher meaning less stress.
 *
 * WHY THE PRIMARY KEY IS THE DATE, NOT (date, method_version). Compare
 * `tdee_estimates`, which puts `method_version` IN its unique key on
 * purpose: a TDEE estimate is a statement about a WINDOW, several methods
 * can hold opinions about the same window, and keeping both lets a new
 * estimator stay comparable to the old one.
 *
 * A stress score is a statement about a DAY, and the point of storing it
 * is the health report to come, which joins stress against meals, sleep,
 * exercise and supplements ON `local_date`. Two rows per day would make
 * every such join silently ambiguous. So: one row per day,
 * `method_version` stamped as provenance rather than identity, and a
 * method change is a REBUILD (`php artisan stress:rebuild --all`), not a
 * second opinion — the table is 100% derived from `health_metrics`, so
 * rebuilding in place loses nothing reconstructable.
 *
 * WHAT IS STORED BESIDES THE SCORE: enough for a row to explain itself
 * without re-running the estimator — the z-score, the day's own
 * deseasonalised HRV level, the baseline it was measured against, and how
 * much data was behind it. A number the UI qualifies has to carry the
 * thing it's qualified BY, or the qualification is folklore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stress_daily', function (Blueprint $table): void {
            // Join key for everything downstream. Same name/type as
            // `daily_summaries.local_date` and `health_metrics.local_date`,
            // which makes the report's joins trivial.
            $table->date('local_date')->primary();

            // 1-99. smallInteger, not tinyInteger: Postgres has no
            // single-byte integer, and Laravel emulates tinyInteger with a
            // CHECK-free smallint anyway.
            $table->smallInteger('score');

            // Denormalised from `score` so a query can group by band
            // without restating thresholds, and a threshold change is
            // visible in the data, not only in whatever code last read it.
            $table->string('band', 16);

            // The standardised deviation the score is a squash of. Kept
            // because the score is lossy at the ends (every day past +2.5
            // sigma scores 96-99), and a correlation study wants the
            // linear quantity.
            $table->decimal('z', 6, 3);

            // Day's own level and the baseline it was compared with, both
            // in MILLISECONDS (the geometric mean, exp of the log median)
            // so a human reading a row recognises the numbers as HRV.
            $table->decimal('hrv_ms', 8, 3);
            $table->decimal('baseline_hrv_ms', 8, 3);

            // The spread, in LOG units, where it was measured. Kept as a
            // fraction rather than converted to ms because it's a
            // relative width: 0.16 means the ordinary day-to-day swing is
            // ~16%.
            $table->decimal('baseline_spread_log', 6, 4);

            // Coverage, first-class. `samples` is HRV readings accepted
            // for the day (overlap-resolved); `covered_hours` is what
            // they add up to.
            $table->smallInteger('samples');
            $table->decimal('covered_hours', 4, 1);

            // Days of the user's own history the baseline drew on. Below
            // config('health.stress.min_baseline_days') no row is written
            // at all, so this can never be smaller than the gate.
            $table->smallInteger('baseline_days');

            // low | medium | high — derived from `samples`, stored so the
            // UI and a later report can't disagree about which days were
            // thin.
            $table->string('confidence', 8);

            $table->string('method_version', 32);

            $table->timestampTz('computed_at');

            $table->timestampsTz();

            // "Show me the bad days" is the query this feature exists for.
            $table->index(['band', 'local_date']);
            $table->index('score');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stress_daily');
    }
};
