<?php

declare(strict_types=1);

namespace App\Services\Validation;

use SplFileInfo;
use ReflectionMethod;
use RuntimeException;
use FilesystemIterator;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Illuminate\Validation\Validator;
use Illuminate\Validation\Rules\Enum;
use Illuminate\Validation\Rules\Password;
use Illuminate\Foundation\Http\FormRequest;
use App\Http\Controllers\Auth\LoginController;
use Illuminate\Validation\ValidationRuleParser;
use Illuminate\Support\Facades\Validator as ValidatorFactory;

/**
 * The server's rules and sentences, GENERATED off the real FormRequests and
 * Laravel's own message lookup, so a moved ceiling or reworded message
 * reaches the artifact — enforced by the agreement test — the moment the
 * export re-runs. See docs/rationale-frontend.md § "Saying it before the round trip"
 */
final class RuleExporter
{
    /** Rules the browser decides on its own; resources/js/lib/validation/index.js implements each, fixture-tested. */
    private const MIRRORED = [
        'Required', 'Nullable', 'Sometimes', 'String', 'Numeric', 'Integer',
        'Boolean', 'Array', 'Uuid', 'Min', 'Max', 'In', 'Enum', 'DateFormat',
        'Confirmed', 'Gte',
    ];

    /**
     * Rules only the server can answer (a lookup, hash, breach corpus or date
     * parser stands behind each); stay quiet rather than risk refusing what
     * the server would accept.
     */
    private const SERVER_ONLY = [
        'CurrentPassword', 'Exists', 'Unique', 'Date', 'AfterOrEqual',
        'BeforeOrEqual', 'Email', 'File', 'Image', 'Mimes', 'Mimetypes',
        'Dimensions', 'Password',
    ];

    /** Rules that make the whole FIELD the server's business: Laravel picks `max`'s kilobyte wording off the value BEING an UploadedFile. */
    private const FILE_RULES = ['File', 'Image'];

    /** Decide whether the OTHERS run and never refuse anything themselves — `validation.nullable` is a key with nothing behind it. */
    private const CONTROL = ['Nullable', 'Sometimes'];

    /** Placeholders filled from the request under validation, which an artifact cannot carry. */
    private const RUNTIME_PLACEHOLDERS = [':value', ':input', ':index', ':position', ':other', ':Attribute', ':ATTRIBUTE'];

    /** Indices materialised for a wildcard field — enough to prove the index lands where it belongs. */
    private const SAMPLE_INDICES = [0, 1, 2];

    /** Stands in for `:attribute` while Laravel fills the other placeholders in. */
    private const ATTRIBUTE_GUARD = "\x01attribute\x01";

    private readonly ReflectionMethod $getMessage;

    public function __construct()
    {
        // Laravel's own message lookup, reached into deliberately: the
        // alternative reimplements its custom-message/wildcard/size ordering.
        $this->getMessage = new ReflectionMethod(Validator::class, 'getMessage');
    }

    /**
     * @return array{generated_by: string, requests: array<string, array{fields: array<string, mixed>}>}
     */
    public function export(): array
    {
        $requests = [];

        foreach ($this->sources() as $source) {
            $requests[$source->name] = ['fields' => $this->fields($source)];
        }

        return [
            'generated_by' => 'php artisan validation:export',
            'requests'     => $requests,
        ];
    }

    /**
     * Every rule set that backs a form, discovered rather than listed: a new
     * FormRequest is exported without anybody remembering this file exists.
     *
     * @return list<RuleSource>
     */
    public function sources(): array
    {
        $sources = [];

        foreach ($this->requestFiles() as $file) {
            $class = $this->classOf($file);

            if (! is_subclass_of($class, FormRequest::class)) {
                continue;
            }

            $request = new $class;

            if (! method_exists($request, 'rules')) {
                continue;
            }

            $rules = $request->rules();

            if (! is_array($rules)) {
                throw new RuntimeException($class.'::rules() did not return an array.');
            }

            $sources[] = new RuleSource(
                name: class_basename($class),
                rules: $rules,
                messages: $request->messages(),
                attributes: $request->attributes(),
            );
        }

        // Login is validated in the controller, not a FormRequest, because a
        // FormRequest would change where a failed login redirects.
        $sources[] = new RuleSource(name: 'Login', rules: LoginController::RULES);

        return $sources;
    }

    /**
     * Every file under app/Http/Requests, however deep — recursive, so nested requests can't vanish from the export.
     *
     * @return list<string>
     */
    public function requestFiles(): array
    {
        $files = [];

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path('Http/Requests'), FilesystemIterator::SKIP_DOTS)
        );

        foreach ($tree as $file) {
            if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * `strlen`, not `mb_strlen`: `substr` counts bytes, and an offset measured
     * in characters would slide the moment a path held a multibyte one.
     *
     * @return class-string
     */
    public function classOf(string $file): string
    {
        /** @var class-string $class */
        $class = 'App\\'.str_replace('/', '\\', substr($file, strlen(app_path().'/'), -strlen('.php')));

        return $class;
    }

    /**
     * One rule set's fields. Public so a test can hand it an unmirrorable
     * rule and prove the export refuses rather than silently dropping it.
     *
     * @return array<string, array{attribute: string, rules: list<array<string, mixed>>}>
     */
    public function fields(RuleSource $source): array
    {
        $validator = $this->validator($source);
        $fields = [];

        foreach ($this->expand($validator, $source) as $pattern => $normalised) {
            $fields[$pattern] = [
                'attribute' => $this->attributeTemplate($validator, $pattern),
                'rules'     => $this->rules($source, $validator, $pattern, $normalised),
            ];
        }

        return $fields;
    }

    /**
     * The rules as the validator itself holds them, keyed by the pattern they
     * were written as — what CaseGenerator drives its probes through. Read off
     * the same validator this export is: a second derivation is a second
     * answer, and the one this subsystem exists to prevent is the pair that
     * quietly stopped agreeing.
     *
     * @return array<string, array<int, mixed>>
     */
    public function normalised(RuleSource $source): array
    {
        return $this->expand($this->validator($source), $source);
    }

    private function validator(RuleSource $source): Validator
    {
        return ValidatorFactory::make(
            $this->sampleData(array_keys($source->rules)),
            $source->rules,
            $source->messages,
            $source->attributes,
        );
    }

    /**
     * A pattern that stops expanding FAILS here rather than exporting an empty
     * rule list: silence is the one way this subsystem can be wrong without
     * anything going red.
     *
     * @return array<string, array<int, mixed>>
     */
    private function expand(Validator $validator, RuleSource $source): array
    {
        $expanded = $validator->getRules();
        $normalised = [];

        foreach (array_keys($source->rules) as $key) {
            $pattern = (string) $key;
            $concrete = $this->concrete($pattern);

            if (! isset($expanded[$concrete])) {
                throw new RuntimeException(
                    $source->name.'.'.$pattern.' did not survive rule expansion; the sample data is missing a shape.'
                );
            }

            $normalised[$pattern] = $expanded[$concrete];
        }

        return $normalised;
    }

    /**
     * @param  array<int, mixed>  $normalised
     * @return list<array<string, mixed>>
     */
    private function rules(RuleSource $source, Validator $validator, string $pattern, array $normalised): array
    {
        $parsed = array_map(fn (mixed $rule): array => $this->parse($source, $pattern, $rule), $normalised);

        // One upload rule anywhere takes the whole field out of the browser's
        // hands — see FILE_RULES.
        $holdsAFile = array_intersect(array_column($parsed, 0), self::FILE_RULES) !== [];

        $exported = [];

        foreach ($parsed as [$name, $parameters]) {
            $entry = ['rule' => Str::snake($name)];

            if ($parameters !== []) {
                $entry['params'] = $parameters;
            }

            if ($holdsAFile || in_array($name, self::SERVER_ONLY, true)) {
                $entry['server_only'] = true;
            } elseif (! in_array($name, self::MIRRORED, true)) {
                throw new RuntimeException(
                    "{$source->name}.{$pattern} carries the rule `{$name}`, which is neither mirrored in the browser "
                    .'nor named server-only. Teach RuleExporter which it is — and resources/js/lib/validation, if it '
                    .'is to be mirrored — rather than letting a rule go unsaid.'
                );
            } elseif (! in_array($name, self::CONTROL, true)) {
                $this->refuseLooseComparison($source, $pattern, $name, $parameters);

                $entry['message'] = $this->message($validator, $pattern, $name, $parameters);
            }

            $exported[] = $entry;
        }

        return $exported;
    }

    /**
     * `in`/`enum` may only offer members the two languages compare alike: PHP's
     * `in_array` is LOOSE (1 also matches "01"/" 1"), the browser's `includes`
     * is exact, and no probe catches that gap, because the members decide
     * whether the comparison is loose at all — so a numeric member stops the export.
     *
     * @param  list<string>  $parameters
     */
    private function refuseLooseComparison(RuleSource $source, string $pattern, string $rule, array $parameters): void
    {
        if (! in_array($rule, ['In', 'Enum'], true)) {
            return;
        }

        foreach ($parameters as $member) {
            if (is_numeric($member)) {
                throw new RuntimeException(
                    "{$source->name}.{$pattern} offers `{$member}` as one of its `".mb_strtolower($rule).'` values. '
                    .'PHP compares those loosely, so the server would accept "0'.$member.'" and a padded copy of it '
                    .'while the browser refused both. Make the members non-numeric, or teach the evaluator PHP\'s '
                    .'loose comparison before exporting this.'
                );
            }
        }
    }

    /**
     * A rule as its name and resolved parameters.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function parse(RuleSource $source, string $pattern, mixed $rule): array
    {
        if ($rule instanceof Enum) {
            // Stringifies as `in:"breakfast","lunch"` — the whole of what the
            // browser needs to decide it.
            [, $values] = ValidationRuleParser::parse((string) $rule);

            return ['Enum', $this->strings($values)];
        }

        if ($rule instanceof Password) {
            return ['Password', []];
        }

        if (is_object($rule)) {
            throw new RuntimeException(
                "{$source->name}.{$pattern} carries the rule object ".$rule::class
                .', which this exporter does not know how to read.'
            );
        }

        [$name, $parameters] = ValidationRuleParser::parse($rule);

        if (! is_string($name)) {
            throw new RuntimeException("{$source->name}.{$pattern} parsed to a rule with no name.");
        }

        return [$name, $this->strings($parameters)];
    }

    /**
     * The sentence the server would emit, `:attribute` left for the box that actually blurred.
     *
     * @param  list<string>  $parameters
     */
    private function message(Validator $validator, string $pattern, string $rule, array $parameters): string
    {
        $attribute = $this->concrete($pattern);

        // An enum refuses through the rule object, not the size-variant lookup.
        $raw = $rule === 'Enum'
            ? (string) trans('validation.enum')
            : (string) $this->getMessage->invoke($validator, $attribute, $rule);

        foreach (self::RUNTIME_PLACEHOLDERS as $placeholder) {
            if (str_contains($raw, $placeholder)) {
                throw new RuntimeException(
                    "The sentence for {$pattern}.{$rule} contains `{$placeholder}`, which is resolved from the "
                    .'request being validated and cannot be exported. Give that rule a message of its own.'
                );
            }
        }

        $filled = $validator->makeReplacements(
            str_replace(':attribute', self::ATTRIBUTE_GUARD, $raw),
            $attribute,
            $rule,
            $parameters,
        );

        return str_replace(self::ATTRIBUTE_GUARD, ':attribute', (string) $filled);
    }

    /**
     * What Laravel would call this box in a sentence, `:index` where the item
     * number goes — read off three real indices rather than assumed, so a
     * name that would break the substitution silently refuses instead.
     */
    private function attributeTemplate(Validator $validator, string $pattern): string
    {
        $displayable = [];

        foreach (self::SAMPLE_INDICES as $index) {
            $displayable[$index] = $validator->getDisplayableAttribute($this->concrete($pattern, $index));
        }

        $first = $displayable[self::SAMPLE_INDICES[0]];

        if (! str_contains($pattern, '*')) {
            return $first;
        }

        // A custom attributes() entry naming every row `food name` rather than
        // `items.0.name` is a NAME, not drift; only an unpredictable one is a problem.
        if (count(array_unique($displayable)) === 1) {
            return $first;
        }

        $second = $displayable[self::SAMPLE_INDICES[1]];

        $prefix = 0;

        while ($prefix < mb_strlen($first) && mb_substr($first, $prefix, 1) === mb_substr($second, $prefix, 1)) {
            $prefix++;
        }

        $suffix = 0;

        while (
            $suffix < mb_strlen($first) - $prefix
            && mb_substr($first, -$suffix - 1, 1) === mb_substr($second, -$suffix - 1, 1)
        ) {
            $suffix++;
        }

        $template = mb_substr($first, 0, $prefix).':index'.($suffix === 0 ? '' : mb_substr($first, -$suffix));

        foreach ($displayable as $index => $expected) {
            if (str_replace(':index', (string) $index, $template) !== $expected) {
                throw new RuntimeException(
                    "The name `{$pattern}` goes by in a sentence does not vary with the item number in a way the "
                    .'browser can reproduce. Say what it should be called instead.'
                );
            }
        }

        return $template;
    }

    /**
     * Data that materialises every wildcard, so `max` on a number isn't read as `max` on a string.
     *
     * @param  list<array-key>  $patterns
     * @return array<string, mixed>
     */
    private function sampleData(array $patterns): array
    {
        $data = [];

        foreach ($patterns as $key) {
            $pattern = (string) $key;

            if (! str_contains($pattern, '*')) {
                continue;
            }

            foreach (self::SAMPLE_INDICES as $index) {
                Arr::set($data, $this->concrete($pattern, $index), null);
            }
        }

        return $data;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return list<string>
     */
    private function strings(array $values): array
    {
        return array_values(array_map(
            static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
            $values,
        ));
    }

    /** The pattern as a real key, the first sample index unless another is asked for. */
    public function concrete(string $pattern, int $index = self::SAMPLE_INDICES[0]): string
    {
        return str_replace('*', (string) $index, $pattern);
    }
}
