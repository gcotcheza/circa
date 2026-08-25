<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The three columns step 6 needed that step 1 could not have known about.
 *
 * All additive, all nullable-or-defaulted, no data rewritten: an existing meal
 * keeps meaning exactly what it meant.
 *
 * 1. meals.memory_fingerprint — "this log has already been counted".
 * `meal_memory.times_logged` is an aggregate incremented by a hook that
 * needs to know whether it has already seen this meal; without this
 * column, opening a confirmed meal and editing its NOTES would increment
 * "times logged", measuring edits rather than how often the food was
 * eaten. With it the rule is exact: a save whose fingerprint matches the
 * one already on the meal is an edit and changes nothing; a differing
 * fingerprint is a new meal shape, counted once against the NEW
 * fingerprint. Doubles as the queryable answer to "which memory is this
 * meal?".
 *
 * 2/3. meals.memory_match_id + memory_match_score — "we have seen this
 * before". Written by the analysis job when a vision proposal is matched
 * against history, BEFORE the proposed items are stored — what the
 * memory layer did to the model's answer, separate from the fingerprint
 * above because it's a different question: that column says what this
 * meal IS, these say what it was COMPARED TO while still a proposal.
 * Score is 1.000 for an exact fingerprint hit, the containment score
 * otherwise, so "how often does partial matching fire, and at what
 * score?" is a query rather than a log-grep.
 *
 * 4. meal_items.memory_adjusted — provenance, per item. `raw_response` is
 * the model's opinion and is never rewritten; when memory tightens a
 * proposed item, the stored item stops matching that audit row, and
 * something has to say which of the two moved — `where memory_adjusted`
 * is every number the model did not choose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meals', function (Blueprint $table): void {
            // sha256 hex, same width/meaning as meal_memory.fingerprint —
            // deliberately NOT a foreign key: the memory row is an aggregate
            // that can be pruned or renamed, and the meal's record of what it
            // was counted as must survive that.
            $table->char('memory_fingerprint', 64)->nullable();

            $table->foreignId('memory_match_id')
                ->nullable()
                ->constrained('meal_memory')
                // A deleted memory must not delete the meal that resembled it.
                ->nullOnDelete();

            // 0.000-1.000. Exact matches are 1.000; anything lower is the
            // containment (shared/union) score that let a partial match through.
            $table->decimal('memory_match_score', 4, 3)->nullable();

            // "How many times have I eaten this?" — a lookup by fingerprint.
            $table->index('memory_fingerprint');
        });

        Schema::table('meal_items', function (Blueprint $table): void {
            $table->boolean('memory_adjusted')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('meal_items', function (Blueprint $table): void {
            $table->dropColumn('memory_adjusted');
        });

        Schema::table('meals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('memory_match_id');
            $table->dropIndex(['memory_fingerprint']);
            $table->dropColumn(['memory_fingerprint', 'memory_match_score']);
        });
    }
};
