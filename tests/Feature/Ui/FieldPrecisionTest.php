<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * "2.345|" — A NUMBER WITH ITS END CUT OFF, IN A BOX ASKING TO BE CORRECTED.
 *
 * A value THIS APP fills in is written at the precision its label claims: kcal
 * whole, grams and macros to a tenth. Two things that rule may not break: a
 * value the USER typed is theirs, rounded neither on the keystroke nor on blur;
 * and the rounding never reaches the state, so it never reaches the payload.
 * One decision keeps both — the item holds the number, the BOX holds the text —
 * and this file pins that shape in the templates, where a future edit would
 * otherwise bind an input straight at a value again. Source inspection, for the
 * reason DecimalInputTest gives: the app is an Inertia SPA, the server never
 * renders these inputs, and the JS suite is `node --test` over lib modules.
 * See docs/rationale-frontend.md § "Precision in an editable number box"
 */
final class FieldPrecisionTest extends TestCase
{
    use ReadsSource;

    private const SHEET = 'resources/js/Components/MealSheet.vue';

    private const REVIEW = 'resources/js/Components/ProposalReview.vue';

    private const SCAN = 'resources/js/Components/ScannedProduct.vue';

    private const MODULE = 'resources/js/lib/boxes.js';

    /** Every component that fills a nutrition figure in for the user. */
    private const COMPONENTS = [self::SHEET, self::REVIEW, self::SCAN];

    /**
     * The one decimal box the app never computes into: ScannedProduct's weight is
     * 100 — the basis — or a number typed off a scale, so it has no tail to hide
     * and is bound straight at the ref like any ordinary field.
     */
    private const NOT_DERIVED = ':value="grams"';

    public function test_every_sheet_fills_its_boxes_in_through_the_one_module(): void
    {
        foreach (self::COMPONENTS as $path) {
            self::assertMatchesRegularExpression(
                "/import \{[^}]*\bboxText\b[^}]*\} from '\.\.\/lib\/boxes'/",
                $this->sourceWithoutComments($path),
                $path.' no longer writes its numbers through '.self::MODULE
                    .', so something in it is drawing a derived value at whatever precision it arrived with.'
            );
        }
    }

    public function test_no_decimal_box_is_bound_straight_at_a_value(): void
    {
        foreach (self::COMPONENTS as $path) {
            foreach ($this->decimalInputs($path) as $input) {
                if (str_contains($input, self::NOT_DERIVED)) {
                    continue;
                }

                self::assertMatchesRegularExpression(
                    '/:value="(box|densityBox)\(/',
                    $input,
                    $path.' binds a decimal box straight at its value: '.$input
                        .' — a figure this app derived then renders with its whole floating-point tail, '
                        .'in a field too narrow to show it.'
                );
            }
        }
    }

    /**
     * `shown()` answers "how much of this was eaten" and hands back a number,
     * tail and all: it is not a formatter, and binding an input at it is the bug.
     */
    public function test_the_sheets_do_not_bind_an_input_at_the_raw_reader(): void
    {
        foreach ([self::SHEET, self::REVIEW] as $path) {
            self::assertStringNotContainsString(
                ':value="shown(',
                $this->sourceWithoutComments($path),
                $path.' binds an input at shown() again, which returns the number and not the text.'
            );
        }
    }

    public function test_what_the_user_types_is_recorded_where_it_is_parsed(): void
    {
        // One funnel per component: whatever calls `decimal()` on a keystroke
        // also remembers it, so a population path added later cannot forget to.
        $funnels = [
            self::SHEET  => ['typedIn(item, key, raw)', 'typedIn(item, `${nutrient}_per_100g`, raw)'],
            self::REVIEW => ['typedIn(item, key, raw)'],
            self::SCAN   => ['typedIn(values.value, key, raw)'],
        ];

        foreach ($funnels as $path => $calls) {
            foreach ($calls as $call) {
                self::assertStringContainsString(
                    $call,
                    $this->sourceWithoutComments($path),
                    $path.' stopped recording what the user typed ('.$call.'), so their own figure '
                        .'would be rounded back at them the next time the sheet re-rendered.'
                );
            }
        }
    }

    /**
     * A box must be filled with text this app's own parser reads. `num()`
     * localises — `num(1200)` is "1,200" and `decimal('1,200')` is 1.2, because
     * this keypad types a comma for a decimal point — so a box filled through
     * the prose formatter would divide a 1200 kcal pizza by a thousand on save.
     */
    public function test_the_filler_does_not_localise_what_it_writes(): void
    {
        $parts = explode('export function numberText', $this->sourceWithoutComments('resources/js/lib/format.js'));

        self::assertCount(2, $parts, 'resources/js/lib/format.js no longer defines numberText.');

        $body = explode('export function num(', $parts[1])[0];

        self::assertStringNotContainsString(
            'toLocaleString',
            $body,
            'numberText() localises its output, so a four-figure box now fills itself in with a '
                .'thousands separator that decimal() reads as a decimal point.'
        );
    }

    /**
     * The rounding stays in the text; the item keeps what it was given. The
     * payload is built from `form.data()` through `payloadFor`, not from any box
     * (QuickTabConversionTest pins that half); what this forbids is the reverse
     * shortcut of writing the rounded TEXT back into the item, which would put
     * display precision into the row and drift further on every tab tap.
     */
    public function test_no_sheet_writes_the_rounded_text_back_into_the_item(): void
    {
        foreach (self::COMPONENTS as $path) {
            self::assertSame(
                0,
                preg_match('/=\s*(boxText|box|densityBox|numberText)\(/', $this->sourceWithoutComments($path)),
                $path.' assigns a formatted box back into its state. The text is for reading; '
                    .'the item holds the number, or a toggle stops being safe to look at.'
            );
        }
    }

    /**
     * @return list<string>
     */
    private function decimalInputs(string $path): array
    {
        preg_match_all('/<input\b[^>]*>/s', $this->sourceWithoutComments($path), $matches);

        $decimal = array_values(array_filter(
            $matches[0],
            static fn (string $input): bool => str_contains($input, 'inputmode="decimal"')
        ));

        self::assertNotEmpty($decimal, $path.' renders no decimal inputs at all.');

        return $decimal;
    }
}
