<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * "You've had this before" — re-rank and pre-fill from history.
 *
 * The fingerprint is a hash of the SORTED, SLUGIFIED item-name set
 * returned by vision — sha256("chicken-breast|rice-white|broccoli") —
 * not a hash of the photo: the same meal photographed twice from a
 * different angle, in different light, on a different plate produces
 * unrelated bytes and unrelated perceptual hashes. Item names are the
 * stable part. Sorting before hashing makes it order-independent — a
 * meal is a set, not a sequence.
 *
 * Everyday UX is "frequent/recent meals" ranked by times_logged +
 * recency, with pg_trgm matching on names for the typo case. Photo
 * similarity via pgvector embeddings is deferred — Laravel 13 has native
 * pgvector query support for when it's worth it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_memory', function (Blueprint $table): void {
            $table->id();

            // sha256 hex of the sorted slug set.
            $table->char('fingerprint', 64)->unique();

            // What to call it in the list — seeded from the item names, then
            // free for the user to rename ("Tuesday chicken bowl").
            $table->text('canonical_name');

            $table->integer('times_logged')->default(0);

            $table->timestampTz('last_logged_at')->nullable();

            $table->timestampsTz();

            // The ranking read: frequent first, then recent.
            $table->index(['times_logged', 'last_logged_at']);
            $table->index('last_logged_at');
        });

        Schema::create('meal_memory_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('meal_memory_id')
                ->constrained('meal_memory')
                ->cascadeOnDelete();

            $table->text('name');

            // The slug that fed the parent fingerprint.
            $table->string('slug', 191);

            // Same per-100g min/max shape as meal_items, so a re-log pre-fills
            // straight across with no conversion and no loss of range.
            $table->decimal('kcal_per_100g_min', 10, 3);
            $table->decimal('kcal_per_100g_max', 10, 3);
            $table->decimal('protein_per_100g_min', 10, 3);
            $table->decimal('protein_per_100g_max', 10, 3);
            $table->decimal('carbs_per_100g_min', 10, 3);
            $table->decimal('carbs_per_100g_max', 10, 3);
            $table->decimal('fat_per_100g_min', 10, 3);
            $table->decimal('fat_per_100g_max', 10, 3);

            // The portion actually logged, averaged over past logs — a single
            // number, unlike the densities: it is a remembered habit, not an
            // uncertainty band, and the user adjusts it on the way in anyway.
            $table->decimal('typical_portion_g', 10, 3);

            $table->timestampsTz();

            $table->unique(['meal_memory_id', 'slug'], 'meal_memory_items_memory_slug_unique');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_memory_items');
        Schema::dropIfExists('meal_memory');
    }
};
