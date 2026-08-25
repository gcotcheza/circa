<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Step 3: the activity strip and the trend chart need numbers per day.
 *
 * Steps / exercise minutes / distance could be computed on the fly from
 * `health_metrics` on every page load, fine for one day — but the trend
 * page asks for 28 at once, and each is not a SUM but a greedy
 * non-overlapping selection across sources (see
 * App\Services\Rollup\BucketSelector): 28 days x 4 metrics of that per
 * request is a lot of work to redo for data that only changes when an
 * export lands.
 *
 * Stored here for the same reason the kcal columns already are: this
 * table is fully derived and safe to drop and rebuild, so caching a
 * derived number costs nothing but a rebuild.
 *
 * `active_kcal_coverage` is the evidence behind `active_kcal_is_partial`.
 * Storing the fraction as well as the flag lets the UI say "the Watch
 * covered 61% of this day" instead of an unfalsifiable warning triangle,
 * and a threshold change becomes a config edit plus rebuild rather than
 * a guess about which days were affected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_summaries', function (Blueprint $table): void {
            // numeric, not integer: step_count arrives fractional when HAE
            // summarises across devices (547.408867 steps is a real value in
            // the captured data — it is a device-split, not a typo).
            $table->decimal('steps', 10, 2)->nullable()->after('resting_kcal');

            $table->decimal('exercise_minutes', 8, 2)->nullable()->after('steps');

            $table->decimal('distance_km', 8, 3)->nullable()->after('exercise_minutes');

            // 0.000-1.000. Fraction of the day covered by watch-derived
            // active-energy buckets.
            $table->decimal('active_kcal_coverage', 4, 3)->nullable()
                ->after('active_kcal_is_partial');
        });
    }

    public function down(): void
    {
        Schema::table('daily_summaries', function (Blueprint $table): void {
            $table->dropColumn([
                'steps',
                'exercise_minutes',
                'distance_km',
                'active_kcal_coverage',
            ]);
        });
    }
};
