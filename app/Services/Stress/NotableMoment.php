<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * One HRV reading that was a long way from what this person's readings at
 * that hour usually are.
 *
 * Deliberately not what the app being replaced does: it prints "overload
 * zone — Today at 1:41 AM, HRV 16", calling ONE READING a stress band,
 * decided against a population range rather than the same person's other
 * 1 a.m. readings. A band here is a property of a DAY — a median of a
 * day's readings against sixty days of the same person (see DailyStress) —
 * never a single hour, and this object never claims it. What it claims is
 * narrower and checkable: this reading was N of your own standard
 * deviations from your own usual for that hour, and here is what that
 * usual actually is.
 *
 * `typicalLowMs`-`typicalHighMs` is the MIDDLE HALF of the readings behind
 * that claim — the median absolute deviation either side of the centre,
 * which under a symmetric distribution is exactly the interquartile range.
 * So "half your 01:00 readings sit between 27 and 42 ms" is a frequency
 * statement about data that exists, not a confidence interval.
 */
final readonly class NotableMoment
{
    public function __construct(
        public string $date,
        /** Local hour the reading's bucket starts in, 0-23. */
        public int $hour,
        public int $weekday,
        public float $valueMs,
        /** Personal standard deviations from the usual for this hour. */
        public float $z,
        /** The centre of this person's readings at this hour, in ms. */
        public float $typicalMs,
        public float $typicalLowMs,
        public float $typicalHighMs,
    ) {}

    /** Below the usual (the tense direction) rather than above it. */
    public function isLow(): bool
    {
        return $this->z < 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date'      => $this->date,
            'hour'      => $this->hour,
            'weekday'   => $this->weekday,
            'valueMs'   => round($this->valueMs, 1),
            'z'         => round($this->z, 2),
            'direction' => $this->isLow() ? 'low' : 'high',
            'typicalMs' => round($this->typicalMs, 1),
            // Whole milliseconds: the range is a rough statement of where
            // half the readings are; a decimal would suggest an edge that
            // isn't there.
            'typicalLowMs'  => round($this->typicalLowMs),
            'typicalHighMs' => round($this->typicalHighMs),
        ];
    }
}
