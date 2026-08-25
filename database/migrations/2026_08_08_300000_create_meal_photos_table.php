<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * A meal stops having ONE photograph, and starts having a series of ENTRIES.
 *
 * WHAT A ROW HERE ACTUALLY IS: not "an attachment", but one PLATE —
 * photographed and analysed on its own (first course, second helping, a
 * dessert twenty minutes later). Each is uploaded, sent to the model
 * separately, and confirmed on its own — confirming APPENDS to the meal
 * rather than replacing what's there. That's why this is a table of
 * entries with a `position` rather than a bag of files: the order is the
 * order the plates arrived, is what "photo 2" in the review sheet means,
 * and is the order the offline queue replays them in.
 *
 * WHY A TABLE, NOT A JSON COLUMN ON `meals`. `meals.photo_paths jsonb`
 * is wrong for what this data is asked to do:
 *   1. RETENTION walks photographs, not meals — a row scan with an index
 *      vs. a full table scan plus a document rewrite to repoint one
 *      element at its thumbnail.
 *   2. A PHOTO IS ADDRESSABLE. The review sheet, the serving route, the
 *      offline queue and a re-analysis each need a stable identifier for
 *      one image — an array index isn't one, it moves when anything
 *      before it is deleted.
 *   3. THINGS POINT AT IT. `vision_requests.meal_photo_id` and
 *      `meal_items.meal_photo_id` say which plate a call/item came from;
 *      neither is expressible as a pointer into a JSON array.
 *
 * `client_id` IS THE IDEMPOTENCY KEY FOR THE UPLOAD, PER PHOTO.
 * `vision_requests.idempotency_key` still makes an analysis idempotent —
 * what it can't do is identify the FILE: three plates from one dinner
 * flush from the offline queue as three uploads to one meal, and the
 * server must tell "the second plate" from "the first plate, twice". The
 * client generates this at the shutter, and a replay carrying a
 * `client_id` already seen writes nothing and answers 200.
 *
 * `meals.photo_path` IS KEPT, DEPRECATED, AND NO LONGER READ. Every read
 * moves to this table in the same commit. The column stays because
 * dropping it here would make the move irreversible for the sake of one
 * nullable text column — `down()` drops this table and the old rows are
 * still exactly where they were. Dropping it is a later cleanup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_photos', function (Blueprint $table): void {
            $table->id();

            // Cascade: `meal_items` is the record of what was eaten. A
            // meal that no longer exists has no record to support, so its
            // pictures go with it — same rule PhotoStore::forget() applies
            // to disk.
            $table->foreignId('meal_id')->constrained()->cascadeOnDelete();

            // Client-generated at capture. Unique GLOBALLY, not per meal:
            // an offline replay must be recognisable without trusting the
            // meal uuid it arrives with.
            $table->uuid('client_id')->unique();

            $table->text('path');
            $table->text('thumb_path');

            // Of the RE-ENCODED bytes actually sent to the model. Nullable
            // only for rows this migration backfills, which may have no
            // vision request to read one from.
            $table->string('sha256', 64)->nullable();

            // Order the plates arrived in — "photo 2" means position 1.
            // Unique per meal so a removal that forgets to renumber is a
            // loud constraint violation, not two photos claiming third.
            $table->smallInteger('position');

            /*
             * The model's account of ITS OWN answer, for THIS plate.
             * `meals.model_notes` keeps its meaning (the last thing said
             * about the meal, shown on the day card) but can't serve a
             * per-entry review — three plates in sequence would leave every
             * review sheet quoting the third's note. Two SUBJECTS, so two
             * columns, the same argument step-9.1 made for `notes` vs
             * `model_notes` one level down.
             */
            $table->text('model_notes')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->unique(['meal_id', 'position']);

            // Retention walks this: "originals eaten before the cutoff" is
            // a join to meals, and the prefix filter makes it cheap.
            $table->index('path');
        });

        $this->backfill();
    }

    /**
     * Move every `meals.photo_path` into a row here, at position 0.
     *
     * Public and separately callable so the RULE can be asserted rather
     * than taken on trust — a data migration runs once and is then
     * unfalsifiable. Guarded by `whereDoesntHave`, so calling it twice
     * writes nothing the second time.
     */
    public function backfill(): void
    {
        /*
         * The rule: nothing on disk is touched, only the POINTER moves,
         * from a column on the meal to a row here at position 0.
         *
         * `thumb_path` is derived under PhotoStore's convention:
         * `originals/x` and `thumbs/x` are the same photograph. A meal
         * whose `photo_path` already starts with `thumbs/` has been
         * through retention — its original is gone, so the path IS the
         * thumbnail, and copying it into both columns preserves that
         * meaning (`isOriginal()` still answers false for it).
         */
        $sha = DB::table('vision_requests')
            ->whereNotNull('image_sha256')
            ->orderBy('id')
            ->pluck('image_sha256', 'meal_id');

        $alreadyMoved = DB::table('meal_photos')->pluck('meal_id')->all();

        DB::table('meals')
            ->whereNotNull('photo_path')
            ->whereNotIn('id', $alreadyMoved)
            ->orderBy('id')
            ->select(['id', 'photo_path', 'model_notes', 'created_at'])
            ->chunkById(200, function ($meals) use ($sha): void {
                $rows = [];

                foreach ($meals as $meal) {
                    $path = (string) $meal->photo_path;

                    $rows[] = [
                        'meal_id'    => $meal->id,
                        'client_id'  => (string) Str::uuid(),
                        'path'       => $path,
                        'thumb_path' => str_starts_with($path, 'originals/')
                            ? 'thumbs/'.substr($path, strlen('originals/'))
                            : $path,
                        'sha256'      => $sha[$meal->id] ?? null,
                        'position'    => 0,
                        'model_notes' => $meal->model_notes,
                        'created_at'  => $meal->created_at,
                    ];
                }

                if ($rows !== []) {
                    DB::table('meal_photos')->insert($rows);
                }
            });
    }

    public function down(): void
    {
        // `meals.photo_path` was never modified, so this is a complete
        // reversal rather than a best effort.
        Schema::dropIfExists('meal_photos');
    }
};
