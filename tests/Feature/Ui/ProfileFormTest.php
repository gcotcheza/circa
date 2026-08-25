<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use App\Models\Profile;
use Tests\Concerns\ReadsSource;

/**
 * The profile form, read off the source.
 *
 * ---------------------------------------------------------------------------
 * WHY THESE ARE READ RATHER THAN RENDERED
 *
 * As ReportTabTest and DecimalInputTest say: no component renderer here, the JS
 * suite is `node --test` over two lib modules, and a DOM for one Vue file is a
 * dependency and a build step bought for very little. So:
 *
 *   FOUR NAV ITEMS. A fifth tab is the obvious later mistake, and the layout's
 *   own comment says that nav is full.
 *
 *   NOT ONE NATIVE CONSTRAINT. The bug DecimalInputTest documents — a browser
 *   silently refusing a submit, on iOS without drawing the bubble — cost an
 *   afternoon, and this sixteen-box form is where it recurs.
 *
 *   THE HEIGHT IS HINTED, NEVER FILLED IN: writing the scale's derived figure
 *   into the box turns a derivation into a stated fact about somebody's body.
 *
 *   THE MEDICAL LINE IS UNCONDITIONAL: a `v-if` added while tidying would remove
 *   it from the screen where it matters most.
 * ---------------------------------------------------------------------------
 */
final class ProfileFormTest extends TestCase
{
    use ReadsSource;

    private const PAGE = 'resources/js/Pages/Profile.vue';

    private const CHIP = 'resources/js/Components/ChipInput.vue';

    private const LAYOUT = 'resources/js/Layouts/AppLayout.vue';

    public function test_the_header_carries_the_profile_link(): void
    {
        $layout = $this->source(self::LAYOUT);

        self::assertStringContainsString('href="/profile"', $layout);
        self::assertStringContainsString('Sign out', $layout);
    }

    /** Day, Trends, Stress, Report — not a fifth. Repeated from ReportTabTest because THIS is the change that would break it. */
    public function test_the_bottom_nav_still_has_exactly_four_items(): void
    {
        self::assertSame(
            4,
            substr_count($this->source(self::LAYOUT), 'flex flex-1 flex-col items-center'),
            'the profile belongs in the header; that nav is full at four.'
        );
    }

    /** A field forgotten in the template is one nobody can fill in, and nothing would say so. */
    public function test_every_editable_field_has_a_box_on_the_page(): void
    {
        $page = $this->sourceWithoutComments(self::PAGE);

        foreach (Profile::EDITABLE as $field) {
            self::assertStringContainsString(
                'form.'.$field,
                $page,
                "The profile form has no control bound to {$field}."
            );
        }
    }

    /** No `<form>`, no submit button, nothing on an input the browser gets to veto. */
    public function test_nothing_on_this_page_can_be_vetoed_by_the_browser(): void
    {
        foreach ([self::PAGE, self::CHIP] as $path) {
            $code = $this->sourceWithoutComments($path);

            self::assertStringNotContainsString(
                'type="submit"',
                $code,
                $path.' has a submit button, which is what turns native constraint validation on.'
            );

            self::assertStringNotContainsString(
                'type="number"',
                $code,
                $path.' has a native number input. The browser, not this app, then decides '
                    .'whether 160,5 is a legal value.'
            );

            foreach ($this->inputs($path) as $input) {
                foreach (['step', 'min', 'max', 'required', 'pattern'] as $attribute) {
                    self::assertDoesNotMatchRegularExpression(
                        '/[\s:]'.$attribute.'[=\s>]/',
                        $input,
                        $path.' puts a `'.$attribute.'` on a box: '.$input
                    );
                }
            }
        }
    }

    /** Text boxes with a decimal keypad, through the one parser that reads the comma this phone types. */
    public function test_the_numeric_boxes_go_through_the_one_parser(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        self::assertMatchesRegularExpression(
            "/import \{[^}]*\bdecimal\b[^}]*\} from '\.\.\/lib\/format'/",
            $code,
            'the profile form no longer imports decimal(), so it is parsing numbers its own way.'
        );

        self::assertStringContainsString('decimal(data.height_cm)', $code);
        self::assertStringContainsString('decimal(data.target_weight_kg)', $code);

        self::assertStringNotContainsString('Number($event.target.value)', $code);

        // Both of them keep the decimal keypad.
        self::assertSame(2, substr_count($code, 'inputmode="decimal"'));
    }

    /**
     * The one picker on the page, allowed because it carries nothing to refuse:
     * empty or a valid date, with plausibility in ProfileRequest where a refusal
     * comes back as a sentence.
     *
     * Native `<input type="date">` once, Air Datepicker now (AirDate.vue). The
     * argument was never "the browser draws it" but "there is nothing on it to
     * refuse", so the assertion follows the control, not the tag; bounds are
     * checked above.
     */
    public function test_the_date_of_birth_is_a_picker_with_no_bounds_on_it(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        self::assertStringNotContainsString(
            'type="date"',
            $code,
            'the profile form is back on the native date input, which is a different control '
                .'in every browser and a three-column wheel on the one phone this app runs on.'
        );

        self::assertSame(
            1,
            substr_count($code, '<AirDate'),
            'the profile form should mount exactly one calendar.'
        );

        $tag = $this->openingTagBefore($code, 'v-model="form.date_of_birth"');

        self::assertStringStartsWith('<AirDate', $tag, 'the date of birth is not the calendar.');
    }

    /** The hint is a sentence, not an assignment. */
    public function test_the_implied_height_is_shown_and_never_written_into_the_box(): void
    {
        $code = $this->sourceWithoutComments(self::PAGE);

        // Displayed...
        self::assertStringContainsString('impliedHeightCm', $code);

        // ...and never assigned in, by any spelling.
        self::assertDoesNotMatchRegularExpression(
            '/form\.height_cm\s*=/',
            $code,
            'the page fills the height box in from the scale. It is a hint, not an answer.'
        );

        self::assertStringNotContainsString(
            'height_cm: props.impliedHeightCm',
            $code,
            'the height box is seeded from the scale-derived figure.'
        );
    }

    /** What the report may and may not do with a medication, beside the box it gets typed into. */
    public function test_the_health_notes_disclaimer_is_unconditional(): void
    {
        $page = $this->source(self::PAGE);

        $lower = mb_strtolower($page);

        self::assertStringContainsString('not medical advice', $lower);
        self::assertStringContainsString('will not name a dose', $lower);
        self::assertStringContainsString('diagnose', $lower);

        $tag = $this->openingTagBefore($page, 'The report may mention that something here interacts');

        self::assertStringNotContainsString('v-if', $tag);
        self::assertStringNotContainsString('v-show', $tag);
    }

    /** A questionnaire gets plausible answers, not true ones, and the report cannot tell them apart. */
    public function test_the_page_says_every_box_is_optional(): void
    {
        self::assertStringContainsString('Every box is optional', $this->source(self::PAGE));
    }

    /**
     * Every box a value goes into — the native ones and the one calendar.
     *
     * `<AirDate>` counts because it IS an input and the claim is about the page,
     * not the tag: a `max` on the calendar is the same promise as one on a native
     * date field, made to a control that greys the day out instead of refusing.
     *
     * @return list<string>
     */
    private function inputs(string $path): array
    {
        preg_match_all('/<(?:input|AirDate)\b[^>]*>/s', $this->sourceWithoutComments($path), $matches);

        self::assertNotEmpty($matches[0], $path.' renders no inputs at all.');

        return $matches[0];
    }

    /** The tag opening the element containing $needle. Crude, as ReportTabTest's: enough to answer "is this conditional?" */
    private function openingTagBefore(string $source, string $needle): string
    {
        $at = mb_strpos($source, $needle);

        self::assertNotFalse($at, "Could not find: {$needle}");

        $open = mb_strrpos(mb_substr($source, 0, $at), '<');

        self::assertNotFalse($open);

        $close = mb_strpos($source, '>', $open);

        return mb_substr($source, $open, $close - $open + 1);
    }
}
