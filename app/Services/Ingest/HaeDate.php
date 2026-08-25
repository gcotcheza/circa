<?php

declare(strict_types=1);

namespace App\Services\Ingest;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use App\Services\Ingest\Exceptions\MalformedDatapoint;

/**
 * Health Auto Export's date strings — every one of the 27,112 captured
 * datapoints is exactly 25 characters (`2026-08-07 10:00:00 +0200`). The
 * trailing offset is the whole point: it's what the clock on the wrist
 * said, and the only thing that makes the hour unambiguous — on the
 * October fall-back night, "2026-10-25 02:00:00" happens twice in
 * Amsterdam, and only the +0200/+0100 suffix separates them. Parse with
 * the offset, store absolute UTC, keep the offset in its own column — a
 * string parsed without its offset is a bug that produces
 * plausible-looking rows. A date without an offset is rejected rather
 * than assumed to be in the app timezone, since assuming would collapse
 * the two 02:00 buckets into one and lose an hour of a DST day, silently.
 */
final class HaeDate
{
    /** The observed format. `O` is "+0200", `P` is "+02:00". */
    private const FORMATS = [
        'Y-m-d H:i:s O',
        'Y-m-d H:i:s P',
        'Y-m-d\TH:i:sO',
        'Y-m-d\TH:i:sP',
    ];

    /**
     * Parse, keeping the device's own offset as the instance timezone.
     *
     * The returned value is NOT converted to UTC: callers need the local wall
     * clock (sleep's night key, the offset column) before they normalise. Call
     * ->utc() when writing.
     *
     * @throws MalformedDatapoint
     */
    public static function parse(string $raw, string $metric = 'unknown'): CarbonImmutable
    {
        $trimmed = trim($raw);

        foreach (self::FORMATS as $format) {
            try {
                // Carbon 3 throws on a mismatch rather than returning
                // false; `!` isn't used since every component is present —
                // a partial match would be a different string than the one
                // this parser accepts.
                $parsed = CarbonImmutable::createFromFormat($format, $trimmed);
            } catch (InvalidFormatException) {
                continue;
            }

            // Carbon returns null instead of throwing when strict mode is off;
            // an unparseable string is an unparseable string either way.
            if ($parsed === null) {
                continue;
            }

            // createFromFormat is lenient about trailing garbage in some
            // builds; round-tripping the format proves the whole string
            // was consumed.
            if ($parsed->format($format) === $trimmed) {
                return $parsed;
            }
        }

        throw MalformedDatapoint::unparseableDate($metric, $trimmed);
    }

    /**
     * The device's UTC offset in minutes (+0200 -> 120). smallint in the
     * schema is fine: real offsets span -720..+840.
     */
    public static function offsetMinutes(CarbonImmutable $at): int
    {
        return (int) ($at->getOffset() / 60);
    }
}
