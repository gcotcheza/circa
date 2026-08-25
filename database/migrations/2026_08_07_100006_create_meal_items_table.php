<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * The actual record of what was eaten. Every entry path lands here.
 *
 * Densities are stored PER 100 g, as min/max ranges; absolute kcal and
 * macros are derived, never stored — what makes portions editable:
 * dragging 150 g → 220 g recomputes the whole item, while storing
 * "480 kcal" would strand the number the moment the portion changes.
 *
 * Ranges everywhere, never point estimates — vision genuinely doesn't
 * know whether that's 140 g or 210 g of rice, and a single number would
 * launder that uncertainty into false precision. A confident item just
 * has min == max.
 *
 * The day's intake band is NOT a linear sum of these (uselessly wide);
 * daily_summaries combines the half-widths in quadrature around a
 * midpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('meal_id')->constrained('meals')->cascadeOnDelete();

            $table->text('name');

            // Slugified name, as used in the meal_memory fingerprint. Stored so
            // the fingerprint is reproducible from the row and so pg_trgm name
            // matching has a normalised column to work against.
            $table->string('slug', 191)->nullable();

            $table->decimal('portion_g_min', 10, 3);
            $table->decimal('portion_g_max', 10, 3);

            $table->decimal('kcal_per_100g_min', 10, 3);
            $table->decimal('kcal_per_100g_max', 10, 3);
            $table->decimal('protein_per_100g_min', 10, 3);
            $table->decimal('protein_per_100g_max', 10, 3);
            $table->decimal('carbs_per_100g_min', 10, 3);
            $table->decimal('carbs_per_100g_max', 10, 3);
            $table->decimal('fat_per_100g_min', 10, 3);
            $table->decimal('fat_per_100g_max', 10, 3);

            // 0.000–1.000 as returned by the model. Null for manual and barcode
            // entries, where the concept does not apply.
            $table->decimal('confidence', 4, 3)->nullable();

            // Null until the user taps. Per-item, not per-meal: editing one
            // proposed item should not silently confirm the rest.
            $table->timestampTz('confirmed_at')->nullable();

            // Barcode path. Nullable, and nullOnDelete rather than cascade — a
            // purged OFF cache entry must not delete the log of what was eaten.
            $table->string('food_product_id', 32)->nullable();
            $table->foreign('food_product_id')
                ->references('barcode')->on('food_products')
                ->nullOnDelete();

            $table->timestampsTz();

            $table->index('meal_id');
            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_items');
    }
};
