<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use Tests\TestCase;
use Anthropic\Client;
use GuzzleHttp\Psr7\Response;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use App\Services\Report\WrittenReport;
use Psr\Http\Message\RequestInterface;
use App\Services\Report\PromptV1Report;
use App\Services\Report\PromptV2Report;
use Psr\Http\Message\ResponseInterface;
use Database\Factories\HealthReportFactory;
use App\Services\Report\AnthropicReportWriter;

/**
 * What actually goes on the wire, and what comes back off it.
 *
 * FakeReportWriter answers our own interface, keeping every other test here
 * network-free while happily hiding a misspelled parameter, a wrong block order
 * or a schema the API rejects. So this one fakes a layer lower — a PSR-18
 * transporter handed to the real SDK client, capturing the exact JSON body —
 * mirroring tests/Feature/Vision/VisionRequestShapeTest for the same class of
 * silent breakage:
 *
 *   - the FACTS before the INSTRUCTION: a question asked after the evidence is
 *     a different prompt, and it keeps the rules reading as rules about the
 *     numbers above them.
 *   - the facts as their OWN block, never concatenated into the instruction:
 *     they carry food names the user typed and notes a model wrote, and
 *     untrusted text does not become part of the prompt.
 *   - `output_config.format.type = json_schema`, carrying OUR schema — which is
 *     what makes the first text block parseable JSON with no prose to strip.
 *   - `output_config.effort`, stated rather than defaulted.
 *   - 16 000 max tokens, not the vision path's 4 096: thinking shares the
 *     ceiling and a truncated report is unparseable rather than short.
 *   - no `temperature` / `top_p` / `top_k`, which this model rejects with a 400.
 */
final class ReportRequestShapeTest extends TestCase
{
    public function test_the_request_carries_the_facts_then_the_prompt_then_the_schema(): void
    {
        $transport = new RecordingReportTransport(self::successBody());

        $this->writer($transport)->write(['range' => ['start' => '2026-08-03', 'end' => '2026-08-09']]);

        $body = $transport->body();

        self::assertSame('claude-opus-5', $body['model']);
        self::assertSame(16000, $body['max_tokens']);

        self::assertSame('user', $body['messages'][0]['role']);

        $content = $body['messages'][0]['content'];

        // Two blocks: the facts, then the instruction. Never one.
        self::assertCount(2, $content);

        self::assertSame('text', $content[0]['type']);
        self::assertStringContainsString('"start": "2026-08-03"', $content[0]['text']);

        self::assertSame('text', $content[1]['type']);

        // The LIVE prompt, not merely "a" prompt: v1 stays in the tree as the
        // control for this voice change, and a writer still sending it would
        // leave every new row stamped v2 and written by v1.
        self::assertSame(PromptV2Report::TEXT, $content[1]['text']);
        self::assertNotSame(PromptV1Report::TEXT, $content[1]['text']);

        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame('high', $body['output_config']['effort']);

        self::assertSame(
            PromptV2Report::schema()['required'],
            $body['output_config']['format']['schema']['required'],
        );

        // v2 reuses v1's shape, so one WrittenReport and one screen serve both.
        self::assertSame(
            PromptV1Report::schema()['required'],
            $body['output_config']['format']['schema']['required'],
        );

        // Removed on this model: sending any of them is a 400, not a shrug.
        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('top_p', $body);
        self::assertArrayNotHasKey('top_k', $body);
    }

    /**
     * Readable JSON on purpose: a few hundred tokens of whitespace buys visible
     * nesting, and the Dutch nutrient names and the µ survive rather than
     * becoming \u-escapes the model has to decode.
     */
    public function test_the_facts_are_pretty_printed_with_unicode_intact(): void
    {
        $transport = new RecordingReportTransport(self::successBody());

        $this->writer($transport)->write([
            'micronutrients' => ['nutrients' => [['nutrient' => 'Vitamine B12 (als cyanocobalamine)', 'unit' => 'µg']]],
        ]);

        $facts = $transport->body()['messages'][0]['content'][0]['text'];

        self::assertStringContainsString('µg', $facts);
        self::assertStringContainsString("\n", $facts);

        // The escape sequence as six literal characters: without
        // JSON_UNESCAPED_UNICODE the model must decode every Dutch nutrient
        // name before quoting it back to somebody holding the bottle.
        self::assertStringNotContainsString('\u00b5', $facts);
    }

    /** The point of structured output: block one IS the JSON — no prose, no fence. */
    public function test_a_successful_response_is_read_into_the_report(): void
    {
        $outcome = $this->writer(new RecordingReportTransport(self::successBody()))->write([]);

        self::assertTrue($outcome->succeeded);
        self::assertSame(19_500, $outcome->inputTokens);
        self::assertSame(3_400, $outcome->outputTokens);

        $report = WrittenReport::fromDecoded($outcome->report);

        self::assertStringContainsString('steady week', $report->headline);
        self::assertCount(1, $report->micronutrients);
    }

    /**
     * A refusal is a successful HTTP 200 with an EMPTY content array: code that
     * reads content[0] first crashes, in a queue worker, on exactly the case it
     * most needs to explain.
     */
    public function test_a_refusal_is_read_before_the_content_array_is_touched(): void
    {
        $outcome = $this->writer(new RecordingReportTransport(self::refusalBody()))->write([]);

        self::assertFalse($outcome->succeeded);
        self::assertStringContainsString('declined', (string) $outcome->error);
        self::assertSame([], $outcome->report);

        // Paid for, and recorded as such.
        self::assertSame(19_500, $outcome->inputTokens);
    }

    /**
     * A truncated structured output is unparseable JSON, not a shorter report,
     * and unlike the vision path nothing can be salvaged by hand — so the
     * advice is a shorter window.
     */
    public function test_a_truncated_answer_is_a_failure_that_suggests_a_shorter_range(): void
    {
        $outcome = $this->writer(new RecordingReportTransport(self::maxTokensBody()))->write([]);

        self::assertFalse($outcome->succeeded);
        self::assertStringContainsString('ran out of room', (string) $outcome->error);
        self::assertStringContainsString('shorter range', (string) $outcome->error);
    }

    public function test_an_api_error_becomes_a_failed_outcome_not_an_exception(): void
    {
        $transport = new RecordingReportTransport(
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
            status: 529,
        );

        $outcome = $this->writer($transport)->write([]);

        self::assertFalse($outcome->succeeded);
        self::assertNotNull($outcome->error);
    }

    /**
     * A schema carrying numeric or length constraints is rejected outright
     * rather than partially honoured, so the whole document has to stay inside
     * the supported subset.
     */
    public function test_the_schema_stays_inside_what_structured_outputs_accept(): void
    {
        $this->assertSchemaIsSupported(PromptV1Report::schema());
        $this->assertSchemaIsSupported(PromptV2Report::schema());
    }

    /**
     * The stamp on the row and the text on the wire are one decision:
     * `health_reports.prompt_version` is worth something only if a row saying
     * `v2.5-report` was written by v2.5, which is what makes a stored
     * `input_snapshot` replayable through a later prompt and the two answers
     * comparable. The writer reads both off the same class.
     */
    public function test_the_version_it_stamps_is_the_prompt_it_sends(): void
    {
        $transport = new RecordingReportTransport(self::successBody());
        $writer = $this->writer($transport);

        $writer->write([]);

        self::assertSame('v2.5-report', $writer->promptVersion());
        self::assertSame(PromptV2Report::VERSION, $writer->promptVersion());
        self::assertSame(
            PromptV2Report::TEXT,
            $transport->body()['messages'][0]['content'][1]['text'],
        );
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function assertSchemaIsSupported(array $schema, string $path = 'root'): void
    {
        foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'pattern', 'multipleOf'] as $unsupported) {
            self::assertArrayNotHasKey($unsupported, $schema, "{$path} carries an unsupported keyword: {$unsupported}");
        }

        if (($schema['type'] ?? null) === 'object') {
            self::assertArrayHasKey('additionalProperties', $schema, "{$path} must set additionalProperties");
            self::assertFalse($schema['additionalProperties'], "{$path}.additionalProperties must be false");

            $properties = $schema['properties'] ?? [];

            // Structured outputs have no notion of an optional field: a section
            // the model has nothing to say about is nullable, not absent.
            self::assertEqualsCanonicalizing(
                array_keys($properties),
                $schema['required'] ?? [],
                "{$path} must require every property it declares",
            );

            foreach ($properties as $name => $property) {
                $this->assertSchemaIsSupported($property, "{$path}.{$name}");
            }
        }

        if (($schema['type'] ?? null) === 'array' && isset($schema['items'])) {
            $this->assertSchemaIsSupported($schema['items'], "{$path}[]");
        }
    }

    private function writer(ClientInterface $transport): AnthropicReportWriter
    {
        return new AnthropicReportWriter(
            client: new Client(
                apiKey: 'sk-ant-test-key-never-used',
                requestOptions: ['transporter' => $transport, 'maxRetries' => 0],
            ),
            logger: Log::getFacadeRoot(),
            model: 'claude-opus-5',
            maxTokens: 16000,
            effort: 'high',
        );
    }

    /** @return array<string, mixed> */
    private static function successBody(): array
    {
        return self::message(
            [['type' => 'text', 'text' => (string) json_encode(HealthReportFactory::document())]],
            'end_turn',
        );
    }

    /** @return array<string, mixed> */
    private static function refusalBody(): array
    {
        return [
            ...self::message([], 'refusal'),
            'stop_details' => ['type' => 'refusal', 'category' => 'bio', 'explanation' => 'Declined.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function maxTokensBody(): array
    {
        return self::message([['type' => 'text', 'text' => '{"headline":"A steady we']], 'max_tokens');
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function message(array $content, string $stopReason): array
    {
        return [
            'id'            => 'msg_01REPORTREPORTREPORT',
            'type'          => 'message',
            'role'          => 'assistant',
            'model'         => 'claude-opus-5',
            'container'     => null,
            'content'       => $content,
            'stop_reason'   => $stopReason,
            'stop_sequence' => null,
            'stop_details'  => null,
            'usage'         => ['input_tokens' => 19500, 'output_tokens' => 3400],
        ];
    }
}

/**
 * A PSR-18 client answering from a canned body and remembering what it was
 * asked. The SDK takes one as `requestOptions.transporter` — also how
 * production supplies a Guzzle client with a report's 300-second read timeout.
 */
final class RecordingReportTransport implements ClientInterface
{
    public ?RequestInterface $request = null;

    /**
     * @param  array<string, mixed>  $response
     */
    public function __construct(private readonly array $response, private readonly int $status = 200) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return new Response(
            $this->status,
            ['Content-Type' => 'application/json'],
            (string) json_encode($this->response),
        );
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->request?->getBody(), true);

        return $decoded;
    }
}
