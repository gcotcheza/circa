<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * `vision_requests` stops being only about meals.
 *
 * WHY `meal_id` HAS TO BECOME NULLABLE. Reading a supplement label is a
 * paid call to the same model, with the same idempotency-key claim, the
 * same `pending -> sent -> succeeded|failed` lifecycle and the same
 * reason for keeping `prompt_version` + `raw_response`: a prompt change
 * has to stay evaluable against the labels it has already read.
 * Everything the audit table exists for applies unchanged.
 *
 * What doesn't apply is the meal. A label read isn't about food that was
 * eaten, and at claim time there's nothing to point at in either
 * direction — the supplement doesn't exist yet, because the whole point
 * of the flow is that the user reviews what was read before agreeing to
 * create it.
 *
 * The alternatives were both worse. A second audit table would mean a
 * second lifecycle, a second idempotency claim and a second set of "how
 * much did this cost" queries UNIONed to answer anything. A dummy meal
 * per label would put rows in `meals` that were never eaten — precisely
 * the table the daily rollup selects from.
 *
 * `supplement_id` IS SET AFTERWARDS, AND IS ALLOWED TO STAY NULL. Written
 * when the user confirms the review — the moment the reading becomes a
 * thing you own. Stays null for every label photographed and then
 * abandoned, and those rows aren't waste: "how often is a label read
 * good enough to accept?" is answerable only if the rejected ones are
 * still there.
 *
 * `nullOnDelete` rather than a cascade, for the same reason
 * `vision_requests` outlives a pruned photograph: what a call cost and
 * what it answered stay true after the thing it produced is thrown away.
 *
 * The new `request_kind` value ('label') needs no DDL — the column is a
 * plain `string(16)`, chosen over a database enum in the migration that
 * added it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vision_requests', function (Blueprint $table): void {
            $table->foreignId('meal_id')->nullable()->change();

            $table->foreignId('supplement_id')
                ->nullable()
                ->after('meal_photo_id')
                ->constrained('supplements')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vision_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('supplement_id');

            // Only reversible with no label rows present — the same caveat
            // every widening migration carries: rolling back with meal-less
            // rows would fail the NOT NULL, and deleting them to succeed
            // would be an audit table rewriting its own history.
            $table->foreignId('meal_id')->nullable(false)->change();
        });
    }
};
