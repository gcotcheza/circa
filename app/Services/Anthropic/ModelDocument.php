<?php

declare(strict_types=1);

namespace App\Services\Anthropic;

/**
 * One structured-output response, reduced to "the document, or why there
 * isn't one" — before anybody decides what the document MEANS.
 *
 * Everything genuinely hard about talking to the API is the same whatever
 * was asked: stop-reason ordering, the refusal case, the truncation check,
 * raw-response capture, usage figures, the "structured outputs should make
 * this unreachable, handle it anyway" JSON branch — none of it about meals
 * or labels.
 *
 * That logic once lived inside a method that also built a VisionOutcome, so
 * a second question with a different answer shape meant copying it — the
 * wrong move here because the failure mode is silent: a copy that forgets to
 * check `stop_reason` before indexing `content[0]` crashes on exactly the
 * case it most needed to explain, in a queue worker. So the response is
 * reduced once, by DocumentCall, and each caller decides only what its own
 * document means.
 *
 * The words a failure is said in stay with the caller — "ran out of room"
 * and "the label could not be read" are said to a user about a task, and
 * this class doesn't know which task it was; `failureText()` only looks the
 * caller's own wording up by the constant that fired.
 */
final readonly class ModelDocument
{
    /**
     * @param  array<string, mixed>|null  $decoded  the answer, or null if there is none
     * @param  string|null  $failure  one of the FAILED_* constants
     * @param  array<string, mixed>|null  $raw  the whole message, for `raw_response`
     */
    private function __construct(
        public ?array $decoded,
        public ?string $failure,
        public ?array $raw,
        public ?int $inputTokens,
        public ?int $outputTokens,
        public int $latencyMs,
    ) {}

    /** A safety classifier declined. Not an error, not a bug, not retryable. */
    public const FAILED_REFUSAL = 'refusal';

    /** Truncated mid-JSON. A partial document is not a small problem. */
    public const FAILED_TRUNCATED = 'truncated';

    /** Content with no text block in it at all. */
    public const FAILED_EMPTY = 'empty';

    /** Text that is not JSON. Structured outputs should make this unreachable. */
    public const FAILED_UNREADABLE = 'unreadable';

    /**
     * @param  array<string, mixed>  $decoded
     * @param  array<string, mixed>  $raw
     */
    public static function of(array $decoded, array $raw, ?int $inputTokens, ?int $outputTokens, int $latencyMs): self
    {
        return new self($decoded, null, $raw, $inputTokens, $outputTokens, $latencyMs);
    }

    /**
     * @param  array<string, mixed>|null  $raw
     */
    public static function failed(string $failure, ?array $raw, ?int $inputTokens, ?int $outputTokens, int $latencyMs): self
    {
        return new self(null, $failure, $raw, $inputTokens, $outputTokens, $latencyMs);
    }

    /**
     * What the user is told about a document that isn't one.
     *
     * The map is keyed by the FAILED_* constants and typed as a shape, so a
     * caller that answers only three of the four cases is a static-analysis
     * error rather than a user shown the bare word "truncated". Anything
     * outside the four is a sentence DocumentCall already built about the
     * call itself, and passes through unchanged.
     *
     * @param  array{refusal: string, truncated: string, empty: string, unreadable: string}  $messages
     */
    public function failureText(array $messages): string
    {
        // A document that succeeded has nothing to be said about; callers
        // reach this only when `decoded` is null, where `failure` never is.
        if ($this->failure === null) {
            return '';
        }

        return $messages[$this->failure] ?? $this->failure;
    }
}
