<?php

declare(strict_types=1);

namespace App\Services\Tdee;

use Carbon\CarbonImmutable;
use App\Models\DailySummary;

/**
 * Which stretch of days the estimate is fitted over.
 *
 * SPEC asks for "rolling 14–28 day windows"; the obvious reading — take the
 * LONGEST window ≤ 28 days ending today that clears the gate — is one rule
 * wearing a disguise. Both gate counts are non-decreasing as the window
 * grows backwards, so if any window in 14…28 clears the gate the 28-day one
 * does too — "longest passing" is therefore always the 28-day window, and
 * searching for it would be a loop that can only return its first
 * iteration. So the rule as implemented:
 *
 *   1. ENDS TODAY (local) — "what is my expenditure now", and un-dated
 *      trailing days make the gate harder, not easier: stop logging for a
 *      fortnight and the estimate withdraws itself instead of going stale.
 *   2. Starts 27 days earlier — `window_days` (28), the SPEC ceiling.
 *   3. FRONT-TRIMMED to the first day in that span with anything the
 *      estimate uses (a complete log or a weigh-in), never below
 *      `min_window_days` (14). Trimming changes no number — those days
 *      contributed nothing — but stops `window_start` (shown to the user,
 *      part of the row's identity) from claiming a fortnight it has no
 *      data for.
 *
 * Longest-first survives via (2): the window is as long as the data
 * supports, up to 28 days.
 */
final readonly class TdeeWindow
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * The candidate window for a given "today" (defaults to now, local).
     */
    public static function current(?CarbonImmutable $today = null): self
    {
        $tz = (string) config('health.timezone');

        $end = ($today ?? CarbonImmutable::now($tz))->setTimezone($tz)->startOfDay();

        $maxDays = max(2, (int) config('health.tdee.window_days', 28));
        $minDays = max(2, (int) config('health.tdee.min_window_days', 14));

        $span = $end->subDays($maxDays - 1);
        $floor = $end->subDays(min($maxDays, $minDays) - 1);

        // First day in the span with anything the estimate reads, in one query —
        // the alternative is a window claiming days nobody logged or weighed on.
        $firstWithData = DailySummary::query()
            ->whereBetween('local_date', [$span->toDateString(), $end->toDateString()])
            ->where(function ($query): void {
                $query->where('is_complete_log', true)->orWhereNotNull('weight_kg');
            })
            ->min('local_date');

        if ($firstWithData === null) {
            return new self($span, $end);
        }

        $start = CarbonImmutable::parse((string) $firstWithData, $tz)->startOfDay();

        // Never past the floor: under 14 days isn't a window this estimator
        // recognises, even if all the data sits at one end.
        return new self($start->greaterThan($floor) ? $floor : $start, $end);
    }

    /** Inclusive length in days. */
    public function days(): int
    {
        return (int) $this->start->diffInDays($this->end) + 1;
    }

    public function contains(string $date): bool
    {
        return $date >= $this->start->toDateString() && $date <= $this->end->toDateString();
    }

    public function startDate(): string
    {
        return $this->start->toDateString();
    }

    public function endDate(): string
    {
        return $this->end->toDateString();
    }

    /**
     * @return array{start: string, end: string, days: int}
     */
    public function toArray(): array
    {
        return [
            'start' => $this->startDate(),
            'end'   => $this->endDate(),
            'days'  => $this->days(),
        ];
    }
}
