<?php

declare(strict_types=1);

namespace App\Services\Fitage;

use ZipArchive;
use SimpleXMLElement;
use App\Services\Fitage\Exceptions\UnreadableExport;

/**
 * The smallest thing that can read a Fitage export: xlsx -> rows of strings.
 *
 * PhpSpreadsheet is ~2 MB, pulls in six more packages, and solves problems
 * this file doesn't have (styles, formulas, charts, merged cells, streaming,
 * writing). A Fitage export is one sheet, one header row, a few dozen data
 * rows, every cell a string — the whole of what's needed is below and
 * auditable in one sitting, which matters more than generality for a format
 * re-read on every export.
 *
 * The cost of skipping the dependency is handling unusual encodings by hand,
 * so all four OOXML permits are handled explicitly rather than assumed away:
 *
 *   t="s"          index into xl/sharedStrings.xml     (what Excel writes)
 *   t="inlineStr"  <is><t>text</t></is>                (what some writers emit)
 *   t="str"        <v>text</v>, a formula's result     (WHAT FITAGE WRITES)
 *   no t / t="n"   <v>number</v>
 *
 * Fitage writes every cell — dates, weights, MAC addresses — as t="str" and
 * ships no sharedStrings.xml; handling only the two the spec calls "normal"
 * would read real files as blank, so this is the observed range plus its two
 * neighbours, not defensive coding.
 *
 * Rows are keyed by COLUMN LETTER, not position: cells are sparse in OOXML
 * (a row whose C and D are empty may just omit them), so a positional reader
 * would shift every later value left and silently import body fat as BMI.
 * The letter is in the cell's own `r` attribute, authoritative and free to
 * respect.
 */
final class XlsxReader
{
    /** The OOXML namespaces we name. */
    private const NS_RELS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * Every row of the workbook's first sheet, in sheet order.
     *
     * @return list<array<string, string>> column letter => cell text
     *
     * @throws UnreadableExport
     */
    public static function rows(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw UnreadableExport::missing($path);
        }

        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            throw UnreadableExport::notAWorkbook($path);
        }

        try {
            $shared = self::sharedStrings($zip, $path);
            $sheet = self::parse($zip->getFromName(self::firstSheetPath($zip, $path)), $path);

            $rows = [];

            foreach ($sheet->sheetData->row as $row) {
                $rows[] = self::cells($row, $shared);
            }

            return $rows;
        } finally {
            $zip->close();
        }
    }

    /**
     * @param  list<string>  $shared
     * @return array<string, string>
     */
    private static function cells(SimpleXMLElement $row, array $shared): array
    {
        $cells = [];

        // Only used when a cell omits `r` entirely — nothing observed does,
        // but a positional fallback beats dropping the cell.
        $position = 0;

        foreach ($row->c as $cell) {
            $reference = (string) $cell['r'];

            $column = $reference !== '' && preg_match('/^([A-Z]+)/', $reference, $match) === 1
                ? $match[1]
                : self::columnName($position);

            $position = self::columnIndex($column) + 1;

            $cells[$column] = self::value($cell, $shared);
        }

        return $cells;
    }

    /**
     * One cell's text, whichever of the four encodings it arrived in.
     *
     * @param  list<string>  $shared
     */
    private static function value(SimpleXMLElement $cell, array $shared): string
    {
        return match ((string) $cell['t']) {
            's'         => isset($cell->v) ? ($shared[(int) $cell->v] ?? '') : '',
            'inlineStr' => isset($cell->is) ? self::text($cell->is) : '',
            // 'str' (formula result), 'n' (number), 'b', 'e' and no-type all
            // carry text in <v>. Booleans/errors aren't produced by Fitage and
            // are read verbatim rather than translated — inventing "TRUE" for
            // a 1 would be a guess nobody asked for.
            default => isset($cell->v) ? (string) $cell->v : '',
        };
    }

    /**
     * xl/sharedStrings.xml, flattened. Absent in every Fitage export so far,
     * so this usually returns [].
     *
     * @return list<string>
     */
    private static function sharedStrings(ZipArchive $zip, string $path): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');

        if ($raw === false) {
            return [];
        }

        $strings = [];

        foreach (self::parse($raw, $path)->si as $item) {
            $strings[] = self::text($item);
        }

        return $strings;
    }

    /**
     * All <t> text under an element, concatenated.
     *
     * Rich text splits one string across several <r> runs — "FITAGE" bold
     * plus "-21" plain is two runs, one value — taking only the first would
     * truncate it.
     */
    private static function text(SimpleXMLElement $element): string
    {
        $parts = $element->xpath('.//*[local-name()="t"]') ?: [];

        return implode('', array_map(static fn (SimpleXMLElement $t): string => (string) $t, $parts));
    }

    /**
     * The path inside the zip of the sheet the workbook lists first.
     *
     * Resolved through the relationship id rather than assumed to be
     * `sheet1.xml`: numbering follows creation order, not sheet order, so a
     * workbook whose only sheet is `sheet2.xml` is legal. Falls back to the
     * conventional path when the rels can't be read — a missing relationship
     * isn't a reason to refuse a file we can otherwise parse.
     */
    private static function firstSheetPath(ZipArchive $zip, string $path): string
    {
        $fallback = 'xl/worksheets/sheet1.xml';

        $workbook = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbook === false || $rels === false) {
            return $fallback;
        }

        $sheet = self::parse($workbook, $path)->sheets->sheet[0] ?? null;

        if ($sheet === null) {
            return $fallback;
        }

        $id = (string) $sheet->attributes(self::NS_RELS)['id'];

        foreach (self::parse($rels, $path)->Relationship as $relationship) {
            if ((string) $relationship['Id'] !== $id) {
                continue;
            }

            $target = ltrim((string) $relationship['Target'], '/');

            // Targets are relative to xl/ unless they are already absolute
            // within the package.
            return str_starts_with($target, 'xl/') ? $target : 'xl/'.$target;
        }

        return $fallback;
    }

    /**
     * @throws UnreadableExport
     */
    private static function parse(string|false $xml, string $path): SimpleXMLElement
    {
        if ($xml === false || trim($xml) === '') {
            throw UnreadableExport::notAWorkbook($path);
        }

        // LIBXML_NONET and, deliberately, NOT LIBXML_NOENT: entity
        // substitution stays off, so a crafted workbook can't read
        // /etc/passwd via an external entity — an XXE through a health
        // importer would be an absurd way to lose a server.
        $previous = libxml_use_internal_errors(true);

        try {
            $parsed = simplexml_load_string($xml, options: LIBXML_NONET);

            if ($parsed === false) {
                throw UnreadableExport::malformedXml($path);
            }

            return $parsed;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** 'A' => 0, 'Z' => 25, 'AA' => 26. */
    private static function columnIndex(string $column): int
    {
        $index = 0;

        foreach (str_split($column) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return $index - 1;
    }

    /** The inverse, for the positional fallback. */
    private static function columnName(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }
}
