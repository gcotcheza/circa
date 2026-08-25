<?php

declare(strict_types=1);

namespace Tests\Feature\Ui;

use Tests\TestCase;
use Tests\Concerns\ReadsSource;
use App\Services\Validation\RuleExporter;

/**
 * Every box says something as it is left, and nothing the browser says is its
 * own idea. Source inspection (Inertia SPA, no component renderer) catches
 * three faults a passing suite otherwise misses:
 *
 *   MISSPELT FIELD NAME = SILENCE: `inline.on('serving_txt')` refuses
 *   nothing, so every wired name is checked against the exported rules.
 *   A NATIVE CONSTRAINT BACK: `required`/`maxlength`/`pattern` refuse in the
 *   browser's own words, sometimes drawing nothing at all on iOS.
 *   NO `novalidate`: native validation still runs on submit regardless.
 */
final class InlineValidationWiringTest extends TestCase
{
    use ReadsSource;

    /** Each form, and the exported rule set its boxes are judged by. */
    private const WIRED = [
        'resources/js/Pages/Auth/Login.vue'            => ['Login'],
        'resources/js/Pages/Profile.vue'               => ['ProfileRequest', 'PasswordUpdateRequest'],
        'resources/js/Pages/Supplements.vue'           => ['SupplementRequest'],
        'resources/js/Components/MealSheet.vue'        => ['MealRequest'],
        'resources/js/Components/ProposalReview.vue'   => ['MealProposalRequest'],
        'resources/js/Components/PhotoCapture.vue'     => ['MealPhotoRequest'],
        'resources/js/Components/ReportFocusChips.vue' => ['HealthReportRequest'],
    ];

    /**
     * The one loop variable besides the row index a checked field name is
     * written in terms of: ProposalReview's three macro pairs.
     */
    private const LOOPED = ['macro' => ['protein_g', 'carbs_g', 'fat_g']];

    public function test_every_box_that_speaks_names_a_field_the_server_knows(): void
    {
        $exported = app(RuleExporter::class)->export()['requests'];

        foreach (self::WIRED as $path => $requests) {
            $code = $this->sourceWithoutComments($path);

            // `on()` as well as the two it wraps: most boxes name their field
            // through the pair now, and a scan that missed those would go quiet
            // rather than red.
            preg_match_all('/\b(?:check|clear|on)\(\s*[`\'"]([^`\'"]+)[`\'"]\s*\)/', $code, $matches);

            self::assertNotEmpty($matches[1], $path.' wires no inline validation at all.');

            foreach (array_unique($matches[1]) as $wired) {
                $fields = $this->patterns($wired);

                foreach ($fields as $field) {
                    $known = false;

                    foreach ($requests as $request) {
                        $known = $known || isset($exported[$request]['fields'][$field]);
                    }

                    self::assertTrue(
                        $known,
                        $path.' validates `'.$field.'`, which none of '.implode(', ', $requests)
                            .' has a rule for. A name nothing matches is a box that silently never speaks.'
                    );
                }
            }
        }
    }

    /**
     * The profile's boxes hold "160,5" but post 160.5, so the blur check needs
     * what is POSTED — judging the box instead would refuse a height the save
     * accepts, the one direction inline validation must never be wrong in.
     */
    public function test_the_profile_judges_what_it_posts_and_not_what_it_holds(): void
    {
        $code = $this->sourceWithoutComments('resources/js/Pages/Profile.vue');

        self::assertStringContainsString(
            "useInlineValidation(form, 'ProfileRequest', { data: () => posted(form.data()) })",
            $code,
            'the profile validates the raw box again, so a comma reads as text and 160,5 is refused on blur.'
        );

        // The same function the submit transforms through, so the two answers can't diverge.
        self::assertStringContainsString('form.transform(posted)', $code);
    }

    /** Not one `<form>` in the app runs the browser's own validation on submit. */
    public function test_every_form_carries_novalidate(): void
    {
        $forms = 0;

        foreach ($this->sourceFiles('resources/js', 'vue') as $path) {
            preg_match_all('/<form\b[^>]*>/s', $this->sourceWithoutComments($path), $matches);

            foreach ($matches[0] as $form) {
                $forms++;

                self::assertStringContainsString(
                    'novalidate',
                    $form,
                    $path.' has a <form> without `novalidate`, so the browser validates it on submit whatever the '
                        .'inputs carry: '.$form
                );
            }
        }

        self::assertSame(3, $forms, 'the number of forms in the app changed; each one needs its own `novalidate`.');
    }

    /**
     * Nowhere in the front end, not just the wired forms — repo-wide, since silence is the failure mode.
     * `:min`/`:max` on the AirDate calendar are untouched (they grey a day out), pinned by DatePickerNullBoundsTest.
     */
    public function test_no_input_anywhere_carries_a_veto_the_browser_enforces(): void
    {
        $scanned = 0;

        foreach ($this->sourceFiles('resources/js', 'vue') as $path) {
            self::assertStringNotContainsString(
                'maxlength',
                $this->source($path),
                $path.' caps a box in the browser again. A box that stops accepting letters says nothing about why, '
                    .'and what it silently cut is gone.'
            );

            $code = $this->sourceWithoutComments($path);
            $scanned++;

            foreach (['required', 'pattern'] as $attribute) {
                self::assertDoesNotMatchRegularExpression(
                    '/<(?:input|textarea)\b[^>]*[\s:]'.$attribute.'[=\s>]/s',
                    $code,
                    $path.' puts a native `'.$attribute.'` on a box. Whatever it refuses, it refuses in the '
                        .'browser\'s words rather than this app\'s — and on iOS sometimes without drawing anything.'
                );
            }
        }

        self::assertGreaterThan(30, $scanned, 'the sweep stopped finding the components.');
    }

    /**
     * Eager: `useInlineValidation(form, …)` reads `form` at setup, so it must
     * already exist — PR #85 crashed on open, unnoticed since nothing executes a component.
     */
    public function test_the_wiring_reads_a_form_that_already_exists(): void
    {
        foreach (array_keys(self::WIRED) as $path) {
            $source = file_get_contents(base_path($path));
            self::assertIsString($source);
            self::assertNotSame('', $source, $path.' is unreadable.');

            preg_match_all('/const \w+ = useInlineValidation\((\w+),/', $source, $calls, PREG_OFFSET_CAPTURE);

            foreach ($calls[1] as [$formVar, $callAt]) {
                $declaredAt = strpos($source, 'const '.$formVar.' = useForm(');

                self::assertNotFalse($declaredAt, "$path wires useInlineValidation($formVar, …) but never declares $formVar via useForm().");
                self::assertLessThan($callAt, $declaredAt, "$path reads $formVar at byte $callAt before declaring it at byte $declaredAt — that is a crash the moment the screen opens.");
            }
        }
    }

    /**
     * Every concrete field name a wired string can stand for.
     *
     * @return list<string>
     */
    private function patterns(string $wired): array
    {
        $pattern = str_replace('${index}', '*', $wired);

        foreach (self::LOOPED as $variable => $values) {
            if (! str_contains($pattern, '${'.$variable.'}')) {
                continue;
            }

            return array_map(
                static fn (string $value): string => str_replace('${'.$variable.'}', $value, $pattern),
                $values,
            );
        }

        return [$pattern];
    }
}
