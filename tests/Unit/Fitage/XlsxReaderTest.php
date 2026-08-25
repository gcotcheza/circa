<?php

declare(strict_types=1);

namespace Tests\Unit\Fitage;

use Tests\TestCase;
use Tests\Support\XlsxFixture;
use App\Services\Fitage\XlsxReader;
use App\Services\Fitage\Exceptions\UnreadableExport;

/**
 * 200 lines of hand-rolled OOXML in place of a 2 MB dependency — defensible
 * only if every case that dependency covered is tested here.
 */
final class XlsxReaderTest extends TestCase
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

    private function write(XlsxFixture $fixture, string $name = 'export.xlsx'): string
    {
        return $fixture->write($this->directory.'/'.$name);
    }

    /**
     * All four OOXML cell encodings read to the same values: Fitage writes
     * `str`, Excel `s`, other writers `inlineStr`, a numeric cell no `t`.
     */
    public function test_every_cell_encoding_reads_to_the_same_values(): void
    {
        $rows = [
            ['Time of Measurement', 'Weight(kg)', 'Visceral fat'],
            ['13/05/2025 15:38:23', '56.00', '4'],
        ];

        foreach ([XlsxFixture::STR, XlsxFixture::SHARED, XlsxFixture::INLINE, XlsxFixture::NUMERIC] as $encoding) {
            $path = $this->write(
                XlsxFixture::make()->encoding($encoding)->rows($rows),
                $encoding.'.xlsx'
            );

            self::assertSame(
                [
                    ['A' => 'Time of Measurement', 'B' => 'Weight(kg)', 'C' => 'Visceral fat'],
                    ['A' => '13/05/2025 15:38:23', 'B' => '56.00', 'C' => '4'],
                ],
                XlsxReader::rows($path),
                'encoding: '.$encoding,
            );
        }
    }

    /**
     * Corrupts rather than fails: OOXML lets a row omit empty cells, so read
     * positionally a row missing B imports C as B — body fat filed as weight,
     * every number still plausible.
     */
    public function test_a_sparse_row_keeps_its_columns_where_they_belong(): void
    {
        $path = $this->write(XlsxFixture::make()->rows([
            ['Time of Measurement', 'Weight(kg)', 'Body fat(%)', 'BMI'],
            // B and C are absent from the file; D is still D.
            ['13/05/2025 15:38:23', null, null, '21.9'],
        ]));

        $rows = XlsxReader::rows($path);

        self::assertSame(['A' => '13/05/2025 15:38:23', 'D' => '21.9'], $rows[1]);
        self::assertArrayNotHasKey('B', $rows[1]);
    }

    /** Past Z: the 28-column schema reaches AB. */
    public function test_it_reads_two_letter_columns(): void
    {
        $header = array_map(static fn (int $i): string => 'col'.$i, range(1, 28));

        $path = $this->write(XlsxFixture::make()->row($header));

        $row = XlsxReader::rows($path)[0];

        self::assertSame('col26', $row['Z']);
        self::assertSame('col27', $row['AA']);
        self::assertSame('col28', $row['AB']);
    }

    /**
     * A shared string split across formatting runs is one value, not its first
     * fragment — the fixture splits every string over four characters in two.
     */
    public function test_rich_text_runs_are_concatenated(): void
    {
        $path = $this->write(
            XlsxFixture::make()->encoding(XlsxFixture::SHARED)->row(['Time of Measurement', 'FITAGE-21'])
        );

        self::assertSame(
            ['A' => 'Time of Measurement', 'B' => 'FITAGE-21'],
            XlsxReader::rows($path)[0],
        );
    }

    public function test_a_missing_file_is_an_error_not_an_empty_sheet(): void
    {
        $this->expectException(UnreadableExport::class);

        XlsxReader::rows($this->directory.'/nothing-here.xlsx');
    }

    public function test_a_file_that_is_not_a_workbook_is_an_error(): void
    {
        @mkdir($this->directory, 0755, true);

        $path = $this->directory.'/not-really.xlsx';
        file_put_contents($path, 'this is a csv, actually');

        $this->expectException(UnreadableExport::class);

        XlsxReader::rows($path);
    }
}
