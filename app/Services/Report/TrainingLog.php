<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Workout;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The training the window actually contained: every session, then the totals.
 *
 * The report has always seen that a day had 62 exercise minutes and
 * 8,400 steps, but never that those minutes were a martial arts class,
 * a fortnightly habit, last done three weeks ago. Apple's exercise-minute
 * ring measures INTENSITY OVER TIME with no idea what the person was
 * doing; the workout label is most of the meaning for a returning
 * athlete. So this hands over named sessions plus the two things a
 * training pattern is made of: WHAT KINDS, and HOW FAR APART.
 *
 * THE ENERGY IN HERE IS NOT NEW ENERGY: every kcal figure below is
 * ALREADY inside `energy.expenditure_kcal_window_total`. The Watch
 * records active energy continuously; starting a workout just names a
 * stretch of already-measured time. The facts document says so in its
 * own words (`note`), because a model that added session kcal onto
 * weekly expenditure would produce a number that looks reasonable and
 * is wrong by a third.
 *
 * IMPLAUSIBLE SESSIONS ARE EXCLUDED FROM EVERY FIGURE, AND SAID OUT
 * LOUD: one captured session is a four-day hike whose timer was never
 * stopped — a real row the day view shows, but here it would add 96
 * hours to a fortnight's training time. So sessions and aggregates run
 * over plausible rows only, and excluded ones are reported separately
 * with their durations, so the report can mention the artifact honestly
 * rather than lying about the total or pretending the day was empty.
 */
final class TrainingLog
{
    /**
     * @return array<string, mixed>
     */
    public function build(ReportRange $range): array
    {
        $tz = (string) config('health.timezone');

        /** @var Collection<int, Workout> $all */
        $all = Workout::query()
            ->whereBetween('local_date', [$range->start, $range->end])
            ->orderBy('started_at')
            ->get();

        // Two filters rather than a partition: `partition` returns a collection
        // OF collections, and destructuring it loses every type on the way out.
        $implausible = $all->filter(static fn (Workout $w): bool => $w->is_implausible);
        $sessions = $all->reject(static fn (Workout $w): bool => $w->is_implausible);

        /*
         * The last session BEFORE the window, so the first gap is a
         * real gap. Without it, the first session of every report
         * looks like the first ever, and "you trained for the first
         * time in three weeks" — arguably the report's most useful
         * sentence — is unsayable from inside the window alone.
         */
        $previous = Workout::query()
            ->plausible()
            ->where('local_date', '<', $range->start)
            ->orderByDesc('started_at')
            ->first();

        $rows = [];
        $byType = [];
        $dates = [];
        $lastDate = $previous?->local_date->toDateString();

        $totalMinutes = 0.0;
        $totalKm = 0.0;
        $totalKcal = 0.0;
        $hasDistance = false;
        $hasKcal = false;

        foreach ($sessions as $workout) {
            $date = $workout->local_date->toDateString();
            $minutes = round($workout->durationMinutes(), 1);

            $rows[] = [
                'date'             => $date,
                'weekday'          => CarbonImmutable::parse($date, $tz)->isoFormat('ddd'),
                'type'             => $workout->type,
                'start_local'      => $workout->started_at->setTimezone($tz)->format('H:i'),
                'duration_minutes' => $minutes,
                'distance_km'      => $workout->distance_km === null ? null : round((float) $workout->distance_km, 2),
                'active_kcal'      => $workout->active_kcal === null ? null : (int) round((float) $workout->active_kcal),
                'avg_hr_bpm'       => $workout->avg_hr,
                'max_hr_bpm'       => $workout->max_hr,
                'indoor'           => $workout->is_indoor,
                // Days since the previous session, counting from the
                // day BEFORE this one: same-day sessions read 0,
                // yesterday reads 1. Null only for the first session.
                'days_since_previous_session' => $lastDate === null
                    ? null
                    : (int) CarbonImmutable::parse($lastDate, $tz)->diffInDays(CarbonImmutable::parse($date, $tz)),
            ];

            $byType[$workout->type] = ($byType[$workout->type] ?? 0) + 1;
            $dates[$date] = true;
            $lastDate = $date;

            $totalMinutes += $minutes;

            if ($workout->distance_km !== null) {
                $totalKm += (float) $workout->distance_km;
                $hasDistance = true;
            }

            if ($workout->active_kcal !== null) {
                $totalKcal += (float) $workout->active_kcal;
                $hasKcal = true;
            }
        }

        arsort($byType);

        $gaps = array_values(array_filter(
            array_column($rows, 'days_since_previous_session'),
            static fn (?int $g): bool => $g !== null,
        ));

        return [
            'what_this_is' => 'Workout sessions the watch recorded, with the activity NAME on them. '
                .'This is the only place in this document that says what the movement actually was — '
                .'exercise minutes and steps measure effort without knowing what produced it.',

            'note' => 'The kcal on a session are ALREADY inside the expenditure figures elsewhere in this '
                .'document. The watch records active energy whether or not a workout is running; starting one '
                .'labels a stretch of time, it does not add calories. Never add these to an expenditure total.',

            'session_count'       => count($rows),
            'days_with_a_session' => count($dates),
            'days_in_range'       => $range->days,

            'sessions_by_type' => $byType,

            'total_duration_minutes' => round($totalMinutes),
            'total_distance_km'      => $hasDistance ? round($totalKm, 2) : null,
            'total_active_kcal'      => $hasKcal ? (int) round($totalKcal) : null,
            'totals_note'            => 'Distance and kcal are totalled over the sessions that reported them. '
                .'Climbing, martial arts and indoor sessions carry no distance at all, so a distance total '
                .'is a total over the sessions that had one and never over all of them.',

            /*
             * The gap series is what a training PATTERN is made of.
             * The first entry can reach back before the window (see
             * `previous`) deliberately: a fortnight with two sessions
             * reads very differently depending on whether the three
             * weeks before were empty.
             */
            'longest_gap_days'              => $gaps === [] ? null : max($gaps),
            'previous_session_before_range' => $previous === null ? null : [
                'date' => $previous->local_date->toDateString(),
                'type' => $previous->type,
            ],

            'sessions' => $rows,

            /*
             * Named, dated, and excluded from everything above.
             */
            'excluded_as_implausible' => $implausible->map(static fn (Workout $w): array => [
                'date'           => $w->local_date->toDateString(),
                'type'           => $w->type,
                'duration_hours' => round($w->durationMinutes() / 60, 1),
                'reason'         => 'Longer than a session can plausibly be — the timer was almost certainly left '
                    .'running. Kept in the app because the export is the record, excluded from every figure '
                    .'above because it would dominate them.',
            ])->values()->all(),
        ];
    }
}
