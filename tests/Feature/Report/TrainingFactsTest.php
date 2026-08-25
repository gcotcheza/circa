<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use App\Models\Workout;
use Carbon\CarbonImmutable;
use App\Enums\HealthReportKind;
use App\Services\Report\ReportFacts;
use App\Services\Report\ReportRange;
use App\Services\Report\TrainingLog;
use App\Services\Report\PromptV2Report;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * The `training` block: what the person DID, not what the watch measured.
 *
 * That distinction is the whole reason it exists — "62 exercise minutes on
 * Sunday" and "a 70-minute martial arts class, the first in three weeks" are
 * one measurement and two entirely different findings.
 */
final class TrainingFactsTest extends TestCase
{
    use RefreshDatabase;

    private const START = '2026-06-08';

    private const END = '2026-06-21';

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-22 09:00:00', 'Europe/Amsterdam'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    private function range(): ReportRange
    {
        return ReportRange::between(self::START, self::END);
    }

    /** @return array<string, mixed> */
    private function training(): array
    {
        return (new TrainingLog)->build($this->range());
    }

    private function seedAFortnightOfTraining(): void
    {
        Workout::factory()->ofType('Outdoor Run')->startingAt('2026-06-09', '07:27', 47.4)
            ->create(['distance_km' => 6.03, 'active_kcal' => 340.34, 'avg_hr' => 157, 'max_hr' => 174]);

        Workout::factory()->ofType('Martial Arts')->startingAt('2026-06-14', '11:03', 70.3)
            ->withoutDistance()
            ->create(['active_kcal' => 377.75, 'avg_hr' => 150, 'max_hr' => 173, 'is_indoor' => null]);

        Workout::factory()->ofType('Outdoor Run')->startingAt('2026-06-20', '08:02', 50.5)
            ->create(['distance_km' => 5.89, 'active_kcal' => 282.03, 'avg_hr' => 153, 'max_hr' => 173]);
    }

    public function test_every_session_arrives_with_its_name_time_and_figures(): void
    {
        $this->seedAFortnightOfTraining();

        $training = $this->training();

        self::assertSame(3, $training['session_count']);
        self::assertSame(3, $training['days_with_a_session']);
        self::assertSame(14, $training['days_in_range']);

        $first = $training['sessions'][0];

        self::assertSame('2026-06-09', $first['date']);
        self::assertSame('Tue', $first['weekday']);
        self::assertSame('Outdoor Run', $first['type']);
        self::assertSame('07:27', $first['start_local']);
        self::assertSame(47.4, $first['duration_minutes']);
        self::assertSame(6.03, $first['distance_km']);
        self::assertSame(340, $first['active_kcal']);
        self::assertSame(157, $first['avg_hr_bpm']);
        self::assertSame(174, $first['max_hr_bpm']);
    }

    public function test_the_aggregates_are_computed_here_and_not_left_to_the_model(): void
    {
        $this->seedAFortnightOfTraining();

        $training = $this->training();

        self::assertSame(['Outdoor Run' => 2, 'Martial Arts' => 1], $training['sessions_by_type']);
        self::assertSame(168.0, $training['total_duration_minutes']);
        // Over the sessions that HAD a distance. Martial arts reports none.
        self::assertSame(11.92, $training['total_distance_km']);
        self::assertSame(1000, $training['total_active_kcal']);
    }

    /**
     * THE GAPS ARE THE PATTERN, and the first must reach back before the window:
     * otherwise every report's first session looks like the first ever, and
     * "your first class in three weeks" is unsayable from inside a fortnight.
     */
    public function test_the_gap_before_the_window_is_carried_in(): void
    {
        Workout::factory()->ofType('Climbing')->startingAt('2026-05-25', '14:30')->withoutDistance()->create();

        $this->seedAFortnightOfTraining();

        $training = $this->training();

        self::assertSame(
            ['date' => '2026-05-25', 'type' => 'Climbing'],
            $training['previous_session_before_range']
        );

        // 25 May -> 9 June is 15 days; then 5, then 6.
        self::assertSame([15, 5, 6], array_column($training['sessions'], 'days_since_previous_session'));
        self::assertSame(15, $training['longest_gap_days']);
    }

    public function test_the_first_session_on_record_has_no_gap_rather_than_a_guessed_one(): void
    {
        $this->seedAFortnightOfTraining();

        self::assertNull($this->training()['sessions'][0]['days_since_previous_session']);
        self::assertNull($this->training()['previous_session_before_range']);
    }

    /**
     * THE FOUR-DAY HIKE. Excluded from every figure — 96 hours inside a
     * fortnight's total is not a small error, it IS the total — but reported
     * separately rather than silently dropping a day the app itself displays.
     */
    public function test_an_implausible_session_is_excluded_from_the_totals_and_named(): void
    {
        $this->seedAFortnightOfTraining();

        Workout::factory()->ofType('Hiking')->startingAt('2026-06-12', '20:56')->implausible()
            ->create(['distance_km' => 15.56, 'active_kcal' => 1079.5]);

        $training = $this->training();

        self::assertSame(3, $training['session_count']);
        self::assertSame(168.0, $training['total_duration_minutes']);
        self::assertArrayNotHasKey('Hiking', $training['sessions_by_type']);

        self::assertCount(1, $training['excluded_as_implausible']);
        self::assertSame('Hiking', $training['excluded_as_implausible'][0]['type']);
        self::assertSame(96.0, $training['excluded_as_implausible'][0]['duration_hours']);
    }

    public function test_a_window_with_no_training_says_so_without_nulls_everywhere(): void
    {
        $training = $this->training();

        self::assertSame(0, $training['session_count']);
        self::assertSame([], $training['sessions']);
        self::assertSame([], $training['sessions_by_type']);
        self::assertSame([], $training['excluded_as_implausible']);
        self::assertNull($training['total_distance_km']);
        self::assertNull($training['longest_gap_days']);
    }

    /**
     * The day table gains a `training` column so a session lines up against the
     * night that followed it — the one view of this data nothing else has.
     */
    public function test_the_snapshot_carries_training_on_the_block_and_on_the_day_rows(): void
    {
        $this->seedAFortnightOfTraining();

        $snapshot = (new ReportFacts)->assemble($this->range(), HealthReportKind::Manual);

        self::assertSame(5, $snapshot['schema_version']);
        self::assertSame(3, $snapshot['training']['session_count']);

        $rows = [];

        /** @var list<array<string, mixed>> $days */
        $days = $snapshot['days'];

        foreach ($days as $row) {
            $rows[(string) $row['date']] = $row;
        }

        self::assertSame(
            [['type' => 'Martial Arts', 'minutes' => 70, 'distance_km' => null, 'avg_hr_bpm' => 150]],
            $rows['2026-06-14']['training'],
        );
        self::assertSame([], $rows['2026-06-15']['training']);
    }

    /**
     * Session kcal are already inside the day's expenditure, so adding a week of
     * them onto the week's burn is the one arithmetic error the arithmetic ban
     * misses — the model feels entitled to that addition. Warned in both places.
     */
    public function test_the_facts_and_the_prompt_both_forbid_adding_session_kcal_to_the_burn(): void
    {
        $note = (string) $this->training()['note'];

        self::assertStringContainsString('ALREADY inside the expenditure', $note);
        self::assertStringContainsString('Never add these to an expenditure total', $note);

        self::assertStringContainsString(
            "NEVER ADD SESSION CALORIES TO THE DAY'S BURN",
            PromptV2Report::TEXT,
        );
    }

    /**
     * The block the version was bumped for. Asserted on the rules that could go
     * wrong, not the prose: use the names, read the gaps, do not prescribe.
     */
    public function test_the_prompt_tells_the_model_how_to_read_a_training_block(): void
    {
        $text = PromptV2Report::TEXT;

        self::assertStringContainsString('THE TRAINING BLOCK: WHAT THEY ACTUALLY DID.', $text);
        self::assertStringContainsString('USE THE NAMES.', $text);
        self::assertStringContainsString('THE GAPS ARE THE PATTERN', $text);
        self::assertStringContainsString('AND STILL NO PLAN.', $text);
        self::assertStringContainsString('excluded_as_implausible', $text);

        // Non-medical and no-invented-numbers survive the addition untouched.
        self::assertStringContainsString('you have no history and no examination', $text);
        self::assertStringContainsString('THE NUMBERS ARE ALREADY WORKED OUT.', $text);
    }
}
