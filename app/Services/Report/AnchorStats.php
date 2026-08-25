<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Services\Stress\Robust;

/**
 * The numbers the model is forbidden to work out for itself.
 *
 * The day table hands over forty rows and invites the model to notice
 * patterns — the wrong job it will cheerfully do unasked is QUANTIFY them
 * ("your stress averaged 41 after short nights"), which is exactly the
 * arithmetic a language model is worst at and most confident about. So
 * every comparison the report might want is computed HERE, over the same
 * rows, and the prompt is told: every NUMBER you print must be one of ours.
 *
 * `min_group` (3 by default) is an honesty gate, not a performance one — an
 * absent anchor cannot be quoted, while one labelled "n=2, unreliable"
 * still can be. Under-powered splits return `mean` null with a stated
 * reason, so the report can honestly say there wasn't enough data to
 * compare.
 *
 * Three splits are LAG-1 (something on day D against day D+1, since that's
 * the direction causality can run — a late dinner can't affect the sleep
 * before it), and the lag is named in the label (`next_day`) so it's never
 * mistaken for same-day. Every group reports both mean and median, since a
 * gap between them exposes an outlier a mean alone would hide.
 */
final class AnchorStats
{
    /**
     * @param  list<array<string, mixed>>  $rows  the aligned day table
     * @return list<array<string, mixed>>
     */
    public static function from(array $rows): array
    {
        /** @var array<string, mixed> $config */
        $config = config('health.report.anchors');

        $minGroup = max(1, (int) ($config['min_group'] ?? 3));
        $shortSleep = (float) ($config['short_sleep_hours'] ?? 6.0);
        $longSleep = (float) ($config['long_sleep_hours'] ?? 7.0);
        $lateHour = (int) ($config['late_meal_hour'] ?? 21);

        $anchors = [];

        /*
         * 1 & 2. Short nights against long ones, same row on both sides — the
         * day table already carries the night BEFORE each day (see
         * DayTable), so sleep and stress are already in causal order. The
         * two thresholds deliberately leave a gap (under 6 h vs. 7 h+)
         * rather than a median split, since 6.9 h and 7.1 h either side of
         * an arbitrary line would make the "difference" mostly the width of
         * the gap.
         */
        $anchors[] = self::split(
            key: 'stress_after_short_vs_long_sleep',
            question: "Does this person's stress score differ after a short night?",
            rows: $rows,
            metric: 'stress_score',
            groupALabel: "under {$shortSleep} h of sleep",
            groupBLabel: "{$longSleep} h or more",
            classify: static fn (array $row): ?bool => self::sleepClass($row, $shortSleep, $longSleep),
            minGroup: $minGroup,
            note: 'Nights between the two thresholds are in neither group, on purpose.',
        );

        $anchors[] = self::split(
            key: 'hrv_after_short_vs_long_sleep',
            question: 'The same split, in the underlying measurement rather than the score.',
            rows: $rows,
            metric: 'hrv_ms',
            groupALabel: "under {$shortSleep} h of sleep",
            groupBLabel: "{$longSleep} h or more",
            classify: static fn (array $row): ?bool => self::sleepClass($row, $shortSleep, $longSleep),
            minGroup: $minGroup,
            note: 'HRV in milliseconds, deseasonalised, median of the day.',
        );

        /*
         * 3 & 4. Late last meal, lagged by one row since a 22:30 dinner can
         * only affect the night after it. Days with no logged meal are
         * excluded rather than counted "early" — an unlogged day says
         * nothing about when the person ate, and treating silence as
         * evidence is what this app exists to avoid.
         */
        $lagged = self::lag($rows);

        $anchors[] = self::split(
            key: 'sleep_after_late_vs_early_last_meal_next_day',
            question: 'Does eating late go with a shorter night?',
            rows: $lagged,
            metric: 'next_sleep_hours',
            groupALabel: "last logged meal at {$lateHour}:00 or later",
            groupBLabel: "last logged meal before {$lateHour}:00",
            classify: static fn (array $row): ?bool => self::lateClass($row, $lateHour),
            minGroup: $minGroup,
            note: 'Lagged: the meal is on day D, the sleep is the night that follows it.',
        );

        $anchors[] = self::split(
            key: 'stress_after_late_vs_early_last_meal_next_day',
            question: 'The same lag, against the next day\'s stress score.',
            rows: $lagged,
            metric: 'next_stress_score',
            groupALabel: "last logged meal at {$lateHour}:00 or later",
            groupBLabel: "last logged meal before {$lateHour}:00",
            classify: static fn (array $row): ?bool => self::lateClass($row, $lateHour),
            minGroup: $minGroup,
            note: 'Lagged by one day, same as the sleep split above.',
        );

        /*
         * 5. Exercise, also lagged: a run at six in the evening shows up
         * mostly in the following row's overnight readings. Thirty minutes
         * is Apple's own exercise-ring target, so it's the threshold the
         * user already thinks in.
         */
        $anchors[] = self::split(
            key: 'stress_after_exercise_next_day',
            question: 'Does a day with real exercise in it read differently the next morning?',
            rows: $lagged,
            metric: 'next_stress_score',
            groupALabel: '30 or more exercise minutes',
            groupBLabel: 'under 30 exercise minutes',
            classify: static function (array $row): ?bool {
                $minutes = $row['exercise_minutes'] ?? null;

                return $minutes === null ? null : ((int) $minutes >= 30);
            },
            minGroup: $minGroup,
            note: 'Lagged by one day.',
        );

        /*
         * 6. A named training session, lagged — a different question from
         * anchor 5: thirty exercise minutes can be a brisk walk, while this
         * asks whether a day with a WORKOUT (class, run, climb) reads
         * differently the next morning, which is what someone rebuilding an
         * athletic base actually wants to know. Every day is classified,
         * since "no session" is a fact about the day, not a missing
         * measurement — implausible sessions are already absent from
         * `training` (see DayTable).
         */
        $anchors[] = self::split(
            key: 'stress_after_a_training_session_next_day',
            question: 'Does a day with a named workout on it read differently the next morning?',
            rows: $lagged,
            metric: 'next_stress_score',
            groupALabel: 'a workout was recorded',
            groupBLabel: 'no workout recorded',
            classify: static function (array $row): ?bool {
                $training = $row['training'] ?? null;

                return is_array($training) ? $training !== [] : null;
            },
            minGroup: $minGroup,
            note: 'Lagged by one day. A session is a workout the watch recorded, not an exercise-minute '
                .'threshold — the two anchors ask different questions.',
        );

        /*
         * 7. Supplement adherence, also lagged — included with no
         * expectation it shows anything (a single day of magnesium isn't a
         * mechanism), but precisely because it's the comparison a reader
         * would otherwise eyeball off the table, badly. Days before the
         * first supplement existed are excluded from both groups.
         */
        $anchors[] = self::split(
            key: 'stress_after_full_supplement_day_next_day',
            question: 'Do days after a complete supplement day score differently?',
            rows: $lagged,
            metric: 'next_stress_score',
            groupALabel: 'every expected supplement ticked',
            groupBLabel: 'at least one missed',
            classify: static function (array $row): ?bool {
                $expected = $row['supplements_expected'] ?? [];

                if (! is_array($expected) || $expected === []) {
                    return null;
                }

                $taken = is_array($row['supplements_taken'] ?? null) ? $row['supplements_taken'] : [];

                return count($taken) >= count($expected);
            },
            minGroup: $minGroup,
            note: 'Lagged by one day. Days before the first supplement existed are excluded.',
        );

        return array_values(array_filter($anchors, static fn (?array $a): bool => $a !== null));
    }

    /**
     * Under the short threshold => group A; at or above the long one => group
     * B; anything between, or missing, => neither.
     *
     * @param  array<string, mixed>  $row  one DayTable row
     */
    private static function sleepClass(array $row, float $short, float $long): ?bool
    {
        $hours = $row['sleep_hours'] ?? null;

        if ($hours === null) {
            return null;
        }

        $hours = (float) $hours;

        return match (true) {
            $hours < $short => true,
            $hours >= $long => false,
            default         => null,
        };
    }

    /** @param  array<string, mixed>  $row  one DayTable row */
    private static function lateClass(array $row, int $lateHour): ?bool
    {
        $time = $row['last_meal_local_time'] ?? null;

        if (! is_string($time) || $time === '') {
            return null;
        }

        return ((int) mb_substr($time, 0, 2)) >= $lateHour;
    }

    /**
     * Every row paired with the one after it, so a split can look forward.
     * The final row is dropped — it has no successor, and null lookaheads
     * would just give the classifier something else to reject.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function lag(array $rows): array
    {
        $out = [];

        for ($i = 0, $n = count($rows) - 1; $i < $n; $i++) {
            $out[] = $rows[$i] + [
                'next_date'         => $rows[$i + 1]['date'],
                'next_stress_score' => $rows[$i + 1]['stress_score'] ?? null,
                'next_sleep_hours'  => $rows[$i + 1]['sleep_hours'] ?? null,
                'next_hrv_ms'       => $rows[$i + 1]['hrv_ms'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * One two-group comparison, with both groups' counts stated whether or not
     * they cleared the gate.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  callable(array<string, mixed>): ?bool  $classify  true => A, false => B, null => excluded
     * @return array<string, mixed>|null null when neither group has data — the split doesn't apply here
     */
    private static function split(
        string $key,
        string $question,
        array $rows,
        string $metric,
        string $groupALabel,
        string $groupBLabel,
        callable $classify,
        int $minGroup,
        ?string $note = null,
    ): ?array {
        $a = [];
        $b = [];
        $aDates = [];
        $bDates = [];

        foreach ($rows as $row) {
            $side = $classify($row);

            if ($side === null) {
                continue;
            }

            $value = $row[$metric] ?? null;

            if ($value === null || ! is_numeric($value)) {
                continue;
            }

            if ($side) {
                $a[] = (float) $value;
                $aDates[] = (string) $row['date'];
            } else {
                $b[] = (float) $value;
                $bDates[] = (string) $row['date'];
            }
        }

        if ($a === [] && $b === []) {
            return null;
        }

        $groupA = self::group($groupALabel, $a, $aDates, $minGroup);
        $groupB = self::group($groupBLabel, $b, $bDates, $minGroup);

        $comparable = $groupA['mean'] !== null && $groupB['mean'] !== null;

        return [
            'key'            => $key,
            'question'       => $question,
            'metric'         => $metric,
            'min_group_size' => $minGroup,
            'group_a'        => $groupA,
            'group_b'        => $groupB,
            'difference'     => $comparable ? round($groupA['mean'] - $groupB['mean'], 1) : null,
            'comparable'     => $comparable,
            'note'           => $note,
        ];
    }

    /**
     * @param  list<float>  $values
     * @param  list<string>  $dates
     * @return array<string, mixed>
     */
    private static function group(string $label, array $values, array $dates, int $minGroup): array
    {
        $n = count($values);

        if ($n < $minGroup) {
            return [
                'label' => $label,
                'n'     => $n,
                // Deliberately null rather than computed-and-flagged. See the
                // class comment: an anchor that exists will be quoted.
                'mean'   => null,
                'median' => null,
                'dates'  => $dates,
                'reason' => "Only {$n} day".($n === 1 ? '' : 's')." in this group; {$minGroup} are needed.",
            ];
        }

        return [
            'label'  => $label,
            'n'      => $n,
            'mean'   => round(array_sum($values) / $n, 1),
            'median' => round((float) Robust::median($values), 1),
            'dates'  => $dates,
            'reason' => null,
        ];
    }
}
