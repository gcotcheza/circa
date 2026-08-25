<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * What a report was asked to be about.
 *
 * WHY TWO COLUMNS WHEN THE SNAPSHOT ALREADY HOLDS THIS. `input_snapshot`
 * gains a `focus` block in the same change, and that block is the
 * authoritative record — it's what makes a past report replayable
 * through a newer prompt, since a replay blind to the focus would re-run
 * a stress report as a full one and diff two documents never asking the
 * same question.
 *
 * These columns do a different job. The archive list renders thirty rows
 * per page and must show "Stress + Sleep · 6m" on each; doing that from
 * the snapshot means decoding thirty JSON documents — the largest column
 * in the table, several hundred KB each — to read two short fields off
 * the top. `ReportView::summary()` is deliberately the cheap half of
 * that class (dates, status, headline, cost, no parsing), and a list
 * that opened every snapshot would stop being cheap on ship day. They're
 * also the queryable form: "show me the stress reports" is a `WHERE` on
 * a jsonb array, not a screen anybody's asked for yet, but the column is
 * already there and indexable when they do.
 *
 * BOTH NULLABLE, AND NULL MEANS SOMETHING. A row with no focus is a
 * report over the whole document — every report before this migration,
 * every weekly one after it. `ReportFocus::fromStored()` reads exactly
 * that: no areas and no question is `ReportFocus::everything()`, the
 * assembly this app has always produced. Backfilling an explicit "all"
 * marker would invent a decision nobody made.
 *
 * `focus_areas` is json rather than a comma-joined string for the
 * ordinary reason: a set is a set, and the day somebody filters on one
 * member, `LIKE '%stress%'` also matches a chip called `stress_test`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_reports', function (Blueprint $table): void {
            // The chips. An empty selection is stored as NULL, not `[]`, so
            // "no focus" has one representation instead of two readers must
            // know are the same thing.
            $table->json('focus_areas')->nullable()->after('kind');

            // The reader's own question, verbatim. `text`, not bounded: the
            // 500-char cap lives in one place (ReportFocus::MAX_TEXT,
            // enforced by the form request) — a column limit disagreeing by
            // one character would surface as an invisible truncation.
            $table->text('focus_text')->nullable()->after('focus_areas');
        });
    }

    public function down(): void
    {
        Schema::table('health_reports', function (Blueprint $table): void {
            $table->dropColumn(['focus_areas', 'focus_text']);
        });
    }
};
