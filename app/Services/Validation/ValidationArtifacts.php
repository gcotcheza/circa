<?php

declare(strict_types=1);

namespace App\Services\Validation;

use RuntimeException;

/**
 * The two committed files, and the one definition of what they should contain.
 *
 * Both the command that writes them and the test that refuses to let them go
 * stale ask this class, so "fresh" means the same thing on both sides — down
 * to the byte, which is what makes the comparison worth running.
 *
 * The rules are pretty-printed because a human reads that diff when a ceiling
 * moves. The cases are one per line for the same reason: a thousand-odd of
 * them pretty-printed is a file nobody can review, while one line each shows
 * exactly which sentence changed.
 */
final class ValidationArtifacts
{
    public const RULES = 'resources/js/lib/validation/rules.generated.json';

    public const CASES = 'tests/js/fixtures/validation-cases.jsonl';

    public function __construct(
        private readonly RuleExporter $exporter,
        private readonly CaseGenerator $cases,
    ) {}

    public function rules(): string
    {
        return $this->json($this->exporter->export(), pretty: true)."\n";
    }

    public function cases(): string
    {
        $lines = array_map(fn (array $case): string => $this->json($case, pretty: false), $this->cases->generate());

        return implode("\n", $lines)."\n";
    }

    /**
     * @return array<string, string> path => contents
     */
    public function all(): array
    {
        return [self::RULES => $this->rules(), self::CASES => $this->cases()];
    }

    public function committed(string $path): ?string
    {
        $full = base_path($path);

        if (! is_file($full)) {
            return null;
        }

        $contents = file_get_contents($full);

        return $contents === false ? null : $contents;
    }

    public function write(string $path, string $contents): void
    {
        $full = base_path($path);
        $directory = dirname($full);

        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Could not create '.$directory);
        }

        if (file_put_contents($full, $contents) === false) {
            throw new RuntimeException('Could not write '.$full);
        }
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function json(array $data, bool $pretty): string
    {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

        return json_encode($data, $pretty ? $flags | JSON_PRETTY_PRINT : $flags);
    }
}
