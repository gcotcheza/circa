<?php

declare(strict_types=1);

namespace App\Services\Fitage\Exceptions;

use RuntimeException;

/**
 * The file itself cannot be read.
 *
 * Distinct from a bad ROW, which is skipped and counted (see FitageExport):
 * a whole unreadable file is a human's mistake — wrong file, truncated
 * download — and the import should say so, not silently report
 * "0 measurements found" and look like it worked.
 */
final class UnreadableExport extends RuntimeException
{
    public static function missing(string $path): self
    {
        return new self(sprintf('Cannot read %s — no such file, or not readable.', $path));
    }

    public static function notAWorkbook(string $path): self
    {
        return new self(sprintf(
            '%s is not an xlsx workbook (no zip container, or no worksheet inside it).',
            $path
        ));
    }

    public static function malformedXml(string $path): self
    {
        return new self(sprintf('%s contains a worksheet that is not well-formed XML.', $path));
    }

    public static function noHeaderRow(string $path): self
    {
        return new self(sprintf(
            '%s has no header row — expected a first row naming the columns, starting with "Time of Measurement".',
            $path
        ));
    }
}
