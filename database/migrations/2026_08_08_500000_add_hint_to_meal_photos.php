<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * What the person holding the phone already knows about the plate.
 *
 * WHY A COLUMN, NOT A REQUEST FIELD. Three reasons a hint riding the
 * upload into the prompt and forgotten would be wrong: (1) it's EDITABLE
 * AFTER THE FACT — "analyse this photo again" is a button on a plate that
 * already exists, and a hint thought of at the sink ("that was 120g of
 * drained tuna") must attach to a photo taken an hour earlier; (2) it's
 * PER PLATE — a dinner is courses analysed separately, and the note about
 * the eggs must not travel to the pudding; `meals.notes` is the user's
 * note about the MEAL, a different sentence with a different subject;
 * (3) it EXPLAINS THE NUMBERS LATER — a portion zero-width because it was
 * weighed looks identical to one the model guessed at, unless the reason
 * sits next to the plate.
 *
 * NOT `meals.notes`, NOT `meal_photos.model_notes`. Three sentences,
 * three subjects, three columns — the same argument step 10 made
 * splitting `model_notes` out of `notes`:
 *
 *   meals.notes             the user, about the MEAL. Editable, never sent
 *                           as instruction, shown on the card.
 *   meal_photos.model_notes the MODEL, about its own answer. Read-only.
 *   meal_photos.hint        the USER, about the FOOD, addressed to the
 *                           model. Editable, and the only one that
 *                           changes what's asked.
 *
 * Merging any two would mean the review sheet's Notes box quietly
 * becoming a prompt input — a diary entry ("felt heavy afterwards")
 * arriving as a claim about what the food was.
 *
 * ADDITIVE AND NULLABLE, SO EVERY EXISTING PLATE IS UNCHANGED. NULL is
 * honest for every photo taken before this existed and for most taken
 * after, where the user said nothing. The prompt sends no block for a
 * null hint, so an unhinted plate is asked exactly what it would have
 * been asked before this migration ran.
 *
 * `text` rather than a bounded string: Postgres stores them identically
 * and the ceiling belongs where it can produce a message —
 * MealPhotoRequest bounds it at 500 characters, a hint rather than an
 * essay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_photos', function (Blueprint $table): void {
            $table->text('hint')->nullable()->after('sha256');
        });
    }

    public function down(): void
    {
        Schema::table('meal_photos', function (Blueprint $table): void {
            $table->dropColumn('hint');
        });
    }
};
