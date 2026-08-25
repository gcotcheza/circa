<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * The model's answer, read back out of a structured response.
 *
 * `AnthropicVisionAnalyzer` decodes the model's JSON document into items
 * plus a note; `vision_requests.raw_response` stores the whole message —
 * the column's entire purpose: a stored request is replayable, so a prompt
 * change can be evaluated against history and a proposal built under a
 * rule that turned out wrong can be rebuilt under the corrected one
 * WITHOUT buying the answer again.
 *
 * Both readings must agree exactly, or "replay" quietly means "something
 * close to what happened" — so the decoding lives here once: the analyzer
 * hands over the document it just parsed from the live response, replay
 * hands over the same document dug out of the stored message.
 */
final readonly class StoredAnswer
{
    /**
     * @param  list<ProposedItem>  $items
     */
    private function __construct(
        public array $items,
        public string $notes,
    ) {}

    /**
     * The decoded structured-output document: `{items: [...], notes: "..."}`.
     *
     * @param  array<array-key, mixed>  $decoded  a decoded JSON object
     */
    public static function fromDecoded(array $decoded): self
    {
        $items = [];

        foreach ((array) ($decoded['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $proposed = ProposedItem::fromArray($item);

            // A nameless item can't be reviewed, edited, or re-logged — nothing
            // for the user to agree to.
            if ($proposed->name !== '') {
                $items[] = $proposed;
            }
        }

        return new self($items, trim((string) ($decoded['notes'] ?? '')));
    }

    /**
     * The same answer, dug out of a stored `vision_requests.raw_response` —
     * null when the row holds no readable answer at all, which the caller
     * decides about, since it is not this class's business to invent an
     * empty meal. See RawResponse for why the content is walked, not indexed.
     *
     * @param  array<string, mixed>  $raw
     */
    public static function fromRawResponse(array $raw): ?self
    {
        $decoded = RawResponse::firstJsonObject($raw);

        return $decoded === null ? null : self::fromDecoded($decoded);
    }
}
