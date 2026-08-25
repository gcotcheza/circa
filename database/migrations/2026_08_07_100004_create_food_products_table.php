<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Local Open Food Facts cache.
 *
 * Barcode is the primary key: the natural key, it comes from the
 * scanner, and a surrogate id would buy nothing. varchar, not integer —
 * EAN-13 leading zeros are significant and UPC-E/ITF-14 lengths vary.
 *
 * A re-log never re-hits the network (OFF allows 15 req/min/IP and
 * requires the `HealthTracker/1.0 (user@example.com)` User-Agent),
 * and a wrong OFF entry is correctable locally — why the parsed nutrient
 * columns are separate from `raw` rather than read back out of the jsonb.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('food_products', function (Blueprint $table): void {
            $table->string('barcode', 32)->primary();

            $table->text('name');
            $table->text('brand')->nullable();

            // Per 100 g, the unit OFF publishes in and the unit meal_items
            // stores densities in — so a barcode item needs no conversion at
            // all. Nullable throughout: OFF entries are crowd-sourced and
            // routinely incomplete, and a missing macro must not become a zero.
            $table->decimal('kcal_per_100g', 10, 3)->nullable();
            $table->decimal('protein_per_100g', 10, 3)->nullable();
            $table->decimal('carbs_per_100g', 10, 3)->nullable();
            $table->decimal('fat_per_100g', 10, 3)->nullable();

            // The whole v3 product object, so a later field (fibre, salt,
            // serving size, NOVA group) needs no re-fetch.
            $table->jsonb('raw')->nullable();

            // Drives staleness, not correctness. Nothing expires automatically.
            $table->timestampTz('fetched_at');

            $table->timestampsTz();

            $table->index('fetched_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_products');
    }
};
