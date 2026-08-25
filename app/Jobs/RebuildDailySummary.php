<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Tdee\TdeeTrigger;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\Rollup\DailySummaryBuilder;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Rebuild one local day's `daily_summaries` row.
 *
 * Unique and delayed (SPEC.md, "Rebuild mechanics"): a single hourly export
 * dirties one or two dates, but a backlog replay dirties ninety, several
 * times over, since the payloads covering a given day arrive as dozens of
 * separate batches. Without coalescing, one `ingest:replay` run would queue
 * the same date's rebuild a hundred times, each computing the identical row.
 *
 * `ShouldBeUnique` keyed on the date drops the duplicates, and the dispatch
 * delay gives them time to pile up against the lock instead of the first
 * one grabbing it and completing before the second is even queued.
 *
 * The rebuild is idempotent, so the worst case of getting this wrong is
 * wasted CPU, not a wrong number — worth handling cheaply here rather than
 * defensively everywhere.
 */
final class RebuildDailySummary implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * How long the unique lock survives if the worker dies mid-job. Must exceed
     * the dispatch delay or the lock expires before the job it protects runs.
     */
    public int $uniqueFor;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function __construct(public readonly string $date)
    {
        $this->uniqueFor = (int) config('health.rollup.rebuild_unique_for', 900);
    }

    /** One lock per local date — NOT per payload, meal or batch. */
    public function uniqueId(): string
    {
        return $this->date;
    }

    public function handle(DailySummaryBuilder $builder, TdeeTrigger $tdee): void
    {
        $builder->build($this->date);

        // A rebuild is the only event that can flip `is_complete_log` or land a
        // weigh-in — the estimator's two inputs. Dispatched from HERE, not the
        // builder (which runs inside request transactions — see step 3's note on
        // queueing from a write): a job queued there would read uncommitted rows.
        // Ignored unless the date is in the estimated window; coalesced by this
        // job's own unique lock when a replay dirties ninety of them.
        $tdee->afterRebuild($this->date);
    }
}
