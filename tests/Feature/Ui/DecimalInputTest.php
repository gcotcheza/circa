<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;

/**
 * "SAVE CHANGES" DID NOTHING, AND THE BROWSER WAS RIGHT.
 *
 * ANY VALUE THE APP CAN RENDER INTO AN INPUT MUST BE ONE THE INPUT WILL HAND
 * BACK: a constraint the browser enforces silently must never stand between the
 * user and a save. So the meal-number boxes are `type="text"
 * inputmode="decimal"` with no `step`, `min`, `max` or `required`, the
 * plausibility ceilings stay in ValidatesMealItems, and `decimal()` in
 * resources/js/lib/format.js is the one parser — it reads the Dutch keypad's
 * comma, which `Number()` turns into NaN and `JSON.stringify` into `null`.
 *
 * Source inspection, for the reason ModelNoteVisibilityTest gives: Inertia SPA,
 * no JS test runner. Comments are stripped before matching.
 *
 * See docs/rationale-frontend.md § "The silent submit veto, and the comma"
 */
final class DecimalInputTest extends TestCase
{
    use ReadsSource;

    /** Every component rendering a box a person types a nutrition figure into. */
    private const COMPONENTS = [
        'resources/js/Components/MealSheet.vue',
        'resources/js/Components/ProposalReview.vue',
        'resources/js/Components/ScannedProduct.vue',
    ];

    private const PARSER = 'resources/js/lib/format.js';

    public function test_no_nutrition_box_is_a_native_number_input(): void
    {
        foreach (self::COMPONENTS as $path) {
            self::assertStringNotContainsString(
                'type="number"',
                $this->sourceWithoutComments($path),
                $path.' has a native number input again. It is the browser, not this app, '
                    .'that then decides whether 28.35 is a legal value — and it says no.'
            );
        }
    }

    public function test_the_decimal_boxes_carry_no_constraint_the_browser_can_veto(): void
    {
        foreach (self::COMPONENTS as $path) {
            foreach ($this->inputs($path) as $input) {
                if (! str_contains($input, 'inputmode="decimal"')) {
                    continue;
                }

                self::assertStringContainsString('type="text"', $input, $path.': '.$input);

                foreach (['step', 'min', 'max', 'required'] as $attribute) {
                    self::assertDoesNotMatchRegularExpression(
                        '/[\s:]'.$attribute.'[=\s>]/',
                        $input,
                        $path.' puts a `'.$attribute.'` back on a decimal box: '.$input
                            .' — a value this app computed would then be one the browser refuses to submit.'
                    );
                }
            }
        }
    }

    public function test_every_component_parses_through_the_one_parser(): void
    {
        foreach (self::COMPONENTS as $path) {
            $code = $this->sourceWithoutComments($path);

            self::assertMatchesRegularExpression(
                "/import \{[^}]*\bdecimal\b[^}]*\} from '\.\.\/lib\/format'/",
                $code,
                $path.' no longer imports decimal(), so something in it is parsing numbers its own way.'
            );

            self::assertStringNotContainsString(
                'Number($event.target.value)',
                $code,
                $path.' parses an input with Number() again, which reads a comma as NaN.'
            );
        }
    }

    public function test_the_parser_reads_the_comma_and_keeps_a_blank_blank(): void
    {
        $code = $this->sourceWithoutComments(self::PARSER);

        self::assertStringContainsString(
            "replace(',', '.')",
            $code,
            self::PARSER.' no longer normalises the comma the phone keypad types.'
        );

        // A cleared box is "I do not know", never 0 — the distinction the
        // estimate offer rests on (ValidatesMealItems).
        self::assertStringContainsString(
            "if (text === '') return null",
            $code,
            self::PARSER.' no longer reads an empty box as a blank.'
        );
    }

    /**
     * THE LAST NATIVE CONSTRAINT WENT TOO, AND THE PROMPT GOT LOUDER.
     *
     * The item's NAME kept a `required` while it was the one thing a user could
     * leave invalid that this app can never fill in itself. What the browser did
     * with it was still a bubble in its own words, gone on the next tap — and on
     * iOS sometimes no bubble at all, just a submit that did nothing. The name is
     * still required, by ValidatesMealItems, and now says so as the box is left,
     * in the sentence the save would have come back with.
     */
    public function test_the_name_is_required_by_a_sentence_and_not_by_the_browser(): void
    {
        $code = $this->sourceWithoutComments(self::COMPONENTS[0]);

        self::assertDoesNotMatchRegularExpression(
            '/placeholder="What was it\?"\s+required/',
            $code,
            'the item name is back on the browser\'s own validation, which refuses without saying why.'
        );

        self::assertMatchesRegularExpression(
            '/v-bind="inline\.on\(`items\.\$\{index\}\.name`\)"/',
            $code,
            'the item name no longer says anything as it is left, so nothing stands in for the `required` it gave up.'
        );

        self::assertStringContainsString(
            '<form class="space-y-4 px-4 py-4" novalidate',
            $code,
            'the one form in the app that runs native constraint validation lost its `novalidate`.'
        );
    }

    /**
     * @return list<string>
     */
    private function inputs(string $path): array
    {
        preg_match_all('/<input\b[^>]*>/s', $this->sourceWithoutComments($path), $matches);

        self::assertNotEmpty($matches[0], $path.' renders no inputs at all.');

        return $matches[0];
    }
}
