<?php

declare(strict_types=1);

namespace App\Services\Food;

use Stringable;

/**
 * A validated retail barcode: EAN-13, EAN-8, UPC-A or UPC-E.
 *
 * A type rather than a `digits:13` rule: the barcode is a URL path segment
 * and a primary key, both wanting a value proven to be nothing but digits
 * of a known length in one place. The check digit is verified too, not
 * just the length — a mis-typed manual-entry digit (the scanner's own
 * decoder already validates) then reads as "that is not a valid barcode"
 * rather than a confusing "product not found".
 *
 * UPC-E IS EXPANDED TO UPC-A: it's a compressed printing of a UPC-A
 * number, not a number of its own — its check digit is computed over the
 * expansion, and Open Food Facts stores the expanded form, so the can's
 * small barcode and the multipack's big one reach the same cache row.
 * 12-digit UPC-A stays unpadded: OFF serves both spellings, and rewriting
 * the scan would make the stored key differ from what was actually
 * scanned for no demonstrable gain.
 */
final readonly class Barcode implements Stringable
{
    private function __construct(public string $value) {}

    /**
     * Parse a scanned or typed barcode, or null if it is not one.
     *
     * Spaces and hyphens are tolerated because a human typing a code off a
     * package copies its printed grouping; anything else non-numeric is a
     * refusal, not something to strip.
     */
    public static function tryFrom(string $raw): ?self
    {
        $digits = str_replace([' ', '-', "\u{00a0}"], '', trim($raw));

        if ($digits === '' || preg_match('/^\d+$/', $digits) !== 1) {
            return null;
        }

        return match (mb_strlen($digits)) {
            8       => self::fromEightDigits($digits),
            12, 13  => self::checksumIsValid($digits) ? new self($digits) : null,
            default => null,
        };
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Eight digits are ambiguous: EAN-8, or UPC-E which expands to UPC-A.
     *
     * Told apart by which reading has a valid check digit. UPC-E always
     * starts with number system 0 or 1, so expansion is only attempted
     * then — but EAN-8 is tried in both cases, since an EAN-8 beginning
     * with 0 is legal and must not be swallowed by a coincidental UPC-E
     * expansion.
     */
    private static function fromEightDigits(string $digits): ?self
    {
        if (self::checksumIsValid($digits)) {
            return new self($digits);
        }

        $expanded = self::expandUpcE($digits);

        return $expanded !== null && self::checksumIsValid($expanded)
            ? new self($expanded)
            : null;
    }

    /**
     * UPC-E (8 digits, `N D1..D6 C`) to the UPC-A it stands for.
     *
     * The suppression rule is chosen by D6, per the GS1 general specification.
     * The check digit rides along unchanged — it was always the UPC-A's.
     */
    private static function expandUpcE(string $code): ?string
    {
        $system = $code[0];

        if ($system !== '0' && $system !== '1') {
            return null;
        }

        [$d1, $d2, $d3, $d4, $d5, $d6] = str_split(mb_substr($code, 1, 6));
        $check = $code[7];

        return match ($d6) {
            '0', '1', '2' => "{$system}{$d1}{$d2}{$d6}0000{$d3}{$d4}{$d5}{$check}",
            '3'           => "{$system}{$d1}{$d2}{$d3}00000{$d4}{$d5}{$check}",
            '4'           => "{$system}{$d1}{$d2}{$d3}{$d4}00000{$d5}{$check}",
            default       => "{$system}{$d1}{$d2}{$d3}{$d4}{$d5}0000{$d6}{$check}",
        };
    }

    /**
     * GS1 modulo-10: weight the digits 3 and 1 alternately from the right,
     * excluding the check digit, and the check digit is what completes the sum
     * to a multiple of ten. Identical for EAN-8, UPC-A and EAN-13 — only the
     * length differs, and the weighting is anchored at the right-hand end.
     */
    private static function checksumIsValid(string $code): bool
    {
        $digits = array_map(intval(...), str_split($code));
        $check = array_pop($digits);

        $sum = 0;

        foreach (array_reverse($digits) as $index => $digit) {
            $sum += $digit * ($index % 2 === 0 ? 3 : 1);
        }

        return (10 - $sum % 10) % 10 === $check;
    }
}
