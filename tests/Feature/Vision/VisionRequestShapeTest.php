<?php

declare(strict_types=1);

namespace Tests\Feature\Vision;

use Tests\TestCase;
use Anthropic\Client;
use GuzzleHttp\Psr7\Response;
use App\Services\Vision\PromptV1;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use App\Services\Vision\PromptV1Text;
use App\Services\Vision\PromptV2Photo;
use App\Services\Vision\PromptV3Photo;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use App\Services\Vision\AnthropicVisionAnalyzer;

/**
 * What actually goes on the wire.
 *
 * FakeVisionAnalyzer answers our own interface — fast and network-free, and
 * blind to a misspelled parameter, a wrong content-block shape or a schema the
 * API rejects. So this one fakes a layer lower: a PSR-18 transporter handed to
 * the real SDK client, capturing the exact JSON body. What it pins is what an
 * SDK upgrade or a careless edit would break silently:
 *
 *   - `image` before `text`: evidence before the question is a different prompt.
 *   - `media_type` on the wire, not the SDK's camelCase property name.
 *   - `output_config.format.type = json_schema` carrying OUR schema, which makes
 *     the first text block parseable JSON with no prose to strip.
 *   - no `temperature` / `top_p` / `top_k`: this model 400s on them.
 */
final class VisionRequestShapeTest extends TestCase
{
    public function test_the_request_carries_the_image_the_prompt_and_the_schema(): void
    {
        $transport = new RecordingTransport(self::successBody());

        $this->analyzer($transport)->analyze('JPEG-BYTES');

        $body = $transport->body();

        self::assertSame('claude-opus-5', $body['model']);
        self::assertSame(4096, $body['max_tokens']);

        $content = $body['messages'][0]['content'];

        self::assertSame('user', $body['messages'][0]['role']);

        // ONE image, always: three courses are three calls — three audit rows,
        // three separately confirmable proposals. See AnalyzeMealPhoto.
        self::assertCount(2, $content);
        self::assertCount(1, array_filter($content, static fn (array $b): bool => $b['type'] === 'image'));

        self::assertSame('image', $content[0]['type']);
        self::assertSame('base64', $content[0]['source']['type']);
        self::assertSame('image/jpeg', $content[0]['source']['media_type']);
        self::assertSame('JPEG-BYTES', base64_decode($content[0]['source']['data'], true));

        self::assertSame('text', $content[1]['type']);
        self::assertSame(PromptV3Photo::TEXT, $content[1]['text']);

        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame(
            PromptV1::schema()['properties']['items']['items']['required'],
            $body['output_config']['format']['schema']['properties']['items']['items']['required'],
        );

        // Sending any of them is a 400 on this model, not a shrug.
        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('top_p', $body);
        self::assertArrayNotHasKey('top_k', $body);
    }

    /**
     * The double-count guard, on the wire. Photograph the second helping with
     * the salad still in shot and a model told nothing about the first course
     * lists it again — and Confirm APPENDS, so the day gains a salad nobody
     * ate. The already-logged names therefore ride as their own block between
     * evidence and instruction, never concatenated into either: they derive
     * from user input, which must never become prompt text — the same rule the
     * text path applies to the typed meal.
     */
    public function test_already_logged_items_ride_as_their_own_block_between_image_and_instruction(): void
    {
        $transport = new RecordingTransport(self::successBody());

        $this->analyzer($transport)->analyze('JPEG-BYTES', ['White rice', 'Grilled chicken breast']);

        $content = $transport->body()['messages'][0]['content'];

        self::assertCount(3, $content);

        self::assertSame('image', $content[0]['type']);

        self::assertSame('text', $content[1]['type']);
        self::assertStringContainsString('Already logged for this meal', $content[1]['text']);
        self::assertStringContainsString('- White rice', $content[1]['text']);
        self::assertStringContainsString('- Grilled chicken breast', $content[1]['text']);

        // Last, so its rules cover everything above — and untouched by the names.
        self::assertSame('text', $content[2]['type']);
        self::assertSame(PromptV3Photo::TEXT, $content[2]['text']);
        self::assertStringNotContainsString('White rice', $content[2]['text']);
    }

    /**
     * A first course sees the two blocks it always did: "Already logged:
     * (nothing)" is bookkeeping in front of every first plate forever, and the
     * prompt already handles its own absence.
     */
    public function test_an_empty_meal_sends_no_already_logged_block_at_all(): void
    {
        $transport = new RecordingTransport(self::successBody());

        $this->analyzer($transport)->analyze('JPEG-BYTES', []);

        $content = $transport->body()['messages'][0]['content'];

        self::assertCount(2, $content);
        self::assertSame('image', $content[0]['type']);
        self::assertSame(PromptV3Photo::TEXT, $content[1]['text']);
    }

    /**
     * The user's note, on the wire. "3 eggs, 120 g drained tuna" is the
     * cheapest way to narrow a range on a photograph — a picture cannot tell
     * you the tuna was drained — and it obeys the rule every piece of user
     * input near a prompt does: ITS OWN BLOCK between evidence and instruction,
     * the instruction UNTOUCHED by it (which is what "delimited" must mean —
     * the note is data, the last word belongs to the constant), and attributed,
     * so the model reads a claim by the person who ate the food rather than an
     * instruction from this app.
     */
    public function test_the_users_note_rides_as_its_own_block_between_the_image_and_the_instruction(): void
    {
        $transport = new RecordingTransport(self::successBody());

        // Deliberately NOT the prompt's own example ("120 g of drained tuna"):
        // the assertion below would otherwise pass for the wrong reason.
        $this->analyzer($transport)->analyze('JPEG-BYTES', [], '3 eggs, 120 g of smoked mackerel');

        $content = $transport->body()['messages'][0]['content'];

        self::assertCount(3, $content);

        self::assertSame('image', $content[0]['type']);

        self::assertSame('text', $content[1]['type']);
        self::assertStringContainsString('A note from the person who ate this', $content[1]['text']);
        self::assertStringContainsString('3 eggs, 120 g of smoked mackerel', $content[1]['text']);

        // A constant, and last. If the note reached it, "delimited" is decoration.
        self::assertSame('text', $content[2]['type']);
        self::assertSame(PromptV3Photo::TEXT, $content[2]['text']);
        self::assertStringNotContainsString('smoked mackerel', $content[2]['text']);
    }

    /**
     * Both, in order: evidence about THIS plate, then the rest of the meal,
     * then the question. The ordering IS the prompt — two context blocks that
     * swapped places would be a different call under the same version string.
     */
    public function test_a_note_and_the_already_logged_names_are_two_blocks_in_a_fixed_order(): void
    {
        $transport = new RecordingTransport(self::successBody());

        $this->analyzer($transport)->analyze('JPEG-BYTES', ['White rice'], 'one scoop, no cone');

        $content = $transport->body()['messages'][0]['content'];

        self::assertCount(4, $content);

        self::assertSame('image', $content[0]['type']);
        self::assertStringContainsString('one scoop, no cone', $content[1]['text']);
        self::assertStringContainsString('Already logged for this meal', $content[2]['text']);
        self::assertSame(PromptV3Photo::TEXT, $content[3]['text']);
    }

    /**
     * No note, no block — most plates, which is why the feature costs nothing
     * to ignore. An empty string counts as no note; the instruction already
     * handles its own absence ("if you were given a note").
     */
    public function test_an_absent_or_empty_note_sends_no_block_at_all(): void
    {
        foreach ([null, '', '   '] as $nothing) {
            $transport = new RecordingTransport(self::successBody());

            $this->analyzer($transport)->analyze('JPEG-BYTES', [], $nothing);

            $content = $transport->body()['messages'][0]['content'];

            self::assertCount(2, $content);
            self::assertSame('image', $content[0]['type']);
            self::assertSame(PromptV3Photo::TEXT, $content[1]['text']);
        }
    }

    /**
     * The rule that makes a note worth writing is IN THE INSTRUCTION, not in
     * the block around the user's words — which is why the photo prompt moved
     * to v3 rather than gaining a context block on v2. v2 says "RANGES, ALWAYS.
     * Never a point estimate", is sent LAST, and governs everything above it,
     * so a note saying "120 g of drained tuna" would have come back as a band.
     */
    public function test_the_instruction_carves_the_exception_for_a_measured_quantity(): void
    {
        self::assertStringContainsString('RANGES, ALWAYS, FOR EVERYTHING YOU ARE ESTIMATING', PromptV3Photo::TEXT);
        self::assertStringContainsString('IT IS EVIDENCE, NOT A', PromptV3Photo::TEXT);
        self::assertStringContainsString('both ends of', PromptV3Photo::TEXT);

        // A note is what the user happened to know, not an inventory of the plate.
        self::assertStringContainsString('food and drink you can see that they did not mention', PromptV3Photo::TEXT);

        // v2 still says the unqualified version — why it could not ship this.
        self::assertStringContainsString('RANGES, ALWAYS. Never a point estimate.', PromptV2Photo::TEXT);
        self::assertStringNotContainsString('RANGES, ALWAYS, FOR EVERYTHING', PromptV2Photo::TEXT);
    }

    public function test_a_successful_response_is_parsed_into_proposed_items(): void
    {
        $outcome = $this->analyzer(new RecordingTransport(self::successBody()))->analyze('JPEG-BYTES');

        self::assertTrue($outcome->succeeded);
        self::assertCount(1, $outcome->items);
        self::assertSame('White rice', $outcome->items[0]->name);
        self::assertSame(120.0, $outcome->items[0]->portionGMin);
        self::assertSame(260.0, $outcome->items[0]->kcalMax);
        self::assertSame('high', $outcome->items[0]->confidence);
        self::assertSame('Judged against a 27 cm plate.', $outcome->notes);
        self::assertSame(1_500, $outcome->inputTokens);
        self::assertSame(300, $outcome->outputTokens);
        self::assertIsArray($outcome->raw);
    }

    /**
     * The text variant, on the wire: same model, ceiling, schema and absence of
     * the three rejected sampling parameters, same EVIDENCE-THEN-INSTRUCTION
     * ordering with the typed meal where the image was. The two text blocks are
     * asserted separately on purpose — concatenating the user's words into the
     * instruction would work and would be wrong: the typed meal is untrusted
     * input and must never become part of the prompt text.
     */
    public function test_the_text_request_carries_the_description_the_text_prompt_and_the_same_schema(): void
    {
        $transport = new RecordingTransport(self::successBody());

        $this->analyzer($transport)->estimate('MEAL AS THE USER TYPED IT');

        $body = $transport->body();

        self::assertSame('claude-opus-5', $body['model']);
        self::assertSame(4096, $body['max_tokens']);

        $content = $body['messages'][0]['content'];

        self::assertSame('user', $body['messages'][0]['role']);
        self::assertCount(2, $content);

        self::assertSame('text', $content[0]['type']);
        self::assertSame('MEAL AS THE USER TYPED IT', $content[0]['text']);

        self::assertSame('text', $content[1]['type']);
        self::assertSame(PromptV1Text::TEXT, $content[1]['text']);

        // No image block at all — this is the whole point of the variant.
        self::assertSame([], array_filter($content, static fn (array $b): bool => $b['type'] === 'image'));

        // The SAME schema: two would mean two parsers, mappers and review screens.
        self::assertSame('json_schema', $body['output_config']['format']['type']);
        self::assertSame(
            PromptV1::schema(),
            $body['output_config']['format']['schema'],
        );

        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('top_p', $body);
        self::assertArrayNotHasKey('top_k', $body);
    }

    public function test_the_two_prompt_versions_are_distinct(): void
    {
        // `vision_requests.prompt_version` makes a prompt change evaluable
        // against history; two prompts sharing a version string destroy that.
        self::assertNotSame(PromptV3Photo::VERSION, PromptV1Text::VERSION);
        self::assertNotSame(PromptV3Photo::TEXT, PromptV1Text::TEXT);

        // v1 and v2-photo are unedited, so every row naming either was produced
        // by the text it still holds — why the note shipped as a new version.
        self::assertNotSame(PromptV1::VERSION, PromptV2Photo::VERSION);
        self::assertNotSame(PromptV1::TEXT, PromptV2Photo::TEXT);
        self::assertNotSame(PromptV2Photo::VERSION, PromptV3Photo::VERSION);
        self::assertNotSame(PromptV2Photo::TEXT, PromptV3Photo::TEXT);

        // What did NOT change is shared rather than copied, so it cannot drift.
        self::assertSame(PromptV1::schema(), PromptV3Photo::schema());
        self::assertSame(PromptV2Photo::alreadyLogged(['Rice']), PromptV3Photo::alreadyLogged(['Rice']));

        $analyzer = $this->analyzer(new RecordingTransport(self::successBody()));

        self::assertSame(PromptV3Photo::VERSION, $analyzer->promptVersion());
        self::assertSame(PromptV1Text::VERSION, $analyzer->textPromptVersion());
    }

    public function test_a_text_estimate_reads_a_response_exactly_as_the_photo_path_does(): void
    {
        $outcome = $this->analyzer(new RecordingTransport(self::successBody()))->estimate('a meal');

        self::assertTrue($outcome->succeeded);
        self::assertCount(1, $outcome->items);
        self::assertSame('White rice', $outcome->items[0]->name);
        self::assertSame(260.0, $outcome->items[0]->kcalMax);
    }

    public function test_a_refusal_on_the_text_path_is_handled_by_the_same_code(): void
    {
        $outcome = $this->analyzer(new RecordingTransport(self::refusalBody()))->estimate('a meal');

        self::assertFalse($outcome->succeeded);
        self::assertStringContainsString('declined', (string) $outcome->error);
    }

    public function test_a_refusal_is_read_before_the_content_array_is_touched(): void
    {
        // HTTP 200 with an EMPTY content array: reading content[0] first
        // crashes on exactly the case it most needs to explain.
        $outcome = $this->analyzer(new RecordingTransport(self::refusalBody()))->analyze('JPEG-BYTES');

        self::assertFalse($outcome->succeeded);
        self::assertStringContainsString('declined', (string) $outcome->error);
        self::assertSame([], $outcome->items);
    }

    public function test_a_truncated_answer_is_a_failure_rather_than_broken_json(): void
    {
        $outcome = $this->analyzer(new RecordingTransport(self::maxTokensBody()))->analyze('JPEG-BYTES');

        self::assertFalse($outcome->succeeded);
        self::assertStringContainsString('ran out of room', (string) $outcome->error);
    }

    public function test_an_api_error_becomes_a_failed_outcome_not_an_exception(): void
    {
        $transport = new RecordingTransport(
            ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
            status: 529,
        );

        $outcome = $this->analyzer($transport)->analyze('JPEG-BYTES');

        self::assertFalse($outcome->succeeded);
        self::assertNotNull($outcome->error);
    }

    private function analyzer(ClientInterface $transport): AnthropicVisionAnalyzer
    {
        return new AnthropicVisionAnalyzer(
            client: new Client(
                apiKey: 'sk-ant-test-key-never-used',
                requestOptions: ['transporter' => $transport, 'maxRetries' => 0],
            ),
            logger: Log::getFacadeRoot(),
            model: 'claude-opus-5',
            maxTokens: 4096,
        );
    }

    /** @return array<string, mixed> */
    private static function successBody(): array
    {
        $json = json_encode([
            'items' => [[
                'name'          => 'White rice',
                'portion_g_min' => 120, 'portion_g_max' => 200,
                'kcal_min'      => 156, 'kcal_max' => 260,
                'protein_g_min' => 3.24, 'protein_g_max' => 5.4,
                'carbs_g_min'   => 33.6, 'carbs_g_max' => 56,
                'fat_g_min'     => 0.36, 'fat_g_max' => 0.6,
                'confidence'    => 'high',
            ]],
            'notes' => 'Judged against a 27 cm plate.',
        ]);

        return self::message([['type' => 'text', 'text' => $json]], 'end_turn');
    }

    /** @return array<string, mixed> */
    private static function refusalBody(): array
    {
        return [
            ...self::message([], 'refusal'),
            'stop_details' => ['type' => 'refusal', 'category' => 'cyber', 'explanation' => 'Declined.'],
        ];
    }

    /** @return array<string, mixed> */
    private static function maxTokensBody(): array
    {
        return self::message([['type' => 'text', 'text' => '{"items":[{"name":"Ri']], 'max_tokens');
    }

    /**
     * @param  list<array<string, mixed>>  $content
     * @return array<string, mixed>
     */
    private static function message(array $content, string $stopReason): array
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

/**
 * A PSR-18 client answering from a canned body and remembering the request.
 * The SDK takes one as `requestOptions.transporter` — how production supplies
 * a Guzzle client with real timeouts.
 */
final class RecordingTransport implements ClientInterface
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
