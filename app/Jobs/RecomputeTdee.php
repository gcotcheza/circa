<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Tdee\TdeeRecorder;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Re-derive the current window's TDEE after data inside it changed.
 *
 * ONE LOCK FOR THE WHOLE JOB, NOT ONE PER DATE: RebuildDailySummary is
 * unique PER DATE since rebuilding the 3rd says nothing about the
 * 4th. Here there's one current window — every dirty date produces
 * the same recomputation, so `uniqueId()` is constant: a replay
 * touching ninety days drops all but one dispatch inside the
 * coalescing window, and the survivor reads whatever landed meanwhile.
 *
 * The delay makes that work: dispatched immediately, the first job
 * would grab the lock and finish before its siblings queued, so every
 * one would run anyway. Delayed, they pile up against the lock
 * instead — same trick as the rollup's rebuild delay.
 *
 * Recomputing is cheap (two queries, <= 28 rows, a least-squares fit)
 * and idempotent: TdeeRecorder only writes when the answer moved.
 */
final class RecomputeTdee implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct()
    {
        $this->uniqueFor = (int) config('health.tdee.recompute_unique_for', 900);
    }

    /** There is one current window, so there is one lock. */
    public function uniqueId(): string
    {
        return 'current-window';
    }

    public function handle(TdeeRecorder $recorder): void
    {
        $recorder->record();
    }
}
