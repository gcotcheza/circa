<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use App\Services\Vision\StoredAnswer;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The model's working note stops being written into the user's notes.
 *
 * WHY A SECOND COLUMN, NOT A CONVENTION. `ProposalWriter::propose()`
 * wrote the model's note into `meals.notes`, reasoning that the daily
 * view already looks there and "portion judged against a 27 cm plate" is
 * context for the numbers above it. That holds for a photograph, where
 * the user typed no notes and the column was empty. It doesn't survive
 * the text path: the user types a description AND notes, and the
 * proposal overwrote the notes with the model's own analysis —
 *
 *     "Split the single line into its five components; the 250 g kwark is …"
 *
 * — which the review sheet then offered back in an EDITABLE Notes box,
 * so confirming stored the model's reasoning as the user's words. No
 * convention untangles that afterwards: once the two are one column,
 * "who said this" isn't recoverable.
 *
 * So two columns, one sentence each:
 *
 *   `notes`        the user's words. Written only by a request the user sent.
 *   `model_notes`  what the model said about its own answer. Written only by
 *                  ProposalWriter, never editable, replaced by each proposal.
 *
 * THE BACKFILL MOVES ONLY WHAT IT CAN PROVE. Every meal that reached
 * `proposed` before this migration has the model's note in `notes`, and
 * some were then confirmed — but a user may have edited the box first,
 * and that edit is theirs. So the backfill compares `meals.notes`
 * against the note in the stored `raw_response` of the meal's own
 * vision requests and moves it only on an exact match; anything the
 * user touched fails the comparison and stays put.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meals', function (Blueprint $table): void {
            $table->text('model_notes')->nullable()->after('notes');
        });

        $this->separate();
    }

    public function down(): void
    {
        // Put back the old column's only reading: no user notes shows the
        // model's; a meal with both keeps the user's — the whole point of the split.
        DB::table('meals')
            ->whereNull('notes')
            ->whereNotNull('model_notes')
            ->update(['notes' => DB::raw('model_notes')]);

        Schema::table('meals', function (Blueprint $table): void {
            $table->dropColumn('model_notes');
        });
    }

    /**
     * Move each model note out of `notes` and into `model_notes`, where the
     * stored answer proves that is what it is.
     */
    private function separate(): void
    {
        $rows = DB::table('meals')
            ->join('vision_requests', 'vision_requests.meal_id', '=', 'meals.id')
            ->whereNotNull('meals.notes')
            ->whereNotNull('vision_requests.raw_response')
            ->orderBy('vision_requests.id')
            ->get(['meals.id as meal_id', 'meals.notes as notes', 'vision_requests.raw_response as raw_response']);

        foreach ($rows as $row) {
            $raw = json_decode((string) $row->raw_response, true);

            if (! is_array($raw)) {
                continue;
            }

            $answer = StoredAnswer::fromRawResponse($raw);

            if ($answer === null || $answer->notes === '' || $answer->notes !== $row->notes) {
                continue;
            }

            DB::table('meals')->where('id', $row->meal_id)->update([
                'notes'       => null,
                'model_notes' => $answer->notes,
            ]);
        }
    }
};
