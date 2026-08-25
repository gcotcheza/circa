<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Back-calculated total daily energy expenditure, as a range.
 *
 *   TDEE = mean(intake over complete-log days)
 *          − kcal_per_kg × OLS slope of the EMA-smoothed weight series
 *
 * over rolling 14–28 day windows. Gated on >= 14 complete-log days AND
 * >= 8 weigh-ins in the window; until then the UI says "collecting data"
 * rather than showing a number built on four data points.
 *
 * The slope is OLS over the EMA series, never endpoint-minus-endpoint:
 * daily weight noise is around ±1 kg, so two endpoints are almost pure
 * noise and can flip the sign of the whole estimate.
 *
 * Stored as min/max (regression standard error combined with the intake
 * range), never a single number — a point TDEE is a lie with a decimal
 * point on it.
 *
 * `method_version` is part of the identity, not metadata: when the
 * estimator changes, old estimates stay comparable instead of being
 * overwritten, and a new version can be backfilled alongside the old one
 * for the same window.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tdee_estimates', function (Blueprint $table): void {
            $table->id();

            $table->date('window_start');
            $table->date('window_end');

            $table->decimal('tdee_min', 10, 2);
            $table->decimal('tdee_max', 10, 2);

            // The gate inputs, kept so a displayed estimate can explain itself.
            $table->integer('n_days');
            $table->integer('n_weighins');

            $table->string('method_version', 32);

            $table->timestampTz('computed_at');

            $table->timestampsTz();

            // Recomputing a window replaces its estimate for that method;
            // a new method adds a row rather than clobbering one.
            $table->unique(
                ['window_start', 'window_end', 'method_version'],
                'tdee_estimates_window_method_unique'
            );

            $table->index('window_end');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tdee_estimates');
    }
};
