<?php

declare(strict_types=1);

use App\Enums\VisionRequestKind;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * `vision_requests` stops being only about photographs.
 *
 * WHY A COLUMN AND NOT `image_sha256 IS NULL`. `image_sha256` was
 * already nullable, so the obvious move is "a row with no image was a
 * text estimate" — wrong, and quietly: MealPhotoController::reanalyze()
 * claims its row with a NULL sha256 (bytes are already on disk, hashed
 * at upload not at re-analysis), so a null there ALSO means "the second
 * look at a photograph". The two are indistinguishable in exactly the
 * query the audit table exists to answer — "what did the text prompt
 * cost, and how often was it right?"
 *
 * So the kind is stated. `default 'photo'` backfills every existing row
 * with what it actually was; NOT NULL because a request whose kind is
 * unknown is a row nobody can evaluate.
 *
 * `input_payload` is the other half of the same argument. `prompt_version`
 * + `raw_response` make a prompt change evaluable against history — but
 * only if the INPUT can be replayed. For a photo the input is the image,
 * identified by `image_sha256`. For a text estimate the input is what
 * the user typed, which lives nowhere else once the proposal has
 * replaced the meal's items. Storing it here keeps a v1-text -> v2-text
 * comparison possible, and is what the job reads back to guarantee a
 * value the user typed survives the model, memory, and both together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vision_requests', function (Blueprint $table): void {
            $table->string('request_kind', 16)
                ->default(VisionRequestKind::Photo->value)
                ->after('meal_id');

            $table->jsonb('input_payload')->nullable()->after('image_sha256');

            // The audit questions are per-kind ("how many text estimates, at
            // what latency, against which prompt version"), so the index that
            // serves them leads with the kind.
            $table->index(['request_kind', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('vision_requests', function (Blueprint $table): void {
            $table->dropIndex(['request_kind', 'created_at']);
            $table->dropColumn(['request_kind', 'input_payload']);
        });
    }
};
