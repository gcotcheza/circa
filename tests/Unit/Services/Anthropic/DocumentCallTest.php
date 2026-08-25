<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Anthropic;

use Throwable;
use Tests\TestCase;
use Anthropic\Client;
use Psr\Log\LogLevel;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use App\Services\Anthropic\DocumentCall;
use App\Services\Anthropic\ModelDocument;
use Anthropic\Messages\OutputConfig\Effort;
use Psr\Http\Client\ClientExceptionInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\PermissionDeniedException;

/**
 * The failure ladder both callers now share, exercised once.
 *
 * The report and vision paths used to hold a copy of this each, and the
 * whole point of merging them is that a rule broken here breaks visibly.
 * So the fakes are no use: this drives the REAL SDK client through a
 * PSR-18 transport that answers from a canned body — no network, and every
 * rung of the ladder reachable, including the two that only a transport
 * throwing can produce.
 */
final class DocumentCallTest extends TestCase
{
    public function test_a_document_is_decoded_with_its_usage_and_the_whole_message(): void
    {
        $document = $this->documentCall(new StubTransport(self::message([['type' => 'text', 'text' => '{"ok":true}']])))
            ->document([], self::SCHEMA, hrtime(true));

        self::assertSame(['ok' => true], $document->decoded);
        self::assertNull($document->failure);
        self::assertSame(1500, $document->inputTokens);
        self::assertSame(300, $document->outputTokens);

        // The WHOLE message, not the answer: a later replay diffs usage, stop
        // reason and model, none of which survive a reduction to the document.
        self::assertSame('end_turn', $document->raw['stop_reason'] ?? null);
        self::assertSame('claude-opus-5', $document->raw['model'] ?? null);
    }

    public function test_a_refusal_is_a_failure_that_still_reports_what_it_cost(): void
    {
        $document = $this->documentCall(new StubTransport(self::message([], 'refusal')))
            ->document([], self::SCHEMA, hrtime(true));

        self::assertNull($document->decoded);
        self::assertSame(ModelDocument::FAILED_REFUSAL, $document->failure);

        // Refused after a full read of the evidence, which was paid for.
        self::assertSame(1500, $document->inputTokens);
        self::assertNotNull($document->raw);
    }

    public function test_a_truncated_answer_is_a_failure_rather_than_broken_json(): void
    {
        $document = $this->documentCall(new StubTransport(self::message([['type' => 'text', 'text' => '{"ok":tr']], 'max_tokens')))
            ->document([], self::SCHEMA, hrtime(true));

        self::assertSame(ModelDocument::FAILED_TRUNCATED, $document->failure);
    }

    public function test_content_carrying_no_text_block_is_empty(): void
    {
        $document = $this->documentCall(new StubTransport(self::message([])))
            ->document([], self::SCHEMA, hrtime(true));

        self::assertSame(ModelDocument::FAILED_EMPTY, $document->failure);
    }

    public function test_text_that_is_not_json_is_unreadable_rather_than_an_exception(): void
    {
        $document = $this->documentCall(new StubTransport(self::message([['type' => 'text', 'text' => 'Sorry, no.']])))
            ->document([], self::SCHEMA, hrtime(true));

        self::assertSame(ModelDocument::FAILED_UNREADABLE, $document->failure);
    }

    public function test_the_document_is_found_past_a_thinking_block(): void
    {
        $document = $this->documentCall(new StubTransport(self::message([
            ['type' => 'thinking', 'thinking' => 'Weighing it up.', 'signature' => 'sig'],
            ['type' => 'text', 'text' => '{"ok":true}'],
        ])))->document([], self::SCHEMA, hrtime(true));

        self::assertSame(['ok' => true], $document->decoded);
    }

    /**
     * Every way the API can refuse to answer, in the words one person reads
     * on a phone — and the technical account of the same failure in the log,
     * which is now the only place it exists.
     *
     * @return iterable<string, array{0: int|null, 1: string, 2: class-string, 3: string}>
     */
    public static function apiFailures(): iterable
    {
        // One exception covers a network that was never reachable and a
        // request that connected and then overran the timeout, so the
        // sentence claims only what is true of both.
        $noAnswer = 'The test service did not answer in time. Check the connection, then try again.';

        yield 'no response at all' => [null, $noAnswer, APIConnectionException::class, LogLevel::WARNING];
        yield 'server-side timeout' => [408, $noAnswer, APIStatusException::class, LogLevel::WARNING];
        yield 'rate limited' => [429, 'The test service is busy right now. Try again in a minute.', RateLimitException::class, LogLevel::WARNING];

        // 529 and 500 are the same EXCEPTION to the SDK and opposite advice
        // to a reader, which is why the ladder reads the status, not the class.
        yield 'overloaded' => [529, 'The test service is busy right now. Try again in a minute.', InternalServerException::class, LogLevel::WARNING];
        yield 'server error' => [500, 'The test service had an internal error. Try again later.', InternalServerException::class, LogLevel::WARNING];

        // Error, not warning: a rejected credential fails every call until
        // somebody changes the deployment.
        yield 'bad key' => [401, "The test service rejected the app's credentials. Check the API key.", AuthenticationException::class, LogLevel::ERROR];
        yield 'no access' => [403, "The test service rejected the app's credentials. Check the API key.", PermissionDeniedException::class, LogLevel::ERROR];

        yield 'bad request' => [400, 'The test service rejected the request.', BadRequestException::class, LogLevel::WARNING];
        yield 'no such model' => [404, 'The test service rejected the request.', NotFoundException::class, LogLevel::WARNING];
    }

    #[DataProvider('apiFailures')]
    public function test_an_api_failure_is_said_plainly_and_explained_only_in_the_log(?int $status, string $expected, string $exception, string $level): void
    {
        $logger = new RecordingLogger;

        // A null status is the one failure no response can produce: the
        // transport itself throwing, which the SDK wraps as a connection error.
        $transport = $status === null
            ? new ThrowingTransport(new TransportFailure('connection reset'))
            : new StubTransport(['type' => 'error', 'error' => ['type' => 'an_error', 'message' => 'Something the SDK will quote']], status: $status);

        $document = $this->documentCall($transport, logger: $logger)->document([], self::SCHEMA, hrtime(true));

        self::assertNull($document->decoded);
        self::assertSame($expected, $document->failure);

        // Every message this SDK raises opens with a description of its own
        // class, so the vendor's name appearing in what a reader is shown is
        // exactly the leak this ladder exists to close.
        self::assertStringNotContainsString('Anthropic', (string) $document->failure);

        // Nothing to store and nothing was spent: the request never produced
        // a message.
        self::assertNull($document->raw);
        self::assertNull($document->inputTokens);
        self::assertSame([[$level, 'Test call failed']], $logger->lines);

        self::assertSame($exception, $logger->contexts[0]['exception']);
        self::assertSame($status, $logger->contexts[0]['status']);
        self::assertStringContainsString('Anthropic', (string) $logger->contexts[0]['message']);
    }

    public function test_any_other_throwable_is_logged_under_the_callers_label_and_never_escapes(): void
    {
        $logger = new RecordingLogger;

        $document = $this->documentCall(new ThrowingTransport(new \RuntimeException('boom')), logger: $logger)
            ->document([], self::SCHEMA, hrtime(true));

        // An exception loose in a queue worker is the failure this guards
        // against: it becomes an outcome, and the detail goes to the log
        // under the label its caller chose. Not the unreachable sentence —
        // a bug here reaches this branch as readily as a dead network, and
        // only one of the two is fixed by checking the connection.
        self::assertSame('The test service returned an error.', $document->failure);
        self::assertSame([[LogLevel::ERROR, 'Test call failed']], $logger->lines);
        self::assertSame(\RuntimeException::class, $logger->contexts[0]['exception']);
        self::assertNull($logger->contexts[0]['status']);
        self::assertSame('boom', $logger->contexts[0]['message']);
    }

    public function test_an_effort_level_reaches_the_wire_only_when_the_caller_asked_for_one(): void
    {
        $without = new StubTransport(self::message([['type' => 'text', 'text' => '{"ok":true}']]));
        $this->documentCall($without)->document([], self::SCHEMA, hrtime(true));

        self::assertArrayNotHasKey('effort', $without->body()['output_config']);

        $with = new StubTransport(self::message([['type' => 'text', 'text' => '{"ok":true}']]));
        $this->documentCall($with, effort: Effort::HIGH)->document([], self::SCHEMA, hrtime(true));

        self::assertSame('high', $with->body()['output_config']['effort']);
    }

    public function test_each_of_the_four_shapes_is_said_in_the_callers_own_words(): void
    {
        $messages = [
            ModelDocument::FAILED_REFUSAL    => 'Declined.',
            ModelDocument::FAILED_TRUNCATED  => 'Ran out of room.',
            ModelDocument::FAILED_EMPTY      => 'Came back empty.',
            ModelDocument::FAILED_UNREADABLE => 'Came back unreadable.',
        ];

        foreach ($messages as $failure => $expected) {
            self::assertSame($expected, ModelDocument::failed($failure, null, null, null, 1)->failureText($messages));
        }

        // Anything outside the four is a sentence DocumentCall already built
        // about the call itself, and passes through unchanged.
        self::assertSame(
            'The test service is busy right now. Try again in a minute.',
            ModelDocument::failed('The test service is busy right now. Try again in a minute.', null, null, null, 1)->failureText($messages),
        );
    }

    /** @var array<string, mixed> */
    private const SCHEMA = [
        'type'                 => 'object',
        'properties'           => ['ok' => ['type' => 'boolean']],
        'required'             => ['ok'],
        'additionalProperties' => false,
    ];

    private function documentCall(ClientInterface $transport, ?Effort $effort = null, ?LoggerInterface $logger = null): DocumentCall
    {
        return new DocumentCall(
            client: new Client(
                apiKey: 'sk-ant-test-key-never-used',
                requestOptions: ['transporter' => $transport, 'maxRetries' => 0],
            ),
            logger: $logger ?? new RecordingLogger,
            model: 'claude-opus-5',
            maxTokens: 1024,
            effort: $effort,
            logMessage: 'Test call failed',
            serviceName: 'test',
        );
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function message(array $content, string $stopReason = 'end_turn'): array
    {
        return [
            'id'            => 'msg_01TESTTESTTESTTEST',
            'type'          => 'message',
            'role'          => 'assistant',
            'model'         => 'claude-opus-5',
            'container'     => null,
            'content'       => $content,
            'stop_reason'   => $stopReason,
            'stop_sequence' => null,
            'stop_details'  => null,
            'usage'         => ['input_tokens' => 1500, 'output_tokens' => 300],
        ];
    }
}

/** A PSR-18 client answering from a canned body and remembering the request. */
final class StubTransport implements ClientInterface
{
    private ?RequestInterface $request = null;

    /** @param  array<string, mixed>  $response */
    public function __construct(private readonly array $response, private readonly int $status = 200) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->request = $request;

        return new Response($this->status, ['Content-Type' => 'application/json'], (string) json_encode($this->response));
    }

    /** @return array<string, mixed> */
    public function body(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->request?->getBody(), true);

        return $decoded;
    }
}

/** A PSR-18 client that never answers, for the two branches only a throw reaches. */
final class ThrowingTransport implements ClientInterface
{
    public function __construct(private readonly Throwable $failure) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        throw $this->failure;
    }
}

/** A failure the SDK recognises as its own: it surfaces as an APIConnectionException. */
final class TransportFailure extends \RuntimeException implements ClientExceptionInterface {}

/** A PSR-3 logger keeping level, message and context, so the caller's label and the technical detail are both assertable. */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{0: string, 1: string}> */
    public array $lines = [];

    /** @var list<array<mixed>> */
    public array $contexts = [];

    /**
     * @param  mixed  $level
     * @param  array<mixed>  $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->lines[] = [(string) $level, (string) $message];
        $this->contexts[] = $context;
    }
}
