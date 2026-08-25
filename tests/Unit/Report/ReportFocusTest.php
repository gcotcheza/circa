<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use App\Enums\ReportFocusArea;
use App\Services\Report\ReportFocus;
use App\Services\Report\ReportRange;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The matrix, the ceilings, and the two places a focus could quietly stop being
 * one.
 *
 * A focused report is only cheaper — and therefore only allowed to be longer —
 * because the blocks outside its focus are genuinely not assembled. Putting the
 * food ledger back into a stress report would break nothing visible: it would
 * still be written and still read fine, just cost several times more and lose
 * the entire justification for the 366-day ceiling still applied to it. That is
 * a regression with no symptom, so every profile below asserts what is NOT
 * there as explicitly as what is.
 */
final class ReportFocusTest extends TestCase
{
    public function test_no_chips_means_everything(): void
    {
        $focus = ReportFocus::everything();

        self::assertTrue($focus->isEverything());
        self::assertNull($focus->label());
        self::assertSame([], $focus->areaValues());

        // Every block, including the two expensive ones.
        foreach (['profile', 'days', 'energy', 'food', 'micronutrients', 'sleep', 'activity',
            'training', 'body', 'cardiovascular', 'stress', 'baseline_drift',
            'anchor_statistics', 'coverage'] as $block) {
            self::assertTrue($focus->hasBlock($block), "unfocused report is missing {$block}");
        }
    }

    /**
     * An empty selection and no chips are one request, the full document.
     * `focus[]=` from a form with nothing ticked must not become a sixth kind
     * of report.
     */
    public function test_an_empty_selection_is_everything(): void
    {
        self::assertTrue(ReportFocus::of([])->isEverything());
        self::assertTrue(ReportFocus::of([], '  ')->isEverything());
    }

    /**
     * THE MATRIX. One case per chip, present and absent.
     *
     * @return iterable<string, array{list<ReportFocusArea>, list<string>, list<string>}>
     */
    public static function matrix(): iterable
    {
        yield 'stress' => [
            [ReportFocusArea::Stress],
            ['sleep', 'activity', 'cardiovascular', 'stress', 'baseline_drift', 'training'],
            ['food', 'micronutrients', 'energy', 'body'],
        ];

        yield 'sleep' => [
            [ReportFocusArea::Sleep],
            ['sleep', 'activity', 'cardiovascular', 'stress', 'baseline_drift'],
            ['food', 'micronutrients', 'energy', 'body', 'training'],
        ];

        yield 'food' => [
            [ReportFocusArea::Food],
            ['food', 'micronutrients', 'energy', 'body'],
            ['stress', 'cardiovascular', 'baseline_drift', 'sleep', 'training', 'activity'],
        ];

        yield 'training' => [
            [ReportFocusArea::Training],
            ['training', 'activity', 'energy', 'cardiovascular', 'body', 'sleep'],
            ['food', 'micronutrients', 'baseline_drift', 'stress'],
        ];

        yield 'weight' => [
            [ReportFocusArea::Weight],
            ['body', 'energy', 'activity', 'training'],
            ['food', 'micronutrients', 'stress', 'cardiovascular', 'baseline_drift', 'sleep'],
        ];

        // A union, not a third rule: everything stress has plus everything
        // food has.
        yield 'stress + food' => [
            [ReportFocusArea::Stress, ReportFocusArea::Food],
            ['stress', 'baseline_drift', 'food', 'micronutrients', 'energy', 'body', 'sleep'],
            [],
        ];
    }

    /**
     * @param  list<ReportFocusArea>  $areas
     * @param  list<string>  $present
     * @param  list<string>  $absent
     */
    #[DataProvider('matrix')]
    public function test_the_block_matrix(array $areas, array $present, array $absent): void
    {
        $focus = ReportFocus::of($areas);

        foreach ($present as $block) {
            self::assertTrue($focus->hasBlock($block), "expected {$block} to be assembled");
        }

        foreach ($absent as $block) {
            self::assertFalse($focus->hasBlock($block), "expected {$block} NOT to be assembled");
        }
    }

    /**
     * Four blocks survive every focus, each load-bearing: the profile gates
     * suggestions against an allergy, coverage stops a fortnight being claimed
     * from four days, the anchors are the only arithmetic the model may quote,
     * and there is no report without a day table.
     */
    public function test_four_blocks_survive_every_focus(): void
    {
        foreach (ReportFocusArea::cases() as $area) {
            $focus = ReportFocus::of([$area]);

            foreach (['profile', 'days', 'anchor_statistics', 'coverage'] as $block) {
                self::assertTrue($focus->hasBlock($block), "{$area->value} dropped {$block}");
            }
        }
    }

    /** The day table is the biggest block, so slimming it pays for the range. */
    public function test_the_day_table_is_cut_to_the_columns_the_focus_reads(): void
    {
        $stress = ReportFocus::of([ReportFocusArea::Stress])->dayColumns();

        self::assertContains('stress_score', $stress);
        self::assertContains('hrv_ms', $stress);
        self::assertContains('sleep_hours', $stress);
        self::assertContains('training', $stress);

        // The food and supplement columns are where the width is.
        self::assertNotContains('meals_logged', $stress);
        self::assertNotContains('supplements_taken', $stress);
        self::assertNotContains('kcal_in_mid', $stress);
        self::assertNotContains('weight_kg', $stress);

        // Identity survives everything: a row nobody can date is not evidence.
        foreach (ReportFocusArea::cases() as $area) {
            $columns = ReportFocus::of([$area])->dayColumns();

            self::assertContains('date', $columns);
            self::assertContains('weekday', $columns);
            self::assertContains('metric_coverage_full', $columns);
        }

        // An unfocused report keeps the whole row.
        self::assertContains('supplements_expected', ReportFocus::everything()->dayColumns());
    }

    /** The two ceilings, and the single rule that decides between them. */
    public function test_the_ceiling_follows_the_food_block(): void
    {
        self::assertSame(92, ReportFocus::everything()->maxRangeDays());
        self::assertSame(92, ReportFocus::of([ReportFocusArea::Food])->maxRangeDays());

        // Food anywhere in the selection keeps the quarter.
        self::assertSame(92, ReportFocus::of([ReportFocusArea::Stress, ReportFocusArea::Food])->maxRangeDays());

        foreach ([ReportFocusArea::Stress, ReportFocusArea::Sleep, ReportFocusArea::Training, ReportFocusArea::Weight] as $area) {
            self::assertSame(366, ReportFocus::of([$area])->maxRangeDays(), $area->value.' should be lean');
        }
    }

    /**
     * A ceiling is useful only if the range enforces it, and the message must
     * name the way out: "A report can cover at most 92 days" to somebody who
     * just tapped a 6-month chip is a contradiction they cannot resolve.
     */
    public function test_the_range_enforces_the_focus_ceiling_with_an_honest_message(): void
    {
        $today = CarbonImmutable::parse('2026-08-10', (string) config('health.timezone'));

        $long = ReportRange::lastDays(182, $today, ReportFocus::of([ReportFocusArea::Stress]));

        self::assertSame(182, $long->days);

        try {
            ReportRange::lastDays(182, $today, ReportFocus::of([ReportFocusArea::Food]));
            self::fail('a 182-day food report should not be constructible');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('Food-focused reports are limited to 92 days', $e->getMessage());
            self::assertStringContainsString('Dropping the Food chip', $e->getMessage());
        }

        try {
            ReportRange::lastDays(182, $today);
            self::fail('a 182-day unfocused report should not be constructible');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('at most 92 days', $e->getMessage());
            self::assertStringContainsString('Focusing it on stress', $e->getMessage());
        }

        // And the lean ceiling is a ceiling too, not an absence of one.
        $this->expectException(InvalidArgumentException::class);
        ReportRange::lastDays(400, $today, ReportFocus::of([ReportFocusArea::Stress]));
    }

    /**
     * Same chips, different order, one request: otherwise "Sleep + Stress" and
     * "Stress + Sleep" are two kinds of report with fingerprints that disagree.
     */
    public function test_chip_order_does_not_matter(): void
    {
        $a = ReportFocus::of(['sleep', 'stress']);
        $b = ReportFocus::of(['stress', 'sleep']);

        self::assertSame('Stress + Sleep', $a->label());
        self::assertSame($a->label(), $b->label());
        self::assertSame($a->fingerprint(), $b->fingerprint());
        self::assertSame($a->blocks(), $b->blocks());
    }

    public function test_duplicate_and_unknown_chips_are_dropped(): void
    {
        $focus = ReportFocus::of(['stress', 'stress', 'not-a-chip', '']);

        self::assertSame(['stress'], $focus->areaValues());
    }

    /**
     * Trimmed, emptied to null, capped — the cap in the value object as well as
     * in the validator, because a focus reconstructed from a row must never
     * carry more than the column was meant to hold.
     */
    public function test_the_question_is_trimmed_and_capped(): void
    {
        self::assertNull(ReportFocus::of([], '   ')->text);
        self::assertSame('why', ReportFocus::of([], '  why  ')->text);

        $long = str_repeat('a', ReportFocus::MAX_TEXT + 50);

        self::assertSame(ReportFocus::MAX_TEXT, mb_strlen((string) ReportFocus::of([], $long)->text));
    }

    /**
     * A row predating the feature has neither column, and reads back as the
     * full report it was — not an empty focus that means something new.
     */
    public function test_a_pre_focus_row_reads_back_as_everything(): void
    {
        $focus = ReportFocus::fromStored(null, null);

        self::assertTrue($focus->isEverything());
        self::assertTrue($focus->hasBlock('food'));
        self::assertSame(92, $focus->maxRangeDays());
    }

    /**
     * A replay reads the snapshot block, so it carries enough to rebuild the
     * question and to tell the model an absent block is a decision, not a gap.
     */
    public function test_the_snapshot_block_says_what_was_asked_and_what_is_here(): void
    {
        $focus = ReportFocus::of([ReportFocusArea::Stress], 'compare before and after daily weigh-ins');

        $block = $focus->toSnapshot();

        self::assertSame(['stress'], $block['areas']);
        self::assertSame('Stress', $block['areas_label']);
        self::assertSame('compare before and after daily weigh-ins', $block['question']);
        self::assertSame(366, $block['max_range_days']);
        self::assertContains('stress', $block['blocks_present']);
        self::assertNotContains('food', $block['blocks_present']);
        self::assertContains('stress_score', $block['day_columns_present']);
        self::assertStringContainsString('ABSENT from this document', $block['what_this_is']);

        // Present on an unfocused report too: a missing focus block and one
        // saying "everything" must not be two documents.
        $plain = ReportFocus::everything()->toSnapshot();

        self::assertSame([], $plain['areas']);
        self::assertNull($plain['question']);
        self::assertStringContainsString('No focus was asked for', $plain['what_this_is']);
    }
}
