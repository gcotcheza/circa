<?php

declare(strict_types=1);

namespace Tests\Feature\Validation;

use Tests\TestCase;
use RuntimeException;
use Illuminate\Validation\Rule;
use Tests\Concerns\ReadsSource;
use App\Services\Validation\RuleSource;
use App\Services\Validation\RuleExporter;
use Illuminate\Foundation\Http\FormRequest;
use App\Services\Validation\ValidationArtifacts;

/**
 * THE AGREEMENT: the browser's copy of the rules is the server's, still.
 * Regenerates both committed files and compares them byte for byte, so any
 * drift fails the gate until somebody runs
 *
 *     php artisan validation:export
 *
 * tests/js/validation-agreement.test.js is the other half, proving the
 * evaluator matches the artifact. See docs/rationale-frontend.md § "Saying it
 * before the round trip"
 */
final class RulesAgreementTest extends TestCase
{
    use ReadsSource;

    public function test_the_exported_rules_are_the_rules_the_server_holds(): void
    {
        $artifacts = app(ValidationArtifacts::class);

        self::assertSame(
            $artifacts->rules(),
            $artifacts->committed(ValidationArtifacts::RULES),
            ValidationArtifacts::RULES.' no longer matches app/Http/Requests. A rule, a sentence or a ceiling moved '
                .'and the browser is still saying the old one: run `php artisan validation:export`.'
        );
    }

    public function test_the_agreement_cases_are_the_answers_the_server_gives_now(): void
    {
        $artifacts = app(ValidationArtifacts::class);

        self::assertSame(
            $artifacts->cases(),
            $artifacts->committed(ValidationArtifacts::CASES),
            ValidationArtifacts::CASES.' is stale, so the browser is being held to sentences the server has stopped '
                .'saying: run `php artisan validation:export`.'
        );
    }

    /**
     * Every request, not just the ones somebody remembered: the exporter
     * discovers FormRequests off the directory, and this notices if it stops.
     */
    public function test_every_form_request_is_exported(): void
    {
        $exporter = app(RuleExporter::class);
        $exported = array_keys($exporter->export()['requests']);

        // Walks the tree, not one directory: a nested request must not vanish
        // from the export and this check at the same time.
        $files = $exporter->requestFiles();

        self::assertNotEmpty($files);
        self::assertContains(app_path('Http/Requests/Concerns/ValidatesMealItems.php'), $files,
            'the discovery stopped walking into subdirectories.');

        foreach ($files as $file) {
            $class = $exporter->classOf($file);

            if (! is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            self::assertContains(
                class_basename($class),
                $exported,
                class_basename($class).' is a FormRequest the browser knows nothing about.'
            );
        }
    }

    /**
     * WHY THE ORDER MATTERS: the browser answers with the first refusing
     * rule, so none may sit BEHIND a server-only one, or the two would contradict each other.
     */
    public function test_no_mirrored_rule_hides_behind_one_only_the_server_can_judge(): void
    {
        foreach (app(RuleExporter::class)->export()['requests'] as $request => $definition) {
            foreach ($definition['fields'] as $field => $entry) {
                $serverOnly = false;

                foreach ($entry['rules'] as $rule) {
                    if (($rule['server_only'] ?? false) === true) {
                        $serverOnly = true;

                        continue;
                    }

                    self::assertFalse(
                        $serverOnly,
                        "{$request}.{$field} puts `{$rule['rule']}` after a rule only the server can judge. The "
                            .'browser would answer with a different sentence than the server leads with.'
                    );
                }
            }
        }
    }

    /**
     * A RULE NOBODY TAUGHT THE BROWSER STOPS THE EXPORT: the alternative is a
     * box the browser calls fine that the server refuses.
     */
    public function test_a_rule_nobody_taught_the_browser_stops_the_export(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/`Ip`.*neither mirrored/s');

        app(RuleExporter::class)->fields(new RuleSource('Probe', ['host' => ['nullable', 'ip']]));
    }

    /**
     * A NUMERIC CHOICE STOPS IT TOO: PHP's `in_array` is LOOSE (a numeric
     * member also matches its zero-padded or spaced string), the browser's
     * `includes` is exact, and no generated case would catch that gap, because
     * the members decide whether the comparison is loose at all.
     */
    public function test_a_numeric_choice_stops_the_export_because_php_compares_it_loosely(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/compares those loosely/');

        app(RuleExporter::class)->fields(new RuleSource('Probe', ['grade' => ['nullable', Rule::in([1, 2, 3])]]));
    }

    /**
     * One name for every row is a NAME, not drift: the exporter only needs
     * `attributes()`'s name to be reproducible, not to vary with the index.
     */
    public function test_a_row_may_be_given_one_name_for_every_index(): void
    {
        $fields = app(RuleExporter::class)->fields(new RuleSource(
            name: 'Probe',
            rules: ['items.*.name' => ['required', 'string', 'max:191']],
            attributes: ['items.*.name' => 'food name'],
        ));

        self::assertSame('food name', $fields['items.*.name']['attribute']);

        self::assertSame(
            'The :attribute field is required.',
            $fields['items.*.name']['rules'][0]['message'],
            'the sentence still carries the placeholder, so the browser fills in the name the server would.'
        );
    }

    /**
     * The rules that are NOT in a FormRequest, named: two inline `validate()`
     * calls back no form (a search box, the error reporter), and login's rules
     * moved to a constant for exactly this. A new inline `validate()` would be
     * a form the browser never learned, so this counts them.
     */
    public function test_the_only_rules_outside_a_request_are_the_ones_accounted_for(): void
    {
        $known = [
            'app/Http/Controllers/Auth/LoginController.php'      => 'exported as the Login rule set',
            'app/Http/Controllers/MealMemoryController.php'      => 'a search box, no form',
            'app/Http/Controllers/Api/ClientErrorController.php' => 'the browser reporting its own faults',
        ];

        $found = [];

        foreach ($this->sourceFiles('app/Http/Controllers', 'php') as $path) {
            if (str_contains($this->sourceWithout(['#/\*.*?\*/#s', '#//[^\n]*#'], $path), '->validate(')) {
                $found[] = $path;
            }
        }

        sort($found);

        $expected = array_keys($known);
        sort($expected);

        self::assertSame(
            $expected,
            $found,
            'A controller validates inline that this list does not know about. If it backs a form, its rules belong '
                .'where App\Services\Validation\RuleExporter can read them; if not, name it here and say why.'
        );
    }
}
