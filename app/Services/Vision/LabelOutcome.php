<?php

declare(strict_types=1);

namespace App\Services\Vision;

/**
 * The result of one call to read a label.
 *
 * A value, not an exception, for the same reason VisionOutcome is one: none
 * of the outcomes is exceptional — model reads it, model declines, call
 * doesn't complete — all three end in a `vision_requests` row and
 * something shown to the user.
 *
 * A reading with an EMPTY label is a SUCCESS: "this is a photograph of a
 * cat" correctly transcribes a photograph of a cat, and `notes` carries
 * the explanation — the review screen turns that into "doesn't look like a
 * label, try again", a different screen from "the analysis failed".
 */
final readonly class LabelOutcome
{
    /**
     * @param  array<string, mixed>|null  $raw
     */
    private function __construct(
        public bool $succeeded,
        public ?ParsedLabel $label,
        public ?string $error,
        public ?array $raw,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $latencyMs,
    ) {}

    /**
     * @param  array<string, mixed>  $raw
     */
    public static function read(
        ParsedLabel $label,
        array $raw,
        ?int $inputTokens,
        ?int $outputTokens,
        int $latencyMs,
    ): self {
        return new self(true, $label, null, $raw, $inputTokens, $outputTokens, $latencyMs);
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
        return new self(false, null, $error, $raw, $inputTokens, $outputTokens, $latencyMs);
    }
}
