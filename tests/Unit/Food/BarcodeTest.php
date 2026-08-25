<?php

declare(strict_types=1);

namespace Tests\Unit\Food;

use App\Services\Food\Barcode;
use PHPUnit\Framework\TestCase;

/**
 * Barcode parsing. The check digit earns its keep on manual entry: a scanner's
 * decoder already validates, a human copying thirteen digits off a jar does
 * not, and one wrong digit reads as "not in Open Food Facts", not "you
 * mistyped it".
 */
final class BarcodeTest extends TestCase
{
    public function test_it_accepts_a_real_ean_13(): void
    {
        // Nutella 400 g — the barcode this feature was verified against live.
        self::assertSame('3017624010701', Barcode::tryFrom('3017624010701')?->value);
    }

    public function test_it_accepts_ean_8_and_upc_a(): void
    {
        self::assertSame('96385074', Barcode::tryFrom('96385074')?->value);          // EAN-8
        self::assertSame('036000291452', Barcode::tryFrom('036000291452')?->value);  // UPC-A
    }

    public function test_it_expands_upc_e_to_the_upc_a_it_stands_for(): void
    {
        // 04252614 is the compressed printing of 042100005264: the can and the
        // multipack are the same number and must reach the same cache row.
        self::assertSame('042100005264', Barcode::tryFrom('04252614')?->value);
    }

    public function test_it_rejects_a_wrong_check_digit(): void
    {
        // One digit of the Nutella code changed: right shape, not a barcode.
        self::assertNull(Barcode::tryFrom('3017624010702'));
    }

    public function test_it_rejects_non_digits_and_impossible_lengths(): void
    {
        self::assertNull(Barcode::tryFrom('abcdefghijklm'));
        self::assertNull(Barcode::tryFrom('301762401070'.'1'.'1'));  // 14
        self::assertNull(Barcode::tryFrom('12345'));
        self::assertNull(Barcode::tryFrom(''));
        self::assertNull(Barcode::tryFrom('../../etc/passwd'));
    }

    public function test_it_tolerates_the_grouping_a_human_copies_off_a_package(): void
    {
        self::assertSame('3017624010701', Barcode::tryFrom(' 3 017624 010701 ')?->value);
        self::assertSame('3017624010701', Barcode::tryFrom('3-017624-010701')?->value);
    }
}
