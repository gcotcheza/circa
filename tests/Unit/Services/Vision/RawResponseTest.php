<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Vision;

use Tests\TestCase;
use App\Services\Vision\RawResponse;

/**
 * Reading an answer back out of a stored row, in isolation.
 *
 * ParsedLabel and StoredAnswer share this reader, so the shapes a stored
 * `raw_response` can actually hold — a refused call, a thinking block ahead
 * of the answer, a row from a failure — are pinned once here rather than
 * twice through two replay paths.
 */
final class RawResponseTest extends TestCase
{
    public function test_the_document_is_the_first_text_block(): void
    {
        self::assertSame(
            ['items' => []],
            RawResponse::firstJsonObject(['content' => [['type' => 'text', 'text' => '{"items":[]}']]]),
        );
    }

    public function test_a_thinking_block_is_walked_past_rather_than_indexed_into(): void
    {
        self::assertSame(
            ['name' => 'Magnesium'],
            RawResponse::firstJsonObject(['content' => [
                ['type' => 'thinking', 'thinking' => 'Reading the panel.'],
                ['type' => 'text', 'text' => '{"name":"Magnesium"}'],
            ]]),
        );
    }

    public function test_a_row_with_no_content_at_all_is_null(): void
    {
        self::assertNull(RawResponse::firstJsonObject([]));
        self::assertNull(RawResponse::firstJsonObject(['content' => []]));
    }

    public function test_a_refusal_row_carrying_no_text_block_is_null(): void
    {
        self::assertNull(RawResponse::firstJsonObject([
            'stop_reason' => 'refusal',
            'content'     => [['type' => 'thinking', 'thinking' => 'No.']],
        ]));
    }

    public function test_text_that_is_not_json_is_null_rather_than_an_exception(): void
    {
        self::assertNull(RawResponse::firstJsonObject(['content' => [['type' => 'text', 'text' => 'Sorry, no.']]]));
    }

    public function test_valid_json_that_is_a_bare_scalar_is_not_the_document_asked_for(): void
    {
        self::assertNull(RawResponse::firstJsonObject(['content' => [['type' => 'text', 'text' => '42']]]));
        self::assertNull(RawResponse::firstJsonObject(['content' => [['type' => 'text', 'text' => 'null']]]));
    }

    public function test_the_replay_accepts_everything_the_live_call_accepted(): void
    {
        // Same nesting limit as DocumentCall decodes at, so a document the
        // call accepted and stored cannot replay as "no readable answer".
        $deep = json_encode(self::nest(40));

        self::assertNotNull(RawResponse::firstJsonObject(['content' => [['type' => 'text', 'text' => (string) $deep]]]));
    }

    public function test_a_later_text_block_does_not_rescue_an_unreadable_first_one(): void
    {
        // The first text block IS the document under a server-side schema, so
        // reading on would be reading something the model did not answer with.
        self::assertNull(RawResponse::firstJsonObject(['content' => [
            ['type' => 'text', 'text' => 'not json'],
            ['type' => 'text', 'text' => '{"name":"Magnesium"}'],
        ]]));
    }

    /** @return array<string, mixed> */
    private static function nest(int $depth): array
    {
        $node = ['leaf' => true];

        for ($i = 1; $i < $depth; $i++) {
            $node = ['child' => $node];
        }

        return $node;
    }
}
