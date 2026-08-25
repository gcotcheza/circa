<?php

declare(strict_types=1);

namespace App\Services\Report;

use JsonException;
use Anthropic\Client;
use Psr\Log\LoggerInterface;
use Anthropic\Messages\TextBlockParam;
use App\Services\Anthropic\DocumentCall;
use App\Services\Anthropic\ModelDocument;
use Anthropic\Messages\OutputConfig\Effort;

/**
 * The one place that asks Anthropic to write a health report.
 *
 * Making the call is DocumentCall's job, shared with the vision path because
 * the stop-reason check, the truncation branch, raw capture and "an SDK
 * error becomes an outcome, never an exception" are properties of the API
 * rather than of this question. What belongs to this class is the question:
 * the facts get a block of their own because they carry untrusted text
 * (user-typed food names, model-written photo notes) that must never become
 * part of the instruction, and the instruction goes last as the other half
 * of that defence — which also keeps its rules reading as rules about the
 * facts above them.
 *
 * Deliberately different from the vision path: maxTokens 16 000, not 4 096,
 * since thinking shares the ceiling with the answer on claude-opus-5 and a
 * report is ten prose sections plus a nutrient table — a budget sized for
 * JSON alone truncates mid-document into an unparseable output (see
 * config('health.report.max_tokens')). The HTTP timeout is five minutes, not
 * two, since 16k tokens of thinking-plus-answer takes minutes and nobody's
 * watching a spinner (it's queued). Non-streaming, on purpose: the audit row
 * needs exact token counts and the whole raw message, this is a single
 * server-to-server call with no proxy and an explicit 300s client timeout,
 * so hand-assembling usage from streamed events buys nothing this deployment
 * needs.
 */
final class AnthropicReportWriter implements ReportWriter
{
    private readonly DocumentCall $anthropic;

    public function __construct(
        Client $client,
        private readonly LoggerInterface $logger,
        private readonly string $model,
        int $maxTokens,
        string $effort,
    ) {
        $this->anthropic = new DocumentCall(
            client: $client,
            logger: $logger,
            model: $model,
            maxTokens: $maxTokens,
            // Resolved at construction rather than at the call, because the
            // call catches Throwable: a ValueError from a bad REPORT_EFFORT
            // would reach the reader as "The report service returned an error"
            // and exist only in the log. A deployment mistake fails like one,
            // before a single report is attempted.
            effort: Effort::from($effort),
            logMessage: 'Report call failed',
            // The one word every failure sentence is built from: the log says
            // "Report", the reader is told "the report service".
            serviceName: 'report',
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    /**
     * The prompt this writer currently sends.
     *
     * One constant, in one place: `health_reports.prompt_version` is what
     * makes a prompt change evaluable (any past report's `input_snapshot`
     * can be replayed through a newer prompt and the answers diffed), which
     * only works if the recorded version is the version that actually
     * wrote it — so this method, the text block and the schema all read
     * from the same class, and moving to v3 is one edit rather than three.
     * PromptV1Report stays in the tree, untouched, as the control this
     * voice change is judged against and the fallback if v2 is worse.
     */
    public function promptVersion(): string
    {
        return PromptV2Report::VERSION;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    public function write(array $facts, ?ReportFocus $focus = null): ReportOutcome
    {
        $startedAt = hrtime(true);
        $focus ??= ReportFocus::everything();

        try {
            $json = json_encode(
                $facts,
                // Pretty-printed on purpose: costs tokens but keeps nesting
                // visible for a deep structure. UNESCAPED_UNICODE keeps Dutch
                // nutrient names and µ readable instead of \u-escaped.
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException $e) {
            $this->logger->error('Report facts could not be encoded', ['message' => $e->getMessage()]);

            return ReportOutcome::failed('The report data could not be prepared.', $this->anthropic->elapsed($startedAt));
        }

        $document = $this->anthropic->document(
            [
                TextBlockParam::with(text: $json),
                TextBlockParam::with(text: PromptV2Report::TEXT),
            ],
            // The focus decides which sections the answer must carry — a
            // stress report forced to produce a food paragraph would have
            // to invent it. See PromptV2Report::schema().
            PromptV2Report::schema($focus),
            $startedAt,
        );

        if ($document->decoded === null) {
            return ReportOutcome::failed(
                // Said to somebody looking at a list of reports, not a plate
                // of food, so the advice differs from the vision path's:
                // nothing to fix by hand here, just try again or pick a
                // shorter window.
                $document->failureText([
                    ModelDocument::FAILED_REFUSAL    => 'Writing this report was declined. Try a different range, or generate it again.',
                    ModelDocument::FAILED_TRUNCATED  => 'The report ran out of room before it finished. Try a shorter range.',
                    ModelDocument::FAILED_EMPTY      => 'The report came back empty.',
                    ModelDocument::FAILED_UNREADABLE => 'The report came back in a shape this app could not read.',
                ]),
                $document->latencyMs,
                $document->raw,
                $document->inputTokens,
                $document->outputTokens,
            );
        }

        return ReportOutcome::written(
            report: $document->decoded,
            raw: (array) $document->raw,
            inputTokens: $document->inputTokens,
            outputTokens: $document->outputTokens,
            latencyMs: $document->latencyMs,
        );
    }
}
