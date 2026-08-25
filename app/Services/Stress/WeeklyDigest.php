<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * One week, read back in sentences: the hardest day, the easiest day, how
 * the average moved, and how much of the week was actually measured.
 *
 * Replaces the commercial app's "most/least stressful day of last 7",
 * which states no denominator — a week the Watch was on for two days
 * produces the same confident headline pair as a week it was on for
 * seven, with no way to tell which is which. So every figure here arrives
 * with its denominator: `scoredDays` sits next to the extremes, skipped
 * days are counted AND broken down by why, and a comparison that can't be
 * made says so instead of quietly using whatever it had.
 *
 * The comparison is in points, not percent. The old app reports its
 * "stress dynamic" as a percentage, meaningless on a 1-99 score: the scale
 * is a normal CDF squash of a z-score (see StressScale) — bounded,
 * non-linear, no meaningful zero. 40 -> 44 and 90 -> 99 are both "+10%"
 * and not remotely the same amount of anything. Points are what the scale
 * is denominated in, so points is what moves; the percentage that IS
 * meaningful — HRV in milliseconds, a ratio scale with a real zero — is
 * reported as a percentage, by LevelChange, right beside it.
 *
 * Rounded first, subtracted second: `meanScore` and `previousMeanScore`
 * round to whole points and the delta is the difference of the two
 * ROUNDED numbers. A delta computed from unrounded means would put three
 * numbers on screen that don't subtract — 62, 67, "4 points below" —
 * reading as a bug every time somebody checks it. The cost is at most
 * half a point of precision in a quantity whose confidence interval is
 * several points wide.
 */
final readonly class WeeklyDigest
{
    private function __construct(
        /** The lowest-scoring scored day of the week. Null if none scored. */
        public ?DailyStress $mostStressful,
        /** The highest-scoring. Equal to $mostStressful when only one scored. */
        public ?DailyStress $leastStressful,
        public int $scoredDays,
        /** Days of the week that have already happened. 7 for a past week. */
        public int $elapsedDays,
        /**
         * Elapsed days with no score, by DailyStress reason code.
         *
         * @var array<string, int>
         */
        public array $skipped,
        public ?int $meanScore,
        public ?int $previousMeanScore,
        public int $previousScoredDays,
        /** Rounded mean minus rounded previous mean. Null below the gate. */
        public ?int $scoreDelta,
        public int $minDays,
        public LevelChange $hrv,
    ) {}

    /**
     * @param  list<DailyStress>  $week  the seven days being viewed, in order
     * @param  list<DailyStress>  $previousWeek  the seven before them
     * @param  string  $today  local date, so a future day is not counted as a gap
     */
    public static function from(
        array $week,
        array $previousWeek,
        LevelChange $hrv,
        string $today,
        ?int $minDays = null,
    ): self {
        $minDays ??= (int) config('health.stress.digest.min_week_days', 4);

        $scored = array_values(array_filter($week, static fn (DailyStress $d): bool => $d->hasScore()));

        /*
         * A day that hasn't happened yet is not a day the Watch missed.
         * Without this the current week always reports "3 of 7 days had no
         * readings" on a Thursday, training the reader to ignore the line
         * that exists to be believed.
         */
        $elapsed = array_values(array_filter($week, static fn (DailyStress $d): bool => $d->date <= $today));

        $skipped = [];

        foreach ($elapsed as $day) {
            if (! $day->hasScore()) {
                $reason = $day->reason ?? DailyStress::NO_READINGS;
                $skipped[$reason] = ($skipped[$reason] ?? 0) + 1;
            }
        }

        $previousScored = array_values(array_filter(
            $previousWeek,
            static fn (DailyStress $d): bool => $d->hasScore()
        ));

        $mean = self::meanScore($scored);
        $previousMean = self::meanScore($previousScored);

        $comparable = count($scored) >= $minDays && count($previousScored) >= $minDays;

        return new self(
            mostStressful: self::extreme($scored, worst: true),
            leastStressful: self::extreme($scored, worst: false),
            scoredDays: count($scored),
            elapsedDays: count($elapsed),
            skipped: $skipped,
            meanScore: $mean,
            previousMeanScore: $previousMean,
            previousScoredDays: count($previousScored),
            scoreDelta: $comparable && $mean !== null && $previousMean !== null
                ? $mean - $previousMean
                : null,
            minDays: $minDays,
            hrv: $hrv,
        );
    }

    /**
     * The lowest (worst) or highest (best) scoring day.
     *
     * Ties go to the EARLIER day: the week is read top to bottom and "the
     * first time it got that low" is the one a reader can place. The choice
     * matters little; making it deterministic does, or two identical weeks
     * would report different headline days depending on array order.
     *
     * @param  list<DailyStress>  $scored
     */
    private static function extreme(array $scored, bool $worst): ?DailyStress
    {
        $best = null;

        foreach ($scored as $day) {
            if ($best === null) {
                $best = $day;

                continue;
            }

            $better = $worst
                ? (int) $day->score < (int) $best->score
                : (int) $day->score > (int) $best->score;

            if ($better) {
                $best = $day;
            }
        }

        return $best;
    }

    /**
     * @param  list<DailyStress>  $scored
     */
    private static function meanScore(array $scored): ?int
    {
        if ($scored === []) {
            return null;
        }

        $sum = array_sum(array_map(static fn (DailyStress $d): int => (int) $d->score, $scored));

        return (int) round($sum / count($scored));
    }

    /** True when exactly one day scored, so the two extremes are one day. */
    public function isSingleDay(): bool
    {
        return $this->scoredDays === 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'mostStressful'  => $this->mostStressful?->toArray(),
            'leastStressful' => $this->leastStressful?->toArray(),
            'singleDay'      => $this->isSingleDay(),
            'scoredDays'     => $this->scoredDays,
            'elapsedDays'    => $this->elapsedDays,
            // Sent as a reason => count map so the wording for each reason stays
            // in lib/stress.js with the rest of the copy, exactly as the daily
            // card's `reason` does.
            'skipped'            => (object) $this->skipped,
            'skippedDays'        => array_sum($this->skipped),
            'meanScore'          => $this->meanScore,
            'previousMeanScore'  => $this->previousMeanScore,
            'previousScoredDays' => $this->previousScoredDays,
            'scoreDelta'         => $this->scoreDelta,
            'minDays'            => $this->minDays,
            'hrv'                => $this->hrv->toArray(),
        ];
    }
}
