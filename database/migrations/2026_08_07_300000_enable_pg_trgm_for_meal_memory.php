<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Database\Migrations\Migration;

/**
 * Trigram search over the "log it again" picker (step 6).
 *
 * WHY AN EXTENSION RATHER THAN `LIKE '%chick%'`. The picker is a search
 * box on a phone: what gets typed is "chikn", "chicken bre", "rice
 * chicken" — prefixes, typos, re-orderings. `LIKE` answers none of
 * those, and full-text search answers the wrong one: to_tsvector stems
 * words and matches whole tokens, excellent at "chickens" -> "chicken",
 * useless at "chikn" -> "chicken".
 *
 * pg_trgm compares three-character shingles, exactly the shape of a
 * typo: similarity('chicken breast', 'chicken brest') = 0.71, comfortably
 * above the 0.3 threshold the ranker uses, while an unrelated food scores
 * near zero. Ships with Postgres, needs no dictionary or maintenance.
 *
 * GIN rather than GiST for both indexes: GIN builds and writes slower,
 * and this table is written once per confirmed meal and read on every
 * keystroke — precisely the trade GIN is for.
 *
 * The extension is created in the `public` schema (the default) rather
 * than a dedicated one: there's one application and one schema in this
 * database, and an `extensions` schema would only add a search_path to
 * get wrong.
 */
return new class extends Migration
{
    public function up(): void
    {
        // IF NOT EXISTS: the extension is database-wide, and the test database
        // is migrated repeatedly by RefreshDatabase.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        // The picker searches the meal's own name...
        DB::statement(
            'CREATE INDEX IF NOT EXISTS meal_memory_canonical_name_trgm
             ON meal_memory USING gin (canonical_name gin_trgm_ops)'
        );

        // ...and the names of the foods in it, because nobody remembers what
        // they called the meal, they remember that it had salmon in it.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS meal_memory_items_name_trgm
             ON meal_memory_items USING gin (name gin_trgm_ops)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS meal_memory_items_name_trgm');
        DB::statement('DROP INDEX IF EXISTS meal_memory_canonical_name_trgm');

        // The extension itself is deliberately NOT dropped: a database-level
        // object another migration, table, or future pgvector-adjacent
        // feature may depend on — dropping it would cascade away their
        // indexes silently.
    }
};
