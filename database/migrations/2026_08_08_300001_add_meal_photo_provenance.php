<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Which plate did this call look at, and which plate did this item come off?
 *
 * WHY PROVENANCE IS NOT OPTIONAL ONCE A MEAL HAS SEVERAL PLATES. With one
 * photograph per meal, "the proposal" and "the meal's unconfirmed items"
 * were the same set, and `whereNull('confirmed_at')` was always right.
 * With three, that set is a pile from three different answers, and three
 * operations become ambiguous at once:
 *
 *   RE-ANALYSE ONE PLATE   must discard that plate's proposal and no other.
 *                          Without this column it discards the dessert too.
 *   REMOVE ONE PLATE       must be able to offer "and its items" — which
 *                          means knowing which items those are.
 *   CONFIRM ONE PLATE      appends. The other plates' items, and anything
 *                          typed or scanned onto the meal by hand, must
 *                          come through it untouched.
 *
 * NULL is real and common: "not from a photograph" — typed, scanned, or
 * text-estimated. Exactly the rows the three operations above must never
 * touch, so the null does work rather than record ignorance.
 *
 * `nullOnDelete`, not `cascadeOnDelete`, on `meal_items`: an item with a
 * `confirmed_at` records something a human agreed they ate. Deleting the
 * photograph is a statement about the photograph; whether the items go
 * with it is a question the user answers
 * (MealPhotoController::destroy takes `remove_items`), not one the
 * database answers by cascading.
 *
 * Same choice on `vision_requests`, for the audit's sake: the row is the
 * model's opinion and what it cost, and stays legible after the image it
 * looked at is deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vision_requests', function (Blueprint $table): void {
            $table->foreignId('meal_photo_id')
                ->nullable()
                ->after('meal_id')
                ->constrained('meal_photos')
                ->nullOnDelete();
        });

        Schema::table('meal_items', function (Blueprint $table): void {
            $table->foreignId('meal_photo_id')
                ->nullable()
                ->after('meal_id')
                ->constrained('meal_photos')
                ->nullOnDelete();
        });

        /*
         * Backfill, under the only claim the old schema actually supports.
         *
         * Before this migration a meal had at most one photograph, so every
         * photo-kind request against a meal that has one looked at THAT one,
         * and every item on a photo-sourced meal came off it. Both true by
         * construction, not inference.
         *
         * A meal whose `source` isn't `photo` is left alone even if a photo
         * row exists: the text-estimate path can run on a photographed meal,
         * with items from a description.
         */
        $photos = DB::table('meal_photos')->where('position', 0)->pluck('id', 'meal_id');

        foreach ($photos as $mealId => $photoId) {
            DB::table('vision_requests')
                ->where('meal_id', $mealId)
                ->where('request_kind', 'photo')
                ->update(['meal_photo_id' => $photoId]);

            DB::table('meal_items')
                ->where('meal_id', $mealId)
                ->whereIn('meal_id', DB::table('meals')->where('source', 'photo')->select('id'))
                ->update(['meal_photo_id' => $photoId]);
        }
    }

    public function down(): void
    {
        Schema::table('meal_items', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('meal_photo_id');
        });

        Schema::table('vision_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('meal_photo_id');
        });
    }
};
