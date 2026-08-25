<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Meal;
use App\Models\Workout;
use App\Enums\MealStatus;
use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Models\SleepSession;
use App\Models\SupplementIntake;
use Illuminate\Support\Collection;
use App\Services\Rollup\SourcePriority;
use App\Services\Stress\StressAnalysis;

/**
 * The aligned day table: one row per local date, every signal side by side.
 *
 * Aggregates ("how much") can't answer "what goes with what" — a
 * fortnight's mean sleep and stress score can't say the worst days
 * followed the shortest nights. So the model gets rows, not a summary:
 * same date per line, nulls explicit, the only part of the prompt doing
 * what a `SELECT` couldn't. It may OBSERVE ("the two lowest scores follow
 * nights under six hours") but not CALCULATE ("mean stress after a short
 * night was 41") — that comes from AnchorStats in PHP, since arithmetic
 * over forty numbers in prose eventually goes wrong unnoticed. See
 * PromptV1Report for how that division is stated to it.
 *
 * Six bulk reads keyed on date, then an in-memory join — one query per
 * source, not per day, or a thirty-day report does 180 round trips.
 * `sleep_sessions.night_date` is HAE's own night key, the morning you woke
 * up on (23:00-08:30 carries the LATER date), so row D's sleep is the
 * night BEFORE day D, which is the pairing every question worth asking
 * wants: "I slept badly and then had a bad day", not "I had a bad day
 * and then slept".
 */
final class DayTable
{
    public function __construct(
        private readonly SourcePriority $priority = new SourcePriority,
    ) {}

    /**
     * @param  array<string, float>  $restingByDate  local date => bpm
     * @return list<array<string, mixed>>
     */
    public function build(ReportRange $range, StressAnalysis $analysis, array $restingByDate): array
    {
        $dates = $range->dates();

        $summaries = DailySummary::query()
            ->whereBetween('local_date', [$range->start, $range->end])
            ->get()
            ->keyBy(fn (DailySummary $s): string => $s->local_date->toDateString());

        $sleep = $this->sleepByNight($range);
        $meals = $this->mealsByDate($range);
        $supplements = $this->supplementsByDate($range, $dates);
        $training = $this->trainingByDate($range);

        $tz = (string) config('health.timezone');

        $rows = [];

        foreach ($dates as $date) {
            /** @var DailySummary|null $summary */
            $summary = $summaries->get($date);

            $stress = $analysis->day($date);

            $night = $sleep[$date] ?? null;
            $day = $meals[$date] ?? null;

            $active = $summary?->active_kcal === null ? null : (float) $summary->active_kcal;
            $resting = $summary?->resting_kcal === null ? null : (float) $summary->resting_kcal;

            $rows[] = [
                'date'    => $date,
                'weekday' => CarbonImmutable::parse($date, $tz)->isoFormat('ddd'),

                // Stress is computed live from the same analysis the stress
                // page uses, not read from `stress_daily`, which writes
                // nightly at 03:50 and would show Sunday's row for Sunday on
                // a 09:00 Monday report.
                'stress_score'           => $stress->score,
                'stress_band'            => $stress->band?->value,
                'stress_confidence'      => $stress->hasScore() ? $stress->confidence() : null,
                'stress_unscored_reason' => $stress->hasScore() ? null : $stress->reason,
                'hrv_ms'                 => $stress->hrvMs === null ? null : round($stress->hrvMs, 1),
                'hrv_readings'           => $stress->samples,

                'resting_hr_bpm' => isset($restingByDate[$date]) ? round($restingByDate[$date]) : null,

                // sleep: the night BEFORE this day
                'sleep_hours'         => $night['hours'] ?? null,
                'sleep_awake_minutes' => $night['awake_minutes'] ?? null,
                'sleep_start_local'   => $night['start_local'] ?? null,
                'sleep_end_local'     => $night['end_local'] ?? null,

                'kcal_in_min'  => $this->number($summary?->kcal_in_min),
                'kcal_in_mid'  => $this->number($summary?->kcal_in_mid),
                'kcal_in_max'  => $this->number($summary?->kcal_in_max),
                'kcal_out'     => $summary?->totalKcalOut() === null ? null : round((float) $summary->totalKcalOut()),
                'active_kcal'  => $active === null ? null : round($active),
                'resting_kcal' => $resting === null ? null : round($resting),

                /*
                 * What the movement on this row actually WAS: exercise
                 * minutes measure effort without knowing what produced it,
                 * while this says "Martial Arts, 70 minutes." A LIST, not a
                 * total — its kcal are already inside `active_kcal` on this
                 * row, since the watch recorded that energy whether or not a
                 * workout was running.
                 */
                'training' => $training[$date] ?? [],

                'steps'            => $summary?->steps === null ? null : (int) round((float) $summary->steps),
                'exercise_minutes' => $summary?->exercise_minutes === null ? null : (int) round((float) $summary->exercise_minutes),
                'distance_km'      => $summary?->distance_km === null ? null : round((float) $summary->distance_km, 2),

                'weight_kg' => $summary?->weight_kg === null ? null : round((float) $summary->weight_kg, 2),

                'meals_logged'         => $day['count'] ?? 0,
                'last_meal_local_time' => $day['last_time'] ?? null,
                'food_log_complete'    => $summary->is_complete_log ?? false,

                // Supplements are per PRODUCT, yes/no, not "3 of 5": the
                // report wants this column to line one bottle up against
                // something else, which a count can't be looked up by name.
                'supplements_taken'    => $supplements[$date]['taken'] ?? [],
                'supplements_expected' => $supplements[$date]['expected'] ?? [],

                // These three travel WITH the row, not only in the coverage
                // block, since they qualify this row's numbers specifically —
                // a Watch half off the wrist gives a real-looking `kcal_out`
                // that's an undercount, and the model must see that on the
                // line it's reading.
                'metric_coverage_full'   => $summary->has_full_metric_coverage ?? false,
                'active_kcal_is_partial' => $summary->active_kcal_is_partial ?? false,
                'active_kcal_coverage'   => $summary?->active_kcal_coverage === null
                    ? null
                    : round((float) $summary->active_kcal_coverage, 3),
            ];
        }

        return $rows;
    }

    /**
     * One night per date, source-resolved: two sources can legitimately
     * report the same night (Watch and AutoSleep both do in this user's
     * history), so the same priority list the day view uses picks one —
     * summing them would produce sixteen-hour nights.
     *
     * @return array<string, array<string, mixed>>
     */
    private function sleepByNight(ReportRange $range): array
    {
        /** @var Collection<int, SleepSession> $sessions */
        $sessions = SleepSession::query()
            ->with('source')
            ->whereBetween('night_date', [$range->start, $range->end])
            ->get();

        $tz = (string) config('health.timezone');

        $out = [];

        foreach ($sessions->groupBy(fn (SleepSession $s): string => $s->night_date->toDateString()) as $date => $forNight) {
            /** @var SleepSession $session */
            $session = $forNight
                ->sortBy(fn (SleepSession $s): int => $this->priority->rankFor('sleep_analysis', $s->source->device_kind))
                ->first();

            $hours = $session->totalSleepHours();

            $out[(string) $date] = [
                'hours'         => $hours === null ? null : round($hours, 2),
                'awake_minutes' => $session->awake_minutes === null ? null : round((float) $session->awake_minutes),
                // Local clock: "went to bed at 01:40" is the fact worth
                // pattern-matching against a late meal, not a UTC instant
                // nobody can read.
                'start_local' => $session->sleep_start?->setTimezone($tz)->format('H:i'),
                'end_local'   => $session->sleep_end?->setTimezone($tz)->format('H:i'),
            ];
        }

        return $out;
    }

    /**
     * The named sessions of each local date, compact.
     *
     * Plausible rows only: the one four-day artifact in this user's history
     * would otherwise put a 5760-minute "session" on a line the model reads
     * patterns off — it's reported separately, honestly, in the `training`
     * facts block. One bulk read for the whole range, like every source
     * here, so a thirty-day report doesn't do thirty round trips to find
     * out it trained four times.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function trainingByDate(ReportRange $range): array
    {
        /** @var Collection<int, Workout> $workouts */
        $workouts = Workout::query()
            ->plausible()
            ->whereBetween('local_date', [$range->start, $range->end])
            ->orderBy('started_at')
            ->get();

        $out = [];

        foreach ($workouts as $workout) {
            $out[$workout->local_date->toDateString()][] = [
                'type'        => $workout->type,
                'minutes'     => (int) round($workout->durationMinutes()),
                'distance_km' => $workout->distance_km === null ? null : round((float) $workout->distance_km, 2),
                'avg_hr_bpm'  => $workout->avg_hr,
            ];
        }

        return $out;
    }

    /**
     * Meal count and the clock time of the last one, per local date.
     *
     * CONFIRMED ONLY, for the same reason the daily rollup counts only
     * confirmed meals: an unagreed proposal is a guess about a photograph,
     * and treating it as intake would summarise food that may never have
     * been eaten.
     *
     * @return array<string, array{count: int, last_time: string|null}>
     */
    private function mealsByDate(ReportRange $range): array
    {
        $tz = (string) config('health.timezone');

        /** @var Collection<int, Meal> $meals */
        $meals = Meal::query()
            ->where('status', MealStatus::Confirmed->value)
            ->whereBetween('local_date', [$range->start, $range->end])
            ->orderBy('eaten_at')
            ->get(['id', 'eaten_at', 'local_date']);

        $out = [];

        foreach ($meals as $meal) {
            $date = $meal->local_date->toDateString();

            $out[$date] ??= ['count' => 0, 'last_time' => null];

            $out[$date]['count']++;
            // Ordered by `eaten_at` above, so the last write wins and is the
            // latest meal of the day.
            $out[$date]['last_time'] = $meal->eaten_at->setTimezone($tz)->format('H:i');
        }

        return $out;
    }

    /**
     * Which supplements were expected on each day, and which were ticked.
     *
     * "Expected" follows the same rule as SupplementAdherence, for the same
     * reason: a bottle bought Thursday must not read the preceding three
     * weeks as missed doses, so a supplement counts from its own
     * `created_at` onward and never before.
     *
     * @param  list<string>  $dates
     * @return array<string, array{taken: list<string>, expected: list<string>}>
     */
    private function supplementsByDate(ReportRange $range, array $dates): array
    {
        /** @var Collection<int, Supplement> $supplements */
        $supplements = Supplement::query()->active()->get();

        if ($supplements->isEmpty()) {
            return [];
        }

        $intakes = SupplementIntake::query()
            ->forSupplementsInRange($supplements, $range->start, $range->end)
            ->get()
            ->groupBy(fn (SupplementIntake $i): string => $i->local_date->toDateString());

        $out = [];

        foreach ($dates as $date) {
            $expected = $supplements->filter(
                static fn (Supplement $s): bool => $s->startedOn() <= $date
            );

            if ($expected->isEmpty()) {
                $out[$date] = ['taken' => [], 'expected' => []];

                continue;
            }

            $takenIds = ($intakes->get($date) ?? collect())->pluck('supplement_id')->all();

            $out[$date] = [
                'taken' => array_values($expected
                    ->filter(static fn (Supplement $s): bool => in_array($s->id, $takenIds, strict: true))
                    ->pluck('name')
                    ->all()),
                'expected' => array_values($expected->pluck('name')->all()),
            ];
        }

        return $out;
    }

    private function number(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 1);
    }
}
