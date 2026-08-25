<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * The resting-heart-rate tile: the latest reading, the one before it, and
 * the middle of the last few months of them.
 *
 * Three honest details. "The day before" is the previous day WITH A
 * READING, and it's named: the Watch derives a resting rate on about three
 * days in four, so the prior reading is often two or three days back, and
 * calling that "yesterday" would be wrong most of the year — carrying the
 * date and printing it ("-1 vs 6 Aug") costs one word and lets the reader
 * discount a delta across a gap themselves.
 *
 * The comparison is with the same person's own median over a stated
 * window, not a range for the reader's age and sex — the app being
 * replaced prints one of those next to every number and it's the single
 * reason its resting HR tile is decorative. A median of your own last
 * ninety days is a fact about you; "normal for a man of 40" is a fact about
 * a table.
 *
 * No label is attached to the number — not "good", not "elevated". A
 * one-word verdict on a resting heart rate is a clinical claim this app
 * doesn't make; the health report is where narrative belongs, with the
 * whole picture rather than one tile.
 */
final readonly class RestingTrend
{
    private function __construct(
        public ?string $latestDate,
        public ?float $latestBpm,
        public ?string $previousDate,
        public ?float $previousBpm,
        /** Latest minus previous, in bpm. Null when there is only one reading. */
        public ?float $delta,
        public ?float $medianBpm,
        /** How many days with a reading are behind the median. */
        public int $days,
        /** The window those days were drawn from, in days. */
        public int $windowDays,
    ) {}

    /**
     * @param  array<string, float>  $byDate  local date => bpm, chronological
     */
    public static function from(array $byDate, int $windowDays): self
    {
        ksort($byDate);

        $dates = array_keys($byDate);
        $count = count($dates);

        $latestDate = $count > 0 ? $dates[$count - 1] : null;
        $previousDate = $count > 1 ? $dates[$count - 2] : null;

        $latest = $latestDate === null ? null : $byDate[$latestDate];
        $previous = $previousDate === null ? null : $byDate[$previousDate];

        return new self(
            latestDate: $latestDate,
            latestBpm: $latest,
            previousDate: $previousDate,
            previousBpm: $previous,
            delta: $latest === null || $previous === null ? null : $latest - $previous,
            // The median rather than the mean, for the reason Robust states: one
            // fever week would drag a mean for the following three months.
            medianBpm: Robust::median(array_values($byDate)),
            days: $count,
            windowDays: $windowDays,
        );
    }

    public function hasReading(): bool
    {
        return $this->latestBpm !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'latestDate' => $this->latestDate,
            // Whole bpm. Apple exports these as integers and a decimal would be
            // precision this number has never had.
            'latestBpm'    => $this->latestBpm === null ? null : round($this->latestBpm),
            'previousDate' => $this->previousDate,
            'previousBpm'  => $this->previousBpm === null ? null : round($this->previousBpm),
            'delta'        => $this->delta === null ? null : round($this->delta),
            'medianBpm'    => $this->medianBpm === null ? null : round($this->medianBpm),
            'days'         => $this->days,
            'windowDays'   => $this->windowDays,
        ];
    }
}
