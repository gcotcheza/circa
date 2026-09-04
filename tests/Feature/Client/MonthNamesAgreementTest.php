<?php

declare(strict_types=1);

namespace Tests\Feature\Client;

use Tests\TestCase;
use Carbon\CarbonImmutable;
use Tests\Concerns\ReadsSource;

/**
 * The client's month names against the ones the server actually prints.
 *
 * A stored report's header is written here by Carbon; the chip that asks for it
 * is written in the browser. Asked for a locale short month, en-GB answers
 * "Sept" — four letters, the only month it does not cut to three — so for the
 * whole of September the same range was labelled two ways on one screen. The
 * client keeps its own tables now, months and weekdays both, and this is the
 * only thing holding either to the server's wording.
 *
 * Read off the source rather than rendered, ReportedPageNameTest's reason: the
 * table is a constant in a module no PHP can call.
 */
final class MonthNamesAgreementTest extends TestCase
{
    use ReadsSource;

    public function test_the_client_names_every_month_the_way_the_server_prints_it(): void
    {
        $months = $this->clientTable('MONTHS');

        self::assertCount(12, $months, 'resources/js/lib/dates.js no longer exports twelve month names.');

        foreach ($months as $index => $name) {
            self::assertSame(
                CarbonImmutable::parse(sprintf('2026-%02d-15', $index + 1))->isoFormat('MMM'),
                $name,
                'Month '.($index + 1).' is written differently by the client and the server.',
            );
        }
    }

    public function test_the_client_names_every_weekday_the_way_the_server_prints_it(): void
    {
        $weekdays = $this->clientTable('WEEKDAY_NAMES');

        self::assertCount(7, $weekdays, 'resources/js/lib/dates.js no longer exports seven weekday names.');

        // 2026-01-04 is a Sunday, matching the table's `Date#getDay()` indexing.
        foreach ($weekdays as $index => $name) {
            self::assertSame(
                CarbonImmutable::parse('2026-01-04')->addDays($index)->isoFormat('ddd'),
                $name,
                'Weekday '.$index.' (Sunday-indexed) is written differently by the client and the server.',
            );
        }
    }

    /**
     * A name table, read out of the module's code rather than its prose.
     *
     * @return list<string>
     */
    private function clientTable(string $constant): array
    {
        $source = $this->sourceWithoutBlockComments('resources/js/lib/dates.js');

        if (preg_match('/export const '.$constant.' = \[([^\]]*)\]/', $source, $declaration) !== 1) {
            self::fail('resources/js/lib/dates.js no longer declares `export const '.$constant.' = [...]`.');
        }

        preg_match_all("/'([A-Za-z]+)'/", $declaration[1], $names);

        return $names[1];
    }
}
