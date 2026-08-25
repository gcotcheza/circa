<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\Source;
use App\Models\Profile;
use App\Models\MealItem;
use App\Enums\MealStatus;
use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Models\HealthMetric;
use App\Models\SleepSession;
use App\Enums\HealthReportKind;
use App\Models\SupplementIntake;
use Tests\Support\StressFixture;
use App\Models\SupplementNutrient;
use App\Services\Report\ReportFacts;
use App\Services\Report\ReportRange;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The fact sheet, built from a week whose answer is known before the code runs.
 *
 * Synthetic for the reason StressFixture gives: asserting against the three
 * years of real data here could only prove today's code agrees with yesterday's.
 * Every figure is arithmetic somebody can do on paper — three short nights and
 * three long ones, a known kcal band on four days of seven, two supplements with
 * printed amounts, one weigh-in.
 *
 * It matters most here because the feature rests on a promise: the model does no
 * arithmetic, so every number in a report comes from this snapshot, and a wrong
 * figure here is a wrong figure in a health report with a model's name on it.
 */
final class ReportFactsTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-10';

    private const START = '2026-08-03';

    private const END = '2026-08-09';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 04:10:00', StressFixture::TZ));
    }

    public function test_the_day_table_has_one_row_per_day_in_order(): void
    {
        $snapshot = $this->assemble();

        self::assertCount(7, $snapshot['days']);
        self::assertSame(self::START, $snapshot['days'][0]['date']);
        self::assertSame(self::END, $snapshot['days'][6]['date']);
        self::assertSame('Mon', $snapshot['days'][0]['weekday']);
    }

    /**
     * `sleep_sessions.night_date` is the morning you woke on, so a row's sleep is
     * the night BEFORE it. Backwards inverts every sleep-and-stress observation.
     */
    public function test_a_rows_sleep_is_the_night_that_preceded_that_day(): void
    {
        $this->night('2026-08-05', 5.0);
        $this->night('2026-08-06', 8.5);

        $days = collect($this->asArray($this->assemble()['days']))->keyBy('date');

        self::assertSame(5.0, $days['2026-08-05']['sleep_hours']);
        self::assertSame(8.5, $days['2026-08-06']['sleep_hours']);
    }

    /** Watch and AutoSleep both report the same night; summing gives sixteen hours. */
    public function test_two_sources_for_one_night_resolve_to_one_session(): void
    {
        $this->night('2026-08-05', 7.0);

        SleepSession::factory()->create([
            'night_date'          => '2026-08-05',
            'total_sleep_minutes' => 6.0 * 60,
            'source_id'           => Source::query()->firstOrCreate(
                ['raw_name' => 'AutoSleep'],
                ['name' => 'AutoSleep', 'slug' => Source::slugFor('AutoSleep'), 'device_kind' => Source::kindFor('AutoSleep')],
            )->id,
        ]);

        $days = collect($this->asArray($this->assemble()['days']))->keyBy('date');

        self::assertContains($days['2026-08-05']['sleep_hours'], [7.0, 6.0]);
        self::assertLessThan(10.0, (float) $days['2026-08-05']['sleep_hours']);
    }

    /** Only confirmed meals count, as in the daily rollup: a proposal is a guess about a photograph. */
    public function test_only_confirmed_meals_reach_the_table_and_the_food_ledger(): void
    {
        $this->confirmedMeal('2026-08-05 19:30', [['White rice', 150, 200, 130, 150]]);

        // A proposal on the same day, never tapped.
        $proposed = Meal::factory()->create([
            'eaten_at' => CarbonImmutable::parse('2026-08-05 21:00', StressFixture::TZ)->utc(),
            'status'   => MealStatus::Proposed,
        ]);
        MealItem::factory()->for($proposed)->create(['name' => 'Chocolate', 'confirmed_at' => null]);

        $snapshot = $this->assemble();

        $days = collect($this->asArray($snapshot['days']))->keyBy('date');

        self::assertSame(1, $days['2026-08-05']['meals_logged']);
        self::assertSame('19:30', $days['2026-08-05']['last_meal_local_time']);

        $names = array_column($snapshot['food']['foods'], 'name');

        self::assertContains('White rice', $names);
        self::assertNotContains('Chocolate', $names);
    }

    public function test_the_last_meal_time_is_the_latest_one_of_the_day(): void
    {
        $this->confirmedMeal('2026-08-05 08:15', [['Porridge', 200, 250, 60, 80]]);
        $this->confirmedMeal('2026-08-05 22:40', [['Toast', 60, 80, 250, 300]]);

        $days = collect($this->asArray($this->assemble()['days']))->keyBy('date');

        self::assertSame(2, $days['2026-08-05']['meals_logged']);
        self::assertSame('22:40', $days['2026-08-05']['last_meal_local_time']);
    }

    /**
     * The window band combines in quadrature, not linearly — the daily rollup's
     * own arithmetic, so a window total and seven day totals cannot disagree.
     */
    public function test_the_window_intake_band_combines_half_widths_in_quadrature(): void
    {
        // Four days, each 1 000-1 400 kcal: mid 1 200, half-width 200.
        foreach (['2026-08-03', '2026-08-04', '2026-08-05', '2026-08-06'] as $date) {
            DailySummary::factory()->onDate($date)->create([
                'kcal_in_min' => 1000,
                'kcal_in_mid' => 1200,
                'kcal_in_max' => 1400,
            ]);
        }

        $totals = $this->assemble()['food']['window_totals']['kcal_in'];

        self::assertSame(4800.0, $totals['mid']);

        // sqrt(4 x 200^2) = 400, not 4 x 200 = 800.
        self::assertSame(4400.0, $totals['min']);
        self::assertSame(5200.0, $totals['max']);
    }

    /** The divisor is stated because a mean over four logged days is not one over seven. */
    public function test_the_per_day_mean_divides_by_logged_days_and_says_so(): void
    {
        /*
         * No hand-written summaries: creating a meal fires the observer that
         * rebuilds the day's `daily_summaries` row, so one written here would be
         * overwritten mid-test and the test would pin a state the app cannot be
         * in. 300-400 g at 200-250 kcal/100 g is 600-1 000 kcal: mid 800.
         */
        foreach (['2026-08-03', '2026-08-04'] as $date) {
            $this->confirmedMeal($date.' 19:00', [['Dinner', 300, 400, 200, 250]]);
        }

        $means = $this->assemble()['food']['per_day_means'];

        self::assertSame(2, $means['divisor_days']);
        self::assertSame('days with at least one logged meal', $means['divisor']);
        self::assertSame(800.0, $means['kcal_in']['mid']);

        // Five days had no food: the mean is over two, not seven.
        self::assertSame(2, $this->assemble()['food']['coverage']['days_with_any_logged_meal']);
    }

    /**
     * The balance gate: an incomplete food log looks like a deficit and a watch
     * off the wrist like a surplus. Both excluded, and the survivors counted.
     */
    public function test_the_energy_balance_only_counts_days_where_both_halves_are_trustworthy(): void
    {
        DailySummary::factory()->onDate('2026-08-03')->create([
            'kcal_in_min'     => 1900, 'kcal_in_mid' => 2000, 'kcal_in_max' => 2100,
            'active_kcal'     => 500, 'resting_kcal' => 1500,
            'is_complete_log' => true, 'active_kcal_is_partial' => false,
        ]);

        // Incomplete log — excluded.
        DailySummary::factory()->onDate('2026-08-04')->create([
            'kcal_in_min'     => 400, 'kcal_in_mid' => 500, 'kcal_in_max' => 600,
            'active_kcal'     => 500, 'resting_kcal' => 1500,
            'is_complete_log' => false, 'active_kcal_is_partial' => false,
        ]);

        // Watch off the wrist — excluded.
        DailySummary::factory()->onDate('2026-08-05')->create([
            'kcal_in_min'     => 1900, 'kcal_in_mid' => 2000, 'kcal_in_max' => 2100,
            'active_kcal'     => 90, 'resting_kcal' => 1500,
            'is_complete_log' => true, 'active_kcal_is_partial' => true,
        ]);

        $balance = $this->assemble()['energy']['balance'];

        self::assertSame(1, $balance['days_where_both_halves_are_trustworthy']);
        self::assertSame(['2026-08-03'], $balance['days_used']);

        // burn 2 000 minus intake 1 900-2 100 => a band from -100 to +100.
        self::assertSame(0.0, $balance['window_total']['mid']);
        self::assertStringContainsString('positive is a deficit', $balance['sign_convention']);
    }

    /** The one exact thing in the report: label figure x units per day x days ticked. */
    public function test_supplement_nutrients_are_exact_and_multiplied_by_days_actually_taken(): void
    {
        $supplement = Supplement::factory()->taking(2)->create([
            'name'       => 'Magnesium Glycinate-120',
            'created_at' => CarbonImmutable::parse('2026-07-01 12:00', StressFixture::TZ)->utc(),
        ]);

        SupplementNutrient::factory()->for($supplement)->create([
            'nutrient' => 'Magnesium (glycinate)',
            'amount'   => 120,
            'unit'     => 'mg',
        ]);

        foreach (['2026-08-03', '2026-08-04', '2026-08-05'] as $date) {
            SupplementIntake::factory()->for($supplement)->create(['local_date' => $date]);
        }

        $nutrients = $this->assemble()['micronutrients']['nutrients'];

        $magnesium = collect($this->asArray($nutrients))->firstWhere('nutrient', 'Magnesium (glycinate)');

        self::assertNotNull($magnesium);
        self::assertSame('mg', $magnesium['unit']);
        // 120 mg per capsule x 2 capsules a day.
        self::assertSame(240.0, $magnesium['per_day_if_all_taken']);
        // ...on 3 of the 7 days.
        self::assertSame(720.0, $magnesium['window_total_exact']);

        // The food side is stated as unknown rather than read as zero.
        self::assertSame(0, $magnesium['food_coverage_pct']);
        self::assertStringContainsString('does not estimate this nutrient from food', $magnesium['food_note']);
    }

    /**
     * One nutrient in two units is two entries. Converting is what the label
     * prompt forbids, and what makes a figure uncheckable against the bottle.
     */
    public function test_the_same_nutrient_in_two_units_is_not_summed(): void
    {
        $d3 = Supplement::factory()->create([
            'name'       => 'Vitamine D3',
            'created_at' => CarbonImmutable::parse('2026-07-01 12:00', StressFixture::TZ)->utc(),
        ]);

        SupplementNutrient::factory()->for($d3)->create(['nutrient' => 'Vitamine D3', 'amount' => 75, 'unit' => 'mcg']);
        SupplementNutrient::factory()->for($d3)->create(['nutrient' => 'Vitamine D3', 'amount' => 3000, 'unit' => 'IE']);

        SupplementIntake::factory()->for($d3)->create(['local_date' => '2026-08-03']);

        $rows = collect($this->asArray($this->assemble()['micronutrients']['nutrients']))
            ->where('nutrient', 'Vitamine D3')
            ->values();

        self::assertCount(2, $rows);
        self::assertSame(['IE', 'mcg'], $rows->pluck('unit')->sort()->values()->all());
    }

    /** A bottle bought on Thursday must not make the weeks before it read as missed doses. */
    public function test_a_supplement_is_only_expected_from_its_own_start_date(): void
    {
        $supplement = Supplement::factory()->create([
            'name'       => 'Omega-3',
            'created_at' => CarbonImmutable::parse('2026-08-07 10:00', StressFixture::TZ)->utc(),
        ]);

        $entry = collect($this->asArray($this->assemble()['micronutrients']['supplements']))->firstWhere('name', 'Omega-3');

        self::assertSame('2026-08-07', $entry['started_on']);
        // 7, 8 and 9 August only.
        self::assertSame(3, $entry['days_expected_in_range']);
    }

    /** `data_gaps` is written from here, and every figure is a denominator elsewhere. */
    public function test_the_coverage_block_counts_what_each_input_actually_covered(): void
    {
        $this->confirmedMeal('2026-08-03 19:00', [['Dinner', 300, 400, 200, 250]]);
        $this->confirmedMeal('2026-08-04 19:00', [['Dinner', 300, 400, 200, 250]]);

        $this->night('2026-08-03', 7.0);

        DailySummary::factory()->onDate('2026-08-05')->withWeighIn(56.2)->create();

        $coverage = $this->assemble()['coverage'];

        self::assertSame(7, $coverage['days_in_range']);
        self::assertSame(2, $coverage['food_logged_days']['n']);
        self::assertEqualsWithDelta(28.6, $coverage['food_logged_days']['pct'], 0.1);
        self::assertSame(1, $coverage['sleep_nights']['n']);
        self::assertSame(1, $coverage['weigh_ins']['n']);
    }

    /** How to read everything below it — data rather than prompt prose, because it is about THIS snapshot. */
    public function test_the_snapshot_carries_its_own_reading_instructions(): void
    {
        $snapshot = $this->assemble();

        // 5 since the day table can fold into period buckets (4 was the focus
        // block, 3 training, 2 profile). A new block, or a block with a second
        // shape, is a different shape, and a stored snapshot must say which.
        self::assertSame(5, $snapshot['schema_version']);
        self::assertNotEmpty($snapshot['how_to_read_these_facts']);

        $joined = implode(' ', $snapshot['how_to_read_these_facts']);

        self::assertStringContainsString('A null is not a zero', $joined);
        self::assertStringContainsString('not to recompute them', $joined);
    }

    public function test_the_range_block_names_the_window_and_the_timezone(): void
    {
        $snapshot = $this->assemble();

        self::assertSame(self::START, $snapshot['range']['start']);
        self::assertSame(self::END, $snapshot['range']['end']);
        self::assertSame(7, $snapshot['range']['days']);
        self::assertSame('weekly', $snapshot['range']['kind']);
        self::assertSame(StressFixture::TZ, $snapshot['range']['timezone']);
        self::assertFalse($snapshot['range']['covers_today']);
    }

    /**
     * The three flags travel WITH the row, not only in the coverage block: a
     * model must see this line's `kcal_out` is an undercount on the line itself.
     */
    public function test_the_honesty_flags_travel_on_the_row_they_qualify(): void
    {
        DailySummary::factory()->onDate('2026-08-06')->create([
            'active_kcal'              => 90,
            'resting_kcal'             => 1500,
            'active_kcal_is_partial'   => true,
            'active_kcal_coverage'     => 0.31,
            'has_full_metric_coverage' => false,
        ]);

        $days = collect($this->asArray($this->assemble()['days']))->keyBy('date');

        self::assertTrue($days['2026-08-06']['active_kcal_is_partial']);
        self::assertSame(0.31, $days['2026-08-06']['active_kcal_coverage']);
        self::assertFalse($days['2026-08-06']['metric_coverage_full']);
    }

    /** A watch-off day names its kind of hole rather than carrying a zero that reads as calm. */
    public function test_an_unscored_day_carries_its_reason_rather_than_a_zero(): void
    {
        $days = collect($this->asArray($this->assemble()['days']))->keyBy('date');

        self::assertNull($days['2026-08-03']['stress_score']);
        self::assertNotNull($days['2026-08-03']['stress_unscored_reason']);
        self::assertSame(0, $days['2026-08-03']['hrv_readings']);
    }

    /** With a real history behind it the table scores, and the stress block summarises that. */
    public function test_with_enough_history_the_days_score_and_the_stress_block_summarises_them(): void
    {
        $this->seedHrv(120);

        $snapshot = $this->assemble();

        self::assertSame(7, $snapshot['stress']['scored_days']);
        self::assertSame(7, $snapshot['stress']['score']['n']);
        self::assertNotNull($snapshot['stress']['score']['median']);
        self::assertSame([], $snapshot['stress']['unscored_days']);

        foreach ($snapshot['days'] as $row) {
            self::assertNotNull($row['stress_score'], "No score for {$row['date']}.");
            self::assertNotNull($row['hrv_ms']);
        }
    }

    /** Anchors are computed over the rows the table shows, so a reader can check them against it. */
    public function test_the_anchor_statistics_are_computed_over_the_day_table(): void
    {
        $this->seedHrv(120);

        // Three short nights, three long ones — a split the gate lets through.
        foreach ([['2026-08-03', 5.0], ['2026-08-04', 5.2], ['2026-08-05', 5.4],
            ['2026-08-06', 8.0], ['2026-08-07', 8.2], ['2026-08-08', 8.4]] as [$date, $hours]) {
            $this->night($date, $hours);
        }

        $anchors = collect($this->asArray($this->assemble()['anchor_statistics']['anchors']))->keyBy('key');

        $sleep = $anchors['stress_after_short_vs_long_sleep'];

        self::assertSame(3, $sleep['group_a']['n']);
        self::assertSame(3, $sleep['group_b']['n']);
        self::assertNotNull($sleep['difference']);
    }

    /**
     * THE PROFILE BLOCK — the only testimony rather than measurement here, and
     * the one place the failure mode is inventing something. Every assertion
     * below is a null staying null, or arithmetic the model must never be asked
     * to do.
     *
     * The long-standing normal case: nothing filled in. Every field present and
     * null — present so the report can see it was asked and not answered, null
     * because a guess is worse than an absence.
     */
    public function test_an_unfilled_profile_is_all_nulls_and_says_so(): void
    {
        $profile = $this->assemble()['profile'];

        self::assertTrue($profile['is_empty']);

        self::assertNull($profile['about']['age_years']);
        self::assertNull($profile['about']['sex']);
        self::assertNull($profile['about']['height_cm']);
        self::assertNull($profile['about']['ethnicity']);
        self::assertNull($profile['about']['country']);
        self::assertNull($profile['about']['bmi']);
        self::assertNull($profile['about']['bmi_computed_from']);

        self::assertNull($profile['goal']['goal']);
        self::assertNull($profile['goal']['target_weight_kg']);
        self::assertNull($profile['goal']['kg_from_target']);

        self::assertNull($profile['diet']['preferences']);
        self::assertNull($profile['diet']['allergies_and_intolerances']);

        self::assertNull($profile['lifestyle']['smoking']);
        self::assertNull($profile['lifestyle']['alcohol']);
        self::assertNull($profile['lifestyle']['sun_exposure']);
        self::assertNull($profile['lifestyle']['activity_context']);

        self::assertNull($profile['health_context']['life_stage']);
        self::assertNull($profile['health_context']['health_notes']);

        // The fallback lives in the snapshot: a fact about THIS one, not a standing rule.
        self::assertStringContainsString('framed generally', $profile['if_it_is_empty']);
    }

    /**
     * Age is whole years AT THE END OF THE RANGE, not today: a report about last
     * February says the age they were then, and taking it from `now()` would make
     * a re-read of an old report disagree with the prose stored in it.
     */
    public function test_the_age_is_whole_years_on_the_last_day_of_the_range(): void
    {
        Profile::put(['date_of_birth' => '1990-08-07']);

        // Range ends 2026-08-09, so the birthday has been and gone: 36.
        self::assertSame(36, $this->assemble()['profile']['about']['age_years']);

        // A range ending before it has not: 35.
        self::assertSame(35, $this->assemble(self::START, '2026-08-04')['profile']['about']['age_years']);
    }

    /**
     * BMI comes from the height on file and the LAST weigh-in in the window, and
     * names both: a figure a reader cannot check is one this app does not print.
     */
    public function test_the_bmi_uses_the_last_weigh_in_in_range_and_shows_its_working(): void
    {
        Profile::put(['height_cm' => '160.0']);

        DailySummary::factory()->onDate('2026-08-04')->withWeighIn(57.0)->create();
        DailySummary::factory()->onDate('2026-08-06')->withWeighIn(56.2)->create();

        $about = $this->assemble()['profile']['about'];

        // 56.2 / 1.60^2 = 21.95…, to one decimal.
        self::assertSame(22.0, $about['bmi']);
        self::assertSame(160.0, $about['bmi_computed_from']['height_cm']);
        self::assertSame(56.2, $about['bmi_computed_from']['weight_kg']);
        self::assertSame('2026-08-06', $about['bmi_computed_from']['weighed_on']);
    }

    /** Both halves or nothing: a BMI off a guessed height looks exactly like a measured one. */
    public function test_there_is_no_bmi_without_both_a_height_and_a_weigh_in(): void
    {
        DailySummary::factory()->onDate('2026-08-06')->withWeighIn(56.2)->create();

        // A weigh-in and no height.
        self::assertNull($this->assemble()['profile']['about']['bmi']);

        // A height and no weigh-in in this window.
        Profile::put(['height_cm' => '160.0']);

        self::assertNull($this->assemble(self::START, '2026-08-05')['profile']['about']['bmi']);
    }

    /** The gap is subtraction, so the app does it and signs it; the model is told not to. */
    public function test_the_goal_block_works_out_the_gap_to_the_target(): void
    {
        Profile::put(['goal' => 'lose weight', 'target_weight_kg' => '54.0']);

        DailySummary::factory()->onDate('2026-08-06')->withWeighIn(56.2)->create();

        $goal = $this->assemble()['profile']['goal'];

        self::assertSame('lose weight', $goal['goal']);
        self::assertSame(54.0, $goal['target_weight_kg']);
        self::assertSame(2.2, $goal['kg_from_target']);
        self::assertSame(['date' => '2026-08-06', 'kg' => 56.2], $goal['latest_weight_in_range']);
        self::assertStringContainsString('positive is above', $goal['sign_convention']);
    }

    /** A target with nothing to compare against is not a gap: a null is not a zero. */
    public function test_a_target_with_no_weigh_in_in_range_yields_no_gap(): void
    {
        Profile::put(['target_weight_kg' => '54.0']);

        $goal = $this->assemble()['profile']['goal'];

        self::assertSame(54.0, $goal['target_weight_kg']);
        self::assertNull($goal['kg_from_target']);
        self::assertNull($goal['latest_weight_in_range']);
    }

    /**
     * Free text travels verbatim: normalising "Lactose intolerant, and nuts"
     * would be this app editing somebody's own words about their own body.
     */
    public function test_the_written_fields_reach_the_snapshot_exactly_as_typed(): void
    {
        Profile::put([
            'sex'                    => 'female',
            'ethnicity'              => 'Mixed heritage',
            'country'                => 'Netherlands',
            'dietary_preferences'    => 'Not much red meat.',
            'allergies_intolerances' => 'Peanuts.',
            'alcohol'                => 'A glass of wine at weekends.',
            'sun_exposure'           => 'low',
            'life_stage'             => 'none',
            'health_notes'           => 'On a long-term PPI.',
        ]);

        $profile = $this->assemble()['profile'];

        self::assertFalse($profile['is_empty']);
        self::assertSame('female', $profile['about']['sex']);
        self::assertSame('Mixed heritage', $profile['about']['ethnicity']);
        self::assertSame('Netherlands', $profile['about']['country']);
        self::assertSame('Not much red meat.', $profile['diet']['preferences']);
        self::assertSame('Peanuts.', $profile['diet']['allergies_and_intolerances']);
        self::assertSame('A glass of wine at weekends.', $profile['lifestyle']['alcohol']);
        self::assertSame('low', $profile['lifestyle']['sun_exposure']);
        self::assertSame('none', $profile['health_context']['life_stage']);
        self::assertSame('On a long-term PPI.', $profile['health_context']['health_notes']);
    }

    /**
     * BODY COMPOSITION. The scale has reported muscle mass and body fat since the
     * Fitage import and no report ever mentioned them, because until the profile
     * existed there was no goal to read them against. An athletic goal is
     * answered by these two series, only incidentally by the weight.
     */
    public function test_the_body_block_carries_what_the_scale_measures_besides_the_weight(): void
    {
        $this->composition('2026-08-04', muscle: 38.6, fat: 24.1);
        $this->composition('2026-08-08', muscle: 39.1, fat: 23.6);

        // Before the window: read by the same query, excluded from the figures.
        $this->composition('2026-07-20', muscle: 37.0, fat: 26.0);

        $composition = $this->assemble()['body']['composition'];

        self::assertSame('kg', $composition['muscle_mass']['unit']);
        self::assertSame(2, $composition['muscle_mass']['n']);
        self::assertSame(38.6, $composition['muscle_mass']['first_in_range']);
        self::assertSame(39.1, $composition['muscle_mass']['last_in_range']);
        self::assertSame(0.5, $composition['muscle_mass']['change']);

        self::assertSame(
            [['date' => '2026-08-04', 'value' => 38.6], ['date' => '2026-08-08', 'value' => 39.1]],
            $composition['muscle_mass']['readings'],
        );

        self::assertSame('%', $composition['body_fat']['unit']);
        self::assertSame(-0.5, $composition['body_fat']['change']);
    }

    /** One reading is a fact about a day, not a direction — the thing this block exists to show. */
    public function test_a_single_reading_carries_no_change(): void
    {
        $this->composition('2026-08-06', muscle: 38.6, fat: 24.1);

        $muscle = $this->assemble()['body']['composition']['muscle_mass'];

        self::assertSame(1, $muscle['n']);
        self::assertSame(38.6, $muscle['first_in_range']);
        self::assertSame(38.6, $muscle['last_in_range']);
        self::assertNull($muscle['change']);
    }

    /** A window with no weigh-ins reports nothing, not zero. */
    public function test_a_window_with_no_scale_readings_is_empty_rather_than_zero(): void
    {
        $composition = $this->assemble()['body']['composition'];

        self::assertSame(0, $composition['muscle_mass']['n']);
        self::assertSame([], $composition['muscle_mass']['readings']);
        self::assertNull($composition['muscle_mass']['first_in_range']);
        self::assertNull($composition['muscle_mass']['change']);
        self::assertNull($composition['body_fat']['change']);
    }

    /**
     * THE SCALE REPORTS EVERY WEIGH-IN TWICE — Fitage app and export — so which
     * reading is the day's is a decision, made by the rollup's source-priority
     * rule through BodyTrend. Sharing that picker with the trends chart stops two
     * implementations picking different readings of one morning, both plausible.
     */
    public function test_two_readings_of_one_morning_resolve_to_one(): void
    {
        $at = CarbonImmutable::parse('2026-08-06 07:15', StressFixture::TZ);

        HealthMetric::factory()->metric('muscle_mass')->fromSource($this->scale())->create([
            'value' => '38.600000', 'started_at' => $at->utc(), 'ended_at' => $at->utc(),
        ]);

        // The same morning, from the watch, an hour later.
        HealthMetric::factory()->metric('muscle_mass')->fromSource(StressFixture::watch())->create([
            'value' => '41.900000', 'started_at' => $at->addHour()->utc(), 'ended_at' => $at->addHour()->utc(),
        ]);

        $muscle = $this->assemble()['body']['composition']['muscle_mass'];

        self::assertSame(1, $muscle['n'], 'one morning is one reading, whichever devices reported it');
        self::assertSame(38.6, $muscle['readings'][0]['value'], 'the scale outranks the watch on body composition');
    }

    /**
     * The scale as its own source. `Source::factory()` would collide on
     * `sources_raw_name_unique` the second time — StressFixture::watch()'s trap.
     */
    private function scale(): Source
    {
        return Source::query()->firstOrCreate(
            ['raw_name' => 'FITAGE'],
            ['name' => 'FITAGE', 'slug' => Source::slugFor('FITAGE'), 'device_kind' => Source::kindFor('FITAGE')],
        );
    }

    /** One morning on the scale: muscle mass and body fat. */
    private function composition(string $date, float $muscle, float $fat): void
    {
        $at = CarbonImmutable::parse($date.' 07:15', StressFixture::TZ)->utc();

        foreach (['muscle_mass' => $muscle, 'body_fat_percentage' => $fat] as $metric => $value) {
            HealthMetric::factory()->metric($metric)->fromSource($this->scale())->create([
                'value'      => number_format($value, 6, '.', ''),
                'started_at' => $at,
                'ended_at'   => $at,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(string $start = self::START, string $end = self::END): array
    {
        return app(ReportFacts::class)->assemble(
            ReportRange::between($start, $end),
            HealthReportKind::Weekly,
        );
    }

    /**
     * Enough HRV history to clear the 21-day baseline gate: a five-day cycle
     * around this user's own 33 ms. The point is a stable spread the estimator
     * can measure, not a particular score.
     */
    private function seedHrv(int $days): void
    {
        $cycle = [-0.2, -0.1, 0.0, 0.1, 0.2];

        StressFixture::days(
            self::END,
            $days,
            static fn (int $i, int $hour): float => 33.0 * exp($cycle[$i % 5]),
        );
    }

    /**
     * One night, from the one Watch. `SleepSession::factory()` mints a fresh
     * source each time, all of them the same Watch, colliding on
     * `sources_raw_name_unique`. The cached source also keeps priority
     * resolution meaningful: two rows for one night need two DIFFERENT sources.
     */
    private function night(string $date, float $hours): SleepSession
    {
        return SleepSession::factory()->create([
            'night_date'          => $date,
            'total_sleep_minutes' => $hours * 60,
            'source_id'           => StressFixture::watch()->id,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: float, 2: float, 3: float, 4: float}>  $items
     *                                                                                 [name, grams min, grams max, kcal/100g min, kcal/100g max]
     */
    private function confirmedMeal(string $eatenAt, array $items): Meal
    {
        $at = CarbonImmutable::parse($eatenAt, StressFixture::TZ);

        $meal = Meal::factory()->create([
            // ->utc() deliberately: Eloquent hands Postgres the wall-clock string
            // of whatever zone the Carbon carries, so a local instant lands hours
            // out and the generated `local_date` can fall on the wrong day.
            'eaten_at' => $at->utc(),
            'status'   => MealStatus::Confirmed,
        ]);

        foreach ($items as [$name, $gramsMin, $gramsMax, $kcalMin, $kcalMax]) {
            MealItem::factory()->for($meal)->create([
                'name'              => $name,
                'slug'              => str($name)->slug()->value(),
                'portion_g_min'     => $gramsMin,
                'portion_g_max'     => $gramsMax,
                'kcal_per_100g_min' => $kcalMin,
                'kcal_per_100g_max' => $kcalMax,
                'confirmed_at'      => $at->utc(),
            ]);
        }

        return $meal;
    }
}
