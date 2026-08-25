<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * What one HOUR of this person's readings usually looks like — the
 * yardstick two different screens now measure a single reading against.
 *
 * Exists as its own object because the notable-moments list and the week
 * grid ask the same question of every reading (how far from your own usual
 * for this hour?) and must not answer it two different ways — a cell drawn
 * amber on the grid could otherwise fail to appear in the list beside it,
 * with no way to tell which was wrong. So centre, spread and the per-hour
 * translation live here once, and both callers share the same instance
 * built from the same pool.
 *
 * Each pooled reading is the residual the daily score is built from
 * (`r = ln(HRV) - the circadian offset for that hour`). `centre` is their
 * median and `spread` is 1.4826x their MAD, floored by `min_spread_log`
 * exactly as the daily baseline's is — so a z here is the same unit
 * (personal standard deviations) as a z from StressAnalysis::baselineAt(),
 * despite measuring different things: this one hour-to-hour variation, that
 * one day-to-day. Hour-to-hour is much wider (production: 0.33 vs. 0.15 log
 * units), which is why a single reading must never be scored against the
 * daily spread — every reading would saturate.
 *
 * The centre moves per hour via the circadian offset (a year of readings,
 * its own `profile_min_hour_samples` gate); the spread doesn't, because a
 * MAD over the 40-60 readings one hour contributes in sixty days is itself
 * mostly noise, and a per-hour spread would make the same threshold mean a
 * different thing at every hour — the hours the Watch is worn least would
 * produce the most "unusual" readings, coverage masquerading as physiology.
 * An hour the profile doesn't know gets NO answer (`knowsHour` false,
 * `zFor` null) rather than one computed as if the offset were zero — every
 * sentence built here says "for you at that hour," and without an offset
 * there's no honest way to finish it.
 */
final readonly class HourlyBaseline
{
    private function __construct(
        private CircadianProfile $profile,
        /** Median residual of the pool, in log units. */
        public float $centre,
        /** 1.4826 x MAD of the pool, floored. */
        public float $spread,
        public int $samples,
    ) {}

    /**
     * Null when the pool can't support the question — no readings, or a
     * median that couldn't be taken. A caller with null shows no grid and
     * no list, the honest state before there's history.
     *
     * @param  list<float>  $pool  residuals in log units
     */
    public static function from(array $pool, CircadianProfile $profile, ?float $minSpreadLog = null): ?self
    {
        if ($pool === []) {
            return null;
        }

        $centre = Robust::median($pool);
        $mad = $centre === null ? null : Robust::scaledMad($pool, $centre);

        if ($centre === null || $mad === null) {
            return null;
        }

        $minSpreadLog ??= (float) config('health.stress.min_spread_log', 0.03);

        return new self(
            profile: $profile,
            centre: $centre,
            // Floored for the same reason the daily baseline's is: a
            // stretch of identical readings must not turn a 2 ms wobble
            // into a wall of colour.
            spread: max($minSpreadLog, $mad),
            samples: count($pool),
        );
    }

    public function knowsHour(int $hour): bool
    {
        return $this->profile->isEstimatedFor($hour);
    }

    /** Personal standard deviations from usual, or null for an unknown hour. */
    public function zFor(HrvSample $sample): ?float
    {
        return $this->zForLevel($sample->lnValue, $sample->hour);
    }

    public function zForLevel(float $lnValue, int $hour): ?float
    {
        if (! $this->knowsHour($hour)) {
            return null;
        }

        return (($lnValue - $this->profile->offsetFor($hour)) - $this->centre) / $this->spread;
    }

    /** The middle of this person's readings at this hour, in milliseconds. */
    public function typicalMs(int $hour): float
    {
        return exp($this->centre + $this->profile->offsetFor($hour));
    }

    /**
     * The MIDDLE HALF of them — the plain MAD either side of centre, which
     * under a symmetric distribution is the interquartile range. So "half
     * your 01:00 readings sit between 27 and 42 ms" is a frequency
     * statement about readings that exist, not a confidence interval.
     *
     * @return array{0: float, 1: float}
     */
    public function typicalRangeMs(int $hour): array
    {
        $halfIqr = $this->spread / Robust::MAD_TO_SIGMA;
        $middle = $this->centre + $this->profile->offsetFor($hour);

        return [exp($middle - $halfIqr), exp($middle + $halfIqr)];
    }
}
