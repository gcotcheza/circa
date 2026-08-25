<?php

declare(strict_types=1);

namespace App\Services\Stress;

/**
 * Find the two or three readings in a week that were furthest from what
 * this person's readings at that hour of the day usually are.
 *
 * The yardstick is HourlyBaseline, built from every residual of the
 * `baseline_days` BEFORE the week being viewed — never the week itself, the
 * same reason the daily baseline ends the day before: a week must not be
 * partly its own yardstick, or the excursions worth seeing are the ones
 * most flattened. The same instance colours the week grid, so a cell drawn
 * amber there and a moment listed here can't disagree about the same
 * reading.
 *
 * One moment per day per direction: a dip lasts longer than an hour, so it
 * arrives as two or three consecutive readings past the threshold, and
 * listing them all reports one event three times and crowds out the rest of
 * the week. Keeping the most extreme reading per (day, direction) reports
 * the event once, at its worst point, where a reader would look anyway.
 * Ordering then takes the strongest low and strongest high FIRST when both
 * exist, so a week with both never shows three lows and no high — a
 * one-sided list is the shape that makes a reader infer a bad week from a
 * ranking artefact.
 */
final class NotableMoments
{
    /**
     * @param  list<HrvSample>  $week  every accepted reading of the week being viewed
     * @param  HourlyBaseline|null  $baseline  built from the days BEFORE that week
     * @return list<NotableMoment> chronological
     */
    public static function find(
        array $week,
        ?HourlyBaseline $baseline,
        ?float $minZ = null,
        ?int $max = null,
    ): array {
        $minZ ??= (float) config('health.stress.digest.moment_min_z', 2.5);
        $max ??= (int) config('health.stress.digest.moment_max', 3);

        if ($baseline === null || $week === []) {
            return [];
        }

        /** @var array<string, NotableMoment> $best keyed "date:direction" */
        $best = [];

        foreach ($week as $sample) {
            $z = $baseline->zFor($sample);

            // Null is an hour the circadian profile has no shape for —
            // skipped rather than compared against the day's overall
            // middle and labelled anyway (see HourlyBaseline).
            if ($z === null || abs($z) < $minZ) {
                continue;
            }

            $key = $sample->localDate.':'.($z < 0 ? 'low' : 'high');

            if (isset($best[$key]) && abs($best[$key]->z) >= abs($z)) {
                continue;
            }

            [$low, $high] = $baseline->typicalRangeMs($sample->hour);

            $best[$key] = new NotableMoment(
                date: $sample->localDate,
                hour: $sample->hour,
                weekday: $sample->weekday,
                valueMs: $sample->valueMs,
                z: $z,
                typicalMs: $baseline->typicalMs($sample->hour),
                typicalLowMs: $low,
                typicalHighMs: $high,
            );
        }

        return self::rank(array_values($best), $max);
    }

    /**
     * Strongest first, one of each direction guaranteed a place, capped, then
     * put back in chronological order for reading.
     *
     * @param  list<NotableMoment>  $moments
     * @return list<NotableMoment>
     */
    private static function rank(array $moments, int $max): array
    {
        if ($moments === [] || $max < 1) {
            return [];
        }

        usort($moments, static function (NotableMoment $a, NotableMoment $b): int {
            // Ties broken by date so the list is stable rather than dependent on
            // the order the samples happened to be loaded in.
            return abs($b->z) <=> abs($a->z) ?: strcmp($a->date, $b->date) ?: $a->hour <=> $b->hour;
        });

        $lows = array_values(array_filter($moments, static fn (NotableMoment $m): bool => $m->isLow()));
        $highs = array_values(array_filter($moments, static fn (NotableMoment $m): bool => ! $m->isLow()));

        $chosen = [];

        // One of each first, when the week produced both — stronger of the two
        // leading, so a cap of one still surfaces the week's biggest excursion.
        if ($lows !== [] && $highs !== []) {
            $chosen = [$lows[0], $highs[0]];

            usort($chosen, static fn (NotableMoment $a, NotableMoment $b): int => abs($b->z) <=> abs($a->z));
        }

        foreach ($moments as $moment) {
            if (count($chosen) >= $max) {
                break;
            }

            if (! in_array($moment, $chosen, strict: true)) {
                $chosen[] = $moment;
            }
        }

        $chosen = array_slice($chosen, 0, $max);

        usort($chosen, static fn (NotableMoment $a, NotableMoment $b): int => [$a->date, $a->hour] <=> [$b->date, $b->hour]);

        return $chosen;
    }
}
