<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * The result of one call to the vision model.
 *
 * A value, not an exception: none of the three outcomes is exceptional —
 * the model answers, declines, or the call doesn't complete. All three
 * end with a `vision_requests` row, a status on the meal, and something
 * shown to the user; throwing for two of them would push the "which is
 * which" decision into a catch block.
 *
 * An answer with an EMPTY item list is a success, not a failure: "no food
 * in this picture" correctly analyses a picture of a chair, and the note
 * explains it.
 */
final readonly class VisionOutcome
{
    /**
     * @param  list<ProposedItem>  $items
     * @param  array<string, mixed>|null  $raw
     */
    private function __construct(
        public bool $succeeded,
        public array $items,
        public string $notes,
        public ?string $error,
        public ?array $raw,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $latencyMs,
    ) {}

    /**
     * @param  list<ProposedItem>  $items
     * @param  array<string, mixed>  $raw
     */
    public static function analysed(
        array $items,
        string $notes,
        array $raw,
        ?int $inputTokens,
        ?int $outputTokens,
        int $latencyMs,
    ): self {
        return new self(true, $items, $notes, null, $raw, $inputTokens, $outputTokens, $latencyMs);
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function failed(
        string $error,
        int $latencyMs,
        ?array $raw = null,
        ?int $inputTokens = null,
        ?int $outputTokens = null,
    ): self {
        return new self(false, [], '', $error, $raw, $inputTokens, $outputTokens, $latencyMs);
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
