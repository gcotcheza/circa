<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * What the model read off a label, read back out of a structured response.
 *
 * The exact counterpart of StoredAnswer, existing for the same reason:
 * there must be ONE definition of "what did it say." The analyzer decodes
 * the live response into this; the review screen digs the same document
 * out of `vision_requests.raw_response` and decodes it into this too — two
 * readings of one document is one of them silently drifting, a review
 * screen showing different figures from what the audit row recorded.
 * That's also why there's no `supplement_label_drafts` table: the reading
 * is already stored verbatim in the row that had to exist anyway, and a
 * second copy would be a second thing to keep in step — the one going
 * stale would be the one the user tapped Confirm on.
 */
final readonly class ParsedLabel
{
    /**
     * @param  list<LabelNutrient>  $nutrients
     */
    private function __construct(
        public string $name,
        public ?string $brand,
        public ?string $servingText,
        public array $nutrients,
        public string $notes,
    ) {}

    /**
     * The decoded structured-output document.
     *
     * @param  array<array-key, mixed>  $decoded  a decoded JSON object
     */
    public static function fromDecoded(array $decoded): self
    {
        $nutrients = [];

        foreach ((array) ($decoded['nutrients'] ?? []) as $line) {
            if (! is_array($line)) {
                continue;
            }

            $nutrient = LabelNutrient::fromArray($line);

            // A nameless line can't be reviewed, edited or believed — same
            // rule StoredAnswer applies to a nameless item.
            if ($nutrient->nutrient !== '') {
                $nutrients[] = $nutrient;
            }
        }

        return new self(
            name: trim((string) ($decoded['name'] ?? '')),
            brand: self::text($decoded['brand'] ?? null),
            servingText: self::text($decoded['serving_text'] ?? null),
            nutrients: $nutrients,
            notes: trim((string) ($decoded['notes'] ?? '')),
        );
    }

    /**
     * The same reading, dug out of a stored `vision_requests.raw_response`
     * — null when the row holds no readable answer. See RawResponse for why
     * the content is walked rather than indexed.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromRawResponse(array $raw): ?self
    {
        $decoded = RawResponse::firstJsonObject($raw);

        return $decoded === null ? null : self::fromDecoded($decoded);
    }

    /**
     * "There is no label in this photograph." A successful answer, not a
     * failure — exactly as an empty `items` array is on the meal path: the
     * photo of a cat was read correctly, there was just nothing to
     * transcribe, and `notes` says so.
     */
    public function isEmpty(): bool
    {
        return $this->nutrients === [] && $this->name === '';
    }

    /**
     * The review screen's payload.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name'        => $this->name,
            'brand'       => $this->brand,
            'servingText' => $this->servingText,
            'nutrients'   => array_map(static fn (LabelNutrient $n): array => $n->toArray(), $this->nutrients),
            'notes'       => $this->notes,
        ];
    }

    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
