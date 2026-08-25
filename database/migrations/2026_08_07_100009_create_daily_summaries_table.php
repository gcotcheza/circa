<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One row per local calendar day. Fully derived — safe to drop and rebuild.
 *
 * `local_date` is the primary key and the single join target for
 * health_metrics.local_date and meals.local_date, both Postgres generated
 * columns using the same hardcoded zone. Nothing joins on a PHP-computed
 * date.
 *
 * ENERGY IS kcal HERE. The device sends kJ and health_metrics keeps kJ;
 * conversion happens on the way into this table, since this is the layer
 * a human reads. See config('health.canonical_units').
 *
 * THE INTAKE BAND IS NOT A LINEAR SUM. Adding every item's min and max
 * produces an interval so wide it says nothing — it assumes every
 * estimate is wrong in the same direction at once. The half-widths
 * combine in quadrature (RSS) around a midpoint instead, and
 * `kcal_in_mid` is stored so the UI has a number to draw the band around.
 *
 * HONESTY FLAGS. Expenditure is uncertain too, and the failure mode is
 * silent: a day the Watch was off the wrist under-reports burn by
 * hundreds of kcal and looks exactly like a sedentary day; a
 * breakfast-only log looks exactly like a disciplined day. Both get
 * flagged rather than averaged into the TDEE estimate as if real.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_summaries', function (Blueprint $table): void {
            $table->date('local_date')->primary();

            // Intake band. mid is the quadrature midpoint, not (min+max)/2 of
            // the linear sums.
            $table->decimal('kcal_in_min', 10, 2)->nullable();
            $table->decimal('kcal_in_mid', 10, 2)->nullable();
            $table->decimal('kcal_in_max', 10, 2)->nullable();

            $table->decimal('protein_g_min', 10, 2)->nullable();
            $table->decimal('protein_g_mid', 10, 2)->nullable();
            $table->decimal('protein_g_max', 10, 2)->nullable();

            $table->decimal('carbs_g_min', 10, 2)->nullable();
            $table->decimal('carbs_g_mid', 10, 2)->nullable();
            $table->decimal('carbs_g_max', 10, 2)->nullable();

            $table->decimal('fat_g_min', 10, 2)->nullable();
            $table->decimal('fat_g_mid', 10, 2)->nullable();
            $table->decimal('fat_g_max', 10, 2)->nullable();

            // Expenditure, kcal, one source per bucket by priority — never
            // summed: iPhone and Watch both legitimately report the same
            // hour, and summing roughly doubles burn and poisons TDEE.
            $table->decimal('active_kcal', 10, 2)->nullable();
            $table->decimal('resting_kcal', 10, 2)->nullable();

            // Nullable: most days have no weigh-in. The TDEE estimator smooths
            // across the gaps rather than interpolating them into this table.
            $table->decimal('weight_kg', 8, 3)->nullable();

            // User-confirmable. Heuristic default: >= N items, last item after
            // ~18:00 local, kcal above a floor.
            $table->boolean('is_complete_log')->default(false);

            // Every expected metric present for the whole day.
            $table->boolean('has_full_metric_coverage')->default(false);

            // Watch coverage gap — burn is an undercount, say so.
            $table->boolean('active_kcal_is_partial')->default(false);

            $table->timestampTz('rebuilt_at')->nullable();

            $table->timestampsTz();

            // The TDEE window gate reads exactly this way: complete-log days
            // inside a date range.
            $table->index(['is_complete_log', 'local_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_summaries');
    }
};
