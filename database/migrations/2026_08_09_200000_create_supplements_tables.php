<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Supplements (tier 1): the bottles on the shelf, what their labels say, and
 * which evenings they were actually taken.
 *
 * ---------------------------------------------------------------------------
 * THREE TABLES, BECAUSE THEY ANSWER THREE DIFFERENT QUESTIONS
 *
 *   supplements           what you own. Slow-moving reference data — a bottle
 *                         is bought once and taken for months.
 *   supplement_nutrients  what its label prints, ONE ROW PER PRINTED LINE, in
 *                         the label's own order and the label's own units. A
 *                         jsonb column would have been fewer tables and would
 *                         have made "how much magnesium am I on across all of
 *                         them" a question nobody can ask in SQL.
 *   supplement_intakes    the record of a tap. One row per supplement per local
 *                         day, and the unique index is the whole idempotency
 *                         story (see below).
 * ---------------------------------------------------------------------------
 *
 * WHAT IS DELIBERATELY NOT HERE. No schedule, no times-per-day, no per-intake
 * dose. The daily dose is a property of the BOTTLE (`units_per_day`), set once
 * when it is added and multiplied into the label figures; it is not asked again
 * at every tap. The question this feature answers is "did I take my supplements
 * last night?", and a form that made the user restate the dose to answer it
 * would be a form standing where a single tap should be. When the answer needs
 * to be "one in the morning and two at night", that is a schema change with a
 * real requirement behind it rather than a guess made in advance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplements', function (Blueprint $table): void {
            $table->id();

            $table->string('name', 120);

            // Two bottles of magnesium are two different things, and the
            // brand is usually the only label difference. Nullable: many
            // labels are read off a pill box that's lost its sleeve.
            $table->string('brand', 120)->nullable();

            /*
             * "2 capsules per day", "1 tablet daily" — VERBATIM, as text,
             * because that's what the panel says and the figures below are
             * per THAT serving.
             *
             * Not parsed into count+unit: a parsed 2 would invite the app to
             * multiply a label figure by a serving the user may not be
             * following. Tier 1 stores exactly what's printed.
             */
            $table->string('serving_text', 120)->nullable();

            /*
             * How many of that serving are taken in a day. daily total =
             * `supplement_nutrients.amount` x `units_per_day` — nothing is
             * ever normalised, converted or divided (see
             * docs/rationale-data.md § "Supplements — units_per_day
             * arithmetic rule"). The CHECK-OFF doesn't touch this: one tap
             * means "I took my dose today", one tablet or two softgels
             * alike.
             */
            $table->unsignedSmallInteger('units_per_day')->default(1);

            /*
             * Label photograph, on the same private disk as meal photos
             * under its own `labels/` prefix. The prefix is load-bearing:
             * the meal retention sweep matches `originals/%` joined to
             * `meals`, so a label can never be caught by it — reference
             * data about something still owned, not evidence of a passed
             * moment. See PhotoStore::storeLabel().
             */
            $table->text('photo_path')->nullable();

            /*
             * WHERE THESE FIGURES CAME FROM. The app ships with five
             * supplements already transcribed from manufacturer data, so
             * launch has something to tick rather than an empty card — the
             * only data in this database the user didn't enter themselves,
             * so it says so.
             *
             *   data_source  'manufacturer_published' | 'retailer_published'
             *                | 'label_photo' | 'hand_entered'.
             *   source_url   page the figures were read off, so a doubtful
             *                line can be checked at the source.
             *   notes        anything transcription couldn't settle (e.g. a
             *                disagreement between the source's own
             *                figures), shown on the edit screen.
             *
             * None of it makes the row read-only — every seeded figure is
             * editable exactly like a photographed one; the seed is a
             * starting position, not an authority.
             */
            $table->string('data_source', 32)->nullable();

            $table->text('source_url')->nullable();

            $table->text('notes')->nullable();

            // Order of the card on the day view, so the list reads in the
            // order taken rather than insertion order.
            $table->unsignedSmallInteger('position')->default(0);

            /*
             * On the card, or not — both directions are real:
             *
             *   STOPPED TAKING IT — `active = false`, not a delete.
             *     `supplement_intakes` records evenings that actually
             *     happened; deleting the bottle would cascade them away and
             *     rewrite honestly-logged adherence history.
             *
             *   NOT STARTED YET — added inactive. A spare bottle is
             *     photographed the evening it's bought (label in hand) and
             *     switched on months later when the current one runs out.
             *
             * The review screen offers it as a choice at creation; settings
             * toggles it afterwards.
             */
            $table->boolean('active')->default(true);

            $table->timestampsTz();

            // The day card's query: the active ones, in order.
            $table->index(['active', 'position']);
        });

        Schema::create('supplement_nutrients', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplement_id')->constrained('supplements')->cascadeOnDelete();

            // As printed: "Vitamine D3", "Omega-3 (EPA)" — not normalised to
            // an English canonical name. The feature reads a label natively
            // and shows back what the bottle says.
            $table->string('nutrient', 120);

            /*
             * THE FIGURE AS PRINTED, for the serving `serving_text` names.
             * Never converted or multiplied — daily total is this times
             * `units_per_day`, computed for display only.
             *
             * Nullable, both columns: a supplement-facts panel isn't a
             * spreadsheet. "1 miljard KVE" parses to a number and a two-word
             * unit; "Bevat sporen van soja" has no number and shouldn't have
             * become a row — a NOT NULL column would turn a wrong answer
             * into a 500 in a queue worker.
             *
             * decimal, not float: 0.0125 mg of B12 is a real label line, and
             * a binary float would render as 0.012500000000000001 on a
             * proof-reading screen.
             */
            $table->decimal('amount', 14, 4)->nullable();

            $table->string('unit', 32)->nullable();

            // The label's own order — "one row per printed line" only means
            // something if lines keep order, and ORDER BY id breaks on the
            // first edit that re-inserts a row.
            $table->unsignedSmallInteger('position')->default(0);

            $table->timestampsTz();

            $table->index(['supplement_id', 'position']);
        });

        Schema::create('supplement_intakes', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('supplement_id')->constrained('supplements')->cascadeOnDelete();

            /*
             * THE DAY THIS RECORDS — deliberately NOT generated from
             * `taken_at`, the opposite of what `meals` does. A meal's date
             * comes from when it was eaten; a check-off is a statement about
             * a day made from ANOTHER day — "I forgot to tick yesterday's"
             * tapped this morning. Deriving from the tap would file it under
             * today and refuse to let anyone fix the past.
             *
             * So the date is what the user was LOOKING AT (client-sent,
             * server-validated); `taken_at` is when the tap happened.
             * Usually the same day, allowed not to be.
             */
            $table->date('local_date');

            $table->timestampTz('taken_at')->useCurrent();

            $table->timestampsTz();

            /*
             * THE IDEMPOTENCY STORY, in one index. The offline queue is
             * at-least-once, so the same tap can arrive twice (two tabs, a
             * race, a replayed IndexedDB action). "Taken" is a fact about a
             * (supplement, day) pair, not an event with a count, so the
             * second arrival has nothing to add.
             *
             * Also makes "tap again to undo" safe: delete is keyed on the
             * same pair, so a replayed undo deletes an already-gone row,
             * which isn't an error.
             */
            $table->unique(['supplement_id', 'local_date']);

            // The adherence query: every intake in a date window, all
            // supplements at once.
            $table->index('local_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplement_intakes');
        Schema::dropIfExists('supplement_nutrients');
        Schema::dropIfExists('supplements');
    }
};
