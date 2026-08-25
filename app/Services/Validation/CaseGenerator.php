<?php

declare(strict_types=1);

namespace App\Services\Validation;

use Throwable;
use RuntimeException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator as ValidatorFactory;

/**
 * Worked examples, answered by the real validator, for the browser to be held to.
 *
 * tests/js/validation-agreement.test.js must get the identical sentence from
 * the browser evaluator on every one. Values are probed, not hand-written. See
 * docs/rationale-frontend.md § "Saying it before the round trip"
 */
final class CaseGenerator
{
    /** No value at all, as distinct from a null one — `sometimes` turns on this difference. */
    public const ABSENT = "\x02absent\x02";

    /** Values tried against every field — empty/blank ones earn their place: Laravel's implicit-only gate on a blank string is the likeliest spot to disagree. */
    private const UNIVERSAL = [null, '', '   ', 'text', 0, 1, -1, true, [], ['a'], ...self::EXOTIC];

    /** Characters where PHP's whitespace and JavaScript's part company, measured rather than remembered. */
    private const EXOTIC = [
        "\u{00A0}", "\u{00A0}5", "5\u{00A0}", "\u{FEFF}", "\u{3000}", "\u{2028}",
        "\x0C", "\x0B5", "\0",
    ];

    /*
     * A form feed before a digit is deliberately NOT probed: `max` throws
     * rather than refuses, so there is no sentence to agree with. Worth
     * reporting upstream of this repo. StripControlCharacters keeps these
     * bytes off every HTTP request; this generator drives the validator
     * directly, so the exclusion stays.
     */

    /**
     * Rules needing a table, session or breach corpus, dropped free of cost:
     * the browser is silent on all four, and no mirrored rule ever sits
     * behind a server-only one.
     */
    private const NEEDS_THE_WORLD = ['exists', 'unique', 'current_password', 'password'];

    public function __construct(private readonly RuleExporter $exporter) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function generate(): array
    {
        $export = $this->exporter->export();
        $cases = [];

        foreach ($this->exporter->sources() as $source) {
            $fields = $export['requests'][$source->name]['fields'];

            $normalised = $this->exporter->normalised($source);
            $answerable = $this->ruleSet($fields, $normalised, keepServerOnly: true);
            $mirroredOnly = $this->ruleSet($fields, $normalised, keepServerOnly: false);

            foreach ($fields as $pattern => $field) {
                if (! is_array($field)) {
                    continue;
                }

                foreach ($this->cases($source, (string) $pattern, $field, $answerable, $mirroredOnly) as $case) {
                    $cases[] = $case;
                }
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<string, array<int, mixed>>  $normalised
     * @param  array<string, array<int, mixed>>  $mirroredOnly
     * @return list<array<string, mixed>>
     */
    private function cases(
        RuleSource $source,
        string $pattern,
        array $field,
        array $normalised,
        array $mirroredOnly,
    ): array {
        $wildcard = str_contains($pattern, '*');
        $concrete = $this->exporter->concrete($pattern);

        $cases = [];
        $seen = [];

        foreach ($this->probes($field, $pattern, $wildcard) as [$value, $siblings]) {
            $data = $this->data($concrete, $value, $siblings);

            $signature = json_encode($data);

            if (! is_string($signature) || isset($seen[$signature])) {
                continue;
            }

            $seen[$signature] = true;

            $cases[] = [
                'request'  => $source->name,
                'field'    => $concrete,
                'data'     => $data,
                'server'   => $this->firstError($source, $normalised, $data, $concrete),
                'expected' => $this->firstError($source, $mirroredOnly, $data, $concrete),
            ];
        }

        return $cases;
    }

    /**
     * The sentence this rule set produces for this box, or null if it is happy.
     *
     * @param  array<string, array<int, mixed>>  $rules
     * @param  array<string, mixed>  $data
     */
    private function firstError(RuleSource $source, array $rules, array $data, string $concrete): ?string
    {
        $validator = ValidatorFactory::make($data, $rules, $source->messages, $source->attributes);

        try {
            $message = $validator->errors()->first($concrete);
        } catch (Throwable $e) {
            // Naming the value matters: the raw MathException hides which character is troublesome.
            throw new RuntimeException(sprintf(
                'Validating %s.%s with %s did not return an answer — it threw %s: %s. A probe the server cannot '
                .'answer has no sentence to agree with; either the value does not belong in the case set, or the '
                .'rule needs looking at.',
                $source->name,
                $concrete,
                json_encode($data),
                $e::class,
                $e->getMessage(),
            ), 0, $e);
        }

        return $message === '' ? null : $message;
    }

    /**
     * Values worth trying against this field, with whatever siblings the rule needs to mean anything.
     *
     * @param  array<string, mixed>  $field
     * @return list<array{0: mixed, 1: array<string, mixed>}>
     */
    private function probes(array $field, string $pattern, bool $wildcard): array
    {
        $rules = $field['rules'] ?? [];

        if (! is_array($rules)) {
            return [];
        }

        // A wildcard leaf never typed is filled with null before rules run (data_fill), so "absent" cannot reach it.
        $values = $wildcard ? self::UNIVERSAL : array_merge([self::ABSENT], self::UNIVERSAL);

        $probes = array_map(static fn (mixed $value): array => [$value, []], $values);

        $timeSensitive = $this->timeSensitive($rules);

        foreach ($rules as $rule) {
            if (! is_array($rule)) {
                continue;
            }

            foreach ($this->probesFor($rule, $pattern, $timeSensitive) as $probe) {
                $probes[] = $probe;
            }
        }

        return $probes;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return list<array{0: mixed, 1: array<string, mixed>}>
     */
    private function probesFor(array $rule, string $pattern, bool $timeSensitive): array
    {
        $name = is_string($rule['rule'] ?? null) ? $rule['rule'] : '';
        $params = is_array($rule['params'] ?? null) ? array_values($rule['params']) : [];
        $first = isset($params[0]) && is_scalar($params[0]) ? (string) $params[0] : '';

        // Judged-against-the-clock fields get fixed dates only — "now" would bake today into a committed file.
        if ($timeSensitive) {
            return $name === 'date' ? [['1990-04-23', []], ['2999-12-31', []], ['not a date', []]] : [];
        }

        return match ($name) {
            'max'         => $this->sizeProbes($rule, (float) $first, 1),
            'min'         => $this->sizeProbes($rule, (float) $first, -1),
            'in', 'enum'  => [[$first, []], ['not-in-the-list', []]],
            'date_format' => $this->formatProbes($first),
            'uuid'        => [['3f2504e0-4f89-41d3-9a0c-0305e82c3301', []], ['not-a-uuid', []]],
            'integer'     => [['7', []], ['7.5', []], [7.5, []]],
            'numeric'     => [['12.5', []], ['1e3', []], ['12,5', []]],
            'boolean'     => [['1', []], [2, []]],
            'confirmed'   => $this->confirmedProbes($pattern),
            'gte'         => $this->gteProbes($first, $pattern),
            default       => [],
        };
    }

    /**
     * One over the ceiling and one exactly on it, in whatever units `numeric` on the field decides.
     *
     * @param  array<string, mixed>  $rule
     * @return list<array{0: mixed, 1: array<string, mixed>}>
     */
    private function sizeProbes(array $rule, float $limit, int $direction): array
    {
        $message = is_string($rule['message'] ?? null) ? $rule['message'] : '';

        if (str_contains($message, 'characters')) {
            $length = (int) max(0, $limit + $direction);

            return [[str_repeat('a', $length), []], [str_repeat('a', (int) max(0, $limit)), []]];
        }

        if (str_contains($message, 'items')) {
            return [
                [array_fill(0, (int) max(0, $limit + $direction), 'a'), []],
                [array_fill(0, (int) max(0, $limit), 'a'), []],
            ];
        }

        return [[$limit + $direction, []], [$limit, []], [(string) ($limit + $direction), []]];
    }

    /**
     * @return list<array{0: mixed, 1: array<string, mixed>}>
     */
    private function formatProbes(string $format): array
    {
        $samples = ['Y-m-d' => '2026-08-23', 'H:i' => '07:45'];

        return [
            [$samples[$format] ?? '', []],
            ['nonsense', []],
            ['2026-13-45', []],
        ];
    }

    /**
     * @return list<array{0: mixed, 1: array<string, mixed>}>
     */
    private function confirmedProbes(string $pattern): array
    {
        return [
            ['a-matching-secret', [$pattern.'_confirmation' => 'a-matching-secret']],
            ['a-matching-secret', [$pattern.'_confirmation' => 'something else']],
            ['a-matching-secret', []],
        ];
    }

    /**
     * The other end of a range, above, level with and below this one.
     *
     * @return list<array{0: mixed, 1: array<string, mixed>}>
     */
    private function gteProbes(string $other, string $pattern): array
    {
        if ($other === '' || ! str_contains($pattern, '*')) {
            return [];
        }

        return [
            [10, [$other => 4]],
            [10, [$other => 10]],
            [10, [$other => 40]],
        ];
    }

    /**
     * @param  array<int, mixed>  $rules
     */
    private function timeSensitive(array $rules): bool
    {
        foreach ($rules as $rule) {
            $name = is_array($rule) && is_string($rule['rule'] ?? null) ? $rule['rule'] : '';

            if (in_array($name, ['date', 'after_or_equal', 'before_or_equal'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $siblings
     * @return array<string, mixed>
     */
    private function data(string $concrete, mixed $value, array $siblings): array
    {
        $data = [];

        if ($value !== self::ABSENT) {
            Arr::set($data, $concrete, $value);
        } elseif (str_contains($concrete, '.')) {
            // The row still has to exist for the box in it to be missing — and
            // it is read back off the key, not spelt with an index of its own,
            // so it cannot disagree with the one concrete() materialised.
            Arr::set($data, Str::beforeLast($concrete, '.'), []);
        }

        foreach ($siblings as $path => $sibling) {
            Arr::set($data, $this->exporter->concrete($path), $sibling);
        }

        return $data;
    }

    /**
     * Unanswerable rules taken out: $keepServerOnly drops just the NEEDS_THE_WORLD four; false drops server-only too.
     *
     * @param  array<string, mixed>  $fields
     * @param  array<string, array<int, mixed>>  $normalised
     * @return array<string, array<int, mixed>>
     */
    private function ruleSet(array $fields, array $normalised, bool $keepServerOnly): array
    {
        $set = [];

        foreach ($normalised as $pattern => $rules) {
            $exported = $fields[$pattern]['rules'] ?? [];

            if (! is_array($exported)) {
                continue;
            }

            $kept = [];

            foreach (array_values($rules) as $index => $rule) {
                $entry = is_array($exported[$index] ?? null) ? $exported[$index] : [];
                $serverOnly = ($entry['server_only'] ?? false) === true;
                $name = is_string($entry['rule'] ?? null) ? $entry['rule'] : '';

                if (in_array($name, self::NEEDS_THE_WORLD, true)) {
                    continue;
                }

                if ($serverOnly && ! $keepServerOnly) {
                    continue;
                }

                $kept[] = $rule;
            }

            $set[$pattern] = $kept;
        }

        return $set;
    }
}
