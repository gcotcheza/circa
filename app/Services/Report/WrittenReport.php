<?php

declare(strict_types=1);

namespace App\Services\Report;

/**
 * One structured answer, read into the shape the screen renders.
 *
 * WHY READ HERE AND NOWHERE ELSE: the same discipline
 * App\Services\Vision\StoredAnswer and ParsedLabel enforce — the
 * document is interpreted in exactly one place, so a report rebuilt
 * from the stored `output` column renders identically to the one the
 * user watched arrive. Two readings of one document is one of them
 * drifting, and since a report is read once and re-read weeks later
 * off the list, the drift would only show up long after its cause.
 *
 * Defensive about a schema it controls: `output_config.format`
 * enforces the shape server-side, so in principle every key is present
 * and correctly typed. In practice this also parses rows written by an
 * EARLIER prompt version — the point of versioning is that old reports
 * stay readable — so a schema change doesn't turn every historical
 * report into a 500 on the list page. A missing section is an empty
 * section, not an exception.
 */
final readonly class WrittenReport
{
    private function __construct(
        public string $headline,
        public string $summary,
        public string $energyBalance,
        public string $foodQuality,
        /** @var list<array<string, mixed>> */
        public array $micronutrients,
        public string $cardiovascular,
        public string $sleep,
        public string $stressPatterns,
        /** @var list<array{observation: string, evidence: string}> */
        public array $observations,
        /** @var list<array{suggestion: string, rationale: string}> */
        public array $suggestions,
        /** @var list<string> */
        public array $dataGaps,
    ) {}

    /**
     * @param  array<string, mixed>  $decoded
     */
    public static function fromDecoded(array $decoded): self
    {
        return new self(
            headline: self::text($decoded, 'headline'),
            summary: self::text($decoded, 'summary'),
            energyBalance: self::text($decoded, 'energy_balance'),
            foodQuality: self::text($decoded, 'food_quality'),
            micronutrients: self::micronutrients($decoded['micronutrients'] ?? null),
            cardiovascular: self::text($decoded, 'cardiovascular'),
            sleep: self::text($decoded, 'sleep'),
            stressPatterns: self::text($decoded, 'stress_patterns'),
            observations: array_map(
                static fn (array $pair): array => ['observation' => $pair[0], 'evidence' => $pair[1]],
                self::pairs($decoded['observations'] ?? null, 'observation', 'evidence'),
            ),
            suggestions: array_map(
                static fn (array $pair): array => ['suggestion' => $pair[0], 'rationale' => $pair[1]],
                self::pairs($decoded['suggestions'] ?? null, 'suggestion', 'rationale'),
            ),
            dataGaps: self::strings($decoded['data_gaps'] ?? null),
        );
    }

    /**
     * The props the report page renders from.
     *
     * camelCase because that's what this app's Inertia props use; the
     * boundary between the model's snake_case schema and the front
     * end's conventions belongs here, not in a Vue component.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'headline'       => $this->headline,
            'summary'        => $this->summary,
            'energyBalance'  => $this->energyBalance,
            'foodQuality'    => $this->foodQuality,
            'micronutrients' => $this->micronutrients,
            'cardiovascular' => $this->cardiovascular,
            'sleep'          => $this->sleep,
            'stressPatterns' => $this->stressPatterns,
            'observations'   => $this->observations,
            'suggestions'    => $this->suggestions,
            'dataGaps'       => $this->dataGaps,
        ];
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private static function text(array $decoded, string $key): string
    {
        $value = $decoded[$key] ?? null;

        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function micronutrients(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $nutrient = $row['nutrient'] ?? null;

            // A row with no nutrient name isn't a row about a
            // nutrient — the one field the screen can't render around.
            if (! is_string($nutrient) || trim($nutrient) === '') {
                continue;
            }

            $coverage = $row['food_coverage_pct'] ?? null;

            $out[] = [
                'nutrient'        => trim($nutrient),
                'supplementExact' => is_string($row['supplement_exact'] ?? null) ? trim($row['supplement_exact']) : '',
                // Null and empty-string are collapsed on purpose: both
                // mean "no food-side figure," and the screen has one
                // way of saying that.
                'foodEstimateRange' => is_string($row['food_estimate_range'] ?? null) && trim($row['food_estimate_range']) !== ''
                    ? trim($row['food_estimate_range'])
                    : null,
                'foodCoveragePct' => is_int($coverage) || is_float($coverage) ? round((float) $coverage, 1) : null,
                'combinedComment' => is_string($row['combined_comment'] ?? null) ? trim($row['combined_comment']) : '',
            ];
        }

        return $out;
    }

    /**
     * The non-empty rows of a two-key list, as ordered pairs.
     *
     * Values rather than a shape: the key names are arguments here, so
     * naming them belongs to the caller — which is also the only place
     * they are literal enough to be checked.
     *
     * @return list<array{string, string}>
     */
    private static function pairs(mixed $value, string $firstKey, string $secondKey): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $row) {
            if (! is_array($row)) {
                continue;
            }

            $first = $row[$firstKey] ?? null;

            if (! is_string($first) || trim($first) === '') {
                continue;
            }

            $second = $row[$secondKey] ?? null;

            $out[] = [trim($first), is_string($second) ? trim($second) : ''];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];

        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return $out;
    }
}
