<?php

declare(strict_types=1);

namespace Tests\Support;

use ZipArchive;
use RuntimeException;

/**
 * Builds a real .xlsx on disk, from synthetic data, for the importer's tests.
 *
 * The real exports are the user's body-composition history and must never enter
 * the repository, so fixtures are generated rather than committed. Generating
 * also wins on what these tests exist for: the reader has to handle four cell
 * encodings and sparse rows, and a committed binary can only demonstrate the one
 * encoding whoever made it produced. Here the encoding is a parameter, so
 * `t="s"`, `t="str"`, `t="inlineStr"` and bare numeric cells are all exercised
 * against the same expected values — and a fixture is readable in the diff.
 *
 * Every number is made up. The SHAPES are real — two schemas, "- -" and empty as
 * absent sentinels, DD/MM/YYYY timestamps, newest-first ordering — because those
 * are what the code has to survive.
 */
final class XlsxFixture
{
    /** How cells carry their text. `str` is what Fitage writes. */
    public const STR = 'str';

    public const SHARED = 'shared';

    public const INLINE = 'inline';

    public const NUMERIC = 'numeric';

    /** @var list<list<string|null>> null omits the cell entirely (sparse row) */
    private array $rows = [];

    private string $encoding = self::STR;

    public static function make(): self
    {
        return new self;
    }

    public function encoding(string $encoding): self
    {
        $this->encoding = $encoding;

        return $this;
    }

    /** @param list<string|null> $cells */
    public function row(array $cells): self
    {
        $this->rows[] = $cells;

        return $this;
    }

    /** @param list<list<string|null>> $rows */
    public function rows(array $rows): self
    {
        foreach ($rows as $row) {
            $this->row($row);
        }

        return $this;
    }

    /**
     * The 18-column 2025 schema: BMI in D, Visceral fat in L, no Fat mass /
     * Protein Mass / Health Score columns.
     *
     * @return list<string>
     */
    public static function headerV1(): array
    {
        return [
            'Time of Measurement', 'Weight(kg)', 'Body fat(%)', 'BMI',
            'Skeletal Muscle(%)', 'Muscle mass(kg)', 'Muscle storage ability level',
            'Protein(%)', 'BMR(kcal)', 'Fat-Free Body Weight(kg)',
            'Subcutaneous fat(%)', 'Visceral fat', 'Body water(%)', 'Bone Mass(kg)',
            'Body Type', 'Metabolic Age', 'Device MAC Address', 'Device Name',
        ];
    }

    /**
     * The 28-column schema of the 2026 exports. The extra columns are
     * INTERLEAVED, not appended — BMI moves from D to E — which is why the
     * reader maps by header text.
     *
     * @return list<string>
     */
    public static function headerV2(): array
    {
        return [
            'Time of Measurement', 'Weight(kg)', 'Body fat(%)', 'Fat mass(kg)', 'BMI',
            'Skeletal Muscle(%)', 'Muscle Mass Percentage(%)', 'Muscle mass(kg)',
            'Muscle storage ability level', 'Protein(%)', 'Protein Mass(kg)', 'BMR(kcal)',
            'Fat-Free Body Weight(kg)', 'Subcutaneous fat(%)', 'Visceral fat',
            'Body water(%)', 'Body Water Mass(kg)', 'Bone Mass(kg)',
            'Bone Mass Percentage(%)', 'Health Score', 'Fat Control(kg)',
            'Muscle control(kg)', 'Weight control(kg)', 'Standard weight(kg)',
            'Body Type', 'Metabolic Age', 'Device MAC Address', 'Device Name',
        ];
    }

    /**
     * One 18-column measurement row. Every metric is a parameter so a test can
     * make a cell absent by passing '- -' or ''.
     *
     * @return list<string|null>
     */
    public static function rowV1(
        string $at,
        string $weight,
        string $bodyFat = '23.0',
        string $bmi = '21.2',
        string $muscleMass = '39.20',
        string $bmr = '1270',
        string $fatFree = '41.70',
        string $visceralFat = '4',
        string $bodyWater = '52.9',
        string $boneMass = '2.49',
    ): array {
        return [
            $at, $weight, $bodyFat, $bmi,
            '44.9', $muscleMass, '5',
            '18.4', $bmr, $fatFree,
            '21.4', $visceralFat, $bodyWater, $boneMass,
            'Normal', '38', 'FF:05:00:04:DF:3C', 'FITAGE-21',
        ];
    }

    /**
     * One 28-column measurement row.
     *
     * @return list<string|null>
     */
    public static function rowV2(
        string $at,
        string $weight,
        string $bodyFat = '24.5',
        string $bmi = '22.1',
        string $muscleMass = '40.08',
        string $bmr = '1291',
        string $fatFree = '42.62',
        string $visceralFat = '4',
        string $bodyWater = '51.8',
        string $boneMass = '2.60',
    ): array {
        return [
            $at, $weight, $bodyFat, '13.83', $bmi,
            '44.0', '71.0', $muscleMass,
            '5', '18.0', '10.16', $bmr,
            $fatFree, '22.8', $visceralFat,
            $bodyWater, '29.24', $boneMass,
            '4.6', '90.4', '-1.1',
            '0', '-1.1', '55.35',
            '', '39', 'FF:05:00:04:DF:3C', 'FITAGE-21',
        ];
    }

    /** Writes the workbook and returns the path it was written to. */
    public function write(string $path): string
    {
        @mkdir(dirname($path), 0755, true);

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create '.$path);
        }

        [$sheet, $shared] = $this->sheet();

        $zip->addFromString('[Content_Types].xml', $this->contentTypes($shared !== []));
        $zip->addFromString('_rels/.rels', $this->rootRels());
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels($shared !== []));
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);

        if ($shared !== []) {
            $zip->addFromString('xl/sharedStrings.xml', $this->sharedStrings($shared));
        }

        $zip->close();

        return $path;
    }

    /**
     * @return array{0: string, 1: list<string>} sheet XML and the shared table
     */
    private function sheet(): array
    {
        $shared = [];
        $body = '';

        foreach ($this->rows as $number => $cells) {
            $line = $number + 1;
            $body .= '<row r="'.$line.'">';

            foreach ($cells as $index => $value) {
                // null: the cell is absent — the sparse case a positional reader
                // gets wrong.
                if ($value === null) {
                    continue;
                }

                $reference = self::column($index).$line;

                $body .= match ($this->encoding) {
                    self::SHARED => $this->sharedCell($reference, $value, $shared),
                    self::INLINE => '<c r="'.$reference.'" t="inlineStr"><is><t>'
                        .self::escape($value).'</t></is></c>',
                    self::NUMERIC => '<c r="'.$reference.'"><v>'.self::escape($value).'</v></c>',
                    default       => '<c r="'.$reference.'" t="str"><v>'.self::escape($value).'</v></c>',
                };
            }

            $body .= '</row>';
        }

        $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<sheetData>'.$body.'</sheetData></worksheet>';

        return [$sheet, $shared];
    }

    /** @param list<string> $shared */
    private function sharedCell(string $reference, string $value, array &$shared): string
    {
        $index = array_search($value, $shared, strict: true);

        if ($index === false) {
            $shared[] = $value;
            $index = count($shared) - 1;
        }

        return '<c r="'.$reference.'" t="s"><v>'.$index.'</v></c>';
    }

    /** @param list<string> $shared */
    private function sharedStrings(array $shared): string
    {
        $items = '';

        foreach ($shared as $string) {
            // Longer entries split across two runs, so the reader's rich-text
            // concatenation is exercised rather than assumed.
            $items .= strlen($string) > 4
                ? '<si><r><t xml:space="preserve">'.self::escape(substr($string, 0, 2))
                    .'</t></r><r><t xml:space="preserve">'.self::escape(substr($string, 2)).'</t></r></si>'
                : '<si><t xml:space="preserve">'.self::escape($string).'</t></si>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="'
            .count($shared).'" uniqueCount="'.count($shared).'">'.$items.'</sst>';
    }

    private function contentTypes(bool $withShared): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .($withShared
                ? '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
                : '')
            .'</Types>';
    }

    private function rootRels(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
    }

    private function workbook(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            .' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private function workbookRels(bool $withShared): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .($withShared
                ? '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>'
                : '')
            .'</Relationships>';
    }

    private static function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
