<?php

declare(strict_types=1);

namespace App\Services\Vision;

use Anthropic\Client;
use Psr\Log\LoggerInterface;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\ImageBlockParam;
use App\Services\Anthropic\DocumentCall;
use Anthropic\Messages\Base64ImageSource;
use App\Services\Anthropic\ModelDocument;

/**
 * The one place that asks Anthropic about food — for the photo path, the
 * text-estimation path and the supplement label alike.
 *
 * The three differ only in the content blocks and which prompt constant
 * closes them. Everything genuinely hard — stop-reason ordering, truncation,
 * refusal, raw-response capture — is DocumentCall's, shared with the report
 * path since it is a property of the API, not of what was asked.
 *
 * Non-obvious points that ARE this class's own:
 *
 * 1. Evidence first, then the instruction — blocks are ordered, and a
 *    question asked after the evidence differs from one asked before it.
 *
 * 2. Thinking is on by default on claude-opus-5, and `maxTokens` caps
 *    thinking and the answer TOGETHER — why the ceiling is 4096, not the
 *    ~800 the JSON needs. Left on deliberately: disabling it is what leaks
 *    `<thinking>` tags into visible output.
 *
 * 3. Retries are the SDK's, once (`maxRetries: 1`, covering 429/5xx/
 *    connection errors); the job's `tries = 1` sits on top, so an
 *    unreadable photo fails visibly with a Re-analyze button rather than
 *    being paid for four times in ninety seconds.
 */
final class AnthropicVisionAnalyzer implements VisionAnalyzer
{
    private readonly DocumentCall $anthropic;

    public function __construct(
        Client $client,
        LoggerInterface $logger,
        private readonly string $model,
        int $maxTokens,
    ) {
        $this->anthropic = new DocumentCall(
            client: $client,
            logger: $logger,
            model: $model,
            maxTokens: $maxTokens,
            // No effort level: unlike a report, none of these questions is
            // worth spending a longer think on, so the model's default holds.
            effort: null,
            logMessage: 'Vision call failed',
            // The one word every failure sentence is built from: the log says
            // "Vision", the reader is told "the analysis service".
            serviceName: 'analysis',
        );
    }

    public function model(): string
    {
        return $this->model;
    }

    public function promptVersion(): string
    {
        return PromptV3Photo::VERSION;
    }

    public function textPromptVersion(): string
    {
        return PromptV1Text::VERSION;
    }

    public function labelPromptVersion(): string
    {
        return PromptV1Label::VERSION;
    }

    /**
     * One photograph of one course, plus what is already on the meal and
     * anything the person eating it said about the plate.
     *
     * Block order IS the prompt — evidence first, then context, then the question:
     *
     *   [0] image           the plate. One per call, always — a meal with
     *                       three courses is three calls, three audit rows,
     *                       three separately confirmable proposals, so "the
     *                       dessert was wrong" is fixable without
     *                       re-analysing the main.
     *   [1] the note        what the user said this food IS. Directly after
     *                       the image as evidence ABOUT THIS PLATE; omitted
     *                       when they didn't write one (most plates).
     *   [2] already-logged  the rest of the meal, for the double-count
     *                       guard. Omitted when the meal is empty, so a
     *                       first unhinted course sees exactly the two
     *                       blocks it always did.
     *   [n] instruction     last, so the rules read as rules about
     *                       everything above them — including the note.
     *
     * Both context blocks are their own block, not interpolated into the
     * instruction: they're user input, and untrusted input never becomes
     * part of the prompt text. Instruction last is the other half of that
     * defence — the final word is always ours.
     *
     * @param  list<string>  $alreadyLogged
     * @param  'image/gif'|'image/jpeg'|'image/png'|'image/webp'  $mediaType  what PhotoStore re-encoded to
     */
    public function analyze(string $imageBytes, array $alreadyLogged = [], ?string $hint = null, string $mediaType = 'image/jpeg'): VisionOutcome
    {
        $content = [
            ImageBlockParam::with(
                source: Base64ImageSource::with(
                    data: base64_encode($imageBytes),
                    mediaType: $mediaType,
                ),
            ),
        ];

        $note = PromptV3Photo::userHint($hint);

        if ($note !== null) {
            $content[] = TextBlockParam::with(text: $note);
        }

        $context = PromptV3Photo::alreadyLogged($alreadyLogged);

        if ($context !== null) {
            $content[] = TextBlockParam::with(text: $context);
        }

        $content[] = TextBlockParam::with(text: PromptV3Photo::TEXT);

        return $this->ask($content, PromptV3Photo::schema());
    }

    /**
     * The same call, with a description where the photograph was.
     *
     * Evidence first, then the instruction — same ordering rule as the
     * photo path, and here it also keeps the "echo what the user gave you"
     * instruction reading as a rule about the list right above it.
     *
     * Two blocks rather than one concatenated string keeps the boundary
     * between the user's words and ours explicit — the typed meal is
     * untrusted input, and never becomes part of the instruction text.
     */
    public function estimate(string $description): VisionOutcome
    {
        return $this->ask(
            [
                TextBlockParam::with(text: $description),
                TextBlockParam::with(text: PromptV1Text::TEXT),
            ],
            PromptV1Text::schema(),
        );
    }

    /**
     * One photograph of a supplement package, and the transcription of it.
     *
     * Same two-block shape as everything else here — evidence, then
     * instruction — deliberately WITHOUT the context blocks the meal path
     * carries: no double-count guard applies and no user note to pass on. A
     * label says what it says; handing the model a hint risks a
     * transcription bent toward what somebody expected to see.
     */
    /** @param  'image/gif'|'image/jpeg'|'image/png'|'image/webp'  $mediaType  what PhotoStore re-encoded to */
    public function readLabel(string $imageBytes, string $mediaType = 'image/jpeg'): LabelOutcome
    {
        // Before the content is built, not after: base64-encoding the label
        // photograph is real work, and `vision_requests.latency_ms` is meant
        // to be what the reader waited for.
        $startedAt = hrtime(true);

        $document = $this->anthropic->document(
            [
                ImageBlockParam::with(
                    source: Base64ImageSource::with(
                        data: base64_encode($imageBytes),
                        mediaType: $mediaType,
                    ),
                ),
                TextBlockParam::with(text: PromptV1Label::TEXT),
            ],
            PromptV1Label::schema(),
            $startedAt,
        );

        if ($document->decoded === null) {
            return LabelOutcome::failed(
                // The same four cases as a meal, said to somebody holding a
                // bottle rather than a fork. The advice differs because the
                // fallback differs: a supplement can be added by hand from
                // the label the user is already looking at.
                $document->failureText([
                    ModelDocument::FAILED_REFUSAL    => 'Reading that label was declined. Try a different photo, or add the supplement by hand.',
                    ModelDocument::FAILED_TRUNCATED  => 'The label was longer than the reading had room for. Try a closer photo of just the panel.',
                    ModelDocument::FAILED_EMPTY      => 'The label reading came back empty.',
                    ModelDocument::FAILED_UNREADABLE => 'The label reading came back in a shape this app could not read.',
                ]),
                $document->latencyMs,
                $document->raw,
                $document->inputTokens,
                $document->outputTokens,
            );
        }

        // Read through ParsedLabel, not here, so a review screen rebuilt from the
        // stored `raw_response` shows exactly the lines this call produced.
        return LabelOutcome::read(
            label: ParsedLabel::fromDecoded($document->decoded),
            raw: (array) $document->raw,
            inputTokens: $document->inputTokens,
            outputTokens: $document->outputTokens,
            latencyMs: $document->latencyMs,
        );
    }

    /**
     * @param  list<mixed>  $content
     * @param  array<string, mixed>  $schema
     */
    private function ask(array $content, array $schema): VisionOutcome
    {
        $document = $this->anthropic->document($content, $schema, hrtime(true));

        if ($document->decoded === null) {
            return VisionOutcome::failed(
                // Word for word what these cases have always said. Anything
                // that is not one of the four is a sentence DocumentCall built
                // about the call itself, in the same plain voice as these.
                $document->failureText([
                    ModelDocument::FAILED_REFUSAL    => 'The analysis was declined for this photo. Try a different picture, or log the meal by hand.',
                    ModelDocument::FAILED_TRUNCATED  => 'The analysis ran out of room before it finished. Try again, or log the meal by hand.',
                    ModelDocument::FAILED_EMPTY      => 'The analysis came back empty.',
                    ModelDocument::FAILED_UNREADABLE => 'The analysis came back in a shape this app could not read.',
                ]),
                $document->latencyMs,
                $document->raw,
                $document->inputTokens,
                $document->outputTokens,
            );
        }

        // Read through StoredAnswer, not here, so replaying a stored `raw_response`
        // produces exactly the items this call produced.
        $answer = StoredAnswer::fromDecoded($document->decoded);

        return VisionOutcome::analysed(
            items: $answer->items,
            notes: $answer->notes,
            raw: (array) $document->raw,
            inputTokens: $document->inputTokens,
            outputTokens: $document->outputTokens,
            latencyMs: $document->latencyMs,
        );
    }
}
