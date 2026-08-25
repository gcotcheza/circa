<?php

declare(strict_types=1);

namespace App\Services\Stress;

use App\Enums\StressBand;

/**
 * What the estimator concluded about one local day.
 *
 * One type with a nullable score, not two outcome classes: step 7's TDEE
 * returns EstimatedTdee or CollectingData because there's exactly one
 * estimate on screen and the two cases are two different cards. Here the
 * unit of display is a WEEK — seven days side by side — and a day without
 * a score is not a different card, it's a gap in a line. A different type
 * would mean every consumer pattern-matching seven times to draw one
 * chart, and the natural shortcut (filter unscored days out) is exactly
 * the dishonesty this feature exists to avoid: a week where the Watch was
 * off for three days must LOOK like three days missing, not a tidy
 * four-point week. So an unscored day is still a day, carrying its sample
 * count, coverage, and the reason there's no number — the chart draws a
 * hole.
 */
final readonly class DailyStress
{
    /** No HRV readings at all for this date. */
    public const NO_READINGS = 'no_readings';

    /** Some readings, but below `min_day_samples`. */
    public const TOO_FEW_READINGS = 'too_few_readings';

    /** Readings enough, but not enough history behind the day to compare with. */
    public const NO_BASELINE = 'no_baseline';

    public function __construct(
        public string $date,
        public int $samples,
        public float $coveredHours,
        public ?int $score = null,
        public ?StressBand $band = null,
        public ?float $z = null,
        /** The day's own level, deseasonalised, back in milliseconds. */
        public ?float $hrvMs = null,
        /** The middle of the rolling baseline the day was measured against. */
        public ?float $baselineHrvMs = null,
        /** Its spread, in log units — 0.16 means a typical +-16% day-to-day swing. */
        public ?float $baselineSpreadLog = null,
        public int $baselineDays = 0,
        public ?string $reason = null,
    ) {}

    /** @phpstan-assert-if-true !null $this->score */
    public function hasScore(): bool
    {
        return $this->score !== null;
    }

    /**
     * How much to trust this day's number, from how many readings backed
     * it. Deliberately NOT folded into the score: shrinking a thin day
     * toward the middle would make "we are not sure" indistinguishable
     * from "you were fine" — the one confusion this app exists to
     * prevent. The score says what the readings say; this says how many
     * there were.
     */
    public function confidence(): string
    {
        if (! $this->hasScore()) {
            return 'none';
        }

        $high = (int) config('health.stress.confidence.high_samples', 16);
        $medium = (int) config('health.stress.confidence.medium_samples', 8);

        return match (true) {
            $this->samples >= $high   => 'high',
            $this->samples >= $medium => 'medium',
            default                   => 'low',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date'          => $this->date,
            'score'         => $this->score,
            'band'          => $this->band?->value,
            'bandLabel'     => $this->band?->label(),
            'z'             => $this->z === null ? null : round($this->z, 2),
            'hrvMs'         => $this->hrvMs === null ? null : round($this->hrvMs, 1),
            'baselineHrvMs' => $this->baselineHrvMs === null ? null : round($this->baselineHrvMs, 1),
            'samples'       => $this->samples,
            'coveredHours'  => round($this->coveredHours, 1),
            'baselineDays'  => $this->baselineDays,
            'confidence'    => $this->confidence(),
            'reason'        => $this->reason,
        ];
    }
}
