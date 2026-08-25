<?php

declare(strict_types=1);

namespace Tests\Unit\Fitage;

use Tests\TestCase;
use Tests\Support\XlsxFixture;
use App\Services\Fitage\Measurement;
use App\Services\Fitage\FitageExport;
use App\Services\Fitage\Exceptions\UnreadableExport;

/**
 * A file, read into measurements. Two things matter: the two schemas produce the
 * SAME measurements, and an absent cell produces no value rather than a zero.
 */
final class FitageExportTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = storage_path('framework/testing/fitage-'.uniqid());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->directory);

        parent::tearDown();
    }

    private function read(XlsxFixture $fixture, string $name = 'export.xlsx'): FitageExport
    {
        return FitageExport::read($fixture->write($this->directory.'/'.$name), 'Europe/Amsterdam');
    }

    /**
     * Why mapping is by header text: the 2025 schema has 18 columns with BMI in
     * D, the 2026 schema 28 with BMI in E. Same weigh-in, two files, one set of
     * measurements out.
     */
    public function test_both_export_schemas_produce_the_same_measurement(): void
    {
        $v1 = $this->read(XlsxFixture::make()->rows([
            XlsxFixture::headerV1(),
            XlsxFixture::rowV1(
                at: '13/05/2025 15:38:23', weight: '56.00', bodyFat: '24.0', bmi: '21.9',
                muscleMass: '39.98', bmr: '1289', fatFree: '42.62',
                visceralFat: '4', bodyWater: '52.2', boneMass: '2.58',
            ),
        ]), 'v1.xlsx');

        $v2 = $this->read(XlsxFixture::make()->rows([
            XlsxFixture::headerV2(),
            XlsxFixture::rowV2(
                at: '13/05/2025 15:38:23', weight: '56.00', bodyFat: '24.0', bmi: '21.9',
                muscleMass: '39.98', bmr: '1289', fatFree: '42.62',
                visceralFat: '4', bodyWater: '52.2', boneMass: '2.58',
            ),
        ]), 'v2.xlsx');

        $expected = [
            'weight_body_mass'      => 56.0,
            'body_fat_percentage'   => 24.0,
            'body_mass_index'       => 21.9,
            'muscle_mass'           => 39.98,
            'basal_metabolic_rate'  => 1289.0,
            'lean_body_mass'        => 42.62,
            'visceral_fat'          => 4.0,
            'body_water_percentage' => 52.2,
            'bone_mass'             => 2.58,
        ];

        foreach ([$v1, $v2] as $export) {
            self::assertCount(1, $export->measurements);
            self::assertSame([], $export->anomalies);

            $measurement = $export->measurements[0];

            self::assertSame('2025-05-13 15:38:23', $measurement->measuredAt->toDateTimeString());

            ksort($expected);
            $values = $measurement->values;
            ksort($values);

            self::assertSame($expected, $values);
        }
    }

    /**
     * A weigh-in in socks: a weight, a BMI, nothing else. The 2025 file writes
     * the rest as "- -", the 2026 file leaves them empty; neither is a zero.
     */
    public function test_absent_cells_import_nothing_rather_than_zero(): void
    {
        $dashes = $this->read(XlsxFixture::make()->rows([
            XlsxFixture::headerV1(),
            XlsxFixture::rowV1(
                at: '13/07/2025 11:30:35', weight: '55.20', bodyFat: '- -', bmi: '21.5',
                muscleMass: '- -', bmr: '- -', fatFree: '- -',
                visceralFat: '- -', bodyWater: '- -', boneMass: '- -',
            ),
        ]), 'dashes.xlsx');

        $blanks = $this->read(XlsxFixture::make()->rows([
            XlsxFixture::headerV2(),
            XlsxFixture::rowV2(
                at: '13/07/2025 11:30:35', weight: '55.20', bodyFat: '', bmi: '21.5',
                muscleMass: '', bmr: '', fatFree: '',
                visceralFat: '', bodyWater: '', boneMass: '',
            ),
        ]), 'blanks.xlsx');

        foreach ([$dashes, $blanks] as $export) {
            $values = $export->measurements[0]->values;

            self::assertSame(['weight_body_mass' => 55.2, 'body_mass_index' => 21.5], $values);
            self::assertNotContains(0.0, $values);
            self::assertSame([], $export->anomalies);
        }
    }

    /** A cell that is missing from the file entirely, not merely empty. */
    public function test_a_cell_omitted_from_the_file_is_simply_absent(): void
    {
        $row = XlsxFixture::rowV1(at: '07/07/2025 07:24:02', weight: '54.15');
        $row[2] = null;  // Body fat(%) — the cell is not in the XML at all
        $row[11] = null; // Visceral fat

        // array_values(): a value written to an existing offset keeps the list
        // at runtime, but PHPStan can no longer prove it stayed contiguous.
        $export = $this->read(XlsxFixture::make()->rows([XlsxFixture::headerV1(), array_values($row)]));

        $values = $export->measurements[0]->values;

        self::assertArrayNotHasKey('body_fat_percentage', $values);
        self::assertArrayNotHasKey('visceral_fat', $values);
        self::assertSame(54.15, $values['weight_body_mass']);
        self::assertSame([], $export->anomalies);
    }

    /**
     * A file is newest-first and nothing may depend on that: rows are keyed on
     * their own instant, so order is irrelevant and stays so.
     */
    public function test_rows_are_read_in_file_order_and_dates_come_back_sorted(): void
    {
        $export = $this->read(XlsxFixture::make()->rows([
            XlsxFixture::headerV1(),
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20'),
            XlsxFixture::rowV1(at: '07/07/2025 07:24:02', weight: '54.15'),
            XlsxFixture::rowV1(at: '04/07/2025 06:18:28', weight: '54.95'),
        ]));

        self::assertSame(
            ['2025-07-13', '2025-07-07', '2025-07-04'],
            array_map(static fn (Measurement $m): string => $m->localDate(), $export->measurements),
        );

        self::assertSame(['2025-07-04', '2025-07-07', '2025-07-13'], $export->dates());
        self::assertSame('2025-07-04 06:18:28', $export->firstAt());
        self::assertSame('2025-07-13 11:30:35', $export->lastAt());
    }

    /** One bad row must not cost the other rows. */
    public function test_an_unreadable_row_is_skipped_reported_and_costs_nothing_else(): void
    {
        $export = $this->read(XlsxFixture::make()->rows([
            XlsxFixture::headerV1(),
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20'),
            XlsxFixture::rowV1(at: 'yesterday morning', weight: '54.15'),
            XlsxFixture::rowV1(at: '04/07/2025 06:18:28', weight: '54.95'),
        ]));

        self::assertCount(2, $export->measurements);
        self::assertSame(3, $export->dataRows);
        self::assertCount(1, $export->anomalies);
        self::assertStringContainsString('row 3', $export->anomalies[0]);
    }

    /** A number that stopped being a number is reported, not coerced. */
    public function test_a_cell_in_an_unexpected_number_format_is_reported(): void
    {
        $export = $this->read(XlsxFixture::make()->rows([
            XlsxFixture::headerV1(),
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55,20'),
        ]));

        $values = $export->measurements[0]->values;

        self::assertArrayNotHasKey('weight_body_mass', $values);
        self::assertCount(1, $export->anomalies);
        self::assertStringContainsString('not a plain decimal', $export->anomalies[0]);
    }

    public function test_trailing_blank_rows_are_not_measurements(): void
    {
        $export = $this->read(XlsxFixture::make()->rows([
            XlsxFixture::headerV1(),
            XlsxFixture::rowV1(at: '13/07/2025 11:30:35', weight: '55.20'),
            array_fill(0, 18, ''),
            array_fill(0, 18, ''),
        ]));

        self::assertSame(1, $export->dataRows);
        self::assertCount(1, $export->measurements);
        self::assertSame([], $export->anomalies);
    }

    public function test_a_sheet_with_no_timestamp_column_is_refused(): void
    {
        $this->expectException(UnreadableExport::class);

        $this->read(XlsxFixture::make()->rows([
            ['When', 'Weight(kg)'],
            ['13/07/2025 11:30:35', '55.20'],
        ]));
    }
}
