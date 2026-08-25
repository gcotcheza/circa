<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * "I ate half of this" — the shared plate.
 *
 * WHY THE ORIGINAL PORTION IS STORED, NOT DERIVED. The cheap move is one
 * column, `share_fraction`, recovering the untouched portion as
 * `portion_g / share_fraction`. It fails silently: both columns are
 * decimal(10,3), and a third of a 100g portion stores 33.333, which
 * divided back by 0.3333 gives 99.999 — every change of mind shaves a
 * little off the number the model actually said, with nothing on screen
 * explaining why the rice is shrinking.
 *
 * So the unscaled estimate is stored, and the eaten portion is derived
 * from it every time:
 *
 *     portion_g_* = portion_full_g_* x share_fraction        (always)
 *
 * `portion_full_g_*` is written once, by whoever produced the estimate,
 * and never computed from `portion_g_*` — changing the share is one
 * multiplication from a number that hasn't moved, so it can't drift no
 * matter how many times the user changes their mind.
 *
 * WHY `portion_g_*` KEEPS THE SCALED VALUE: it's the one every existing
 * reader already wants (IntakeCalculator, the rollup, the meal card, the
 * trends page, `photos:prune`) — after this migration they get the right
 * answer with no change at all, instead of five call sites each needing
 * to remember to multiply. DENSITIES are untouched: per 100g is a fact
 * about the food, not how much was eaten, so a share scales the portion
 * and nothing else.
 *
 * THE SHARE IS ALSO ON THE PLATE. `meal_photos.share_fraction` is the
 * ENTRY's chosen share (the chip row on the review sheet). It exists
 * because re-analysis throws the items away — fresh rows have no identity
 * to match the old ones to — so a re-analysed shared plate must stay
 * shared, and the sheet reads it back to keep the chip on ½.
 *
 * Per-item overrides live on the items themselves and are derived, not
 * flagged: an item whose `share_fraction` differs from its plate's IS the
 * override — a boolean beside it would be a second place for the same
 * fact to be wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_items', function (Blueprint $table): void {
            /*
             * 0.0100-1.0000, four decimal places: thirds and sixths are the
             * fractions people actually eat, and 0.3333 is close enough to
             * a third that no portion this app stores can tell the
             * difference.
             *
             * NOT NULL, default 1: every existing row was eaten whole as
             * far as anybody knew — "no share recorded" and "ate all of
             * it" are the same claim.
             */
            $table->decimal('share_fraction', 5, 4)->default(1);

            // Nullable only for this migration's duration; backfilled then
            // made NOT NULL below — a row without it can't answer "what
            // did the model actually say", and every reader would need a
            // fallback meaning the same thing.
            $table->decimal('portion_full_g_min', 10, 3)->nullable();
            $table->decimal('portion_full_g_max', 10, 3)->nullable();
        });

        // Every existing item was eaten whole, so the estimate and the
        // eaten portion are the same number — not an assumption, since
        // before this migration there was no way to say otherwise.
        DB::table('meal_items')->update([
            'portion_full_g_min' => DB::raw('portion_g_min'),
            'portion_full_g_max' => DB::raw('portion_g_max'),
        ]);

        DB::statement('ALTER TABLE meal_items ALTER COLUMN portion_full_g_min SET NOT NULL');
        DB::statement('ALTER TABLE meal_items ALTER COLUMN portion_full_g_max SET NOT NULL');

        Schema::table('meal_photos', function (Blueprint $table): void {
            $table->decimal('share_fraction', 5, 4)->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('meal_items', function (Blueprint $table): void {
            $table->dropColumn(['share_fraction', 'portion_full_g_min', 'portion_full_g_max']);
        });

        Schema::table('meal_photos', function (Blueprint $table): void {
            $table->dropColumn('share_fraction');
        });
    }
};
