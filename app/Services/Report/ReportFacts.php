<?php

declare(strict_types=1);

namespace App\Services\Report;

use App\Models\Profile;
use Carbon\CarbonImmutable;
use App\Enums\HealthReportKind;
use App\Services\Stress\Robust;
use App\Services\Rollup\IntakeBand;
use App\Services\Reporting\BodyTrend;
use App\Services\Reporting\WeightEma;
use App\Services\Stress\NotableMoment;
use App\Services\Stress\StressAnalysis;
use App\Services\Stress\RestingHeartRate;
use App\Services\Stress\StressCalculator;

/**
 * Everything the model is told, assembled before it is asked anything.
 *
 * The model does not do arithmetic: every number in a finished report is
 * computed here, in PHP, against the database, by tested code, and the model
 * is handed finished figures to read across and describe in prose — the one
 * thing it does better than a SQL query. A model asked to average scores in
 * prose produces a plausible, unchecked, occasionally wrong number, and a
 * wrong number in a health report is worse than no report because it looks
 * exactly like a right one. When the report cannot say something, that's
 * visible HERE first: a null in the snapshot is a fact the app doesn't have.
 *
 * The whole snapshot is stored (see the migration) because the tables
 * underneath keep moving — a late export lands, `stress:rebuild` re-scores a
 * trailing window, a meal is corrected days later — so a report is only
 * checkable against the facts it was actually written from.
 *
 * `StressCalculator::analyse` is called ONCE for the union of every window
 * anything here needs (the range, the drift block's year-ago window, the
 * eight-week trend), so the day table, stress summary, notable moments and
 * all three drift comparisons come from one read of one sample set — on this
 * user's history the widest case (30 days reaching back a year plus a
 * 60-day baseline) is around 6,000 rows, one indexed query.
 *
 * A focused report is not the full document with instructions to skip most
 * of it: blocks outside the focus are ABSENT — never queried, never encoded,
 * never sent (see ReportFocus, which owns the matrix and the ceilings this
 * buys). `food` and `micronutrients` are built lazily since each runs its
 * own queries over every meal/supplement in the window — building then
 * filtering would pay for a six-month food ledger before discarding it. The
 * HRV load shrinks with the focus too: the year-ago window exists only for
 * `baseline_drift`, so a focus without it queries a few hundred rows instead
 * of ~6,000. Never dropped, whatever the focus: `profile` (every suggestion
 * must be checked against an allergy), `coverage` (the honesty block —
 * without it a report could describe a fortnight from four days),
 * `anchor_statistics` (the only numeric comparison the model may make), and
 * the day table, slimmed to the columns the focus reads.
 */
final class ReportFacts
{
    public function __construct(
        private readonly StressCalculator $calculator = new StressCalculator,
        private readonly RestingHeartRate $resting = new RestingHeartRate,
        private readonly DayTable $days = new DayTable,
        private readonly FoodLedger $food = new FoodLedger,
        private readonly MicronutrientLedger $micronutrients = new MicronutrientLedger,
        private readonly WeightEma $weight = new WeightEma,
        private readonly BodyTrend $bodyTrend = new BodyTrend,
        private readonly TrainingLog $training = new TrainingLog,
        private readonly DayBuckets $buckets = new DayBuckets,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(
        ReportRange $range,
        HealthReportKind $kind,
        ?CarbonImmutable $today = null,
        ?ReportFocus $focus = null,
    ): array {
        $tz = (string) config('health.timezone');
        $today ??= CarbonImmutable::now($tz)->startOfDay();
        $focus ??= ReportFocus::everything();

        /** @var array<string, mixed> $driftConfig */
        $driftConfig = config('health.report.drift');

        $yearOffset = (int) ($driftConfig['year_offset_days'] ?? 365);
        $trendDays = (int) ($driftConfig['trend_days'] ?? 56);
        $restingDays = (int) ($driftConfig['resting_median_days'] ?? 365);

        $wantsDrift = $focus->hasBlock('baseline_drift');

        /*
         * The widest date anything downstream will ask about. `analyse()`
         * then reaches a further `baseline_days` behind whatever it's
         * given, so a day at the start of the year-ago window scores the
         * same as if the user browsed to it. Without the drift block it's
         * the range and nothing else: the year-ago window, eight-week
         * trend and resting median all exist to feed `baseline_drift`, and
         * loading a year of samples for a question about last fortnight's
         * food is a query nobody asked for.
         */
        $loadFrom = $wantsDrift
            ? min(
                $range->shifted($yearOffset)->start,
                CarbonImmutable::parse($range->end, $tz)->subDays(max($trendDays, $restingDays))->toDateString(),
                $range->start,
            )
            : $range->start;

        $analysis = $this->calculator->analyse($loadFrom, $range->end, $today);

        $restingByDate = $this->resting->between($loadFrom, $range->end);

        $dayRows = $this->days->build($range, $analysis, $restingByDate);

        /*
         * The blocks, as closures — lazy because `food` and `micronutrients`
         * each query every meal and supplement intake in the window, and a
         * six-month stress report that built then filtered them would have
         * saved the tokens and paid the database anyway.
         */
        $blocks = [
            'profile'           => fn (): array => $this->profile($range, $dayRows),
            'days'              => fn (): array => $this->dayTable($dayRows, $range, $focus),
            'energy'            => fn (): array => $this->energy($dayRows),
            'food'              => fn (): array => $this->food->build($range, $dayRows),
            'micronutrients'    => fn (): array => $this->micronutrients->build($range),
            'sleep'             => fn (): array => $this->sleep($dayRows),
            'activity'          => fn (): array => $this->activity($dayRows),
            'training'          => fn (): array => $this->training->build($range),
            'body'              => fn (): array => $this->body($range, $dayRows),
            'cardiovascular'    => fn (): array => $this->cardiovascular($dayRows, $analysis, $range),
            'stress'            => fn (): array => $this->stress($dayRows, $analysis, $range),
            'baseline_drift'    => fn (): array => BaselineDrift::measure($range, $analysis, $restingByDate, $today)->toArray(),
            'anchor_statistics' => fn (): array => [
                'why_these_exist' => 'Pre-computed cross-cuts of the day table. If you want to state a '
                    .'numeric comparison between groups of days, it must come from here. '
                    .'A group with fewer days than min_group_size has a null mean on purpose: '
                    .'say there were too few, do not estimate one.',
                'anchors' => AnchorStats::from($dayRows),
            ],
            'coverage' => fn (): array => $this->coverage($dayRows, $range, $focus),
        ];

        $assembled = [];

        foreach ($focus->blocks() as $name) {
            if (isset($blocks[$name])) {
                $assembled[$name] = $blocks[$name]();
            }
        }

        $howToRead = [
            'Every number here was computed by the application against its database. '
                .'You are being asked to read them, not to recompute them.',
            'Food figures are ranges because they are estimates from photographs and descriptions. '
                .'A range is the fact; its midpoint is not.',
            'Supplement figures are exact: transcribed from printed labels and multiplied by whole units.',
            'A null is not a zero. It means the app does not have that value for that day.',
            'Coverage figures qualify everything they sit next to. A total over four logged days out of '
                .'fourteen is a total over four days.',
        ];

        /*
         * One extra line, only when the day table was folded — a daily
         * report must not carry a sentence about buckets it doesn't have.
         * See DayBuckets::granularityFor for when this range is not daily.
         */
        $granularity = DayBuckets::granularityFor($range->days);

        if ($granularity !== DayBuckets::DAILY) {
            $howToRead[] = 'This range is long, so the day table below is grouped into '.$granularity
                .' periods rather than one row per day. Each entry summarises its period — the mean, median, '
                .'range and count of the days in it — and `n` is how many days a figure was actually measured '
                .'over. Compare the periods to say which stood out; the day-level cross-cuts are still in '
                .'anchor_statistics.';
        }

        return [
            /*
             * Exists so a stored snapshot can be read without guessing its
             * shape (3 was `training`, 2 was `profile`, 1 was before them).
             * 4 added `focus` and was the first version where a block can be
             * ABSENT — earlier bumps were purely additive, so a v4 reader
             * must know a missing `food` block means "not about food," not
             * "no food data yet." 5 is the first version where `days` has
             * two shapes: one row per date up to a quarter, folded into
             * weekly/monthly period buckets beyond it (see DayBuckets), with
             * `range.day_granularity` saying which — a v5 reader must not
             * assume the daily shape v4 always had.
             */
            'schema_version' => 5,

            'range' => [
                'start'        => $range->start,
                'end'          => $range->end,
                'days'         => $range->days,
                'kind'         => $kind->value,
                'timezone'     => $tz,
                'assembled_at' => CarbonImmutable::now($tz)->toIso8601String(),
                'covers_today' => $range->end === $today->toDateString(),

                // How the `days` block below is delivered: `daily` is one row
                // per date, `weekly`/`monthly` one pre-computed bucket per
                // period. Stated here, at the top, so a "row" with a
                // `period` and a mean isn't read as a day.
                'day_granularity' => DayBuckets::granularityFor($range->days),
            ],

            // The first thing the prompt reads, so every figure below is
            // read in the right register — data rather than prose because
            // it's ABOUT THIS SNAPSHOT (a window with no weigh-ins reads
            // differently from one with seven).
            'how_to_read_these_facts' => $howToRead,

            /*
             * What was asked for, above the facts that answer it: the model
             * reads top to bottom, and a `focus` block arriving after the
             * day table would be a footnote explaining an already-read
             * document. Present even with no focus, since the absence of a
             * focus block and one saying "everything" are the same document
             * only if one of them is always written.
             */
            'focus' => $focus->toSnapshot(),

            ...$assembled,
        ];
    }

    /**
     * The `days` block: the slimmed day table, folded into period buckets
     * when the range is too long for a row per day to be the right shape.
     *
     * Slimming happens before folding, so a monthly bucket only summarises
     * columns the focus kept and the fold never re-widens a narrowed
     * report. Under a quarter the fold is a no-op; beyond it, DayBuckets
     * turns rows into weekly or monthly summaries — see DayBuckets for why
     * a year is a different shape, and range.day_granularity for how the
     * choice is announced.
     *
     * @param  list<array<string, mixed>>  $dayRows
     * @return list<array<string, mixed>>
     */
    private function dayTable(array $dayRows, ReportRange $range, ReportFocus $focus): array
    {
        $slim = $this->slimDays($dayRows, $focus);

        $granularity = DayBuckets::granularityFor($range->days);

        return $granularity === DayBuckets::DAILY
            ? $slim
            : $this->buckets->build($slim, $granularity);
    }

    /**
     * The day table, cut to the columns this focus actually reads.
     *
     * The day table is the single biggest block in an unfocused report —
     * one row per day, twenty-eight columns wide — so it can never be
     * dropped outright; slimming is the compromise, making a six-month
     * stress report 183 rows of eleven columns rather than twenty-eight,
     * roughly the difference between fitting and not.
     *
     * Slimmed here rather than in DayTable, deliberately: DayTable's job is
     * one honest row per day from half a dozen sources with source-priority
     * rules applied, and a version building a different row shape per focus
     * would be a second shape to keep right, disagreeing with the first on
     * the day it mattered.
     *
     * @param  list<array<string, mixed>>  $dayRows
     * @return list<array<string, mixed>>
     */
    private function slimDays(array $dayRows, ReportFocus $focus): array
    {
        if ($focus->isEverything()) {
            return $dayRows;
        }

        $keep = array_flip($focus->dayColumns());

        return array_map(
            static fn (array $row): array => array_intersect_key($row, $keep),
            $dayRows,
        );
    }

    /**
     * Who this report is about — and nothing the app was not told.
     *
     * Everything else in this snapshot is a measurement; this is the only
     * part that is testimony, existing because the report writes
     * nutritional suggestions to somebody it knew nothing about. Reference
     * intakes are keyed on sex and age and move again on pregnancy or
     * breastfeeding; vitamin D synthesis depends on skin tone, latitude and
     * season (negligible at this app's latitude for half the year); and
     * "eat more oily fish" is useless to a vegetarian and unsafe for a fish
     * allergy. It also changes what an existing number MEANS: `energy.balance`
     * was always stated neutrally (burn minus intake) because the app
     * couldn't tell whether a deficit was the plan — with a goal it can, and
     * the same measurement becomes progress, drift, or a stalled week.
     *
     * Same rules as every other block: a field is null unless the user
     * filled it in, nothing is inferred or defaulted, and the two computed
     * figures (age, BMI) exist only when every input to them exists. The
     * arithmetic is done HERE for the same reason all of it is — the model
     * gets whole years and a signed distance to target weight, not a birth
     * date and a subtraction to perform.
     *
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function profile(ReportRange $range, array $dayRows): array
    {
        $profile = Profile::current();

        /*
         * The LAST weigh-in in the window, not the first and not the EMA:
         * BMI is a statement about a body now, and the EMA smooths every
         * reading ever taken, so a BMI off it would match no day the user
         * actually stood on the scale.
         */
        /** @var array{date: string, kg: float}|null $latestWeight */
        $latestWeight = null;

        foreach ($dayRows as $row) {
            if ($row['weight_kg'] !== null) {
                $latestWeight = [
                    'date' => (string) $row['date'],
                    'kg'   => round((float) $row['weight_kg'], 2),
                ];
            }
        }

        $weightKg = $latestWeight === null ? null : $latestWeight['kg'];

        $bmi = $profile->bmiFor($weightKg);

        return [
            'what_this_is' => 'What the user has told the app about themselves, on the profile page. '
                .'It is the only part of this document that is testimony rather than measurement. '
                .'A null is a question they have not answered, and it must not be guessed at.',

            'is_empty' => $profile->isBlank(),

            'if_it_is_empty' => 'Say once, plainly, that these suggestions are framed generally because the app '
                .'does not know their age, sex, diet or goals, and that the profile page is where that gets '
                .'filled in. Then write the rest of the report exactly as you otherwise would.',

            'about' => [
                'age_years' => $profile->ageOn($range->end),
                'age_note'  => 'Completed years on the last day of this range, computed by the application.',

                'sex'      => $profile->sex,
                'sex_note' => 'Recorded for nutrition reference intakes, which differ by it. Free text — use the '
                    .'word they chose.',

                'height_cm' => $profile->heightCm(),
                'ethnicity' => $profile->ethnicity,
                'country'   => $profile->country,

                'bmi'               => $bmi,
                'bmi_computed_from' => $bmi === null || $latestWeight === null ? null : [
                    'height_cm'  => $profile->heightCm(),
                    'weight_kg'  => $latestWeight['kg'],
                    'weighed_on' => $latestWeight['date'],
                ],
                'bmi_note' => 'Null unless a height AND a weigh-in inside this range both exist. It is a rough '
                    .'screening figure that says nothing about body composition, and its cut-offs were derived '
                    .'on European populations.',
            ],

            'goal' => [
                'goal'                   => $profile->goal,
                'notes'                  => $profile->goal_notes,
                'target_weight_kg'       => $profile->targetWeightKg(),
                'latest_weight_in_range' => $latestWeight,
                'kg_from_target'         => $profile->kgFromTarget($weightKg),
                'sign_convention'        => 'kg_from_target is the latest weigh-in minus the target: positive is above '
                    .'it, negative is below it.',
                'note' => 'This is what makes the energy balance readable as progress or as drift. With no goal '
                    .'set, report the balance neutrally, exactly as before.',
            ],

            'diet' => [
                'preferences'                => $profile->dietary_preferences,
                'allergies_and_intolerances' => $profile->allergies_intolerances,
                'note'                       => 'A preference is a choice; an intolerance or allergy is a constraint. Neither is a '
                    .'food to suggest around — never suggest something ruled out here.',
            ],

            'lifestyle' => [
                'smoking'          => $profile->smoking,
                'alcohol'          => $profile->alcohol,
                'sun_exposure'     => $profile->sun_exposure,
                'activity_context' => $profile->activity_context,
                'note'             => 'Context for patterns the watch can see but not explain. Not a subject for advice.',
            ],

            'health_context' => [
                'life_stage'   => $profile->life_stage,
                'health_notes' => $profile->health_notes,
                'note'         => 'Volunteered so the report can be aware of it. It does not licence anything the '
                    .'instructions forbid: no dose, no diagnosis, no test, and nothing that contradicts a '
                    .'clinician who has actually examined them.',
            ],
        ];
    }

    /**
     * Intake against expenditure over the window, with the honesty flags that
     * decide whether the difference means anything.
     *
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function energy(array $dayRows): array
    {
        $inRanges = [];
        $out = [];
        $bothDays = [];

        foreach ($dayRows as $row) {
            if ($row['kcal_in_min'] !== null && $row['kcal_in_max'] !== null) {
                $inRanges[] = ['min' => (float) $row['kcal_in_min'], 'max' => (float) $row['kcal_in_max']];
            }

            if ($row['kcal_out'] !== null) {
                $out[] = (float) $row['kcal_out'];
            }

            /*
             * A balance only exists on a day with both halves, both
             * trustworthy: an incomplete food log looks like a deficit, a
             * watch off the wrist looks like a surplus, and averaging
             * either in produces a number confidently wrong in a known
             * direction — the failure mode `daily_summaries`'s flags
             * already exist to prevent, so the same flags gate it here.
             */
            if (
                $row['kcal_in_mid'] !== null
                && $row['kcal_out'] !== null
                && ($row['food_log_complete'] ?? false)
                && ! ($row['active_kcal_is_partial'] ?? false)
            ) {
                $bothDays[] = [
                    'date'   => $row['date'],
                    'in_mid' => (float) $row['kcal_in_mid'],
                    'in_min' => (float) $row['kcal_in_min'],
                    'in_max' => (float) $row['kcal_in_max'],
                    'out'    => (float) $row['kcal_out'],
                ];
            }
        }

        $balanceBand = IntakeBand::fromRanges(array_map(
            static fn (array $d): array => [
                // Burn minus intake (negative is a surplus), signed the
                // same way App\Services\Reporting\EnergyBalance does — two
                // conventions in one codebase is one being read wrong.
                'min' => $d['out'] - $d['in_max'],
                'max' => $d['out'] - $d['in_min'],
            ],
            $bothDays,
        ));

        return [
            'intake_kcal_window_total' => IntakeBand::fromRanges($inRanges)->toArray(0),
            'intake_days_contributing' => count($inRanges),

            'expenditure_kcal_window_total' => $out === [] ? null : round(array_sum($out)),
            'expenditure_days_contributing' => count($out),
            'expenditure_mean_per_day'      => $out === [] ? null : round(array_sum($out) / count($out)),

            'balance' => [
                'sign_convention'                        => 'burn minus intake: positive is a deficit, negative is a surplus',
                'days_where_both_halves_are_trustworthy' => count($bothDays),
                'window_total'                           => $balanceBand->toArray(0),
                'mean_per_day'                           => $bothDays === [] ? null : [
                    'min' => round(((float) $balanceBand->min) / count($bothDays)),
                    'mid' => round(((float) $balanceBand->mid) / count($bothDays)),
                    'max' => round(((float) $balanceBand->max) / count($bothDays)),
                ],
                'days_used' => array_column($bothDays, 'date'),
                'gate'      => 'Only days flagged as a complete food log AND without partial watch coverage '
                    .'are used. An incomplete log looks like a deficit; a watch off the wrist looks like '
                    .'a surplus.',
            ],
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function sleep(array $dayRows): array
    {
        $hours = [];
        $short = [];
        $awake = [];

        $shortThreshold = (float) config('health.report.anchors.short_sleep_hours', 6.0);

        foreach ($dayRows as $row) {
            if ($row['sleep_hours'] === null) {
                continue;
            }

            $value = (float) $row['sleep_hours'];

            $hours[] = $value;

            if ($value < $shortThreshold) {
                $short[] = $row['date'];
            }

            if ($row['sleep_awake_minutes'] !== null) {
                $awake[] = (float) $row['sleep_awake_minutes'];
            }
        }

        return [
            'note' => 'Sleep on a given row is the night BEFORE that day — the session Health Auto Export '
                .'keys to the morning of that date.',
            'nights_with_data'            => count($hours),
            'nights_in_range'             => count($dayRows),
            'mean_hours'                  => $hours === [] ? null : round(array_sum($hours) / count($hours), 2),
            'median_hours'                => $hours === [] ? null : round((float) Robust::median($hours), 2),
            'shortest_hours'              => $hours === [] ? null : round(min($hours), 2),
            'longest_hours'               => $hours === [] ? null : round(max($hours), 2),
            'nights_under_threshold'      => count($short),
            'short_night_threshold_hours' => $shortThreshold,
            'short_night_dates'           => $short,
            'mean_awake_minutes'          => $awake === [] ? null : round(array_sum($awake) / count($awake)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function activity(array $dayRows): array
    {
        $steps = [];
        $exercise = [];
        $distance = [];

        foreach ($dayRows as $row) {
            if ($row['steps'] !== null) {
                $steps[] = (float) $row['steps'];
            }

            if ($row['exercise_minutes'] !== null) {
                $exercise[] = (float) $row['exercise_minutes'];
            }

            if ($row['distance_km'] !== null) {
                $distance[] = (float) $row['distance_km'];
            }
        }

        return [
            'what_this_is' => 'What the watch MEASURED, with no idea what produced it. What the person '
                .'actually did is in the `training` block.',
            'steps'                                 => Series::summarise($steps, 0),
            'exercise_minutes'                      => Series::summarise($exercise, 0),
            'distance_km'                           => Series::summarise($distance, 1),
            'days_with_30_or_more_exercise_minutes' => count(array_filter($exercise, static fn (float $m): bool => $m >= 30)),
        ];
    }

    /**
     * Weight, and the trend line the trends page already draws.
     *
     * The EMA is read from the SAME service the trends page uses rather
     * than recomputed here, so the report number and the chart number are
     * one calculation — two smoothings of one series is one being wrong.
     *
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function body(ReportRange $range, array $dayRows): array
    {
        $weighIns = [];

        foreach ($dayRows as $row) {
            if ($row['weight_kg'] !== null) {
                $weighIns[] = ['date' => $row['date'], 'kg' => (float) $row['weight_kg']];
            }
        }

        $ema = $this->weight->series();

        $inRange = array_filter(
            $ema,
            static fn (string $date): bool => $date >= $range->start && $date <= $range->end,
            ARRAY_FILTER_USE_KEY,
        );

        $first = $inRange === [] ? null : reset($inRange);
        $last = $inRange === [] ? null : end($inRange);

        // Computed here, not in the literal below, so "both ends exist" is
        // asked once and the subtraction is guarded by the answer.
        $change = $first === null || $last === null ? null : round($last - $first, 2);

        return [
            'weigh_ins'      => $weighIns,
            'weigh_in_count' => count($weighIns),
            'days_in_range'  => $range->days,

            'ema' => [
                'what_it_is' => 'Exponentially weighted moving average of every weigh-in ever recorded, '
                    .'evaluated on the days inside this range. Daily weight swings by about a kilo on '
                    .'water alone, so the trend line is the signal and a single reading is not.',
                'alpha'             => (float) config('health.trends.ema_alpha', 0.25),
                'first_in_range_kg' => $first === null ? null : round($first, 2),
                'last_in_range_kg'  => $last === null ? null : round($last, 2),
                'change_kg'         => $change,
                'points_in_range'   => count($inRange),
            ],

            // What the scale measures besides weight: muscle mass and body
            // fat, unmentioned until now for lack of a goal to read them
            // against. An athletic goal is answered by these two series —
            // muscle rising against a flat weight is a good week a
            // weight-only report would have called nothing at all.
            'composition' => $this->composition($range),
        ];
    }

    /**
     * Muscle mass and body fat over the window, one reading per day.
     *
     * Read through BodyTrend rather than queried here — the scale reports
     * every weigh-in twice (once from the Fitage app, once from its
     * export), so "which reading is the day's reading" is the rollup's own
     * source-priority decision. A second query here would eventually pick
     * the other one, and the trends chart and report would disagree about
     * the same morning while both looked right.
     *
     * @return array<string, mixed>
     */
    private function composition(ReportRange $range): array
    {
        $picked = $this->bodyTrend->pickedDaily(['muscle_mass', 'body_fat_percentage']);

        return [
            'what_it_is' => 'What the scale measures besides the weight, one reading per day, chosen by the '
                .'same source-priority rule the daily rollup uses for weight — so a muscle-mass point '
                .'belongs to the same weigh-in as the weight of that morning.',

            'muscle_mass' => [
                'unit' => 'kg',
                ...$this->compositionSeries($picked['muscle_mass'] ?? [], $range, 2),
            ],

            'body_fat' => [
                'unit' => '%',
                ...$this->compositionSeries($picked['body_fat_percentage'] ?? [], $range, 1),
            ],

            'note' => 'Read these WITH the weight above rather than instead of it. Body-composition figures '
                .'from a consumer bioimpedance scale are a trend worth watching and not a measurement worth '
                .'quoting to the gram — a change across two readings a fortnight apart is well inside what '
                .'hydration alone moves.',
        ];
    }

    /**
     * One composition series, cut to the window.
     *
     * @param  array<string, float>  $byDate
     * @return array<string, mixed>
     */
    private function compositionSeries(array $byDate, ReportRange $range, int $precision): array
    {
        $inRange = array_filter(
            $byDate,
            static fn (string $date): bool => $date >= $range->start && $date <= $range->end,
            ARRAY_FILTER_USE_KEY,
        );

        ksort($inRange);

        $readings = [];

        foreach ($inRange as $date => $value) {
            $readings[] = ['date' => $date, 'value' => round($value, $precision)];
        }

        $first = $readings === [] ? null : $readings[0]['value'];
        $last = $readings === [] ? null : $readings[array_key_last($readings)]['value'];

        return [
            'n'              => count($readings),
            'readings'       => $readings,
            'first_in_range' => $first,
            'last_in_range'  => $last,

            // Two readings, or no change — one reading says nothing about a
            // direction, and inventing one would defeat this block's purpose.
            'change' => count($readings) < 2 || $first === null || $last === null
                ? null
                : round($last - $first, $precision),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function cardiovascular(array $dayRows, StressAnalysis $analysis, ReportRange $range): array
    {
        $resting = [];
        $hrv = [];

        foreach ($dayRows as $row) {
            if ($row['resting_hr_bpm'] !== null) {
                $resting[] = (float) $row['resting_hr_bpm'];
            }

            if ($row['hrv_ms'] !== null) {
                $hrv[] = (float) $row['hrv_ms'];
            }
        }

        return [
            'resting_heart_rate_bpm' => Series::summarise($resting, 0),
            'hrv_ms'                 => Series::summarise($hrv, 1),
            'hrv_note'               => 'HRV here is the day median of the app\'s deseasonalised readings — the time-of-day '
                .'shape has already been removed, so two days are comparable even if the watch was worn at '
                .'different hours.',
            'levels_in_range' => count($analysis->levelsBetween($range->start, $range->end)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function stress(array $dayRows, StressAnalysis $analysis, ReportRange $range): array
    {
        $scores = [];
        $bands = [];
        $unscored = [];

        foreach ($dayRows as $row) {
            if ($row['stress_score'] === null) {
                $unscored[] = ['date' => $row['date'], 'reason' => $row['stress_unscored_reason']];

                continue;
            }

            $scores[] = (float) $row['stress_score'];
            $bands[(string) $row['stress_band']] = ($bands[(string) $row['stress_band']] ?? 0) + 1;
        }

        $moments = $analysis->moments($range->start, $range->end);

        return [
            'what_the_score_is' => 'A 1-99 score per day, higher meaning less stress. It is the day\'s own '
                .'deseasonalised HRV compared with the median of the 60 days behind it. It is RELATIVE by '
                .'construction: it cannot see slow drift, which is what the baseline_drift block is for.',
            'scored_days'   => count($scores),
            'unscored_days' => $unscored,
            'score'         => Series::summarise($scores, 0),
            'band_counts'   => $bands,

            /*
             * The hour-level excursions of the window, from the same analysis
             * the stress page's digest uses. NULL and [] mean different things
             * and both are passed through unchanged: null is "there is not
             * enough history behind this window to know what unusual would
             * be", and [] is "there is, and nothing was".
             */
            'notable_moments' => $moments === null
                ? null
                : array_map(static fn (NotableMoment $m): array => $m->toArray(), $moments),
            'notable_moments_note' => $moments === null
                ? 'Not enough history behind this window to say what an unusual reading would be.'
                : 'Individual HRV readings that were a long way from usual for that hour of the day.',
        ];
    }

    /**
     * The honesty block: how much of the window each input actually
     * covered.
     *
     * Assembled last and kept together, because it's what the report's
     * `data_gaps` section is written from — a gaps section built by
     * scanning for nulls would report the same absence three different
     * ways. It's cut to the focus too, and that's not a token saving: a
     * stress report carrying "food logged on 0 of 182 days" hands the
     * model an accurate figure about a block that was never assembled,
     * inviting the false sentence "you did not log any food this
     * half-year" about someone who logs most days. The prompt forbids that
     * sentence; not putting the figure in front of it is the cheaper half
     * of the same defence, and the one that doesn't depend on being obeyed.
     * Three lines survive every focus: the denominator, whether the watch
     * had the day, and the reminder that all of this qualifies something.
     *
     * @param  list<array<string, mixed>>  $dayRows
     * @return array<string, mixed>
     */
    private function coverage(array $dayRows, ReportRange $range, ReportFocus $focus): array
    {
        $count = static fn (callable $predicate): int => count(array_filter($dayRows, $predicate));

        $days = max(1, $range->days);

        $withFood = $count(static fn (array $r): bool => ($r['meals_logged'] ?? 0) > 0);
        $completeLogs = $count(static fn (array $r): bool => ($r['food_log_complete'] ?? false) === true);
        $withStress = $count(static fn (array $r): bool => $r['stress_score'] !== null);
        $withSleep = $count(static fn (array $r): bool => $r['sleep_hours'] !== null);
        $withWeight = $count(static fn (array $r): bool => $r['weight_kg'] !== null);
        $withSteps = $count(static fn (array $r): bool => $r['steps'] !== null);
        $withResting = $count(static fn (array $r): bool => $r['resting_hr_bpm'] !== null);
        $partialWatch = $count(static fn (array $r): bool => ($r['active_kcal_is_partial'] ?? false) === true);
        $fullMetrics = $count(static fn (array $r): bool => ($r['metric_coverage_full'] ?? false) === true);

        $pct = static fn (int $n): float => round($n / $days * 100, 1);

        // Each line, with the block whose presence makes it worth stating.
        $lines = [
            'food_logged_days'       => ['food', ['n' => $withFood, 'pct' => $pct($withFood)]],
            'complete_food_log_days' => ['food', ['n' => $completeLogs, 'pct' => $pct($completeLogs)]],
            'stress_scored_days'     => ['stress', ['n' => $withStress, 'pct' => $pct($withStress)]],
            'sleep_nights'           => ['sleep', ['n' => $withSleep, 'pct' => $pct($withSleep)]],
            'weigh_ins'              => ['body', ['n' => $withWeight, 'pct' => $pct($withWeight)]],
            'step_days'              => ['activity', ['n' => $withSteps, 'pct' => $pct($withSteps)]],
            'resting_hr_days'        => ['cardiovascular', ['n' => $withResting, 'pct' => $pct($withResting)]],
        ];

        $coverage = ['days_in_range' => $range->days];

        foreach ($lines as $key => [$block, $value]) {
            if ($focus->hasBlock($block)) {
                $coverage[$key] = $value;
            }
        }

        // Always: the watch either had the day or it did not, and that qualifies
        // every measurement on the row whatever the report is about.
        $coverage['days_with_full_metric_coverage'] = ['n' => $fullMetrics, 'pct' => $pct($fullMetrics)];
        $coverage['days_with_partial_watch_energy'] = ['n' => $partialWatch, 'pct' => $pct($partialWatch)];

        $coverage['reminder'] = 'Each figure above is a denominator for something in this report. '
            .'Any claim about a trend or a habit has to be qualified by the coverage behind it.';

        if (! $focus->isEverything()) {
            $coverage['why_some_lines_are_missing'] = 'This report is focused, so coverage is reported only for '
                .'the areas it covers. A line that is not here was not measured for this report and is NOT a gap '
                .'in what the person logged — do not describe it as one.';
        }

        return $coverage;
    }
}
