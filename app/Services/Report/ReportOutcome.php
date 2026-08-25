<?php

declare(strict_types=1);

namespace App\Services\Report;

/**
 * What came back from one attempt at writing a report: the document, or why
 * there is not one — plus what it cost either way.
 *
 * THE USAGE FIGURES TRAVEL ON THE FAILURE PATH TOO, deliberately. A refusal
 * after a full read of the facts has been paid for; a truncation twice over.
 * A failure reporting no tokens would make the audit table under-count
 * spend by exactly the calls somebody most wants explained.
 */
final readonly class ReportOutcome
{
    /**
     * @param  array<string, mixed>  $report  the structured answer, decoded
     * @param  array<string, mixed>|null  $raw  the whole message, for audit
     */
    private function __construct(
        public bool $succeeded,
        public array $report,
        public ?string $error,
        public ?array $raw,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $latencyMs,
    ) {}

    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, mixed>  $raw
     */
    public static function written(
        array $report,
        array $raw,
        ?int $inputTokens,
        ?int $outputTokens,
        int $latencyMs,
    ): self {
        return new self(true, $report, null, $raw, $inputTokens, $outputTokens, $latencyMs);
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
        return new self(false, [], $error, $raw, $inputTokens, $outputTokens, $latencyMs);
    }
}
