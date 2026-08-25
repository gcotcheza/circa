<?php

declare(strict_types=1);

use App\Enums\MealType;
use App\Enums\MealSource;
use App\Enums\MealStatus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One eating event.
 *
 * `uuid` is generated on the CLIENT, before the row exists server-side —
 * iOS WebKit has no Background Sync API, so offline logging is an
 * in-page IndexedDB queue flushed on foreground, and a retried flush
 * must land on the same meal, not a second one. The unique index makes
 * that safe.
 *
 * The bigint `id` stays the FK target for meal_items and vision_requests:
 * uuid is the *external* identity, id the internal one.
 */
return new class extends Migration
{
    /**
     * Same literal, same reason, same warning as health_metrics — a generated
     * column cannot read config at runtime.
     *
     * !! KEEP IN SYNC WITH config/health.php 'timezone'. !!
     */
    private const APP_TIMEZONE = 'Europe/Amsterdam';

    public function up(): void
    {
        Schema::create('meals', function (Blueprint $table): void {
            $table->id();

            $table->uuid('uuid')->unique();

            $table->enum('status', MealStatus::values())
                ->default(MealStatus::Draft->value);

            $table->enum('source', MealSource::values());

            $table->timestampTz('eaten_at');

            $table->enum('meal_type', MealType::values())->nullable();

            // Relative path on the dedicated photos disk. Nullable: barcode/
            // manual entries have no photo, and photo retention (originals 90
            // days) nulls this while meal_items — the actual record — stay.
            $table->text('photo_path')->nullable();

            $table->text('notes')->nullable();

            $table->timestampsTz();

            // The only join key to daily_summaries, matching health_metrics.
            // Derived from eaten_at, not created_at: a meal logged at 00:30 for
            // a dinner eaten at 20:00 belongs to the day it was eaten.
            $table->date('local_date')->storedAs(
                sprintf("((eaten_at AT TIME ZONE '%s')::date)", self::APP_TIMEZONE)
            );

            // The daily view's query: this day's meals, in order.
            $table->index(['local_date', 'eaten_at']);

            // Drives the vision-job sweep and the "stuck in analyzing" check.
            $table->index(['status', 'eaten_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meals');
    }
};
