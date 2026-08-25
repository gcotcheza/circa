<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * "HRV averaged 34.6 ms, 8.6% above the seven days before that."
 *
 * The inputs are DAY LEVELS in log units — the same quantity the score is
 * built on: the median of a day's readings after the circadian offset for
 * each hour is removed (StressAnalysis::levelsBetween). Averaging raw
 * readings instead would make this tile a report on wearing pattern: a week
 * worn overnight averages ~41 ms and the identical week worn through the
 * evening averages ~29 ms, in the same person, with no physiological
 * difference whatsoever (see CircadianProfile). A "+12% vs last week"
 * produced that way is a statement about the charger.
 *
 * The mean of logs — a geometric mean — for two reasons that are the same
 * reason twice. Log units are where HRV is symmetric (ln HRV has sd 0.375
 * and is close to normal; raw values are visibly skewed — mean 36.2, median
 * 33.7), so the mean is entitled to be taken there. And it makes the
 * percentage exact rather than approximate: the change is
 * exp(meanNow - meanBefore) - 1, one subtraction with no dependence on
 * which week is called the base, where a percentage from two arithmetic
 * means of milliseconds gives a different number in each direction.
 *
 * The mean rather than the median, because seven day-levels are already
 * seven medians — the robustness was applied where the outliers are, inside
 * each day. A median of seven medians would throw away most of the week to
 * defend against an outlier step 3 already removed.
 */
final readonly class LevelChange
{
    private function __construct(
        /** Geometric mean of the current window, in milliseconds. */
        public ?float $currentMs,
        public ?float $previousMs,
        public int $currentDays,
        public int $previousDays,
        /** Days each window needs before the two may be compared. */
        public int $minDays,
        /** Percent change, current against previous. Null below the gate. */
        public ?float $percent,
    ) {}

    /**
     * @param  list<float>  $current  day levels in log units, one per day with data
     * @param  list<float>  $previous
     */
    public static function from(array $current, array $previous, int $minDays): self
    {
        $currentMean = self::mean($current);
        $previousMean = self::mean($previous);

        /*
         * The gate is on BOTH windows — why this returns a null percent, not
         * a small one. Four days against one isn't a quieter week, it's a
         * week measured less, and that distinction is the entire point of
         * this feature.
         */
        $comparable = count($current) >= $minDays
            && count($previous) >= $minDays
            && $currentMean !== null
            && $previousMean !== null;

        return new self(
            currentMs: $currentMean === null ? null : exp($currentMean),
            previousMs: $previousMean === null ? null : exp($previousMean),
            currentDays: count($current),
            previousDays: count($previous),
            minDays: $minDays,
            percent: $comparable ? (exp($currentMean - $previousMean) - 1.0) * 100.0 : null,
        );
    }

    /**
     * @param  list<float>  $values
     */
    private static function mean(array $values): ?float
    {
        return $values === [] ? null : array_sum($values) / count($values);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            // One decimal — the Watch reports to the millisecond; a second
            // decimal would invent precision the sensor doesn't have.
            'currentMs'    => $this->currentMs === null ? null : round($this->currentMs, 1),
            'previousMs'   => $this->previousMs === null ? null : round($this->previousMs, 1),
            'currentDays'  => $this->currentDays,
            'previousDays' => $this->previousDays,
            'minDays'      => $this->minDays,
            'percent'      => $this->percent === null ? null : round($this->percent, 1),
        ];
    }
}
