<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\Workout;
use Carbon\CarbonImmutable;
use App\Services\Stress\DailyStress;
use App\Services\Stress\LevelChange;
use App\Services\Support\LocalDates;
use App\Services\Stress\RestingTrend;
use App\Services\Stress\WeeklyDigest;
use App\Services\Stress\NotableMoment;
use App\Services\Stress\StressAnalysis;
use App\Services\Stress\StressRecorder;
use App\Services\Stress\CircadianProfile;
use App\Services\Stress\RestingHeartRate;
use App\Services\Stress\StressCalculator;

/**
 * Everything the stress page renders: today's card, one browsable week,
 * and the hour x weekday grid.
 *
 * The page computes; the table remembers — same split as step 7's
 * TdeeCard, plus one extra justification of its own. Today's score MUST
 * be live: HRV arrives hour by hour, so a score read from a table
 * written at 03:50 would be last night's answer with today's date on
 * it, and the most likely moment to open this page is a morning already
 * suspected rough.
 *
 * The rows are still written (see StressRecorder) because the table is
 * what the coming health report joins against, and a nightly job that
 * only ever agreed with the page would be untestable — DailyView and
 * TdeeCard both write during a GET too. The write is an idempotent
 * upsert that only touches `computed_at` when the answer moved, so a
 * refresh is genuinely a no-op.
 */
final class StressView
{
    public function __construct(
        private readonly StressCalculator $calculator = new StressCalculator,
        private readonly StressRecorder $recorder = new StressRecorder,
        private readonly RestingHeartRate $resting = new RestingHeartRate,
        private readonly HistorySpan $history = new HistorySpan,
    ) {}

    /**
     * The named sessions of each day in the week, as one readable label.
     *
     * One bulk read, and a string rather than a structure: the chart puts
     * this straight into a dot's <title>, so "Martial Arts, Outdoor Run"
     * is the whole contract — anything richer would be a second card
     * pretending to be an axis mark.
     *
     * @return array<string, string>
     */
    private function trainingByDate(string $start, string $end): array
    {
        $labels = [];

        $workouts = Workout::query()
            ->plausible()
            ->whereBetween('local_date', [$start, $end])
            ->orderBy('started_at')
            ->get(['type', 'local_date']);

        foreach ($workouts as $workout) {
            $date = $workout->local_date->toDateString();

            $labels[$date] = isset($labels[$date])
                ? $labels[$date].', '.$workout->type
                : $workout->type;
        }

        return $labels;
    }

    /**
     * @param  string|null  $week  any date inside the week to show; defaults to this week
     * @return array<string, mixed>
     */
    public function props(?string $week = null): array
    {
        $tz = (string) config('health.timezone');
        $now = CarbonImmutable::now($tz);
        $today = $now->startOfDay();

        $weekStart = $this->weekStart($week, $today, $tz);
        $weekEnd = $weekStart->addDays(6);

        $previousStart = $weekStart->subWeek();

        /*
         * Load once, from the start of the PREVIOUS week (its 60-day
         * baseline is pulled in by the calculator) through today, so the
         * week, card, heatmap and week-on-week digest all answer from
         * one read of one set of samples.
         *
         * Starting from the previous week rather than this one is what
         * the digest costs: this week's start alone reaches back sixty
         * days, seven short of what LAST week's Monday needs to score
         * the same way it would if browsed to directly — otherwise an
         * old week would compare against a neighbour built from a
         * shorter baseline and the two screens would disagree for no
         * visible reason. Seven extra rows is cheaper than an
         * explanation.
         */
        $analysis = $this->calculator->analyse(
            $previousStart->toDateString(),
            max($weekEnd->toDateString(), $today->toDateString()),
            $today,
        );

        $dates = LocalDates::inclusive($weekStart->toDateString(), $weekEnd->toDateString(), $tz);

        $days = $analysis->days($dates);

        $previousDays = $analysis->days(LocalDates::inclusive(
            $previousStart->toDateString(),
            $weekStart->subDay()->toDateString(),
            $tz,
        ));

        $todayStress = $days[$today->toDateString()] ?? $analysis->day($today->toDateString());
        $yesterday = $analysis->day($today->subDay()->toDateString());

        /*
         * Persist what was just computed: the week on screen, plus today
         * and yesterday when outside it. Unscored days delete any row
         * that was there, so withdrawn readings stop being a remembered
         * score.
         */
        $this->recorder->record([...array_values($days), $todayStress, $yesterday]);

        [$heatFrom, $heatTo] = StressCalculator::heatmapWindow($today);

        $heatmap = $analysis->heatmap($heatFrom, $heatTo);

        $trained = $this->trainingByDate($weekStart->toDateString(), $weekEnd->toDateString());

        return [
            'today'     => $todayStress->toArray() + ['isToday' => true],
            'yesterday' => $yesterday->toArray(),

            'week' => [
                'start'    => $weekStart->toDateString(),
                'end'      => $weekEnd->toDateString(),
                'label'    => $this->weekLabel($weekStart, $weekEnd, $today),
                'previous' => $weekStart->subWeek()->toDateString(),

                /*
                 * The floor of the header's date picker: the first day
                 * there is an HRV reading for.
                 *
                 * The arrows step, not jump, and eighteen months of
                 * history is seventy-eight presses — so the picker is
                 * clamped between this and today and never offers a
                 * week that could only ever be seven blanks. Null
                 * before the first reading: see HistorySpan.
                 */
                'earliest' => $this->history->stress(),
                // No link into a week that has not happened yet — unlike
                // the day view's link to tomorrow (a real, loggable day),
                // a future WEEK of a passive measurement is only blanks.
                'next'      => $weekStart->addWeek()->greaterThan($today) ? null : $weekStart->addWeek()->toDateString(),
                'isCurrent' => $weekStart->lessThanOrEqualTo($today) && $weekEnd->greaterThanOrEqualTo($today),
                'days'      => array_map(
                    fn (DailyStress $day): array => $day->toArray() + [
                        'label'      => CarbonImmutable::parse($day->date, $tz)->isoFormat('dd'),
                        'dayOfMonth' => (int) CarbonImmutable::parse($day->date, $tz)->format('j'),
                        'isToday'    => $day->date === $today->toDateString(),
                        'isFuture'   => $day->date > $today->toDateString(),

                        /*
                         * A training day is a mark under the axis, not a
                         * second line on it: a score is 1-99 and a
                         * session either happened or didn't, so they
                         * don't share an axis. The mark answers "was
                         * Wednesday's dip the day after the class?"
                         * without implying a mechanism — the same
                         * restraint the report's prompt is held to.
                         *
                         * Plausible sessions only: the one four-day
                         * artifact in this history would otherwise mark
                         * four days as training off one forgotten timer.
                         */
                        'trained'      => isset($trained[$day->date]),
                        'trainedLabel' => $trained[$day->date] ?? null,
                    ],
                    array_values($days),
                ),
            ],

            /*
             * Two grids answering different questions. `weekGrid` is the
             * seven viewed days, hour by hour — what HAPPENED; it
             * follows ?week= and is the card's default since it's what
             * changes when you browse. `heatmap` is the last ninety days
             * pooled into one typical week — what USUALLY happens — and
             * ignores ?week= since a ninety-day habit isn't a property
             * of any one week.
             *
             * Either can be null on its own gate; the card copes with
             * one, both or neither.
             */
            'weekGrid' => $analysis->weekGrid(
                $weekStart->toDateString(),
                $today->toDateString(),
                (int) $now->format('G'),
            )?->toArray(),

            'heatmap' => $heatmap?->toArray(),

            // The week being VIEWED, read back in sentences. Follows ?week=.
            'digest' => $this->digestProps($analysis, $days, $previousDays, $weekStart, $weekEnd, $today),

            /*
             * The two tiles beside the score card follow TODAY, not
             * ?week=: one is by definition the latest resting heart
             * rate, and there's no such thing as "latest" for a week in
             * March. Following the browsed week would put a stale
             * "latest" under a live headline, so both stay rolling.
             */
            'hrv7' => LevelChange::from(
                $analysis->levelsBetween($today->subDays(6)->toDateString(), $today->toDateString()),
                $analysis->levelsBetween($today->subDays(13)->toDateString(), $today->subDays(7)->toDateString()),
                (int) config('health.stress.digest.min_week_days', 4),
            )->toArray(),

            'restingHr' => $this->restingProps($today),

            // The legend, generated from the same config that decided the score.
            'bands' => $analysis->scale()->bands(),

            'scale' => [
                'min' => $analysis->scale()->min,
                'max' => $analysis->scale()->max,
                // Stated so the page can explain what a score IS in one line
                // without the sentence being able to drift from the arithmetic.
                'baselineScore' => (int) config('health.stress.scale.baseline_score', 65),
                'greatZ'        => (float) config('health.stress.scale.great_z', 0.85),
            ],

            'baseline' => [
                'days'          => (int) config('health.stress.baseline_days', 60),
                'minDays'       => (int) config('health.stress.min_baseline_days', 21),
                'minDaySamples' => (int) config('health.stress.min_day_samples', 3),
                'highSamples'   => (int) config('health.stress.confidence.high_samples', 16),
                'mediumSamples' => (int) config('health.stress.confidence.medium_samples', 8),
            ],

            'circadian' => $this->circadianProps($analysis->profile()),

            'methodVersion' => (string) config('health.stress.method_version', 'v1'),
            'today_date'    => $today->toDateString(),
        ];
    }

    /**
     * The viewed week, read back: its extremes, average against the week
     * before, HRV against the seven days before, and readings far from
     * usual.
     *
     * All of it follows `?week=`, including notable moments, whose
     * comparison pool is the sixty days before the week LOOKED at, not
     * before today — otherwise browsing to March would rate readings
     * unusual by 2026 standards, a different question than asked.
     *
     * @param  array<string, DailyStress>  $days
     * @param  array<string, DailyStress>  $previousDays
     * @return array<string, mixed>
     */
    private function digestProps(
        StressAnalysis $analysis,
        array $days,
        array $previousDays,
        CarbonImmutable $weekStart,
        CarbonImmutable $weekEnd,
        CarbonImmutable $today,
    ): array {
        $digest = WeeklyDigest::from(
            array_values($days),
            array_values($previousDays),
            LevelChange::from(
                $analysis->levelsBetween($weekStart->toDateString(), $weekEnd->toDateString()),
                $analysis->levelsBetween($weekStart->subWeek()->toDateString(), $weekStart->subDay()->toDateString()),
                (int) config('health.stress.digest.min_week_days', 4),
            ),
            $today->toDateString(),
        );

        $moments = $analysis->moments($weekStart->toDateString(), $weekEnd->toDateString());

        return $digest->toArray() + [
            // null = not enough history to say what unusual is; [] = nothing was.
            'moments' => $moments === null
                ? null
                : array_map(static fn (NotableMoment $m): array => $m->toArray(), $moments),
            'momentMinZ'     => (float) config('health.stress.digest.moment_min_z', 2.5),
            'momentPoolDays' => (int) config('health.stress.baseline_days', 60),
        ];
    }

    /**
     * The resting-heart-rate tile.
     *
     * Reads to today rather than the end of the browsed week for the
     * same reason it doesn't follow `?week=`: "latest" means latest.
     *
     * @return array<string, mixed>
     */
    private function restingProps(CarbonImmutable $today): array
    {
        $window = max(1, (int) config('health.stress.digest.resting_window_days', 90));

        return RestingTrend::from(
            $this->resting->between(
                $today->subDays($window - 1)->toDateString(),
                $today->toDateString(),
            ),
            $window,
        )->toArray();
    }

    /**
     * The Monday of the week to show.
     *
     * A garbage or future `?week=` falls back to this week rather than
     * 404ing: it's a position in a browsable series, not a resource, and
     * a stale link in the PWA's history should land somewhere useful.
     */
    private function weekStart(?string $week, CarbonImmutable $today, string $tz): CarbonImmutable
    {
        $thisWeek = $today->startOfWeek(CarbonImmutable::MONDAY);

        if ($week === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $week) !== 1) {
            return $thisWeek;
        }

        try {
            $start = CarbonImmutable::parse($week, $tz)->startOfDay()->startOfWeek(CarbonImmutable::MONDAY);
        } catch (\Throwable) {
            return $thisWeek;
        }

        return $start->greaterThan($thisWeek) ? $thisWeek : $start;
    }

    /**
     * The week, written the way somebody browsing would say it.
     *
     * The year appears only when it's not this one: "13–19 Apr" is how
     * you'd say a week you're in the middle of, and stamping 2026 on it
     * every time is noise on the most-read label. Browse back far
     * enough, though, and "13–19 Apr" stops being a date — this history
     * has three Aprils — so the year is added exactly when it carries
     * information.
     *
     * A week straddling New Year gets BOTH years: "28 Dec – 3 Jan 2027"
     * would otherwise put the wrong year on four of its seven days.
     */
    private function weekLabel(CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $today): string
    {
        if ($start->year !== $end->year) {
            return $start->isoFormat('D MMM YYYY').' – '.$end->isoFormat('D MMM YYYY');
        }

        $year = $end->year === $today->year ? '' : ' '.$end->isoFormat('YYYY');

        return $start->month === $end->month
            ? $start->isoFormat('D').'–'.$end->isoFormat('D MMM').$year
            : $start->isoFormat('D MMM').' – '.$end->isoFormat('D MMM').$year;
    }

    /**
     * A one-line summary of the circadian correction, so the page can say what
     * it removed instead of asking to be believed.
     *
     * @return array<string, mixed>
     */
    private function circadianProps(CircadianProfile $profile): array
    {
        $rows = $profile->toArray();

        $estimated = array_filter($rows, static fn (array $r): bool => $r['estimated']);

        if ($estimated === []) {
            return [
                'days'           => $profile->days,
                'estimatedHours' => 0,
                'peakHour'       => null,
                'troughHour'     => null,
                'swingPercent'   => null,
                'hours'          => array_values($rows),
            ];
        }

        $ratios = array_column($estimated, 'ratio', 'hour');

        $peak = (int) array_search(max($ratios), $ratios, strict: true);
        $trough = (int) array_search(min($ratios), $ratios, strict: true);

        return [
            'days'           => $profile->days,
            'estimatedHours' => count($estimated),
            'peakHour'       => $peak,
            'troughHour'     => $trough,
            // How much of the day's HRV swing is the clock rather than the day.
            'swingPercent' => (int) round((max($ratios) / min($ratios) - 1) * 100),
            'hours'        => array_values($rows),
        ];
    }
}
