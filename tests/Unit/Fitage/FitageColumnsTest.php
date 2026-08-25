<?php

declare(strict_types=1);

namespace Tests\Unit\Fitage;

use PHPUnit\Framework\TestCase;
use App\Services\Fitage\FitageColumns;
use App\Services\Ingest\MetricCatalog;

/** How a Fitage cell reads: a small surface, every case a way to bank an unmeasured number. */
final class FitageColumnsTest extends TestCase
{
    // ------------------------------------------------------ timestamps -----

    /** DD/MM/YYYY: half the year both parse, and the only symptom is a history out of order. */
    public function test_a_day_first_date_is_read_day_first(): void
    {
        $at = FitageColumns::timestamp('05/01/2026 07:26:49', 'Europe/Amsterdam');

        self::assertNotNull($at);
        self::assertSame('2026-01-05 07:26:49', $at->toDateTimeString());
        self::assertSame('2026-01-05', $at->toDateString());
    }

    /** The export carries no offset, so the zone comes from configuration. */
    public function test_it_reads_the_wall_clock_in_the_reporting_timezone(): void
    {
        $summer = FitageColumns::timestamp('13/05/2025 15:38:23', 'Europe/Amsterdam');
        $winter = FitageColumns::timestamp('05/01/2026 07:26:49', 'Europe/Amsterdam');

        self::assertNotNull($summer);
        self::assertNotNull($winter);

        // CEST in May, CET in January — the difference the offset column keeps.
        self::assertSame(120, (int) ($summer->getOffset() / 60));
        self::assertSame(60, (int) ($winter->getOffset() / 60));

        self::assertSame('2025-05-13 13:38:23', $summer->utc()->toDateTimeString());
        self::assertSame('2026-01-05 06:26:49', $winter->utc()->toDateTimeString());
    }

    /**
     * 02:30 does not exist in Amsterdam on the spring-forward night and Carbon
     * silently returns 03:30, so the round-trip check rejects rather than fabricates.
     */
    public function test_a_timestamp_inside_the_spring_forward_gap_is_rejected(): void
    {
        self::assertNull(FitageColumns::timestamp('29/03/2026 02:30:00', 'Europe/Amsterdam'));
    }

    public function test_rubbish_dates_are_rejected_rather_than_guessed_at(): void
    {
        foreach (['', 'not a date', '2026-08-09 09:32:20', '32/01/2026 07:00:00', '09/08/2026'] as $raw) {
            self::assertNull(FitageColumns::timestamp($raw, 'Europe/Amsterdam'), $raw);
        }
    }

    // ----------------------------------------------------------- cells -----

    /**
     * The two sentinels both export schemas use for "no reading". Both import as
     * nothing — a 0 here is a body-fat sample that never happened.
     */
    public function test_both_absent_sentinels_are_absent(): void
    {
        foreach (['', ' ', '- -', '--', '-', 'N/A', 'Normal'] as $raw) {
            self::assertTrue(FitageColumns::isAbsent($raw), var_export($raw, true));
        }
    }

    public function test_a_real_reading_is_not_absent(): void
    {
        foreach (['0', '4', '56.45', '1291', '-1.1'] as $raw) {
            self::assertFalse(FitageColumns::isAbsent($raw), $raw);
        }
    }

    public function test_numbers_are_read_as_decimals(): void
    {
        self::assertSame(56.45, FitageColumns::number('56.45'));
        self::assertSame(4.0, FitageColumns::number('4'));
        self::assertSame(1291.0, FitageColumns::number(' 1291 '));
        self::assertSame(-1.1, FitageColumns::number('-1.1'));
    }

    /**
     * Digits that are not a plain decimal are a FORMAT CHANGE, reported. Coercing
     * is the quiet disaster: (float) '1,234' is 1.0, and 1.0 kg of muscle mass
     * sits in the history looking almost believable.
     */
    public function test_a_number_in_an_unexpected_format_is_not_coerced(): void
    {
        foreach (['1,234', '56,45', '56.45 kg', '1e3', '5%'] as $raw) {
            self::assertNull(FitageColumns::number($raw), $raw);
        }
    }

    // --------------------------------------------------------- headers -----

    public function test_headers_are_matched_case_and_spacing_insensitively(): void
    {
        foreach (['Body fat(%)', 'BODY FAT(%)', ' Body  fat(%) ', "Body\u{00A0}fat(%)"] as $header) {
            self::assertSame(
                ['body_fat_percentage', '%'],
                FitageColumns::metricFor($header),
                $header,
            );
        }
    }

    public function test_the_derived_columns_are_not_mapped(): void
    {
        foreach ([
            'Fat Control(kg)', 'Muscle control(kg)', 'Weight control(kg)',
            'Standard weight(kg)', 'Health Score', 'Body Type', 'Metabolic Age',
            'Device MAC Address', 'Device Name', 'Muscle storage ability level',
        ] as $header) {
            self::assertNull(FitageColumns::metricFor($header), $header);
        }
    }

    /**
     * A column mapped to a metric the catalog does not know gets an unknown unit
     * and an Avg aggregation — silently wrong for a body-composition sample.
     */
    public function test_every_mapped_metric_is_a_known_instant_in_the_catalog(): void
    {
        foreach (FitageColumns::METRICS as $header => [$metric, $unit]) {
            self::assertTrue(MetricCatalog::isKnown($metric), $header);
            self::assertTrue(MetricCatalog::isInstant($metric), $header);
            self::assertFalse(MetricCatalog::isCumulative($metric), $header);
            self::assertSame($unit, MetricCatalog::canonicalUnitFor($metric, $unit), $header);
        }
    }

    /**
     * The scale's bioimpedance BMR must never be mistaken for Apple's measured
     * basal energy: a kcal/day rate off a body-fat model against the hourly
     * measurement every expenditure number is built from.
     */
    public function test_the_scales_bmr_is_a_different_metric_from_apples_basal_energy(): void
    {
        self::assertSame(['basal_metabolic_rate', 'kcal'], FitageColumns::metricFor('BMR(kcal)'));

        // Apple's is a cumulative hourly total that GREATEST()s upward; the scale's
        // an instant that restates. Different upsert directions, no path between.
        self::assertTrue(MetricCatalog::isCumulative('basal_energy_burned'));
        self::assertFalse(MetricCatalog::isCumulative('basal_metabolic_rate'));
        self::assertTrue(MetricCatalog::isInstant('basal_metabolic_rate'));
        self::assertFalse(MetricCatalog::isInstant('basal_energy_burned'));
    }
}
