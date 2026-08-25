<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Three additive columns the step-1 sketch could not have known it needed.
 *
 * `tdee_mid` — the range is ASYMMETRIC. Its half-widths are
 *   sqrt(slope_kcal² + band_low²) and sqrt(slope_kcal² + band_high²), and
 *   the intake band around a meal-photo estimate is routinely lopsided
 *   ("600–900, probably 700"). So (min + max) / 2 is NOT the number the
 *   equation produced, and reconstructing the midpoint that way would
 *   quietly move the estimate. TdeeEstimate::midpoint() now reads this
 *   column.
 *
 * `intake_mean_kcal` and `weight_slope_kg_per_day` — the two terms the
 *   estimate is made of. The card says "you averaged 2 040 kcal while the
 *   trend fell 0.34 kg/week", and that sentence has to come from the same
 *   computation the range came from — deriving it at read time instead
 *   would mean two answers that can disagree, and the one on screen
 *   would be the one nobody stored. Also makes a stored estimate
 *   auditable after a config change: with the inputs recorded, an old
 *   row can be checked rather than merely believed.
 *
 * NOT NULL with no default and no backfill, safe here for a reason worth
 * stating: `tdee_estimates` is empty in production (the gate has never
 * been met — food logging began this week), the table is fully derived,
 * and `php artisan tdee:estimate` reconstructs any row. Nothing here is
 * a source of truth about the past.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tdee_estimates', function (Blueprint $table): void {
            $table->decimal('tdee_mid', 10, 2)->after('window_end');
            $table->decimal('intake_mean_kcal', 10, 2)->after('tdee_max');
            $table->decimal('weight_slope_kg_per_day', 8, 5)->after('intake_mean_kcal');
        });
    }

    public function down(): void
    {
        Schema::table('tdee_estimates', function (Blueprint $table): void {
            $table->dropColumn(['tdee_mid', 'intake_mean_kcal', 'weight_slope_kg_per_day']);
        });
    }
};
