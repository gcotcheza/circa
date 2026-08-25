<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use Tests\TestCase;
use App\Services\Report\WrittenReport;
use Database\Factories\HealthReportFactory;

/**
 * Reading one stored answer. The obvious half is that a well-formed document
 * parses; the other is that a document written by an EARLIER prompt version —
 * the whole reason the prompt is versioned — still renders, because otherwise
 * every historical report becomes a 500 on the list page the day the schema
 * moves.
 */
final class WrittenReportTest extends TestCase
{
    public function test_it_reads_a_well_formed_document(): void
    {
        $report = WrittenReport::fromDecoded(HealthReportFactory::document());

        self::assertStringContainsString('steady week', $report->headline);
        self::assertStringContainsString('Seven days', $report->summary);
        self::assertCount(1, $report->micronutrients);
        self::assertCount(1, $report->observations);
        self::assertCount(1, $report->suggestions);
        self::assertCount(2, $report->dataGaps);
    }

    public function test_the_props_are_camel_cased_for_the_front_end(): void
    {
        $props = WrittenReport::fromDecoded(HealthReportFactory::document())->toArray();

        self::assertArrayHasKey('energyBalance', $props);
        self::assertArrayHasKey('stressPatterns', $props);
        self::assertArrayNotHasKey('energy_balance', $props);
    }

    public function test_a_micronutrient_row_keeps_its_printed_name_untranslated(): void
    {
        $props = WrittenReport::fromDecoded(HealthReportFactory::document())->toArray();

        self::assertSame('Vitamine B12 (als cyanocobalamine)', $props['micronutrients'][0]['nutrient']);
    }

    /**
     * Null and empty-string both mean "no food-side figure", and the screen has
     * exactly one way of saying that.
     */
    public function test_an_empty_food_range_is_collapsed_to_null(): void
    {
        $props = WrittenReport::fromDecoded([
            'micronutrients' => [
                ['nutrient' => 'Fibre', 'supplement_exact' => 'none', 'food_estimate_range' => '   ', 'food_coverage_pct' => 4.2, 'combined_comment' => ''],
            ],
        ])->toArray();

        self::assertNull($props['micronutrients'][0]['foodEstimateRange']);
        self::assertSame(4.2, $props['micronutrients'][0]['foodCoveragePct']);
    }

    /**
     * A document from an older schema: every section it lacks comes back empty
     * rather than throwing, because the list page has to keep working.
     */
    public function test_a_document_from_an_older_schema_still_parses(): void
    {
        $report = WrittenReport::fromDecoded(['summary' => 'An older report.']);

        self::assertSame('An older report.', $report->summary);
        self::assertSame('', $report->headline);
        self::assertSame([], $report->micronutrients);
        self::assertSame([], $report->observations);
        self::assertSame([], $report->dataGaps);
    }

    public function test_rows_of_the_wrong_shape_are_dropped_rather_than_rendered_half_empty(): void
    {
        $report = WrittenReport::fromDecoded([
            // A nutrient row with no name is not a row about a nutrient.
            'micronutrients' => [['supplement_exact' => '500 µg'], ['nutrient' => 'Magnesium', 'supplement_exact' => '120 mg']],
            'observations'   => ['a bare string', ['observation' => 'Real one.', 'evidence' => '8 Aug']],
            'data_gaps'      => ['', '  ', 'A real gap.'],
        ]);

        self::assertCount(1, $report->micronutrients);
        self::assertSame('Magnesium', $report->micronutrients[0]['nutrient']);

        self::assertCount(1, $report->observations);
        self::assertSame('Real one.', $report->observations[0]['observation']);

        self::assertSame(['A real gap.'], $report->dataGaps);
    }

    public function test_a_non_numeric_coverage_is_dropped_rather_than_cast_to_zero(): void
    {
        $props = WrittenReport::fromDecoded([
            'micronutrients' => [
                ['nutrient' => 'Fibre', 'supplement_exact' => '', 'food_estimate_range' => '2-4 g', 'food_coverage_pct' => 'about 4%', 'combined_comment' => ''],
            ],
        ])->toArray();

        // Zero reads as "none of the food is covered", a much stronger claim
        // than "we could not read it".
        self::assertNull($props['micronutrients'][0]['foodCoveragePct']);
    }
}
