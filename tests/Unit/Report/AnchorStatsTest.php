<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use Tests\TestCase;
use App\Services\Report\AnchorStats;

/**
 * The numbers the model is forbidden to work out for itself.
 *
 * Under test is not "does it compute a mean" but that a group too small to mean
 * anything returns a NULL mean. An anchor that exists will be quoted, so the gate
 * is the honesty mechanism — a test of the arithmetic alone would pass with the
 * gate doing nothing.
 */
final class AnchorStatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('health.report.anchors', [
            'min_group'         => 3,
            'short_sleep_hours' => 6.0,
            'long_sleep_hours'  => 7.0,
            'late_meal_hour'    => 21,
        ]);
    }

    public function test_it_compares_stress_after_short_nights_with_stress_after_long_ones(): void
    {
        $rows = [
            $this->row('2026-08-01', sleep: 5.0, stress: 40),
            $this->row('2026-08-02', sleep: 5.5, stress: 44),
            $this->row('2026-08-03', sleep: 5.2, stress: 36),
            $this->row('2026-08-04', sleep: 8.0, stress: 70),
            $this->row('2026-08-05', sleep: 7.5, stress: 66),
            $this->row('2026-08-06', sleep: 7.2, stress: 68),
        ];

        $anchor = $this->anchor(AnchorStats::from($rows), 'stress_after_short_vs_long_sleep');

        self::assertSame(3, $anchor['group_a']['n']);
        self::assertSame(40.0, $anchor['group_a']['mean']);
        self::assertSame(3, $anchor['group_b']['n']);
        self::assertSame(68.0, $anchor['group_b']['mean']);
        self::assertSame(-28.0, $anchor['difference']);
        self::assertTrue($anchor['comparable']);
    }

    /** THE GATE. Two short nights is a pair, not a mean; "n=2, mean=42" would be quoted as a finding. */
    public function test_a_group_below_the_gate_has_a_null_mean_and_says_why(): void
    {
        $rows = [
            $this->row('2026-08-01', sleep: 5.0, stress: 40),
            $this->row('2026-08-02', sleep: 5.5, stress: 44),
            $this->row('2026-08-03', sleep: 8.0, stress: 70),
            $this->row('2026-08-04', sleep: 7.5, stress: 66),
            $this->row('2026-08-05', sleep: 7.2, stress: 68),
        ];

        $anchor = $this->anchor(AnchorStats::from($rows), 'stress_after_short_vs_long_sleep');

        self::assertSame(2, $anchor['group_a']['n']);
        self::assertNull($anchor['group_a']['mean']);
        self::assertStringContainsString('2 days in this group', (string) $anchor['group_a']['reason']);

        // The other side still reports: "only two short nights, but six long ones averaged 68".
        self::assertSame(68.0, $anchor['group_b']['mean']);

        // And no difference, because there is nothing to subtract from.
        self::assertNull($anchor['difference']);
        self::assertFalse($anchor['comparable']);
    }

    /**
     * The gap between the thresholds is the point: 6-7 h is in neither group, so
     * the comparison is genuinely different nights, not 6.9 h against 7.1 h.
     */
    public function test_nights_between_the_two_thresholds_are_in_neither_group(): void
    {
        $rows = [
            $this->row('2026-08-01', sleep: 6.5, stress: 50),
            $this->row('2026-08-02', sleep: 6.2, stress: 52),
            $this->row('2026-08-03', sleep: 6.9, stress: 55),
        ];

        // Every row is in the dead zone, so the split does not apply at all.
        self::assertNull($this->find(AnchorStats::from($rows), 'stress_after_short_vs_long_sleep'));
    }

    public function test_a_day_with_no_sleep_recorded_is_excluded_rather_than_counted_as_long(): void
    {
        $rows = [
            $this->row('2026-08-01', sleep: null, stress: 20),
            $this->row('2026-08-02', sleep: null, stress: 22),
            $this->row('2026-08-03', sleep: 5.0, stress: 40),
            $this->row('2026-08-04', sleep: 5.1, stress: 42),
            $this->row('2026-08-05', sleep: 5.2, stress: 44),
            $this->row('2026-08-06', sleep: 8.0, stress: 70),
            $this->row('2026-08-07', sleep: 8.1, stress: 72),
            $this->row('2026-08-08', sleep: 8.2, stress: 74),
        ];

        $anchor = $this->anchor(AnchorStats::from($rows), 'stress_after_short_vs_long_sleep');

        self::assertSame(3, $anchor['group_a']['n']);
        self::assertSame(3, $anchor['group_b']['n']);
        self::assertSame(72.0, $anchor['group_b']['mean']);
    }

    /**
     * THE LAG. A dinner at 22:30 cannot affect the night before it, so the split
     * looks forward — and the dates reported are the MEAL's, not the sleep's.
     */
    public function test_the_late_meal_split_is_lagged_by_a_day(): void
    {
        $rows = [
            // Late meals on 1-3 August; the nights that FOLLOW them are short.
            $this->row('2026-08-01', sleep: 8.0, stress: 70, lastMeal: '22:10'),
            $this->row('2026-08-02', sleep: 5.0, stress: 40, lastMeal: '21:40'),
            $this->row('2026-08-03', sleep: 5.2, stress: 41, lastMeal: '22:30'),
            $this->row('2026-08-04', sleep: 5.4, stress: 42, lastMeal: '18:00'),
            $this->row('2026-08-05', sleep: 8.1, stress: 71, lastMeal: '18:30'),
            $this->row('2026-08-06', sleep: 8.2, stress: 72, lastMeal: '19:00'),
            $this->row('2026-08-07', sleep: 8.3, stress: 73, lastMeal: null),
        ];

        $anchor = $this->anchor(AnchorStats::from($rows), 'sleep_after_late_vs_early_last_meal_next_day');

        // Three late meals, and the sleep counted for each is the NEXT day's.
        self::assertSame(['2026-08-01', '2026-08-02', '2026-08-03'], $anchor['group_a']['dates']);
        self::assertSame(3, $anchor['group_a']['n']);
        self::assertEqualsWithDelta(5.2, (float) $anchor['group_a']['mean'], 0.05);

        // 4-6 August are the early ones with a successor; the 7th has none.
        self::assertSame(['2026-08-04', '2026-08-05', '2026-08-06'], $anchor['group_b']['dates']);
        self::assertEqualsWithDelta(8.2, (float) $anchor['group_b']['mean'], 0.05);
    }

    public function test_a_day_with_no_logged_meal_is_not_treated_as_an_early_one(): void
    {
        $rows = [
            $this->row('2026-08-01', sleep: 7.0, stress: 60, lastMeal: null),
            $this->row('2026-08-02', sleep: 7.0, stress: 60, lastMeal: null),
            $this->row('2026-08-03', sleep: 7.0, stress: 60, lastMeal: null),
            $this->row('2026-08-04', sleep: 7.0, stress: 60, lastMeal: null),
        ];

        self::assertNull($this->find(AnchorStats::from($rows), 'sleep_after_late_vs_early_last_meal_next_day'));
    }

    /** A group whose mean and median disagree has an outlier, worth seeing without asking. */
    public function test_a_group_reports_its_median_as_well_as_its_mean(): void
    {
        $rows = [
            $this->row('2026-08-01', sleep: 5.0, stress: 50),
            $this->row('2026-08-02', sleep: 5.0, stress: 52),
            $this->row('2026-08-03', sleep: 5.0, stress: 51),
            $this->row('2026-08-04', sleep: 5.0, stress: 5),
        ];

        $anchor = $this->anchor(AnchorStats::from($rows), 'stress_after_short_vs_long_sleep');

        self::assertSame(39.5, $anchor['group_a']['mean']);
        self::assertSame(50.5, $anchor['group_a']['median']);
    }

    public function test_supplement_days_before_the_first_bottle_existed_are_excluded(): void
    {
        $rows = [
            // Nothing on the shelf yet: no expectation, so no group.
            $this->row('2026-08-01', stress: 50, expected: [], taken: []),
            $this->row('2026-08-02', stress: 52, expected: [], taken: []),
            $this->row('2026-08-03', stress: 54, expected: ['D3'], taken: ['D3']),
            $this->row('2026-08-04', stress: 56, expected: ['D3'], taken: ['D3']),
            $this->row('2026-08-05', stress: 58, expected: ['D3'], taken: ['D3']),
            $this->row('2026-08-06', stress: 60, expected: ['D3'], taken: []),
        ];

        $anchor = $this->anchor(AnchorStats::from($rows), 'stress_after_full_supplement_day_next_day');

        // 3-5 August ticked with a successor; 1-2 are in neither group, the 6th has none.
        self::assertSame(['2026-08-03', '2026-08-04', '2026-08-05'], $anchor['group_a']['dates']);
    }

    /**
     * @param  list<array<string, mixed>>  $anchors
     * @return array<string, mixed>
     */
    private function anchor(array $anchors, string $key): array
    {
        $found = $this->find($anchors, $key);

        self::assertNotNull($found, "No anchor named {$key}.");

        return $found;
    }

    /**
     * @param  list<array<string, mixed>>  $anchors
     * @return array<string, mixed>|null
     */
    private function find(array $anchors, string $key): ?array
    {
        foreach ($anchors as $anchor) {
            if ($anchor['key'] === $key) {
                return $anchor;
            }
        }

        return null;
    }

    /**
     * Only the columns a split reads; the rest null, as on a real quiet day.
     *
     * @param  list<string>  $expected
     * @param  list<string>  $taken
     * @return array<string, mixed>
     */
    private function row(
        string $date,
        ?float $sleep = null,
        ?int $stress = null,
        ?string $lastMeal = null,
        ?int $exercise = null,
        array $expected = [],
        array $taken = [],
    ): array {
        return [
            'date'                 => $date,
            'sleep_hours'          => $sleep,
            'stress_score'         => $stress,
            'hrv_ms'               => null,
            'last_meal_local_time' => $lastMeal,
            'exercise_minutes'     => $exercise,
            'supplements_expected' => $expected,
            'supplements_taken'    => $taken,
        ];
    }
}
