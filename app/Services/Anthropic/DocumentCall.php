<?php

declare(strict_types=1);

namespace App\Services\Anthropic;

use Throwable;
use JsonException;
use Anthropic\Client;
use Psr\Log\LogLevel;
use Psr\Log\LoggerInterface;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\StopReason;
use Anthropic\Messages\MessageParam;
use Anthropic\Messages\OutputConfig;
use Anthropic\Messages\JSONOutputFormat;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Messages\OutputConfig\Effort;

/**
 * One structured-output call to Anthropic, reduced to a ModelDocument.
 *
 * Shared rather than copied because every rule in here is a property of the
 * API and not of the question asked, and each one fails SILENTLY when it is
 * forgotten: `stop_reason` read before `content` is touched, truncation
 * treated as a failure rather than parsed, the whole message kept for the
 * audit row, usage carried on the failure path too, and an SDK error turned
 * into an outcome rather than an exception loose in a queue worker. The
 * report and vision paths each carried a copy until this class; two copies
 * of a silent rule can drift apart without a single test going red.
 *
 * What genuinely differs between callers is a constructor argument, so the
 * differences are readable at the two construction sites rather than hidden
 * in here: the effort level, the ceiling on tokens, the label a log line
 * carries and the name the reader knows this service by.
 *
 * See docs/rationale-app.md § "DocumentCall: the rules every
 * structured-output call has to get right".
 */
final class DocumentCall
{
    /**
     * @param  Effort|null  $effort  null leaves it off the request entirely, taking the model's default
     * @param  string  $logMessage  the log line when the call did not complete at all
     * @param  string  $serviceName  the service as the READER knows it ("report", "analysis"); every failure sentence is built from it, so the callers differ by a word rather than by a ladder each
     */
    public function __construct(
        private readonly Client $client,
        private readonly LoggerInterface $logger,
        private readonly string $model,
        private readonly int $maxTokens,
        private readonly ?Effort $effort,
        private readonly string $logMessage,
        private readonly string $serviceName,
    ) {}

    /**
     * The call, and the response reduced to "the document, or why there
     * isn't one".
     *
     * The clock starts at the caller, not here, so work the caller does
     * before the request — encoding the facts, reading a photo — is counted
     * in the latency somebody is later shown.
     *
     * No temperature / top_p / top_k: REMOVED on this model, so a request
     * carrying any is rejected with a 400 rather than ignored.
     *
     * @param  list<mixed>  $content
     * @param  array<string, mixed>  $schema
     */
    public function document(array $content, array $schema, float|int $startedAt): ModelDocument
    {
        try {
            $message = $this->client->messages->create(
                maxTokens: $this->maxTokens,
                messages: [MessageParam::with(role: 'user', content: $content)],
                model: $this->model,
                // A null effort is dropped by the SDK rather than sent as
                // `"effort": null`, which is what lets one call site want it
                // and the other not without either shaping its own request.
                outputConfig: OutputConfig::with(
                    effort: $this->effort,
                    format: JSONOutputFormat::with(schema: $schema),
                ),
            );
        } catch (APIException $e) {
            // Logged whatever the status, since the reader is now told a
            // plain sentence and this is the only place the class, the status
            // and the API's own words survive. Error only for a credential the
            // API rejected: that is a deployment broken for every call until
            // somebody acts, where a 429 or a 5xx is the API having a minute.
            $level = $e->status === 401 || $e->status === 403 ? LogLevel::ERROR : LogLevel::WARNING;

            $this->note($level, $e, $e->status);

            return ModelDocument::failed($this->friendly($e->status), null, null, null, $this->elapsed($startedAt));
        } catch (Throwable $e) {
            $this->note(LogLevel::ERROR, $e, null);

            // Deliberately not the unreachable sentence: a bug in this app
            // lands here as readily as a transport the SDK did not recognise,
            // and sending somebody to check their network over a TypeError
            // sends them after the wrong thing.
            return ModelDocument::failed($this->unexpected(), null, null, null, $this->elapsed($startedAt));
        }

        return $this->interpret($message, $this->elapsed($startedAt));
    }

    /**
     * Milliseconds since a caller's `hrtime(true)`, so a failure raised
     * before the request is timed on the same clock as one raised after it.
     */
    public function elapsed(float|int $startedAt): int
    {
        return (int) round((hrtime(true) - $startedAt) / 1_000_000);
    }

    /**
     * A refusal is a successful HTTP 200 with empty or partial content, so
     * `stop_reason` is read before `content` is touched — code that indexes
     * `content[0]` first crashes on the case it most needs to explain. A
     * truncation is checked in the same breath: partial JSON is not a small
     * problem, it is unparseable.
     */
    private function interpret(Message $message, int $latencyMs): ModelDocument
    {
        $raw = $this->rawOf($message);
        $inputTokens = $message->usage->inputTokens;
        $outputTokens = $message->usage->outputTokens;

        // The SDK hands the stop reason back as its raw string, not as the
        // enum it documents, so the constants are compared by value.
        $stopReason = $message->stopReason;

        if ($stopReason === StopReason::REFUSAL->value) {
            return ModelDocument::failed(ModelDocument::FAILED_REFUSAL, $raw, $inputTokens, $outputTokens, $latencyMs);
        }

        if ($stopReason === StopReason::MAX_TOKENS->value) {
            return ModelDocument::failed(ModelDocument::FAILED_TRUNCATED, $raw, $inputTokens, $outputTokens, $latencyMs);
        }

        $json = $this->firstText($message);

        if ($json === null) {
            return ModelDocument::failed(ModelDocument::FAILED_EMPTY, $raw, $inputTokens, $outputTokens, $latencyMs);
        }

        try {
            // Depth 64 matches the raw capture below; the limit is only a
            // guard against pathological nesting, and a server-side schema
            // cannot produce anything close to it either way.
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Structured outputs should make this unreachable; handled anyway
            // since "should" isn't a guarantee, and the alternative is a 500
            // in a queue worker.
            return ModelDocument::failed(ModelDocument::FAILED_UNREADABLE, $raw, $inputTokens, $outputTokens, $latencyMs);
        }

        return ModelDocument::of($decoded, (array) $raw, $inputTokens, $outputTokens, $latencyMs);
    }

    /**
     * The schema is enforced server-side, so the first text block IS the
     * JSON — nothing here strips prose, unwraps a fence or hunts for a `{`.
     * It is walked to rather than indexed, since a thinking block can come
     * before it.
     */
    private function firstText(Message $message): ?string
    {
        foreach ($message->content as $block) {
            if ($block instanceof TextBlock) {
                return $block->text;
            }
        }

        return null;
    }

    /**
     * The response as it will be stored on the audit row.
     *
     * Kept WHOLE rather than reduced to the answer: the point of the column
     * is replaying a newer prompt against an old question and diffing, and
     * usage, stop reason and model all matter to that comparison.
     *
     * @return array<string, mixed>|null
     */
    private function rawOf(Message $message): ?array
    {
        try {
            /** @var array<string, mixed> $array */
            $array = json_decode(json_encode($message, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR);

            return $array;
        } catch (JsonException) {
            return null;
        }
    }

    /**
     * What the reader is told, which is deliberately never the SDK's own
     * text: that begins with a description of the exception class ("Anthropic
     * API Connection Error") and, on a status error, continues into a JSON
     * dump of the response body. One person reads these, on a phone, from a
     * list of failed reports — so each sentence says what happened and what
     * to do about it, and the technical account goes to the log instead.
     *
     * Keyed on the STATUS rather than the exception class, because the class
     * cannot tell a 529 from a 500: the SDK maps every 5xx to
     * InternalServerException, and "busy, try in a minute" is the opposite
     * advice to "try later".
     */
    private function friendly(?int $status): string
    {
        // No status means no answer — but not necessarily no connection: the
        // SDK raises one APIConnectionException both for a network that was
        // never reachable and for a request that connected and then overran
        // the client timeout, which at 300s for a report is the likelier of
        // the two. So the sentence has to be true of both. A 408 is the same
        // event reported by a server that did stay up, and is not a rejection.
        if ($status === null || $status === 408) {
            return "The {$this->serviceName} service did not answer in time. Check the connection, then try again.";
        }

        return match (true) {
            $status === 429, $status === 529 => "The {$this->serviceName} service is busy right now. Try again in a minute.",
            $status === 401, $status === 403 => "The {$this->serviceName} service rejected the app's credentials. Check the API key.",
            $status >= 500                   => "The {$this->serviceName} service had an internal error. Try again later.",
            $status >= 400                   => "The {$this->serviceName} service rejected the request.",
            default                          => $this->unexpected(),
        };
    }

    /** The sentence that promises nothing about the cause, for a failure nothing above recognised. */
    private function unexpected(): string
    {
        return "The {$this->serviceName} service returned an error.";
    }

    /**
     * The technical account of a call that did not complete, which no longer
     * reaches the reader: class, status and the SDK's own text live here or
     * nowhere. The status is a key of its own because grepping a JSON blob
     * for "529" finds the body of a 400 just as happily.
     */
    private function note(string $level, Throwable $e, ?int $status): void
    {
        $this->logger->log($level, $this->logMessage, [
            'exception' => $e::class,
            'status'    => $status,
            'message'   => $e->getMessage(),
        ]);
    }
}
