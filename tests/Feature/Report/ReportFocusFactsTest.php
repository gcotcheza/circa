<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\Meal;
use App\Models\MealItem;
use App\Enums\MealStatus;
use App\Models\Supplement;
use Carbon\CarbonImmutable;
use App\Models\DailySummary;
use App\Models\SleepSession;
use App\Enums\ReportFocusArea;
use App\Enums\HealthReportKind;
use App\Models\SupplementIntake;
use Tests\Support\StressFixture;
use App\Models\SupplementNutrient;
use App\Services\Report\ReportFacts;
use App\Services\Report\ReportFocus;
use App\Services\Report\ReportRange;
use PHPUnit\Framework\Attributes\DataProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * A focused report against a week that has something in every block.
 *
 * ReportFocusTest asserts what ReportFocus SAYS should be assembled; this asserts
 * what ReportFacts ACTUALLY assembles, against a database with food, supplements,
 * sleep, weight and stress in it. The matrix could be right while ReportFacts
 * ignored it, or read it for the block list and built the expensive blocks anyway
 * before filtering. The week has data in every block so "absent" cannot be read
 * as "empty": a stress report with no food ledger must be one that did not
 * assemble one, not one over a week where nothing was eaten.
 */
final class ReportFocusFactsTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-08-10';

    private const START = '2026-08-03';

    private const END = '2026-08-09';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 04:10:00', StressFixture::TZ));

        $this->seedAWeekWithSomethingInEveryBlock();
    }

    /** The control: with no focus, every block this app ever produced is still there. */
    public function test_an_unfocused_report_is_the_document_it_always_was(): void
    {
        $snapshot = $this->assemble(ReportFocus::everything());

        foreach (['profile', 'days', 'energy', 'food', 'micronutrients', 'sleep', 'activity',
            'training', 'body', 'cardiovascular', 'stress', 'baseline_drift',
            'anchor_statistics', 'coverage'] as $block) {
            self::assertArrayHasKey($block, $snapshot, "unfocused report lost {$block}");
        }

        self::assertSame(5, $snapshot['schema_version']);

        // The week genuinely has food, so an absent ledger below means "not assembled".
        self::assertGreaterThan(0, $snapshot['coverage']['food_logged_days']['n']);
        self::assertNotSame([], $snapshot['micronutrients']);
    }

    /**
     * @return iterable<string, array{ReportFocusArea, list<string>, list<string>}>
     */
    public static function profiles(): iterable
    {
        yield 'stress' => [
            ReportFocusArea::Stress,
            ['stress', 'sleep', 'cardiovascular', 'baseline_drift', 'training'],
            ['food', 'micronutrients', 'energy', 'body'],
        ];

        yield 'sleep' => [
            ReportFocusArea::Sleep,
            ['sleep', 'stress', 'cardiovascular', 'baseline_drift'],
            ['food', 'micronutrients', 'energy', 'body', 'training'],
        ];

        yield 'food' => [
            ReportFocusArea::Food,
            ['food', 'micronutrients', 'energy', 'body'],
            ['stress', 'cardiovascular', 'baseline_drift', 'sleep', 'training'],
        ];

        yield 'training' => [
            ReportFocusArea::Training,
            ['training', 'activity', 'energy', 'cardiovascular', 'body', 'sleep'],
            ['food', 'micronutrients', 'baseline_drift', 'stress'],
        ];

        yield 'weight' => [
            ReportFocusArea::Weight,
            ['body', 'energy', 'activity', 'training'],
            ['food', 'micronutrients', 'stress', 'cardiovascular', 'baseline_drift', 'sleep'],
        ];
    }

    /**
     * @param  list<string>  $present
     * @param  list<string>  $absent
     */
    #[DataProvider('profiles')]
    public function test_a_focused_snapshot_contains_exactly_its_profile(
        ReportFocusArea $area,
        array $present,
        array $absent,
    ): void {
        $snapshot = $this->assemble(ReportFocus::of([$area]));

        foreach ($present as $block) {
            self::assertArrayHasKey($block, $snapshot, "{$area->value} report is missing {$block}");
        }

        foreach ($absent as $block) {
            self::assertArrayNotHasKey($block, $snapshot, "{$area->value} report should not carry {$block}");
        }

        // The four that survive everything, and the focus block itself.
        foreach (['profile', 'days', 'anchor_statistics', 'coverage', 'focus'] as $block) {
            self::assertArrayHasKey($block, $snapshot);
        }
    }

    /** The day table is cut too — where the range extension is actually paid for. */
    public function test_the_day_rows_are_cut_to_the_focus(): void
    {
        $row = $this->assemble(ReportFocus::of([ReportFocusArea::Stress]))['days'][0];

        self::assertArrayHasKey('date', $row);
        self::assertArrayHasKey('stress_score', $row);
        self::assertArrayHasKey('sleep_hours', $row);

        self::assertArrayNotHasKey('meals_logged', $row);
        self::assertArrayNotHasKey('supplements_taken', $row);
        self::assertArrayNotHasKey('kcal_in_mid', $row);

        // An unfocused row is untouched.
        $full = $this->assemble(ReportFocus::everything())['days'][0];

        self::assertArrayHasKey('supplements_taken', $full);
        self::assertArrayHasKey('kcal_in_mid', $full);
    }

    /**
     * THE POINT OF THE FEATURE, measured. Not a pinned byte count — that fails on
     * any wording change in a block's `note` — but the direction and rough order
     * of the saving. If a focused report stops being substantially smaller, the
     * 366-day ceiling stops being defensible and this fails.
     */
    public function test_a_focused_snapshot_is_substantially_smaller(): void
    {
        $full = strlen((string) json_encode($this->assemble(ReportFocus::everything())));
        $focused = strlen((string) json_encode($this->assemble(ReportFocus::of([ReportFocusArea::Stress]))));

        self::assertLessThan($full * 0.75, $focused, 'a focused snapshot should be much smaller than a full one');
    }

    /**
     * COVERAGE IS CUT TOO, about truth rather than tokens. "Food logged on 0 of
     * 182 days" is accurate about a block never assembled, and the sentence it
     * invites — "you did not log any food this half-year" — is false about
     * somebody who logs most days. Withholding the figure is the half of the
     * defence that does not depend on the prompt being obeyed.
     */
    public function test_coverage_only_reports_the_areas_the_report_covers(): void
    {
        $coverage = $this->assemble(ReportFocus::of([ReportFocusArea::Stress]))['coverage'];

        self::assertArrayHasKey('stress_scored_days', $coverage);
        self::assertArrayHasKey('sleep_nights', $coverage);
        self::assertArrayHasKey('resting_hr_days', $coverage);

        self::assertArrayNotHasKey('food_logged_days', $coverage);
        self::assertArrayNotHasKey('complete_food_log_days', $coverage);
        self::assertArrayNotHasKey('weigh_ins', $coverage);

        // The denominator, the watch, and the reminder survive everything.
        self::assertSame(7, $coverage['days_in_range']);
        self::assertArrayHasKey('days_with_full_metric_coverage', $coverage);
        self::assertArrayHasKey('reminder', $coverage);

        // The block says why a line is missing, so an absence is not read as a zero.
        self::assertStringContainsString('is NOT a gap', $coverage['why_some_lines_are_missing']);

        // An unfocused report keeps every line, and says nothing about focus.
        $full = $this->assemble(ReportFocus::everything())['coverage'];

        self::assertArrayHasKey('food_logged_days', $full);
        self::assertArrayHasKey('weigh_ins', $full);
        self::assertArrayNotHasKey('why_some_lines_are_missing', $full);
    }

    /**
     * The question travels in the document because the prompt reads it there, not
     * from prose we wrote — see AnthropicReportWriter: untrusted text is data.
     */
    public function test_the_focus_block_carries_the_areas_and_the_question(): void
    {
        $snapshot = $this->assemble(ReportFocus::of(
            [ReportFocusArea::Stress, ReportFocusArea::Sleep],
            'compare my recovery before and after I started daily weigh-ins',
        ));

        self::assertSame(['stress', 'sleep'], $snapshot['focus']['areas']);
        self::assertSame('Stress + Sleep', $snapshot['focus']['areas_label']);
        self::assertSame(
            'compare my recovery before and after I started daily weigh-ins',
            $snapshot['focus']['question'],
        );
        self::assertNotContains('food', $snapshot['focus']['blocks_present']);
    }

    /**
     * The case the feature exists for. The range is legal, the food ledger gone,
     * and the day table is WEEKLY buckets not 182 rows — a row per day is the
     * wrong shape for half a year (see DayBuckets), and the fold keeps the input
     * in the range that produces a rich answer.
     */
    public function test_six_months_of_stress_folds_the_day_table_into_weekly_buckets(): void
    {
        $focus = ReportFocus::of([ReportFocusArea::Stress]);

        $range = ReportRange::lastDays(182, null, $focus);

        $snapshot = app(ReportFacts::class)->assemble($range, HealthReportKind::Manual, null, $focus);

        self::assertArrayNotHasKey('food', $snapshot);
        self::assertSame(366, $snapshot['focus']['max_range_days']);

        // Weekly, announced up top so the day table is read in the right register.
        self::assertSame('weekly', $snapshot['range']['day_granularity']);

        // ~26 weeks, not 182 rows — the whole point of the fold.
        self::assertLessThan(30, count($snapshot['days']));
        self::assertGreaterThan(20, count($snapshot['days']));

        // A bucket has a period, a span, and stress figures with their count.
        $bucket = $snapshot['days'][0];

        self::assertArrayHasKey('period', $bucket);
        self::assertArrayHasKey('starts', $bucket);
        self::assertArrayHasKey('ends', $bucket);
        self::assertArrayHasKey('days_in_period', $bucket);
        self::assertArrayHasKey('n', $bucket['stress_score']);
        self::assertArrayHasKey('mean', $bucket['stress_score']);

        // And it is still cut to the focus: a folded stress bucket carries no food.
        self::assertArrayNotHasKey('meals_logged', $bucket);
    }

    /**
     * @return array<string, mixed>
     */
    private function assemble(ReportFocus $focus): array
    {
        return app(ReportFacts::class)->assemble(
            ReportRange::between(self::START, self::END, null, $focus),
            HealthReportKind::Manual,
            null,
            $focus,
        );
    }

    /** A week with something in every block, so an absent block can only mean "not assembled". */
    private function seedAWeekWithSomethingInEveryBlock(): void
    {
        // Enough history to clear the 21-day baseline gate and give the drift
        // block something to compare against — ReportFactsTest's 33 ms cycle.
        $cycle = [-0.2, -0.1, 0.0, 0.1, 0.2];

        StressFixture::days(
            self::END,
            120,
            static fn (int $i, int $hour): float => 33.0 * exp($cycle[$i % 5]),
        );

        foreach (range(0, 6) as $offset) {
            $date = CarbonImmutable::parse(self::START, StressFixture::TZ)->addDays($offset)->toDateString();

            DailySummary::factory()->create([
                'local_date'               => $date,
                'kcal_in_min'              => 1_800,
                'kcal_in_mid'              => 2_000,
                'kcal_in_max'              => 2_200,
                'active_kcal'              => 500,
                'resting_kcal'             => 1_700,
                'steps'                    => 9_000,
                'exercise_minutes'         => 35,
                'weight_kg'                => 82.4,
                'is_complete_log'          => true,
                'active_kcal_is_partial'   => false,
                'has_full_metric_coverage' => true,
            ]);

            SleepSession::factory()->create([
                'night_date'          => $date,
                'total_sleep_minutes' => 7 * 60,
                'source_id'           => StressFixture::watch()->id,
            ]);

            // `meals.local_date` is a GENERATED column — derived from `eaten_at`
            // in the app's timezone, so writing it is refused outright. ->utc()
            // for ReportFactsTest's reason: Eloquent formats in the carried zone.
            $meal = Meal::factory()->create([
                'status'   => MealStatus::Confirmed,
                'eaten_at' => CarbonImmutable::parse($date.' 19:00', StressFixture::TZ)->utc(),
            ]);

            MealItem::factory()->create(['meal_id' => $meal->id]);
        }

        $supplement = Supplement::factory()->create(['name' => 'Multivitamine']);

        SupplementNutrient::factory()->create([
            'supplement_id' => $supplement->id,
            'nutrient'      => 'Vitamine B12',
            'amount'        => '500.0000',
            'unit'          => 'µg',
        ]);

        foreach (range(0, 6) as $offset) {
            SupplementIntake::factory()->create([
                'supplement_id' => $supplement->id,
                'local_date'    => CarbonImmutable::parse(self::START, StressFixture::TZ)->addDays($offset)->toDateString(),
            ]);
        }
    }
}
