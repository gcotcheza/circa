<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * The user's own HRV shape across the 24 hours of a day, in log units.
 *
 * Over three years of readings, this user's median HRV by local hour: 40.9 ms
 * at 06:00, 41.4 at 05:00, 39.9 at 04:00, against 29.4 at 18:00, 28.7 at
 * 22:00, 28.4 at 23:00 — a 45% swing every single day in a healthy person
 * doing nothing in particular. HRV rises with parasympathetic tone overnight
 * and falls when you're upright, awake and digesting: a clock, not a mood.
 * Score raw HRV against a flat baseline and the app says "great" at 6 a.m.
 * and "pay attention" at 10 p.m. for the rest of the user's life — exactly
 * what the commercial app being replaced does, and the single biggest reason
 * its afternoon readings are unusable.
 *
 * Naively, "median HRV at hour h" — but that's contaminated by coverage: this
 * user wore the Watch overnight and little else for the first seven months,
 * so an unweighted per-hour median would compare well-populated night hours
 * against sparse evening ones from a different era of their life. So each
 * reading is first expressed RELATIVE TO ITS OWN DAY:
 *
 *     residual = ln(value) - median of ln(value) over that same day
 *
 * A day that was simply a good day contributes nothing to the shape, because
 * its level cancels; what survives is the within-day pattern, the only thing
 * being asked for. The per-hour medians of those residuals are then centred
 * so the whole profile sums to zero — the profile is a SHAPE, and any overall
 * level belongs to the baseline instead, where it can drift.
 *
 * Two gates keep a guess from being subtracted as if it were knowledge:
 * `profile_min_day_samples` (a day needs enough readings for its own median
 * to mean anything) and `profile_min_hour_samples` (an hour needs enough
 * residuals to be worth an offset). Below the second, an hour gets an offset
 * of exactly zero and reports itself unestimated — no correction is a
 * smaller error than a wrong one.
 */
final readonly class CircadianProfile
{
    /**
     * @param  array<int, float>  $offsets  hour (0-23) => log-units offset
     * @param  array<int, int>  $counts  hour => residuals behind that offset
     */
    private function __construct(
        private array $offsets,
        private array $counts,
        public int $days,
    ) {}

    /**
     * @param  array<string, list<HrvSample>>  $samplesByDate
     */
    public static function from(array $samplesByDate): self
    {
        $minDaySamples = (int) config('health.stress.profile_min_day_samples', 3);
        $minHourSamples = (int) config('health.stress.profile_min_hour_samples', 10);

        /** @var array<int, list<float>> $residuals */
        $residuals = [];
        $days = 0;

        foreach ($samplesByDate as $samples) {
            if (count($samples) < $minDaySamples) {
                continue;
            }

            $dayMedian = Robust::median(array_map(
                static fn (HrvSample $s): float => $s->lnValue,
                $samples
            ));

            if ($dayMedian === null) {
                continue;
            }

            $days++;

            foreach ($samples as $sample) {
                $residuals[$sample->hour][] = $sample->lnValue - $dayMedian;
            }
        }

        $offsets = [];
        $counts = [];

        foreach ($residuals as $hour => $values) {
            $counts[$hour] = count($values);

            if ($counts[$hour] < $minHourSamples) {
                continue;
            }

            $median = Robust::median($values);

            if ($median !== null) {
                $offsets[$hour] = $median;
            }
        }

        return new self(self::centred($offsets), $counts, $days);
    }

    /** A profile that corrects nothing — the honest state before there is data. */
    public static function flat(): self
    {
        return new self([], [], 0);
    }

    /**
     * The log-units correction for an hour. Zero for an hour with too little
     * behind it, which leaves that hour scored against the overall baseline —
     * less sharp, never wrong in a direction nobody can see.
     */
    public function offsetFor(int $hour): float
    {
        return $this->offsets[$hour] ?? 0.0;
    }

    public function isEstimatedFor(int $hour): bool
    {
        return isset($this->offsets[$hour]);
    }

    /** How many of the 24 hours carry a real offset. */
    public function estimatedHours(): int
    {
        return count($this->offsets);
    }

    /**
     * The shape as multipliers on HRV, for display: 1.23 at 06:00 means "your
     * HRV at 6 a.m. usually runs 23% above your day's own middle".
     *
     * @return array<int, array{hour: int, offset: float, ratio: float, samples: int, estimated: bool}>
     */
    public function toArray(): array
    {
        $rows = [];

        for ($hour = 0; $hour < 24; $hour++) {
            $rows[$hour] = [
                'hour'      => $hour,
                'offset'    => round($this->offsetFor($hour), 4),
                'ratio'     => round(exp($this->offsetFor($hour)), 4),
                'samples'   => $this->counts[$hour] ?? 0,
                'estimated' => $this->isEstimatedFor($hour),
            ];
        }

        return $rows;
    }

    /**
     * Remove the mean of the estimated offsets, so the profile is purely a
     * shape. Without this it carries an arbitrary level — the mean of
     * whichever hours cleared the gate — that would shift every residual by
     * an amount that changes whenever coverage does; the baseline is the
     * only place a level should drift.
     *
     * @param  array<int, float>  $offsets
     * @return array<int, float>
     */
    private static function centred(array $offsets): array
    {
        if ($offsets === []) {
            return [];
        }

        $mean = array_sum($offsets) / count($offsets);

        return array_map(static fn (float $o): float => $o - $mean, $offsets);
    }
}
