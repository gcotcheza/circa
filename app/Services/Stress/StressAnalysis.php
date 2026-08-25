<?php

declare(strict_types=1);

namespace App\Services\Stress;

use App\Enums\StressBand;
use Carbon\CarbonImmutable;

/**
 * A loaded window of HRV, and everything derivable from it.
 *
 * Built by StressCalculator, which does the I/O; this class does the
 * arithmetic and touches no database, no clock, no queue — the split that
 * makes the whole algorithm testable against synthetic samples whose
 * answer is known before the code runs, the same discipline step 7 used
 * for TDEE.
 *
 * The five steps, in order:
 *
 *  1. ln(HRV) for every accepted reading                     [HrvSample]
 *  2. subtract the circadian offset for that hour            [CircadianProfile]
 *  3. the day's LEVEL is the median of what is left          [levelFor]
 *  4. z = (level - median of the previous `baseline_days` levels)
 *         / (1.4826 x MAD of those levels)                   [baselineAt]
 *  5. score = a 1-99 squash of z                             [StressScale]
 *
 * Step 3 is a median, not a mean: a day with a single 200 ms reading in it
 * — a genuine artefact in this user's data — would otherwise be dragged
 * half a band upward by one number.
 *
 * Step 4's window ENDS THE DAY BEFORE: today is compared with the days
 * behind it, never itself, or an unusual day would be partly its own
 * yardstick and quietly flatten exactly the excursions worth seeing.
 */
final class StressAnalysis
{
    /** @var array<int, float> epoch-day => deseasonalised level in log units */
    private array $levels = [];

    /** @var array<int, string> epoch-day => local date, for readable output */
    private array $dates = [];

    /**
     * @param  array<string, list<HrvSample>>  $samplesByDate
     */
    public function __construct(
        private readonly array $samplesByDate,
        private readonly CircadianProfile $profile,
        private readonly StressScale $scale,
    ) {
        $minSamples = (int) config('health.stress.min_day_samples', 3);

        foreach ($samplesByDate as $date => $samples) {
            if (count($samples) < $minSamples) {
                continue;
            }

            $level = Robust::median($this->residuals($samples));

            if ($level === null) {
                continue;
            }

            $day = self::epochDay($date);

            $this->levels[$day] = $level;
            $this->dates[$day] = $date;
        }

        ksort($this->levels);
    }

    public function profile(): CircadianProfile
    {
        return $this->profile;
    }

    public function scale(): StressScale
    {
        return $this->scale;
    }

    /**
     * Every date the window holds readings for, chronological.
     *
     * @return list<string>
     */
    public function datesWithReadings(): array
    {
        return array_keys($this->samplesByDate);
    }

    /**
     * The verdict for one local date. Never null: a day with nothing behind it
     * is still a day, and says why it has no number.
     */
    public function day(string $date): DailyStress
    {
        $samples = $this->samplesByDate[$date] ?? [];

        $coveredHours = array_sum(array_map(
            static fn (HrvSample $s): int => $s->durationSeconds,
            $samples
        )) / 3600.0;

        if ($samples === []) {
            return new DailyStress(
                date: $date,
                samples: 0,
                coveredHours: 0.0,
                reason: DailyStress::NO_READINGS,
            );
        }

        $day = self::epochDay($date);

        if (! isset($this->levels[$day])) {
            return new DailyStress(
                date: $date,
                samples: count($samples),
                coveredHours: $coveredHours,
                reason: DailyStress::TOO_FEW_READINGS,
            );
        }

        $baseline = $this->baselineAt($day);

        if ($baseline === null) {
            return new DailyStress(
                date: $date,
                samples: count($samples),
                coveredHours: $coveredHours,
                hrvMs: exp($this->levels[$day]),
                reason: DailyStress::NO_BASELINE,
            );
        }

        [$centre, $spread, $days] = $baseline;

        $z = ($this->levels[$day] - $centre) / $spread;
        $score = $this->scale->score($z);

        return new DailyStress(
            date: $date,
            samples: count($samples),
            coveredHours: $coveredHours,
            score: $score,
            band: StressBand::fromScore($score),
            z: $z,
            hrvMs: exp($this->levels[$day]),
            baselineHrvMs: exp($centre),
            baselineSpreadLog: $spread,
            baselineDays: $days,
        );
    }

    /**
     * @param  list<string>  $dates
     * @return array<string, DailyStress>
     */
    public function days(array $dates): array
    {
        $out = [];

        foreach ($dates as $date) {
            $out[$date] = $this->day($date);
        }

        return $out;
    }

    /**
     * The deseasonalised day levels over [$from, $to], in log units — one per
     * day that cleared `min_day_samples`, chronological.
     *
     * What the weekly digest averages instead of raw milliseconds: a level
     * already had the clock removed and is already a within-day median, so
     * two weeks are comparable even when the Watch was worn at different
     * hours in each — the whole reason a week-on-week HRV percentage is
     * worth printing.
     *
     * @return list<float>
     */
    public function levelsBetween(string $from, string $to): array
    {
        $out = [];

        foreach ($this->levels as $day => $level) {
            $date = $this->dates[$day];

            if ($date >= $from && $date <= $to) {
                $out[] = $level;
            }
        }

        return $out;
    }

    /**
     * Every accepted reading in [$from, $to], flattened and chronological.
     *
     * @return list<HrvSample>
     */
    public function samplesBetween(string $from, string $to): array
    {
        $out = [];

        foreach ($this->samplesByDate as $date => $samples) {
            if ($date >= $from && $date <= $to) {
                foreach ($samples as $sample) {
                    $out[] = $sample;
                }
            }
        }

        return $out;
    }

    /**
     * The strongest hourly excursions of [$from, $to] against the days behind
     * them. NULL — not an empty list — when there's too little history for
     * the question to have an answer.
     *
     * The two return values are different statements: `null` is "not enough
     * behind this week to know what unusual would be", `[]` is "there is,
     * and nothing this week was" — collapsing them would turn a thin month
     * into a run of calm weeks, the same lie the score card refuses to tell
     * with "not enough data".
     *
     * The comparison pool is `baseline_days` immediately BEFORE $from, never
     * the week itself — the same half-open convention baselineAt() uses.
     *
     * @return list<NotableMoment>|null
     */
    public function moments(string $from, string $to): ?array
    {
        $baseline = $this->hourlyBaseline($from);

        if ($baseline === null) {
            return null;
        }

        return NotableMoments::find($this->samplesBetween($from, $to), $baseline);
    }

    /**
     * The grid of one specific week: 168 real hours of seven real days.
     *
     * Null on the same gate the moments list uses, for the same reason:
     * without enough history there's nothing to colour a reading against,
     * and an uncoloured grid would just be a picture of when the Watch was
     * worn.
     */
    public function weekGrid(string $from, string $nowDate, int $nowHour): ?WeekGrid
    {
        $baseline = $this->hourlyBaseline($from);

        if ($baseline === null) {
            return null;
        }

        $start = CarbonImmutable::parse($from);

        $dates = [];

        for ($day = 0; $day < 7; $day++) {
            $dates[] = $start->addDays($day)->toDateString();
        }

        return WeekGrid::build(
            $this->samplesBetween($dates[0], $dates[6]),
            $dates,
            $baseline,
            $this->scale,
            $nowDate,
            $nowHour,
        );
    }

    /**
     * What an HOUR of this person's day usually looks like, as of the week
     * starting $from — the yardstick both the week grid and the
     * notable-moments list measure a reading against.
     *
     * The pool is `baseline_days` immediately BEFORE $from, never the week
     * itself: the same half-open convention baselineAt() uses, so a week
     * can't be partly its own yardstick. Built once here and handed to both
     * callers, so a cell drawn amber on the grid and a moment listed under
     * it can't disagree about the same reading.
     */
    public function hourlyBaseline(string $from): ?HourlyBaseline
    {
        $window = (int) config('health.stress.baseline_days', 60);
        $minDays = (int) config('health.stress.min_baseline_days', 21);

        $start = CarbonImmutable::parse($from);

        $poolFrom = $start->subDays($window)->toDateString();
        $poolTo = $start->subDay()->toDateString();

        // The gate is days, not readings: a well-covered fortnight still only
        // knows what a fortnight knows. Counting LEVELS rather than
        // dates-with-any-reading keeps it the same gate a daily score passes.
        if (count($this->levelsBetween($poolFrom, $poolTo)) < $minDays) {
            return null;
        }

        $pool = array_map(
            fn (HrvSample $s): float => $s->lnValue - $this->profile->offsetFor($s->hour),
            $this->samplesBetween($poolFrom, $poolTo),
        );

        return HourlyBaseline::from($pool, $this->profile);
    }

    /**
     * Every date in the window that produced a score, chronological.
     *
     * @return list<DailyStress>
     */
    public function scoredDays(): array
    {
        $out = [];

        foreach ($this->dates as $date) {
            $day = $this->day($date);

            if ($day->hasScore()) {
                $out[] = $day;
            }
        }

        return $out;
    }

    /**
     * The hour x weekday grid over [$from, $to].
     *
     * Normalised against the day-to-day spread OF THAT SAME WINDOW, so a
     * cell and a daily score share a unit and a colour means the same thing
     * on both charts. Null when the window holds too few days to say
     * anything.
     */
    public function heatmap(string $from, string $to): ?StressHeatmap
    {
        $levels = [];

        foreach ($this->levels as $day => $level) {
            $date = $this->dates[$day];

            if ($date >= $from && $date <= $to) {
                $levels[] = $level;
            }
        }

        $minDays = (int) config('health.stress.min_baseline_days', 21);

        if (count($levels) < $minDays) {
            return null;
        }

        $centre = (float) Robust::median($levels);
        $spread = max(
            (float) config('health.stress.min_spread_log', 0.03),
            (float) Robust::scaledMad($levels, $centre),
        );

        /** @var array<string, list<float>> $cells keyed "weekday:hour" */
        $cells = [];

        foreach ($this->samplesByDate as $date => $samples) {
            if ($date < $from || $date > $to) {
                continue;
            }

            foreach ($samples as $sample) {
                $cells[$sample->weekday.':'.$sample->hour][] =
                    $sample->lnValue - $this->profile->offsetFor($sample->hour);
            }
        }

        return StressHeatmap::build($cells, $centre, $spread, $this->scale, $from, $to, count($levels));
    }

    /**
     * Where the baseline stands for a given day: [centre, spread, days behind
     * it]. Null below the gate.
     *
     * @return array{0: float, 1: float, 2: int}|null
     */
    public function baselineAt(int $day): ?array
    {
        $window = (int) config('health.stress.baseline_days', 60);
        $minDays = (int) config('health.stress.min_baseline_days', 21);

        $levels = [];

        // Half-open on the target: [day - window, day - 1]. Integer keys make
        // this 60 array probes rather than 60 date parses — matters on a full
        // rebuild, run once per day of a three-year history.
        for ($d = $day - $window; $d <= $day - 1; $d++) {
            if (isset($this->levels[$d])) {
                $levels[] = $this->levels[$d];
            }
        }

        if (count($levels) < $minDays) {
            return null;
        }

        $centre = (float) Robust::median($levels);

        $spread = max(
            (float) config('health.stress.min_spread_log', 0.03),
            (float) Robust::scaledMad($levels, $centre),
        );

        return [$centre, $spread, count($levels)];
    }

    /**
     * @param  list<HrvSample>  $samples
     * @return list<float>
     */
    private function residuals(array $samples): array
    {
        return array_map(
            fn (HrvSample $s): float => $s->lnValue - $this->profile->offsetFor($s->hour),
            $samples
        );
    }

    /**
     * Days since the epoch, from a YYYY-MM-DD string.
     *
     * Deliberately arithmetic on the date parts, not a timestamp divided by
     * 86400: Europe/Amsterdam has two days a year that run 23 or 25 hours,
     * and a UTC-midnight timestamp for a local date can land one second on
     * the wrong side of that division. This can't.
     */
    public static function epochDay(string $date): int
    {
        [$y, $m, $d] = array_map(intval(...), explode('-', $date));

        // Fliegel & Van Flandern, via the civil-from-days inverse. Integer only.
        $y -= $m <= 2 ? 1 : 0;
        $era = intdiv($y >= 0 ? $y : $y - 399, 400);
        $yoe = $y - $era * 400;
        $doy = intdiv(153 * ($m + ($m > 2 ? -3 : 9)) + 2, 5) + $d - 1;
        $doe = $yoe * 365 + intdiv($yoe, 4) - intdiv($yoe, 100) + $doy;

        return $era * 146097 + $doe - 719468;
    }
}
