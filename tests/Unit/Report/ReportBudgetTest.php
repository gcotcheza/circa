<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use Tests\TestCase;
use App\Enums\ReportFocusArea;
use App\Services\Report\ReportFocus;
use App\Services\Report\ReportBudget;

/**
 * The fuse. Range ceilings bound ROWS; this bounds the DOCUMENT, which is what
 * is billed — a year of complete supplement logging, or a fortnight of meals
 * photographed item by item, moves one without moving the other.
 *
 * Not tested: the accuracy of chars/4, approximate on purpose with headroom in
 * the ceiling (see the config note). Tested: a document an order of magnitude
 * too big is refused BEFORE the call, saying something a person can act on.
 */
final class ReportBudgetTest extends TestCase
{
    public function test_it_estimates_from_the_json_the_writer_would_actually_send(): void
    {
        $snapshot = ['range' => ['start' => '2026-08-01'], 'note' => str_repeat('x', 4_000)];

        $expected = (int) ceil(strlen((string) json_encode($snapshot, ReportBudget::ENCODE_FLAGS)) / 4);

        self::assertSame($expected, ReportBudget::estimateTokens($snapshot));

        // ~1 000 tokens for 4 000 characters: a check on the ratio, not a pin.
        self::assertGreaterThan(900, (int) ReportBudget::estimateTokens($snapshot));
        self::assertLessThan(1_200, (int) ReportBudget::estimateTokens($snapshot));
    }

    public function test_an_ordinary_snapshot_is_not_refused(): void
    {
        // Past the largest real report measured on production data (~37 000
        // input tokens, seven days) and still inside the ceiling.
        $snapshot = ['days' => array_fill(0, 200, ['date' => '2026-08-01', 'note' => str_repeat('y', 200)])];

        self::assertLessThan(ReportBudget::ceiling(), (int) ReportBudget::estimateTokens($snapshot));
        self::assertNull(ReportBudget::refusal($snapshot, ReportFocus::everything()));
    }

    /** Past the ceiling, refused with both numbers and a way out. */
    public function test_an_oversized_snapshot_is_refused_with_both_figures(): void
    {
        // ~200 000 tokens of day rows, past the 150 000 ceiling — built rather
        // than mocked so the estimator sees a document of the real shape.
        $snapshot = ['days' => array_fill(0, 800, [
            'date'    => '2026-08-01',
            'weekday' => 'Sat',
            'note'    => str_repeat('z', 1_000),
        ])];

        self::assertGreaterThan(ReportBudget::ceiling(), (int) ReportBudget::estimateTokens($snapshot));

        $refusal = ReportBudget::refusal($snapshot, ReportFocus::everything());

        self::assertNotNull($refusal);
        self::assertStringContainsString('150,000', $refusal);
        self::assertStringContainsString('Try a shorter range', $refusal);

        // "Focus the report" is not advice you can act on when it already is.
        self::assertStringContainsString(
            'focus the report on one or two areas',
            (string) ReportBudget::refusal($snapshot, ReportFocus::everything()),
        );

        self::assertStringNotContainsString(
            'focus the report on one or two areas',
            (string) ReportBudget::refusal($snapshot, ReportFocus::of([ReportFocusArea::Stress])),
        );
    }

    /** Configuration, so a smaller context window can lower it without a deploy. */
    public function test_the_ceiling_is_configurable(): void
    {
        config()->set('health.report.max_input_tokens', 10);

        self::assertSame(10, ReportBudget::ceiling());
        self::assertNotNull(ReportBudget::refusal(['note' => str_repeat('a', 500)], ReportFocus::everything()));
    }
}
