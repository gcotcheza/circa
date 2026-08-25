<?php

declare(strict_types=1);

use App\Enums\HealthReportKind;
use App\Enums\HealthReportStatus;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * One written health report over one date range, and the audit trail of the
 * call that produced it.
 *
 * WHY `input_snapshot` IS A COLUMN, NOT A REBUILD. Every number in a
 * report is assembled in PHP before the model is asked anything (see
 * App\Services\Report\ReportFacts) — it's handed finished figures and
 * asked to read them back in sentences, which makes the facts the
 * QUESTION, and a question you can't reproduce is a report you can't
 * check. Rebuilding on demand wouldn't work: the source tables keep
 * changing under them (a late export moves `daily_summaries`,
 * `stress:rebuild` re-scores a trailing window, a meal is corrected days
 * later), so a report "rebuilt" later would be measured against facts
 * that no longer match the ones it was written from — the first
 * disagreement would look like the model hallucinating when it had been
 * right. So the snapshot is stored whole, the mirror image of
 * `vision_requests.raw_response`: between the two, a report is completely
 * reproducible (same facts, same prompt version, same model).
 *
 * `idempotency_key` is the same device `vision_requests` uses: the weekly
 * cron derives a DETERMINISTIC key from the week it covers
 * (`weekly:2026-08-03:2026-08-09`), so a double fire after a restart lands
 * on the row already created. A manual run gets a fresh uuid — asking for
 * the same week again on purpose is a new report, not a duplicate.
 *
 * COSTS ARE FROZEN ON THE ROW. `cost_usd` is computed from token counts at
 * the rates in force when the call was made, not derived on read: prices
 * change, and a row must keep saying what it actually cost. Same
 * discipline as `model`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_reports', function (Blueprint $table): void {
            $table->id();

            // Inclusive, both ends, in the app's reporting timezone — the
            // same local-date space `daily_summaries`, `stress_daily` and
            // `meals.local_date` live in, so every join in the assembler
            // is a plain date comparison with no zone arithmetic.
            $table->date('range_start');
            $table->date('range_end');

            $table->enum('kind', HealthReportKind::values())
                ->default(HealthReportKind::Manual->value);

            $table->enum('status', HealthReportStatus::values())
                ->default(HealthReportStatus::Pending->value);

            // Claimed before the job is dispatched. Unique, so a
            // double-submit and a re-delivered cron both land on one row
            // and one bill.
            $table->string('idempotency_key', 128)->unique();

            // The facts the model was handed. See the class comment above.
            $table->jsonb('input_snapshot')->nullable();

            // The structured answer, exactly as it came back.
            $table->jsonb('output')->nullable();

            // e.g. "claude-opus-5". Recorded per report so changing the
            // config doesn't rewrite what old rows say produced them.
            $table->string('model', 128)->nullable();

            $table->string('prompt_version', 32)->nullable();

            $table->integer('input_tokens')->nullable();
            $table->integer('output_tokens')->nullable();

            // Six decimal places: a single report is cents, and rounding
            // to two would report every one of them as $0.03.
            $table->decimal('cost_usd', 10, 6)->nullable();

            $table->integer('latency_ms')->nullable();

            $table->text('error')->nullable();

            // When the answer landed — distinct from `created_at` (when
            // it was asked for) and `updated_at` (moves for any reason).
            $table->timestampTz('generated_at')->nullable();

            $table->timestampsTz();

            // The list screen: newest first.
            $table->index(['created_at']);

            // "Is there already a report for this week?" — the weekly
            // cron's question, and the UI's when offering to open one
            // rather than pay for a second.
            $table->index(['kind', 'range_start', 'range_end']);

            // The generating-now guard, which asks only about unfinished
            // rows.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('health_reports');
    }
};
